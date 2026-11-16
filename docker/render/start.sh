#!/bin/sh
echo "Starting container..."
cd /var/www

php artisan config:cache || echo "config:cache failed"
php artisan route:cache || echo "route:cache failed"
php artisan view:cache || echo "view:cache failed"

echo "Testing nginx config..."
nginx -t

echo "Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf