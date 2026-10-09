# Trình duyệt ngầm (Chromium qua Playwright) để thao tác meta.ai bằng tài khoản của người dùng.
FROM mcr.microsoft.com/playwright:v1.64.0-noble

WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev
# Claude Code CLI: chỉ dùng cho `claude setup-token` (đăng nhập gói Claude từ màn hình Xác thực Claude).
RUN curl -fsSL https://claude.ai/install.sh | bash
ENV PATH="/root/.local/bin:${PATH}"

COPY server.js ./

ENV PORT=3000
EXPOSE 3000
CMD ["node", "server.js"]
