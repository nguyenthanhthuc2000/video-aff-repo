"""Đọc lời thoại biểu cảm bằng Chatterbox (gọi nội bộ từ app/queue).

POST /tts {"lines": [{"text", "ref" (WAV base64: giọng mẫu của nhân vật), "exaggeration", "cfg_weight"}], "lead", "gap", "tail"}
    -> {"id"} (chạy nền, lần lượt từng yêu cầu: mỗi câu mất khoảng 1 phút trên CPU)
POST /vc {"source": WAV base64 (tiếng Muse của clip), "segments": [{"start", "end", "ref"}], "pad": giây}
    -> {"id"}: đổi giọng từng đoạn nói sang giọng mẫu của nhân vật, GIỮ NGUYÊN vị trí và nhịp (khớp khẩu hình Muse);
    ngoài các đoạn nói là im lặng. Kết quả hỏi ở GET /tts/<id> như đọc lời.
GET /tts/<id> -> {"state": "pending", "done": số câu xong, "total"} | {"state": "failed", "error"} |
    {"state": "done", "base64": WAV 24 kHz mono, "duration": giây, "lines": [{"start", "end"}]}

Mô hình chỉ nạp khi có việc và tự giải phóng sau IDLE_UNLOAD_SECONDS rảnh (máy Docker ít RAM).
"""

import base64
import gc
import hashlib
import json
import os
import queue
import tempfile
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import numpy as np
import soundfile as sf

TOKEN = os.environ.get("AUDIO_TOKEN", "")
IDLE = int(os.environ.get("IDLE_UNLOAD_SECONDS", "600"))
RATE = 24000
TASKS = {}
JOBS = queue.Queue()
STATE = {"model": None, "vc": None, "last": 0.0, "refs": {}}
LOCK = threading.Lock()


def model():
    if STATE["model"] is None:
        from chatterbox.tts import ChatterboxTTS

        STATE["model"] = ChatterboxTTS.from_pretrained(device="cpu")
    STATE["last"] = time.time()
    return STATE["model"]


def vc_model():
    if STATE["vc"] is None:
        from chatterbox.vc import ChatterboxVC

        STATE["vc"] = ChatterboxVC.from_pretrained(device="cpu")
    STATE["last"] = time.time()
    return STATE["vc"]


def convert(task_id, body):
    """Đổi giọng từng đoạn nói, đặt lại đúng chỗ cũ (dài hơn thì cắt, ngắn hơn thì đệm lặng) để giữ khớp khẩu hình."""
    with tempfile.TemporaryDirectory() as tmp:
        src = os.path.join(tmp, "source.wav")
        with open(src, "wb") as f:
            f.write(base64.b64decode(body["source"]))
        audio, rate = sf.read(src, dtype="float32", always_2d=True)
        audio = audio.mean(axis=1)
        out = np.zeros(len(audio), dtype=np.float32)
        pad = float(body.get("pad", 0.06))
        vc = vc_model()
        for i, seg in enumerate(body.get("segments", [])):
            a = max(0, int((float(seg["start"]) - pad) * rate))
            b = min(len(audio), int((float(seg["end"]) + pad) * rate))
            if b - a < int(0.1 * rate):
                continue
            piece = os.path.join(tmp, f"seg-{i}.wav")
            sf.write(piece, audio[a:b], rate)
            wav = vc.generate(piece, target_voice_path=ref_path(base64.b64decode(seg["ref"])))
            converted = wav.squeeze(0).numpy().astype(np.float32)
            if vc.sr != rate:
                idx = np.linspace(0, len(converted) - 1, int(len(converted) * rate / vc.sr))
                converted = np.interp(idx, np.arange(len(converted)), converted).astype(np.float32)
            n = min(b - a, len(converted))
            out[a : a + n] = converted[:n]
            TASKS[task_id]["done"] = i + 1
            STATE["last"] = time.time()
        path = os.path.join(tmp, "out.wav")
        sf.write(path, out, rate, subtype="PCM_16")
        with open(path, "rb") as f:
            data = base64.b64encode(f.read()).decode()
    return {"base64": data, "duration": round(len(out) / rate, 3), "lines": []}


def ref_path(data):
    """Giọng mẫu lưu theo mã băm, dùng lại cho các câu / shot sau của cùng nhân vật."""
    key = hashlib.sha1(data).hexdigest()
    if key not in STATE["refs"]:
        path = os.path.join(tempfile.gettempdir(), f"ref-{key}.wav")
        with open(path, "wb") as f:
            f.write(data)
        STATE["refs"][key] = path
    return STATE["refs"][key]


