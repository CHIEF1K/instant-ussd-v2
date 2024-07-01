FROM php:8.2-fpm

ENV PROJECT_DIR=/app
RUN mkdir -p ${PROJECT_DIR}
WORKDIR ${PROJECT_DIR}

RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    libpq-dev \
    nano \
    bash \
    zlib1g-dev \
    libpng-dev \
    openssl \
    && docker-php-ext-install pdo pdo_mysql gd


COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

COPY composer.json artisan ./

COPY . .

RUN chmod +x artisan

RUN composer install --prefer-source

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
