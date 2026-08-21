# Imagen base: PHP 8.3 con el servidor web Apache ya incluido.
FROM php:8.3-apache

# Instala la extension pdo_mysql (para que PHP hable con MySQL con PDO)
# y activa mod_rewrite (para que funcione nuestro .htaccess / rutas).
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

# El sitio se sirve desde la carpeta public/ (nuestro Front Controller).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Permite que el .htaccess funcione (AllowOverride All).
RUN sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
