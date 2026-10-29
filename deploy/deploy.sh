#!/bin/bash
# DomainDash deploy script (Ubuntu). Run from the app directory as root:
#   sudo bash deploy/deploy.sh
#
# Code stays root-owned (required by the Platform HTTPS helper, which runs as root);
# storage/ is handed back to the web user at the end so php-fpm can write logs,
# compiled views, sessions and cache. Override with WEB_USER / PHP_FPM_SERVICE.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_USER="${WEB_USER:-www-data}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"

[ "$(id -u)" -eq 0 ] || { echo "Run as root: sudo bash deploy/deploy.sh" >&2; exit 1; }
cd "$APP_DIR"

echo "==> Pulling latest code"
git pull --ff-only

echo "==> Installing PHP dependencies"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Building front-end assets"
npm ci
npm run build
rm -f public/hot

echo "==> Migrating and caching"
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

echo "==> Fixing storage ownership"
chown -R "$WEB_USER":"$WEB_USER" storage
chmod -R ug+rwX storage
sudo -u "$WEB_USER" php artisan view:cache

echo "==> Restarting workers and php-fpm"
sudo -u "$WEB_USER" php artisan queue:restart
systemctl reload "$PHP_FPM_SERVICE"

echo "==> Done"
