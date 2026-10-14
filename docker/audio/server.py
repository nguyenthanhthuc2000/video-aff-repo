"""Phân tích giọng hát của một bài nhạc, và đọc lời thoại phim hoạt hình bằng Kokoro TTS (gọi nội bộ từ app/queue).

POST /tts {"lines": [{"text", "voice", "speed", "pitch"}], "lead": giây, "gap": giây, "tail": giây}
    -> {"base64": WAV 24 kHz mono, "duration": giây, "lines": [{"start", "end"}]}
    Cùng giọng + cùng thông số luôn ra cùng một chất giọng (Kokoro không ngẫu nhiên), nên mỗi nhân vật giữ một giọng ở mọi cảnh.

    Câu có "lang": "vi" đọc bằng Piper giọng tiếng Việt vais1000 (CC BY 4.0) cho bản lồng tiếng Việt; pitch / speed như trên.

POST /music {"cues": [{"cue": {tempo, style, lead, pad, arp, bars: [{chord, melody}]}, "seconds"}]}
    -> {"cues": [{"base64": WAV 44,1 kHz stereo, "duration"}]}  Nhạc Claude sáng tác cho tập phim (music.py, FluidSynth).

POST /transcribe {"base64": file ghi âm, "language": "en"}
    -> {"text", "duration", "words": [{"start", "end", "word", "probability"}], "segments": [{"start", "end", "text"}]}
    Chép lời người học đọc / nói (faster-whisper, không gợi ý câu mẫu để chấm phát âm cho thật).

POST /analyze {"base64": "...", "name": "song.mp3", "language": "vi"} -> {"id": "..."} (chạy nền, mỗi lần một bài)
GET /analyze/<id> -> {"state": "pending"} | {"state": "failed", "error"} |
    {"state": "done", "duration": giây, "vocals": [{"start", "end"}], "words": [{"start", "end", "text"}]}

POST /youtube {"url": link YouTube, "audio": false} -> {"id": "..."} (chạy nền, yt-dlp; chỉ đọc thông tin + phụ đề, không tải nhạc)
GET /youtube/<id> -> {"state": "pending"} | {"state": "failed", "error"} |
    {"state": "done", "title", "artist", "channel", "duration", "description", "language", "captions_kind": "manual"|"auto"|null,
     "captions": [{"start", "end", "text"}]}
    Bài trên YouTube chỉ làm tham khảo để Claude sáng tác bài mới: không tải âm thanh, video.
    Chỉ nhận link youtube.com / youtu.be (không cho yt-dlp mở trang khác).

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


def transcribe(data, language):
    with tempfile.TemporaryDirectory() as tmp:
        source = os.path.join(tmp, "speech")
        with open(source, "wb") as f:
            f.write(data)
        wav = os.path.join(tmp, "speech.wav")
        subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", source, "-ac", "1", "-ar", "16000", wav], check=True)
        with LOCK:
            segments, info = whisper().transcribe(
                wav, language=language or "en", word_timestamps=True, vad_filter=True, condition_on_previous_text=False, beam_size=5,
            )
            segments = list(segments)
        words = [
            {"start": round(w.start, 2), "end": round(w.end, 2), "word": w.word.strip(), "probability": round(w.probability, 3)}
            for seg in segments for w in (seg.words or []) if w.word.strip()
        ]
        return {
            "text": " ".join(seg.text.strip() for seg in segments).strip(),
            "duration": round(info.duration, 2),
            "words": words,
            "segments": [{"start": round(seg.start, 2), "end": round(seg.end, 2), "text": seg.text.strip()} for seg in segments],
        }


def run(task_id, data, name, language):
    try:
        # Mỗi lần một bài: Demucs và Whisper dùng nhiều CPU / RAM.
        with LOCK:
            TASKS[task_id] = {"state": "done", **analyze(data, name, language)}
    except subprocess.CalledProcessError as e:
        TASKS[task_id] = {"state": "failed", "error": "Tách giọng thất bại: " + (e.stderr or b"").decode(errors="ignore")[-300:]}
    except Exception as e:  # noqa: BLE001
        TASKS[task_id] = {"state": "failed", "error": str(e)[:300]}


YOUTUBE_HOSTS = {"youtube.com", "www.youtube.com", "m.youtube.com", "music.youtube.com", "youtu.be"}


def youtube_url(url):
    from urllib.parse import urlparse

    parsed = urlparse(str(url).strip())
    if parsed.scheme not in ("http", "https") or (parsed.hostname or "").lower() not in YOUTUBE_HOSTS:
        raise ValueError("Chỉ nhận link YouTube (youtube.com hoặc youtu.be).")
    return parsed.geturl()


def vtt_cues(path):
    """Phụ đề WebVTT -> các câu có mốc; bỏ thẻ, bỏ dòng lặp của phụ đề tự động (cuộn từng dòng), bỏ [Music] / [Âm nhạc]."""

    def seconds(stamp):
        parts = stamp.replace(",", ".").split(":")
        return sum(float(p) * 60 ** i for i, p in enumerate(reversed(parts)))

    cues, last = [], ""
    with open(path, encoding="utf-8", errors="ignore") as f:
        blocks = f.read().split("\n\n")
    for block in blocks:
        lines = [l for l in block.strip().splitlines() if l.strip()]
        timing = next((l for l in lines if "-->" in l), None)
        if timing is None:
            continue
        start, end = (seconds(t.strip().split(" ")[0]) for t in timing.split("-->"))
        text = " ".join(re.sub(r"<[^>]+>", "", l).strip() for l in lines[lines.index(timing) + 1:]).strip()
        text = re.sub(r"\s+", " ", text)
        if not text or re.fullmatch(r"[\[(♪].*[\])♪]|♪+", text):
            continue
        # Phụ đề tự động lặp lại dòng trước rồi thêm chữ mới: chỉ giữ phần mới.
        if last and text.startswith(last):
            text = text[len(last):].strip()
        if text:
            cues.append({"start": round(start, 2), "end": round(end, 2), "text": text})
            last = text
    return cues


def youtube(task_id, url):
    tmp = tempfile.mkdtemp()
    try:
        info = json.loads(subprocess.run(
            ["yt-dlp", "--no-playlist", "--dump-single-json", "--no-warnings", url], check=True, capture_output=True, timeout=120,
        ).stdout)
        manual, auto = info.get("subtitles") or {}, info.get("automatic_captions") or {}
        language = (info.get("language") or "").split("-")[0] or None
        # Phụ đề do người đăng tải lên chính xác hơn phụ đề tự động; ưu tiên đúng ngôn ngữ của video.
        kind, lang = None, None
        for source, name in ((manual, "manual"), (auto, "auto")):
            keys = [k for k in source if not k.startswith("live_chat")]
            same = [k for k in keys if language and k.split("-")[0] == language]
            # Phụ đề tự động: chỉ lấy bản gốc đúng ngôn ngữ video (các thứ tiếng khác là bản dịch máy).
            pick = (sorted(same, key=lambda k: (k.endswith("-orig") is False, len(k)))[0] if same else None) \
                or (keys[0] if name == "manual" and keys else None)
            if pick:
                kind, lang = name, pick
                break
        if kind:
            subprocess.run(
                ["yt-dlp", "--no-playlist", "--no-warnings", "--skip-download", "--write-subs" if kind == "manual" else "--write-auto-subs",
                 "--sub-langs", lang, "--sub-format", "vtt", "-o", os.path.join(tmp, "ref.%(ext)s"), url],
                check=True, capture_output=True, timeout=120,
            )
        subs = next((os.path.join(tmp, f) for f in os.listdir(tmp) if f.endswith(".vtt")), None)
        captions = vtt_cues(subs) if subs else []
        TASKS[task_id] = {
            "state": "done",
            "title": info.get("track") or info.get("title") or "",
            "artist": info.get("artist") or info.get("creator") or info.get("uploader") or "",
            "channel": info.get("channel") or info.get("uploader") or "",
            "duration": round(float(info.get("duration") or 0), 2),
            "description": (info.get("description") or "")[:5000],
            "tags": (info.get("tags") or [])[:20],
            "language": (lang or language or "").split("-")[0] or None,
            "captions_kind": kind if captions else None,
            "captions": captions,
        }
    except subprocess.TimeoutExpired:
        TASKS[task_id] = {"state": "failed", "error": "YouTube phản hồi quá lâu, thử lại sau."}
    except subprocess.CalledProcessError as e:
        TASKS[task_id] = {"state": "failed", "error": "yt-dlp lỗi: " + (e.stderr or b"").decode(errors="ignore").strip()[-300:]}
    except Exception as e:  # noqa: BLE001
        TASKS[task_id] = {"state": "failed", "error": str(e)[:300]}
    finally:
        subprocess.run(["rm", "-rf", tmp])


TTS_RATE = 24000
TTS_LOCK = threading.Lock()
_pipelines = {}


def pipeline(voice):
    """Kokoro theo giọng: a = Anh-Mỹ (af_, am_), b = Anh-Anh (bf_, bm_)."""
    from kokoro import KPipeline

    lang = "b" if voice.startswith("b") else "a"
    if lang not in _pipelines:
        _pipelines[lang] = KPipeline(lang_code=lang, repo_id="hexgrad/Kokoro-82M")
    return _pipelines[lang]


_piper = None


def piper_vi(text, speed):
    """Tiếng Việt: Piper (MIT) + giọng vais1000 (CC BY 4.0), đổi về 24 kHz như Kokoro."""
    global _piper
    from piper import PiperVoice

    if _piper is None:
        _piper = PiperVoice.load(os.environ.get("PIPER_VI_MODEL", "/models/piper/vi_VN-vais1000-medium.onnx"))
    rate = _piper.config.sample_rate
    pcm = b"".join(_piper.synthesize_stream_raw(text, length_scale=1.0 / max(0.5, speed), sentence_silence=0.15))
    audio = np.frombuffer(pcm, dtype=np.int16).astype(np.float32) / 32768.0
    if rate != TTS_RATE and len(audio) > 1:
        idx = np.linspace(0, len(audio) - 1, int(len(audio) * TTS_RATE / rate))
        audio = np.interp(idx, np.arange(len(audio)), audio).astype(np.float32)
    return audio


def shift_pitch(audio, pitch):
    """Nâng / hạ cao độ, giữ nguyên tốc độ (asetrate rồi atempo bù lại), như bản nghe thử F0."""
    if abs(pitch - 1.0) < 0.001:
        return audio
    with tempfile.TemporaryDirectory() as tmp:
        src, out = os.path.join(tmp, "in.wav"), os.path.join(tmp, "out.wav")
        sf.write(src, audio, TTS_RATE)
        af = f"asetrate={int(TTS_RATE * pitch)},aresample={TTS_RATE},atempo={1 / pitch:.4f}"
        subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", src, "-af", af, "-ac", "1", out], check=True)
        shifted, _ = sf.read(out, dtype="float32")
        return shifted


def tts(body):
    lead, gap, tail = float(body.get("lead", 0.3)), float(body.get("gap", 0.35)), float(body.get("tail", 0.3))
    pieces, lines, cursor = [np.zeros(int(lead * TTS_RATE), dtype=np.float32)], [], lead
    with TTS_LOCK:
        for i, line in enumerate(body.get("lines", [])):
            voice = str(line.get("voice", ""))
            if line.get("lang") == "vi":
                raw = piper_vi(str(line["text"]), float(line.get("speed", 1.0)))
            else:
                chunks = [np.asarray(audio, dtype=np.float32) for _, _, audio in pipeline(voice)(str(line["text"]), voice=voice, speed=float(line.get("speed", 1.0)))]
                raw = np.concatenate(chunks) if chunks else np.zeros(1, dtype=np.float32)
            audio = shift_pitch(raw if len(raw) else np.zeros(1, dtype=np.float32), float(line.get("pitch", 1.0)))
            if i > 0:
                pieces.append(np.zeros(int(gap * TTS_RATE), dtype=np.float32))
                cursor += gap
            seconds = len(audio) / TTS_RATE
            lines.append({"start": round(cursor, 3), "end": round(cursor + seconds, 3)})
            pieces.append(audio)
            cursor += seconds
    pieces.append(np.zeros(int(tail * TTS_RATE), dtype=np.float32))
    mix = np.concatenate(pieces)
    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, "line.wav")
        sf.write(path, mix, TTS_RATE, subtype="PCM_16")
        with open(path, "rb") as f:
            data = base64.b64encode(f.read()).decode()
    return {"base64": data, "duration": round(len(mix) / TTS_RATE, 3), "lines": lines}


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
        if not self.path.startswith(("/analyze/", "/youtube/")):
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
        if self.path not in ("/analyze", "/tts", "/transcribe", "/music", "/youtube"):
            return self.reply(404, {"error": "not found"})
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        if self.path == "/music":
            try:
                import music

                return self.reply(200, music.score(json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))))
            except Exception as e:  # noqa: BLE001
                return self.reply(500, {"error": "Phát nhạc thất bại: " + str(e)[:300]})
        if self.path == "/transcribe":
            try:
                body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))
                return self.reply(200, transcribe(base64.b64decode(body["base64"]), body.get("language", "en")))
            except Exception as e:  # noqa: BLE001
                return self.reply(500, {"error": "Chép lời thất bại: " + str(e)[:300]})
        if self.path == "/youtube":
            try:
                body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))
                url = youtube_url(body.get("url", ""))
            except Exception as e:  # noqa: BLE001
                return self.reply(400, {"error": str(e)[:300]})
            task_id = uuid.uuid4().hex
            TASKS[task_id] = {"state": "pending"}
            threading.Thread(target=youtube, args=(task_id, url), daemon=True).start()
            return self.reply(202, {"id": task_id})
        if self.path == "/tts":
            try:
                body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))
                return self.reply(200, tts(body))
            except Exception as e:  # noqa: BLE001
                return self.reply(500, {"error": "Đọc lời thoại thất bại: " + str(e)[:300]})
        try:
            body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))
            data = base64.b64decode(body["base64"])
        except Exception as e:  # noqa: BLE001
            return self.reply(400, {"error": str(e)[:300]})

        task_id = uuid.uuid4().hex
        TASKS[task_id] = {"state": "pending"}
        threading.Thread(target=run, args=(task_id, data, body.get("name", "song.mp3"), body.get("language", "vi") or None), daemon=True).start()
        self.reply(202, {"id": task_id})

    def log_message(self, fmt, *args):
        print(fmt % args, flush=True)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", int(os.environ.get("PORT", 3100))), Handler).serve_forever()
