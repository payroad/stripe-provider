FROM php:8.2-cli-alpine

RUN apk add --no-cache curl-dev \
 && docker-php-ext-install bcmath curl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .
