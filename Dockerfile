FROM php:8.3-apache

RUN docker-php-ext-install mysqli

COPY TestFiles/ /var/www/html/
COPY secrets/ /var/www/secrets/

RUN mkdir -p /var/www/html/uploads/rosters \
    && chown -R www-data:www-data /var/www/html /var/www/secrets

EXPOSE 80
