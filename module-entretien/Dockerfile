# Base = image applicative deja construite : PHP 8.4 + extensions + Composer +
# dependances vendor. Cela rend le build reproducible sans acces a GitHub ni au
# registre Docker (api.github.com et codeload.github.com etant injoignables).
FROM gestion-flotte-final-entretien-app:latest
WORKDIR /var/www
COPY . .
# Le .dockerignore exclut storage/ : recreer les repertoires requis par Laravel
# (sinon GET /up et toute vue Blade renvoient 500 "Please provide a valid cache path").
RUN mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs \
    && touch .env && composer dump-autoload --optimize
EXPOSE 8004
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8004 --no-reload
