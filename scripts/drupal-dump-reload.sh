#!/usr/bin/env bash
# Ricarica la copia del database Drupal di Sardegna Sentieri (oc:8705).
#
# Prende il dump più recente in storage/drupal-dump/ (in base alla data AAAA-MM-GG nel nome,
# .sql o .sql.gz), cancella e ricrea il database sardegnasentieri dentro il container
# mysql-sardegnasentieri-dump e ricrea l'utente readonly con il solo SELECT.
# Non tocca volumi Docker né altri container. Bash portabile: gira su macOS (bash 3.2) e Ubuntu.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUMP_DIR="${DRUPAL_DUMP_DIR:-$REPO_DIR/storage/drupal-dump}"
CONTAINER="mysql-sardegnasentieri-dump"
DATABASE="sardegnasentieri"
ROOT_PASSWORD="readonly"
READONLY_USER="readonly"
READONLY_PASSWORD="readonly"

fail() {
    echo "ERRORE: $*" >&2
    exit 1
}

# Esegue il client mysql come root dentro il container. MYSQL_PWD evita la password sulla riga
# di comando e il relativo warning.
mysql_root() {
    docker exec -i -e MYSQL_PWD="$ROOT_PASSWORD" "$CONTAINER" \
        mysql --default-character-set=utf8mb4 -uroot "$@"
}

# 1. Dump più recente, per data nel nome.
DUMP=""
LATEST=""
for file in "$DUMP_DIR"/*.sql "$DUMP_DIR"/*.sql.gz; do
    [ -e "$file" ] || continue
    name="$(basename "$file")"
    if [[ "$name" =~ ([0-9]{4}-[0-9]{2}-[0-9]{2})\.sql(\.gz)?$ ]]; then
        date_in_name="${BASH_REMATCH[1]}"
        if [ -z "$LATEST" ] || [[ "$date_in_name" > "$LATEST" ]]; then
            LATEST="$date_in_name"
            DUMP="$file"
        fi
    fi
done
[ -n "$DUMP" ] || fail "nessun dump in $DUMP_DIR: serve un file .sql o .sql.gz con la data AAAA-MM-GG nel nome"

# 2. Container acceso.
if [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null || true)" != "true" ]; then
    fail "il container $CONTAINER non è acceso: docker compose -f sardegnasentieri-dump.compose.yml up -d"
fi

echo "Carico $(basename "$DUMP") in $CONTAINER..."

# 3. Database ricreato da zero.
mysql_root -e "DROP DATABASE IF EXISTS \`$DATABASE\`; CREATE DATABASE \`$DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Caricamento. Con pipefail un errore di gzip interrompe lo script.
case "$DUMP" in
    *.gz) gzip -dc "$DUMP" | mysql_root "$DATABASE" ;;
    *) mysql_root "$DATABASE" < "$DUMP" ;;
esac

# 5. Utente in sola lettura, ricreato anche se esisteva con altri permessi.
mysql_root -e "DROP USER IF EXISTS '$READONLY_USER'@'%'; CREATE USER '$READONLY_USER'@'%' IDENTIFIED BY '$READONLY_PASSWORD'; GRANT SELECT ON \`$DATABASE\`.* TO '$READONLY_USER'@'%';"

# 6. Riepilogo.
TABLES="$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DATABASE'")"
LAST_CHANGED="$(mysql_root -N "$DATABASE" -e "SELECT FROM_UNIXTIME(MAX(changed)) FROM node_field_data")"
echo "Caricato: $(basename "$DUMP")"
echo "Tabelle: $TABLES"
echo "Ultima modifica dei nodi: $LAST_CHANGED"
