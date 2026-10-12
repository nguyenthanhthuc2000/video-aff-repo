# Phân tích nhạc cho hát nhép (CPU, không cần GPU): Demucs tách giọng hát, faster-whisper ghi lời kèm mốc thời gian.
# Phim hoạt hình: Kokoro-82M (Apache-2.0) đọc lời thoại tiếng Anh, cần espeak-ng.
FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*

WORKDIR /app
RUN pip install --no-cache-dir torch==2.5.1 torchaudio==2.5.1 --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir demucs==4.0.1 faster-whisper==1.1.1 requests soundfile numpy

# Tải sẵn mô hình lúc build để chạy không cần mạng.
ENV WHISPER_MODEL=small HF_HOME=/models/hf TORCH_HOME=/models/torch
RUN python -c "from demucs.pretrained import get_model; get_model('htdemucs')" \
    && python -c "import os; from faster_whisper import WhisperModel; WhisperModel(os.environ['WHISPER_MODEL'], device='cpu', compute_type='int8')"
# Mô hình Kokoro + các giọng dùng trong app (danh sách theo App\Services\Film\Voices). Lớp riêng để không tải lại torch.
RUN apt-get update && apt-get install -y --no-install-recommends espeak-ng && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir 'kokoro>=0.9,<1' 'transformers==4.46.3'
COPY kokoro_voices.txt ./
RUN python -c "from kokoro import KPipeline; [KPipeline(lang_code=l, repo_id='hexgrad/Kokoro-82M').load_voice(v) for l in 'ab' for v in open('kokoro_voices.txt').read().split() if (v[0] == 'b') == (l == 'b')]"

COPY server.py ./
ENV PORT=3100
EXPOSE 3100
CMD ["python", "server.py"]
