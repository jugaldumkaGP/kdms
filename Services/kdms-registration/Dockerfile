FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    unzip \
    curl \
    ca-certificates \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql gd bcmath \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/* \
    && printf '%s\n' \
        'upload_max_filesize=8M' \
        'post_max_size=10M' \
        'max_file_uploads=20' \
        > /usr/local/etc/php/conf.d/kdms-uploads.ini

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

RUN ln -snf /var/www/html/pwa /var/www/html/public/pwa \
    && sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite headers

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV PORT=8080
EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
