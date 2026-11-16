FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql mysqliCOPY . /var/www/html
EXPOSE 80