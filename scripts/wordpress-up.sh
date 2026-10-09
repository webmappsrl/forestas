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
if [ "$(docker inspect -f '{{.State.Running}}' "$SHARD" 2>/dev/null)" != "true" ]; then
    echo "Il container $SHARD non è avviato: avvia prima lo shard, poi rilancia questo script." >&2
    exit 1
fi

FILES=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.config_files"}}' "$SHARD")
if [ -z "$FILES" ]; then
    echo "Il container $SHARD non è stato avviato con docker compose: non so quali file usare." >&2
    exit 1
fi

ARGS=()
IFS=',' read -ra LISTA <<< "$FILES"
for f in "${LISTA[@]}"; do
    ARGS+=(-f "$f")
done

echo "File compose dello shard: ${FILES}"
docker compose "${ARGS[@]}" config --quiet
docker compose "${ARGS[@]}" up -d --build wordpress mariadb
echo "WordPress aggiornato: docker logs -f wordpress-${APP_NAME}"
