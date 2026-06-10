# Apache + PHP (Cloud Run: listens on $PORT, default 8080)
# Single base image from Docker Hub (composer installed in-image to avoid a second Hub pull in CI).
FROM php:8.3-apache
RUN a2enmod rewrite \
    && apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        unzip \
        curl \
        ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql mysqli zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/kdms
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --optimize-autoloader --ignore-platform-req=ext-gd

COPY . .
COPY docker/kdms-vhost.conf /etc/apache2/sites-available/kdms-vhost.conf
COPY docker/kdms-vhost-prefix.conf /etc/apache2/sites-available/kdms-vhost-prefix.conf
COPY docker/kdms-vhost.conf /etc/apache2/sites-enabled/000-default.conf

COPY docker/php/kdms-php.ini /usr/local/etc/php/conf.d/99-kdms.ini

RUN mkdir -p /var/www/html \
	&& chown -R www-data:www-data /var/www/kdms

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV PORT=8080
EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
