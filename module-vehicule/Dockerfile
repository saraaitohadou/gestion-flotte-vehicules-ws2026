FROM php:8.4-cli

RUN docker-php-ext-install pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "-t", "public"]
