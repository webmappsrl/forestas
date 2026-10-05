#!/usr/bin/env bash
# Scarica da Acquia il dump più recente del database Drupal di Sardegna Sentieri (oc:8706).
#
# Sul server esegue solo letture: `ls` su prod/backups/ e `scp` del file verso questa macchina.
# Il dump arriva in storage/drupal-dump/; si tengono gli ultimi 2 per data nel nome.
# Uscita: 0 = dump nuovo scaricato, 2 = nessun dump nuovo, 1 = errore.
# Lo può lanciare solo chi ha una chiave autorizzata su Acquia (oggi il dev e UAT).
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUMP_DIR="${DRUPAL_DUMP_DIR:-$REPO_DIR/storage/drupal-dump}"
SSH_HOST="${DRUPAL_DUMP_SSH_HOST:-prod.sardegnasentieri}"
REMOTE_DIR="prod/backups"
KEEP=2
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=30)
NAME_RE='^prod-sardegnasentieri-[a-z0-9]+-([0-9]{4}-[0-9]{2}-[0-9]{2})\.sql\.gz$'
LOCAL_RE='-([0-9]{4}-[0-9]{2}-[0-9]{2})\.sql(\.gz)?$'

fail() {
    echo "ERRORE: $*" >&2
    exit 1
}

mkdir -p "$DUMP_DIR"

# 1. Elenco dei dump sul server (sola lettura).
LISTING="$(ssh "${SSH_OPTS[@]}" "$SSH_HOST" "ls -1 $REMOTE_DIR/")" \
    || fail "impossibile leggere $REMOTE_DIR/ su $SSH_HOST"

# 2. Il più recente per data nel nome.
LATEST_NAME=""
LATEST_DATE=""
while IFS= read -r name; do
    if [[ "$name" =~ $NAME_RE ]]; then
        if [ -z "$LATEST_DATE" ] || [[ "${BASH_REMATCH[1]}" > "$LATEST_DATE" ]]; then
            LATEST_DATE="${BASH_REMATCH[1]}"
            LATEST_NAME="$name"
        fi
    fi
done <<< "$LISTING"
[ -n "$LATEST_NAME" ] || fail "nessun dump in $REMOTE_DIR/ su $SSH_HOST"

# 3. Già presente in locale, in .sql o .sql.gz?
for existing in "$DUMP_DIR"/*-"$LATEST_DATE".sql "$DUMP_DIR"/*-"$LATEST_DATE".sql.gz; do
    if [ -e "$existing" ]; then
        echo "Nessun dump nuovo: $(basename "$existing") è già presente"
        exit 2
    fi
done

# 4. Download in un file temporaneo nascosto, controllo, poi nome definitivo.
TMP="$DUMP_DIR/.$LATEST_NAME.part"
trap 'rm -f "$TMP"' EXIT
echo "Scarico $LATEST_NAME da $SSH_HOST..."
scp -q "${SSH_OPTS[@]}" "$SSH_HOST:$REMOTE_DIR/$LATEST_NAME" "$TMP" \
    || fail "download di $LATEST_NAME non riuscito"
gzip -t "$TMP" || fail "$LATEST_NAME scaricato ma corrotto"
mv "$TMP" "$DUMP_DIR/$LATEST_NAME"
trap - EXIT
echo "Scaricato: $LATEST_NAME"

# 5. Pulizia locale: restano i dump delle ultime $KEEP date.
DATES=""
for file in "$DUMP_DIR"/*.sql "$DUMP_DIR"/*.sql.gz; do
    [ -e "$file" ] || continue
    if [[ "$(basename "$file")" =~ $LOCAL_RE ]]; then
        DATES="$DATES${BASH_REMATCH[1]}"$'\n'
    fi
done
KEEP_DATES=" $(printf '%s' "$DATES" | sort -u -r | head -n "$KEEP" | tr '\n' ' ')"
for file in "$DUMP_DIR"/*.sql "$DUMP_DIR"/*.sql.gz; do
    [ -e "$file" ] || continue
    if [[ "$(basename "$file")" =~ $LOCAL_RE ]] && [[ "$KEEP_DATES" != *" ${BASH_REMATCH[1]} "* ]]; then
        rm -f "$file"
        echo "Rimosso il dump vecchio: $(basename "$file")"
    fi
done
exit 0
