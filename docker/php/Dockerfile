FROM php:8.3-fpm-bookworm

# policy-rc.d prevents apt from trying to start daemons (clamd/supervisor)
# inside the build container.
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d && chmod +x /usr/sbin/policy-rc.d \
    && apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
        tesseract-ocr poppler-utils supervisor clamav \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -f /usr/sbin/policy-rc.d \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY supervisord.conf /etc/supervisor/conf.d/queue.conf

WORKDIR /var/www/html/laravel
CMD ["php-fpm"]
