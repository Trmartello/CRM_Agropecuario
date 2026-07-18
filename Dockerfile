# CRM Agropecuário Copérdia — imagem para deploy (Railway ou qualquer host Docker)
FROM php:8.3-apache

# Extensões necessárias: PDO MySQL e GD (ícones/imagens)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_mysql gd \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# DocumentRoot aponta para /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}/!g' /etc/apache2/apache2.conf

COPY . /var/www/html/

# Uploads persistentes: monte um volume em /var/www/html/public/uploads
RUN mkdir -p /var/www/html/public/uploads \
 && chown -R www-data:www-data /var/www/html/public/uploads

# Railway injeta a porta em $PORT — troca apenas as diretivas de porta do Apache
CMD ["sh", "-c", "sed -ri \"s/^Listen 80$/Listen ${PORT:-80}/\" /etc/apache2/ports.conf; sed -ri \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]
