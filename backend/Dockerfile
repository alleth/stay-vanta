# Production image for the StayVanta CakePHP API (Railway).
# Apache serves CakePHP's webroot; PHP-FPM is not needed for this size of app.
FROM php:8.2-apache

# System libs + PHP extensions required by CakePHP 5.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libonig-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql mbstring zip opcache \
    # mod_php needs exactly one MPM (prefork). Disable any others that the apt
    # step may have enabled, otherwise Apache fails: "More than one MPM loaded".
    && a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies (skip post-install scripts so the interactive
# CakePHP installer / app_local.php generation never runs in the image).
COPY . .
# tmp/ and logs/ are empty in the repo (untracked by git), so recreate the
# runtime dirs CakePHP needs and make them writable by Apache.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    && mkdir -p tmp/cache/models tmp/cache/persistent tmp/sessions tmp/tests logs \
    && chown -R www-data:www-data tmp logs

# Apache vhost -> CakePHP webroot, plus the entrypoint that binds $PORT.
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["entrypoint.sh"]
