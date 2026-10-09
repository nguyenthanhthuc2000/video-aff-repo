FROM php:8.3-fpm

# ffmpeg: ghép video; các extension cần cho Laravel + MySQL; gd cho xử lý/kiểm tra ảnh (và test)
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg git unzip curl libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Chạy PHP bằng UID/GID của máy host để file tạo ra không bị root sở hữu
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g www-data www-data \
    # Thư mục home của www-data phải ghi được (tinker/psysh, cache composer)
    && chown www-data:www-data /var/www

WORKDIR /var/www/html
USER www-data

# Claude Code CLI: viết kịch bản bằng gói Claude Pro/Max (SCRIPT_DRIVER=claude_cli, token từ `claude setup-token`)
RUN curl -fsSL https://claude.ai/install.sh | bash
ENV PATH="/var/www/.local/bin:${PATH}"
