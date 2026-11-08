FROM php:8.4-cli
RUN apt-get update && apt-get install -y git unzip libzip-dev \
    && docker-php-ext-install pdo_mysql zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY . .
RUN cp .env.example .env && composer install --no-interaction
EXPOSE 8001
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8001
