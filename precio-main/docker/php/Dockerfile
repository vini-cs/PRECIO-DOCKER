# Imagem: precio-web  |  Função: back-end/front-end PHP rodando em Apache
FROM php:8.2-apache

# Extensões usadas pelo sistema (PDO/MySQL). curl e mbstring já vêm na imagem.
RUN docker-php-ext-install -j"$(nproc)" pdo pdo_mysql mysqli \
    && a2enmod rewrite headers remoteip access_compat

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-precio.ini
COPY docker/php/apache-precio.conf /etc/apache2/conf-available/precio.conf
RUN a2enconf precio

WORKDIR /var/www/html
COPY back-end ./back-end
COPY front-end ./front-end

# cache/ e logs/ precisam ser graváveis pelo Apache (viram volumes nomeados)
RUN mkdir -p back-end/cache back-end/logs \
    && chown -R www-data:www-data back-end/cache back-end/logs

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=5 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1/front-end/') === false ? 1 : 0);"
