# CRM Agropecuário Copérdia — imagem para deploy de testes (Railway)
# Usa o servidor embutido do PHP (simples e sem configuração de Apache),
# adequado para homologação. Em produção definitiva, migrar para FPM+Nginx.
FROM php:8.3-cli

# Extensões: PDO MySQL e GD (imagens/ícones)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_mysql gd \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

# Uploads persistentes: monte um volume em /var/www/html/public/uploads
RUN mkdir -p public/uploads

# Vários workers para atender requisições em paralelo
ENV PHP_CLI_SERVER_WORKERS=8

# Railway injeta a porta em $PORT (padrão 8080)
CMD ["sh", "-c", "php -d upload_max_filesize=20M -d post_max_size=25M -d memory_limit=256M -S 0.0.0.0:${PORT:-8080} -t public"]
