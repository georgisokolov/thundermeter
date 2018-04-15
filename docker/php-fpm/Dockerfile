FROM php:7.0-fpm
# Install modules
RUN apt-get update && apt-get install -y \
    libmcrypt-dev  \
    libicu-dev \
    mysql-client \
    && docker-php-ext-install mcrypt \
    && docker-php-ext-install mbstring