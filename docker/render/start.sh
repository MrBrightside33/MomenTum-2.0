#!/bin/sh
echo "Starting container..."
cd /var/www

php artisan config:cache || echo "config:cache failed"
php artisan route:cache || echo "route:cache failed"
php artisan view:cache || echo "view:cache failed"

echo "Testing nginx config..."
nginx -t

echo "nginx listen lines:"
grep -R "listen" /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null

echo "Starting php-fpm..."
php-fpm -D

echo "Starting nginx..."
exec nginx -g "daemon off;"