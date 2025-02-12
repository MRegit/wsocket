# Use PHP 7.4.3 Apache base image
FROM php:7.4.3-cli

# Configurar variable de entorno para permitir composer como superusuario
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /var/www/html

# Copy composer.json only first
COPY composer.json .

# Install dependencies
RUN composer install --no-scripts --no-autoloader --no-interaction

# Copy application files
COPY . .

# Ensure correct permissions
RUN chmod +x websocket_server.php

# Generate autoloader
RUN composer dump-autoload --optimize

# Expose WebSocket port
EXPOSE 8080

# Command to run the WebSocket server
CMD ["php", "/var/www/html/websocket_server.php"]