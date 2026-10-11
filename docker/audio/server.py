"""Phân tích giọng hát của một bài nhạc (gọi nội bộ từ app/queue).

POST /analyze {"base64": "...", "name": "song.mp3", "language": "vi"} -> {"id": "..."} (chạy nền, mỗi lần một bài)
GET /analyze/<id> -> {"state": "pending"} | {"state": "failed", "error"} |
    {"state": "done", "duration": giây, "vocals": [{"start", "end"}], "words": [{"start", "end", "text"}]}

vocals: các khoảng có giọng hát (từ bản tách giọng của Demucs, đo độ lớn từng 50 ms).
words: lời bài hát kèm mốc thời gian (faster-whisper trên bản tách giọng), có thể sai chữ nhưng mốc thời gian đủ dùng.
"""

import base64
import json
import os
import re
import subprocess
import tempfile
import threading
import uuid
import warnings
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import numpy as np
from numpy.lib.stride_tricks import sliding_window_view
import soundfile as sf

TOKEN = os.environ.get("AUDIO_TOKEN", "")
FRAME = 0.05
LOCK = threading.Lock()
TASKS = {}
_whisper = None


def whisper():
    global _whisper
    if _whisper is None:
        from faster_whisper import WhisperModel

        _whisper = WhisperModel(os.environ.get("WHISPER_MODEL", "small"), device="cpu", compute_type="int8")
    return _whisper


# Câu Whisper hay "nghe nhầm" khi nhạc không có lời (học từ video YouTube): bỏ đi.
HALLUCINATIONS = re.compile(r"subscribe|đăng ký kênh|ghiền mì gõ|cảm ơn các bạn đã|theo dõi kênh|like và share|bấm chuông", re.I)



def vocal_mask(path):
    """Từng khung 50 ms của bản tách giọng có tiếng hát hay không.

    Ngưỡng theo chính bài (giọng hát thường đều quanh mức đỉnh); chỗ lấy hơi giữa câu là khung tụt hơn 8 dB
    so với mức giọng hát trong khoảng ±1,5 giây xung quanh, để tách từng câu dù cả đoạn hát liền.
    """
    audio, rate = sf.read(path, always_2d=True)
    mono = audio.mean(axis=1)
    size = int(rate * FRAME)
    frames = len(mono) // size
    if frames == 0:
        return np.zeros(0, dtype=bool), 0.0
    rms = np.sqrt(np.mean(mono[: frames * size].reshape(frames, size) ** 2, axis=1) + 1e-12)
    db = 20 * np.log10(rms)
    threshold = max(np.percentile(db, 10) + 10, np.percentile(db, 97) - 20, -45)
    voiced = db > threshold

    half = int(1.5 / FRAME)
    padded = np.pad(np.where(voiced, db, np.nan), (half, half), constant_values=np.nan)
    with np.errstate(all="ignore"), warnings.catch_warnings():
        warnings.simplefilter("ignore", RuntimeWarning)
        local = np.nanmedian(sliding_window_view(padded, 2 * half + 1), axis=1)
    local = np.where(np.isnan(local), db, local)

    return voiced & ~(db < local - 8), frames * FRAME


def voiced_ratio(mask, start, end):
    a, b = int(start / FRAME), max(int(start / FRAME) + 1, int(end / FRAME))
    window = mask[a:b]
    return float(window.mean()) if len(window) else 0.0


def mask_intervals(mask):
    """Các câu hát: khoảng có tiếng hát liền nhau (nối các khe rất ngắn), bỏ tiếng lẻ dưới 0,3 giây."""
    intervals, start = [], None
    for i, on in enumerate(list(mask) + [False]):
        if on and start is None:
            start = i
        elif not on and start is not None:
            intervals.append([start * FRAME, i * FRAME])
            start = None
    merged = []
    for a, b in intervals:
        if merged and a - merged[-1][1] < 0.15:
            merged[-1][1] = b
        else:
            merged.append([a, b])
    return [{"start": round(a, 2), "end": round(b, 2)} for a, b in merged if b - a >= 0.3]