def synthesize(task_id, body):
    lead, gap, tail = float(body.get("lead", 0.3)), float(body.get("gap", 0.35)), float(body.get("tail", 0.4))
    lines = body.get("lines", [])
    pieces, timeline, cursor = [np.zeros(int(lead * RATE), dtype=np.float32)], [], lead
    tts = model()
    for i, line in enumerate(lines):
        wav = tts.generate(
            str(line["text"]),
            audio_prompt_path=ref_path(base64.b64decode(line["ref"])),
            exaggeration=float(line.get("exaggeration", 0.5)),
            cfg_weight=float(line.get("cfg_weight", 0.5)),
        )
        audio = wav.squeeze(0).numpy().astype(np.float32)
        if tts.sr != RATE:
            idx = np.linspace(0, len(audio) - 1, int(len(audio) * RATE / tts.sr))
            audio = np.interp(idx, np.arange(len(audio)), audio).astype(np.float32)
        if i > 0:
            pieces.append(np.zeros(int(gap * RATE), dtype=np.float32))
            cursor += gap
        seconds = len(audio) / RATE
        timeline.append({"start": round(cursor, 3), "end": round(cursor + seconds, 3)})
        pieces.append(audio)
        cursor += seconds
        TASKS[task_id]["done"] = i + 1
        STATE["last"] = time.time()
    pieces.append(np.zeros(int(tail * RATE), dtype=np.float32))
    mix = np.concatenate(pieces)
    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, "line.wav")
        sf.write(path, mix, RATE, subtype="PCM_16")
        with open(path, "rb") as f:
            data = base64.b64encode(f.read()).decode()
    return {"base64": data, "duration": round(len(mix) / RATE, 3), "lines": timeline}


def worker():
    while True:
        try:
            task_id, body = JOBS.get(timeout=30)
        except queue.Empty:
            # Rảnh lâu: giải phóng mô hình để trả RAM cho Docker.
            if (STATE["model"] is not None or STATE["vc"] is not None) and time.time() - STATE["last"] > IDLE:
                with LOCK:
                    STATE["model"] = None
                    STATE["vc"] = None
                    gc.collect()
                print("model unloaded (idle)", flush=True)
            continue
        try:
            with LOCK:
                run = convert if body.get("kind") == "vc" else synthesize
                TASKS[task_id] = {**TASKS[task_id], "state": "done", **run(task_id, body)}
        except Exception as e:  # noqa: BLE001
            TASKS[task_id] = {"state": "failed", "error": str(e)[:300]}


class Handler(BaseHTTPRequestHandler):
    def reply(self, status, body):
        payload = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def authorized(self):
        return not TOKEN or self.headers.get("Authorization") == f"Bearer {TOKEN}"

    def do_GET(self):
        if self.path == "/health":
            return self.reply(200, {"ok": True, "loaded": STATE["model"] is not None, "queued": JOBS.qsize()})
        if not self.path.startswith("/tts/"):
            return self.reply(404, {"error": "not found"})
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        task_id = self.path.rsplit("/", 1)[-1]
        task = TASKS.get(task_id)
        if task is None:
            return self.reply(404, {"error": "Không tìm thấy yêu cầu đọc (dịch vụ đã khởi động lại?)."})
        self.reply(200, {k: v for k, v in task.items()} if task["state"] != "pending" else {"state": "pending", "done": task.get("done", 0), "total": task.get("total", 0)})
        if task["state"] != "pending":
            TASKS.pop(task_id, None)

    def do_POST(self):
        if self.path not in ("/tts", "/vc"):
            return self.reply(404, {"error": "not found"})
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        try:
            body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))
            if self.path == "/vc":
                assert body.get("source") and isinstance(body.get("segments"), list), "thiếu source / segments"
                body["kind"] = "vc"
            else:
                assert isinstance(body.get("lines"), list) and body["lines"], "lines trống"
        except Exception as e:  # noqa: BLE001
            return self.reply(400, {"error": str(e)[:300]})
        task_id = uuid.uuid4().hex
        TASKS[task_id] = {"state": "pending", "done": 0, "total": len(body.get("lines") or body.get("segments") or [])}
        JOBS.put((task_id, body))
        self.reply(202, {"id": task_id})

    def log_message(self, fmt, *args):
        if "/health" not in (args[0] if args else ""):
            print(fmt % args, flush=True)


if __name__ == "__main__":
    threading.Thread(target=worker, daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", int(os.environ.get("PORT", 3200))), Handler).serve_forever()
