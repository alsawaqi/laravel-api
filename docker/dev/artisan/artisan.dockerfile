FROM php:8.3-cli-alpine

WORKDIR /var/www/html

CMD ["php", "artisan"]
