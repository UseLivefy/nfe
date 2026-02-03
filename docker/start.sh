#!/bin/bash

echo "Iniciando Livefy NFe API..."

# Criar diretórios necessários
mkdir -p /var/www/storage/framework/{sessions,views,cache}
mkdir -p /var/www/storage/logs
mkdir -p /var/www/bootstrap/cache

# Ajustar permissões
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Limpar cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Iniciar PHP-FPM em background
php-fpm -D

# Iniciar Nginx em foreground
nginx -g 'daemon off;'
