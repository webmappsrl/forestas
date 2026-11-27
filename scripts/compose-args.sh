#!/bin/bash
# Da includere con `source`, con SHARD valorizzato (es. php-forestasuat). Legge dalla label del
# container i file compose con cui gira lo shard e valorizza FILES (lista separata da virgole) e
# ARGS (array di `-f <file>` da passare a `docker compose`). Se il container non c'e' o non e'
# stato avviato con docker compose termina con errore: non ripiega mai su compose.yml (oc:8711).

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
