FROM php:8.3-fpm

# ffmpeg: ghép video; các extension cần cho Laravel + MySQL; gd cho xử lý/kiểm tra ảnh (và test)
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg git unzip libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Chạy PHP bằng UID/GID của máy host để file tạo ra không bị root sở hữu
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g www-data www-data

WORKDIR /var/www/html
USER www-data
