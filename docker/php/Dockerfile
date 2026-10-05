FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y $PHPIZE_DEPS git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
    tesseract-ocr poppler-utils \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html/laravel
CMD ["php-fpm"]
