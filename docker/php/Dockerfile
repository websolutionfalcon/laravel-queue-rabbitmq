FROM php:8.3-cli-alpine

# Install system dependencies and PHP extensions required for the package
RUN apk add --no-cache \
    git \
    unzip \
    linux-headers \
    && docker-php-ext-install \
    sockets \
    bcmath \
    pcntl \
    && apk del linux-headers

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /application
