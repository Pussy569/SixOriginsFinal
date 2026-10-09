FROM composer:2 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --classmap-authoritative

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring mysqli \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite \
    && test "$(apache2ctl -M | awk '$1 ~ /^mpm_/ && $2 == "(shared)" { print $1 }')" = "mpm_prefork_module" \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-app.conf /etc/apache2/conf-available/app.conf
RUN a2enconf app \
    && apache2ctl -t

WORKDIR /var/www/html
COPY . .
COPY config.sample.php ./config.php
COPY --from=dependencies /app/vendor ./vendor
COPY docker/entrypoint.sh /usr/local/bin/six-origins-entrypoint

RUN mkdir -p uploads logs images/user_uploads images/admin_uploads /data/sessions /data/topup_proofs \
    && chown -R www-data:www-data images/user_uploads images/admin_uploads uploads logs /data \
    && chmod 755 /usr/local/bin/six-origins-entrypoint

ENV PORT=80
ENV VERIFICATION_UPLOAD_DIR=/data/verification_uploads
EXPOSE 80

CMD ["six-origins-entrypoint"]
