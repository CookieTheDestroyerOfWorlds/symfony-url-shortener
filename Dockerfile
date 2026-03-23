FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libzip-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql zip opcache

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy php.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

# Copy composer files first for layer caching
COPY composer.json composer.lock symfony.lock ./

# Install dependencies (no dev in prod — override via build arg if needed)
RUN composer install --no-interaction --no-dev --optimize-autoloader --no-scripts

# Copy app source
COPY . .

# Run post-install scripts after source is available
RUN composer run-script post-install-cmd --no-interaction 2>/dev/null || true

EXPOSE 9000
CMD ["php-fpm"]
