FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        bash \
        git \
        curl \
        zip \
        unzip \
        oniguruma-dev \
        libzip-dev \
        icu-dev \
        autoconf \
        build-base \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf build-base \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

EXPOSE 9000

CMD ["php-fpm"]
