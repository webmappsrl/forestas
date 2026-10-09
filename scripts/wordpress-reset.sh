#!/bin/bash
# Azzera WordPress e lo ricrea da zero: cancella i soli volumi wordpress-<APP_NAME> e
# mariadb-<APP_NAME>, poi rilancia scripts/wordpress-up.sh. Gli altri volumi dello shard non
# vengono toccati.
# Cosa ha il sito ricreato e cosa si perde: docs/knowledge/wordpress-nello-shard.md, «Azzerare»
# (oc:8717). Su UAT si lancia a mano: il ciclo giornaliero non è ancora attivo. In produzione i dati
# di WordPress sono permanenti e questo script NON va usato (oc:8711).
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${1:-}" != "--conferma" ]; then
    echo "Cancella tutti i dati di WordPress (database e file). Per procedere:" >&2
    echo "  $0 --conferma" >&2
    exit 1
fi

# shellcheck source=wordpress-app-name.sh
source scripts/wordpress-app-name.sh

docker rm -f "wordpress-${APP_NAME}" "mariadb-${APP_NAME}" 2>/dev/null || true
for v in "wordpress-${APP_NAME}" "mariadb-${APP_NAME}"; do
    if docker volume inspect "$v" >/dev/null 2>&1; then
        docker volume rm "$v"
    fi
done

scripts/wordpress-up.sh
