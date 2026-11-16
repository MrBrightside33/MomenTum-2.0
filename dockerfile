# Stage 1: build frontend assets
FROM node:20-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Stage 2: PHP app
FROM php:8.4-fpm

# System tools and PHP extensions (includes nginx and supervisor)
RUN apt-get update && apt-get install -y \
    git curl zip unzip libzip-dev libpng-dev libonig-dev libxml2-dev libpq-dev \
    nginx supervisor \
    && docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring zip gd bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy your project code
COPY . /var/www

# Copy the built frontend assets from Stage 1 (this fixes the Vite manifest error)
COPY --from=assets /app/public/build /var/www/public/build

# Install PHP packages
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Folder permissions for Laravel
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# nginx and supervisor config
COPY docker/render/nginx.conf /etc/nginx/sites-available/default
COPY docker/render/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/render/start.sh /start.sh
RUN sed -i '1s/^\xEF\xBB\xBF//' /start.sh /etc/supervisor/conf.d/supervisord.conf /etc/nginx/sites-available/default \
    && sed -i 's/\r$//' /start.sh /etc/supervisor/conf.d/supervisord.conf /etc/nginx/sites-available/default \
    && chmod +x /start.sh
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

EXPOSE 10000

CMD ["/start.sh"]