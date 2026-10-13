#!/bin/sh
# Bring the database schema up to date, then hand over to PHP-FPM.
set -e
mkdir -p /data/photos
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
exec "$@"
