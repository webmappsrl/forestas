#!/bin/bash
# Aggiorna e riavvia solo i servizi WordPress dello shard (wordpress, mariadb), con gli stessi
# file compose con cui gira lo shard. I file li dice Docker, dalla label del container
# php-<APP_NAME>: nessuna variabile da configurare, vale in locale, su UAT e in produzione.
# Va lanciato sull'host, dalla root del repo o da qualsiasi cartella (oc:8711).
set -euo pipefail

cd "$(dirname "$0")/.."

# shellcheck source=wordpress-app-name.sh
source scripts/wordpress-app-name.sh

SHARD="php-${APP_NAME}"
# shellcheck source=compose-args.sh
source scripts/compose-args.sh

echo "File compose dello shard: ${FILES}"
docker compose "${ARGS[@]}" config --quiet
docker compose "${ARGS[@]}" build --pull wordpress
docker compose "${ARGS[@]}" up -d wordpress mariadb
echo "WordPress aggiornato: docker logs -f wordpress-${APP_NAME}"
