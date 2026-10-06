#!/usr/bin/env bash
# Giro giornaliero della copia Drupal (oc:8706): scarica il dump da Acquia e, solo se è nuovo,
# ricarica la copia. Pensato per il cron di UAT, con l'output in storage/logs/drupal-dump-sync.log.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
echo "=== $(date -u '+%Y-%m-%d %H:%M:%S') UTC — inizio"

rc=0
scripts/drupal-dump-download.sh || rc=$?

case "$rc" in
    0) scripts/drupal-dump-reload.sh; rc=$? ;;
    2) echo "Ricarica saltata: nessun dump nuovo"; rc=0 ;;
    *) echo "Ricarica saltata: download fallito (uscita $rc)" ;;
esac

echo "=== $(date -u '+%Y-%m-%d %H:%M:%S') UTC — fine (uscita $rc)"
exit "$rc"
