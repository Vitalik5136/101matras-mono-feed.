FROM php:8.2-apache
RUN apt-get update && apt-get install -y libxml2-dev && docker-php-ext-install xml mbstring
RUN a2enmod rewrite
COPY . /var/www/html/
EXPOSE 80
