FROM php:8.3.7-fpm

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Install dependencies
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
    zip \
    unzip \
    git \
    && apt-get upgrade -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Add Microsoft package signing key and repo (securely)
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc \
    | gpg --dearmor \
    | tee /usr/share/keyrings/microsoft.asc.gpg > /dev/null

RUN echo "deb [signed-by=/usr/share/keyrings/microsoft.asc.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
    > /etc/apt/sources.list.d/mssql-release.list

# Update & install the ODBC driver
RUN apt-get update && ACCEPT_EULA=Y apt-get install -y \
    msodbcsql18 \
    mssql-tools18

# Install SQLSRV PHP extensions
RUN pecl install pdo_sqlsrv sqlsrv \
    && docker-php-ext-enable pdo_sqlsrv sqlsrv

# Install default PHP extensions
RUN docker-php-ext-install pdo opcache

# Add at the bottom
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN chown -R www-data:www-data /var/www/html
 
WORKDIR /var/www/html

CMD ["php-fpm"]

