#!/bin/bash
set -e

echo "Prod deployment started ..."

php artisan down

git submodule update --init --recursive

composer install --no-interaction --prefer-dist --optimize-autoloader

php artisan optimize

php artisan migrate --force

# gracefully terminate laravel horizon
php artisan horizon:terminate

php artisan up

echo "Deployment finished!"