def analyze(data, name, language):
    with tempfile.TemporaryDirectory() as tmp:
        source = os.path.join(tmp, os.path.basename(name) or "song.mp3")
        with open(source, "wb") as f:
            f.write(data)
        wav = os.path.join(tmp, "song.wav")
        subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", source, "-ac", "2", "-ar", "44100", wav], check=True)
        subprocess.run(
            ["python", "-m", "demucs", "--two-stems=vocals", "-n", "htdemucs", "-d", "cpu", "-j", "2", "-o", tmp, wav],
            check=True,
            capture_output=True,
        )
        vocals_path = os.path.join(tmp, "htdemucs", "song", "vocals.wav")
        mask, duration = vocal_mask(vocals_path)

        segments, _ = whisper().transcribe(
            vocals_path,
            language=language or None,
            word_timestamps=True,
            vad_filter=False,
            condition_on_previous_text=False,
            beam_size=5,
        )
        words = []
        for segment in segments:
            # Bỏ đoạn Whisper đoán bừa (độ tin cậy thấp, lặp chữ); no_speech_prob luôn cao với tiếng hát nên không dùng.
            # Câu bịa hay bị gộp chung với lời thật nên lọc theo từng chữ: đoạn đáng ngờ thì chữ phải có giọng hát rõ hơn.
            if segment.avg_logprob < -1.5 or segment.compression_ratio > 2.4:
                continue
            minimum = 0.6 if HALLUCINATIONS.search(segment.text) else 0.2
            for word in segment.words or []:
                text = word.word.strip()
                # Chỉ giữ chữ mà bản tách giọng thật sự có tiếng hát ở đó.
                if text and word.end > word.start and voiced_ratio(mask, word.start, word.end) >= minimum:
                    words.append({"start": round(word.start, 2), "end": round(word.end, 2), "text": text})

        # Mốc câu lấy từ bản tách giọng (chính xác tới 50 ms), chữ của Whisper gắn vào theo thời gian.
        vocals = mask_intervals(mask)
        return {"duration": round(duration, 2), "vocals": vocals, "words": words}


def run(task_id, data, name, language):
    try:
        # Mỗi lần một bài: Demucs và Whisper dùng nhiều CPU / RAM.
        with LOCK:
            TASKS[task_id] = {"state": "done", **analyze(data, name, language)}
    except subprocess.CalledProcessError as e:
        TASKS[task_id] = {"state": "failed", "error": "Tách giọng thất bại: " + (e.stderr or b"").decode(errors="ignore")[-300:]}
    except Exception as e:  # noqa: BLE001
        TASKS[task_id] = {"state": "failed", "error": str(e)[:300]}


class Handler(BaseHTTPRequestHandler):
    def reply(self, status, body):
        payload = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def authorized(self):
        return not TOKEN or self.headers.get("Authorization") == f"Bearer {TOKEN}"

    def do_GET(self):
        if self.path == "/health":
            return self.reply(200, {"ok": True})
        if not self.path.startswith("/analyze/"):
            return self.reply(404, {"error": "not found"})
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        task = TASKS.get(self.path.rsplit("/", 1)[-1])
        if task is None:
            return self.reply(404, {"error": "Không tìm thấy yêu cầu phân tích (dịch vụ đã khởi động lại?)."})
        self.reply(200, task)
        if task["state"] != "pending":
            TASKS.pop(self.path.rsplit("/", 1)[-1], None)

    def do_POST(self):
        if self.path != "/analyze":
            return self.reply(404, {"error": "not found"})
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        try:
            body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))
            data = base64.b64decode(body["base64"])
        except Exception as e:  # noqa: BLE001
            return self.reply(400, {"error": str(e)[:300]})

        task_id = uuid.uuid4().hex
        TASKS[task_id] = {"state": "pending"}
        threading.Thread(target=run, args=(task_id, data, body.get("name", "song.mp3"), body.get("language", "vi")), daemon=True).start()
        self.reply(202, {"id": task_id})

    def log_message(self, fmt, *args):
        print(fmt % args, flush=True)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", int(os.environ.get("PORT", 3100))), Handler).serve_forever()
