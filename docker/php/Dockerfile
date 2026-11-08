FROM php:8.4-fpm-alpine

# Install system utilities
RUN apk add --no-cache \
    curl \
    git \
    bash \
    postgresql-client

# Install PHP extensions via installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    bcmath \
    zip \
    intl \
    pcntl \
    opcache \
    mbstring

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
