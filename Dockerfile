FROM php:8.3-apache

RUN docker-php-ext-install mysqli \
    && (a2dismod --force mpm_event mpm_worker || true) \
    && a2enmod mpm_prefork

COPY TestFiles/ /var/www/html/
COPY secrets/ /var/www/secrets/

RUN mkdir -p /var/www/html/uploads/rosters \
    && chown -R www-data:www-data /var/www/html /var/www/secrets

EXPOSE 80
