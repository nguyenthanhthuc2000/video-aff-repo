# Phân tích nhạc cho hát nhép (CPU, không cần GPU): Demucs tách giọng hát, faster-whisper ghi lời kèm mốc thời gian.
FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*

WORKDIR /app
RUN pip install --no-cache-dir torch==2.5.1 torchaudio==2.5.1 --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir demucs==4.0.1 faster-whisper==1.1.1 requests soundfile numpy

# Tải sẵn mô hình lúc build để chạy không cần mạng.
ENV WHISPER_MODEL=small HF_HOME=/models/hf TORCH_HOME=/models/torch
RUN python -c "from demucs.pretrained import get_model; get_model('htdemucs')" \
    && python -c "import os; from faster_whisper import WhisperModel; WhisperModel(os.environ['WHISPER_MODEL'], device='cpu', compute_type='int8')"

COPY server.py ./
ENV PORT=3100
EXPOSE 3100
CMD ["python", "server.py"]
