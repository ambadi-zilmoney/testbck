# syntax=docker/dockerfile:1

# ---- Stage 1: install dependencies & build an optimised autoloader ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY src/ src/
RUN composer dump-autoload --no-dev --classmap-authoritative

# ---- Stage 2: runtime ----
FROM php:8.3-apache AS runtime

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
 && a2enmod rewrite headers \
 && a2dissite 000-default \
 && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/apache.conf /etc/apache2/sites-available/app.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN a2ensite app \
 && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY public/ ./public/
COPY src/ ./src/
COPY bin/ ./bin/
COPY migrations/ ./migrations/

# Run as an unprivileged user (port 8080 doesn't need root)
USER www-data
EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=3s --start-period=10s --retries=3 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/api/health') === false ? 1 : 0);"

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
