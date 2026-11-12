#!/bin/bash
# Deploy di dev e del collaudo, dentro il container PHP dello shard. Lo lanciano i
# workflow di GitHub Actions (deploy-dev.yml, deploy-uat.yml) dopo il `git pull`
# sull'host; si puo' lanciare anche a mano: docker exec php-<APP_NAME> bash scripts/deploy.sh
set -e

echo "Deployment started ..."

# Solo wm-package, e prima della manutenzione. Lo script gira nel container
# PHP come www-data: il submodule wp-forestas lo aggiorna l'host, la sua
# cartella in .git/modules e' di root, e un `--recursive` qui fallisce sul suo
# config. Con il fallimento dopo `php artisan down` il sito restava in
# manutenzione. wp-forestas si aggiorna dall'host con scripts/wordpress-up.sh.
git submodule update --init wm-package

# Enter maintenance mode or return true
# if already is in maintenance mode
(php artisan down) || true

# Install composer dependencies
composer install  --no-interaction --prefer-dist --optimize-autoloader

# Run database migrations. --force perche' il collaudo gira con APP_ENV=production,
# dove migrate senza --force si ferma a chiedere conferma.
php artisan migrate --force

php artisan optimize:clear
php artisan config:clear

# I worker di Horizon girano nel loro container con il codice vecchio in memoria:
# terminati, il supervisore li riavvia con quello nuovo.
php artisan horizon:terminate

# Exit maintenance mode
php artisan up

echo "Deployment finished!"
echo "Se il puntatore di wp-forestas e' cambiato, sull'host: git submodule update wp-forestas && scripts/wordpress-up.sh"
