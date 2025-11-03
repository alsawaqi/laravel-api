 FROM php:8.3.7-fpm

# Opcache config for production
COPY docker/prod/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Install system deps
RUN apt-get update && apt-get install -y \
    gnupg2 \
    curl \
    apt-transport-https \
    ca-certificates \
    gnupg \
    unixodbc-dev \
    lsb-release \
    libxml2-dev \
    libssl-dev \
    zip unzip git \
    build-essential autoconf \
 && apt-get upgrade -y \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Microsoft SQL Server ODBC repo
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc \
    | gpg --dearmor \
    | tee /usr/share/keyrings/microsoft.asc.gpg > /dev/null

RUN echo "deb [signed-by=/usr/share/keyrings/microsoft.asc.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
    > /etc/apt/sources.list.d/mssql-release.list

# Install the ODBC driver
RUN apt-get update && ACCEPT_EULA=Y apt-get install -y \
    msodbcsql18 \
    mssql-tools18

# PHP SQL Server extensions
RUN pecl install pdo_sqlsrv sqlsrv \
    && docker-php-ext-enable pdo_sqlsrv sqlsrv

# Core PHP extensions
RUN docker-php-ext-install pdo opcache

# Composer (inside image too, handy for artisan container reuse)
RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

# 🔥 Copy the ENTIRE Laravel codebase in
COPY src/ /var/www/html/

# Make sure runtime dirs exist + set perms during build
RUN mkdir -p storage/logs storage/framework/sessions storage/framework/views storage/framework/cache bootstrap/cache \
 && touch storage/logs/laravel.log \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Lightweight entrypoint (no crazy chown -R on startup)
COPY docker/prod/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
