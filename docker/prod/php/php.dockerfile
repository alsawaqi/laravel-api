FROM php:8.3-fpm

# Required tools
RUN apt-get update && apt-get install -y \
    gnupg2 \
    curl \
    apt-transport-https \
    ca-certificates \
    unixodbc-dev \
    lsb-release \
    gnupg \
    libxml2-dev \
    libssl-dev \
    zip unzip git

# Add Microsoft repo & key
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor > /usr/share/keyrings/microsoft.asc.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/microsoft.asc.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list

RUN apt-get update && ACCEPT_EULA=Y apt-get install -y msodbcsql18 mssql-tools18

# Install extensions
RUN pecl install pdo_sqlsrv sqlsrv \
 && docker-php-ext-enable pdo_sqlsrv sqlsrv \
 && docker-php-ext-install pdo

WORKDIR /var/www/html
CMD ["php-fpm"]
