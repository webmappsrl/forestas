#!/bin/bash
# Azzera WordPress e lo ricrea da zero: cancella i soli volumi wordpress-<APP_NAME> e
# mariadb-<APP_NAME>, poi rilancia scripts/wordpress-up.sh. Gli altri volumi dello shard non
# vengono toccati.
# Su UAT WordPress si ricrea ogni giorno; in produzione i suoi dati sono permanenti e questo
# script NON va usato (oc:8711).
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${1:-}" != "--conferma" ]; then
    echo "Cancella tutti i dati di WordPress (database e file). Per procedere:" >&2
    echo "  $0 --conferma" >&2
    exit 1
fi

APP_NAME=$(grep -E '^APP_NAME=' .env | head -1 | cut -d= -f2- | tr -d '"'"'")
if [ -z "$APP_NAME" ]; then
    echo "APP_NAME non trovato nel .env di forestas" >&2
    exit 1
fi

docker rm -f "wordpress-${APP_NAME}" "mariadb-${APP_NAME}" 2>/dev/null || true
for v in "wordpress-${APP_NAME}" "mariadb-${APP_NAME}"; do
    if docker volume inspect "$v" >/dev/null 2>&1; then
        docker volume rm "$v"
    fi
done

scripts/wordpress-up.sh
