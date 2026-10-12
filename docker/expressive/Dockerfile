# Giọng đọc biểu cảm cho phim hoạt hình: Chatterbox (Resemble AI, giấy phép MIT), chạy CPU.
# Bắt chước giọng từ đoạn mẫu của nhân vật (đọc bằng Kokoro) nên mỗi nhân vật giữ một giọng; chỉnh độ biểu cảm theo cảm xúc từng câu.
FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*
WORKDIR /app
RUN pip install --no-cache-dir chatterbox-tts soundfile numpy

# Tải sẵn mô hình lúc build (vài GB) để chạy không cần mạng.
ENV HF_HOME=/models/hf
RUN python -c "from chatterbox.tts import ChatterboxTTS; ChatterboxTTS.from_pretrained(device='cpu')"

COPY server.py ./
ENV PORT=3200 IDLE_UNLOAD_SECONDS=600
EXPOSE 3200
CMD ["python", "server.py"]
