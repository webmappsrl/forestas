> Ticket: oc:8706

# Sync della copia Drupal da produzione a UAT — piano di implementazione

> **Per chi esegue:** sotto-skill richiesta: `superpowers:executing-plans`. I passi usano le
> checkbox (`- [ ]`). **Nessun `git add`, `git commit`, `git push` né creazione di branch**: i
> blocchi «Commit» sono istruzioni testuali per il dev. Su UAT si esegue solo ciò che il Task 5
> elenca, dopo il via del dev.

**Obiettivo:** su UAT la copia del database Drupal si aggiorna ogni giorno da sola con il dump di
produzione di Acquia.

**Architettura:** `scripts/drupal-dump-download.sh` scarica da Acquia (solo `ls` e `scp` in
lettura) il dump più recente in `storage/drupal-dump/` ed esce con 0 (nuovo), 2 (niente di nuovo)
o 1 (errore). `scripts/drupal-dump-sync.sh` è il giro giornaliero: scrive l'intestazione nel log,
lancia il download e, solo con lo 0, la ricarica già esistente (`scripts/drupal-dump-reload.sh`).
Il cron di UAT chiama solo `drupal-dump-sync.sh` e ne manda l'output in
`storage/logs/drupal-dump-sync.log`.

**Stack:** bash (3.2 di macOS e 5 di Ubuntu), OpenSSH (`ssh`, `scp`), gzip, Docker Compose, cron.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Su Acquia solo lettura: l'unico comando remoto è `ls` su `prod/backups/`, più lo `scp` dal
  server alla macchina locale. Nessun `rm`, `mv` o altra scrittura remota.
- Bash portabile: niente `mapfile`, `declare -A`, `${var,,}`, `stat -f`/`stat -c`, `sed -i`,
  `date -d`/`date -j`.
- Codici di uscita del download: 0 dump nuovo, 2 nessun dump nuovo, 1 errore.
- Si tengono gli ultimi 2 dump per data nel nome, solo nella cartella locale.
- Cron di UAT alle 10:00 UTC; log in `storage/logs/drupal-dump-sync.log`.
- Niente test Pest: verifiche con comandi e output attesi.
- Documentazione in italiano, termini tecnici in inglese.

## Review focus

1. **Download interrotto** — non resta un `.sql.gz` che la ricarica potrebbe prendere: il file
   temporaneo ha il nome che inizia con un punto, e viene cancellato all'uscita. Task 2, passo 5.
2. **File in cartella senza data nel nome** (es. un dump rinominato a mano) — la pulizia non lo
   tocca. Task 2, passo 6.
3. **Connessione ad Acquia che fallisce** — uscita 1 con un messaggio che nomina l'host, senza
   toccare la cartella. Task 2, passo 4.
4. **Giro senza dump nuovo** — il sync esce con 0, scrive «ricarica saltata» e non tocca il
   database. Task 3, passo 3.
5. **Ambiente minimo di cron su UAT** — `docker` e `ssh` trovati con il `PATH` di cron. Task 5,
   passo 8.

---

### Task 1: riavvio automatico della copia

**File:**
- Modifica: `sardegnasentieri-dump.compose.yml`

- [ ] **Passo 1: aggiungere `restart: unless-stopped`** al servizio, subito dopo `container_name`:

```yaml
    container_name: "mysql-sardegnasentieri-dump"
    restart: unless-stopped
```

- [ ] **Passo 2: verificare in locale**

```bash
docker compose -f sardegnasentieri-dump.compose.yml up -d
docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' mysql-sardegnasentieri-dump
docker port mysql-sardegnasentieri-dump
```

Atteso: `unless-stopped`; porta solo `127.0.0.1:3307`. Il volume resta, la copia caricata pure
(`SELECT COUNT(*)` sulle tabelle → 972).

- [ ] **Passo 3: commit (istruzione per il dev)**

```bash
git add sardegnasentieri-dump.compose.yml
git commit -m "feat(oc:8706): la copia Drupal riparte da sola dopo un riavvio"
```

---

### Task 2: script di download

**File:**
- Crea: `scripts/drupal-dump-download.sh` (eseguibile)

**Interfacce:**
- Produce: `scripts/drupal-dump-download.sh`, variabili `DRUPAL_DUMP_DIR` (default
  `storage/drupal-dump` del repo) e `DRUPAL_DUMP_SSH_HOST` (default `prod.sardegnasentieri`);
  uscita 0 / 2 / 1.

- [ ] **Passo 1: scrivere lo script**

```bash
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
```

```bash
chmod +x scripts/drupal-dump-download.sh
```

- [ ] **Passo 2: sintassi con bash 3.2 e bash 5**

```bash
/bin/bash -n scripts/drupal-dump-download.sh && echo OK-3.2
docker run --rm -v "$PWD/scripts:/s:ro" bash:5 bash -n /s/drupal-dump-download.sh; echo "bash5 exit=$?"
```

Atteso: `OK-3.2` e `bash5 exit=0`.

- [ ] **Passo 3: solo lettura su Acquia, controllo a macchina**

```bash
grep -n -E '\b(ssh|scp)\b' scripts/drupal-dump-download.sh
```

Atteso: un solo `ssh` con comando `ls -1 $REMOTE_DIR/` e un solo `scp` con sorgente
`$SSH_HOST:...` e destinazione locale. Nessun `rm`/`mv` dentro una stringa passata a `ssh`.

- [ ] **Passo 4: connessione che fallisce**

```bash
T="$(mktemp -d)"; DRUPAL_DUMP_DIR="$T" DRUPAL_DUMP_SSH_HOST=host-inesistente.invalid scripts/drupal-dump-download.sh; echo "exit=$?"; ls -A "$T"; rm -r "$T"
```

Atteso: `ERRORE: impossibile leggere prod/backups/ su host-inesistente.invalid`, `exit=1`,
cartella vuota.

- [ ] **Passo 5: nessun dump nuovo (cartella reale del Mac, che ha già il 05/10)**

```bash
scripts/drupal-dump-download.sh; echo "exit=$?"
```

Atteso, se su Acquia il più recente è ancora del 05/10: `Nessun dump nuovo: prod-…-2026-10-05.sql
è già presente`, `exit=2`. Se nel frattempo c'è quello del giorno dopo, si passa al passo 6 con la
cartella reale.

- [ ] **Passo 6: download reale e pulizia, in una cartella di prova**

```bash
T="$(mktemp -d)"
touch "$T/prod-sardegnasentieri-sardegnasentieriuyyrag83f9-2026-09-01.sql.gz" \
      "$T/prod-sardegnasentieri-sardegnasentieriuyyrag83f9-2026-09-02.sql" \
      "$T/prod-sardegnasentieri-sardegnasentieriuyyrag83f9-2026-09-03.sql.gz" \
      "$T/appunti.sql.gz"
DRUPAL_DUMP_DIR="$T" scripts/drupal-dump-download.sh; echo "exit=$?"
ls -A "$T"
```

Atteso: `Scaricato: prod-…-<data più recente>.sql.gz`, rimossi i file del 01/09 e del 02/09,
`exit=0`. Restano il dump scaricato, quello del 03/09 e `appunti.sql.gz` (senza data, non
toccato). Nessun file `.…part`. Poi `gzip -t` sul dump scaricato → nessun errore, e
`rm -r "$T"`.

- [ ] **Passo 7: commit (istruzione per il dev)**

```bash
git add scripts/drupal-dump-download.sh
git commit -m "feat(oc:8706): script di download del dump Drupal da Acquia in sola lettura"
```

---

### Task 3: giro giornaliero

**File:**
- Crea: `scripts/drupal-dump-sync.sh` (eseguibile)

**Interfacce:**
- Consuma: `scripts/drupal-dump-download.sh` (uscita 0/2/1) e `scripts/drupal-dump-reload.sh`.
- Produce: `scripts/drupal-dump-sync.sh`, chiamato dal cron di UAT.

- [ ] **Passo 1: scrivere lo script**

```bash
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
```

```bash
chmod +x scripts/drupal-dump-sync.sh
```

- [ ] **Passo 2: sintassi con bash 3.2 e bash 5**

```bash
/bin/bash -n scripts/drupal-dump-sync.sh && echo OK-3.2
docker run --rm -v "$PWD/scripts:/s:ro" bash:5 bash -n /s/drupal-dump-sync.sh; echo "bash5 exit=$?"
```

- [ ] **Passo 3: giro senza dump nuovo, in locale**

```bash
scripts/drupal-dump-sync.sh; echo "exit=$?"
```

Atteso (se su Acquia il più recente è quello già presente): intestazione `=== … UTC — inizio`,
`Nessun dump nuovo: …`, `Ricarica saltata: nessun dump nuovo`, `=== … — fine (uscita 0)`,
`exit=0`. Il database non viene toccato.

- [ ] **Passo 4: giro con download fallito**

```bash
DRUPAL_DUMP_SSH_HOST=host-inesistente.invalid scripts/drupal-dump-sync.sh; echo "exit=$?"
```

Atteso: `ERRORE: impossibile leggere …`, `Ricarica saltata: download fallito (uscita 1)`,
`exit=1`.

- [ ] **Passo 5: commit (istruzione per il dev)**

```bash
git add scripts/drupal-dump-sync.sh
git commit -m "feat(oc:8706): giro giornaliero di download e ricarica della copia Drupal"
```

---

### Task 4: documentazione

**File:**
- Modifica: `docs/howto/copia-database-drupal.md`
- Modifica: `docs/knowledge/copia-database-drupal.md`

- [ ] **Passo 1: howto, nuova sezione «Scaricare il dump da produzione»** dopo «Caricare o
      aggiornare la copia»: comando `scripts/drupal-dump-download.sh`; chi può lanciarlo (solo chi
      ha una chiave autorizzata su Acquia e l'host `prod.sardegnasentieri` nel `~/.ssh/config`);
      sul server solo `ls` e `scp`; codici di uscita 0/2/1; ultimi 2 dump; i dump su Acquia
      restano 3 giorni, generati verso le 08:14 UTC.

- [ ] **Passo 2: howto, nuova sezione «La copia su UAT»**: il cron (riga esatta del Task 5), il
      log `storage/logs/drupal-dump-sync.log` con `tail -n 30`, la chiave dedicata
      `/root/.ssh/id_rsa_acquia_uat` (revocabile dalla console Acquia, voce
      `id_rsa_acquia_to_forestas_uat`), la regola del firewall Hetzner in uscita TCP 22 verso
      `18.201.110.246`, da aggiornare se il download va in timeout perché Acquia ha cambiato IP.
      Nella sezione «Raggiungere la copia di UAT dal locale» togliere «valido da quando il ticket
      di sync…»: la copia su UAT esiste.

- [ ] **Passo 3: pagina di conoscenza**, in «Dove sta»: su UAT la copia si aggiorna ogni giorno
      alle 10:00 UTC. In «Perché così», con (oc:8706): download e ricarica separati, e ricarica
      solo con un dump nuovo; file temporaneo nascosto e `gzip -t` prima del nome definitivo;
      ultimi 2 dump; chiave dedicata a UAT perché Acquia non limita una chiave alla lettura.

- [ ] **Passo 4: commit (istruzione per il dev)**

```bash
git add docs/howto/copia-database-drupal.md docs/knowledge/copia-database-drupal.md
git commit -m "docs(oc:8706): download da produzione e copia su UAT"
```

---

### Task 5: messa in opera su UAT

**Si esegue dopo che il codice è in `main` e UAT è aggiornato** (squash merge in `develop`,
`develop` in `main`, aggiornamento di UAT come al solito). Ogni comando è una scrittura su UAT:
si lancia solo con il via del dev.

- [ ] **Passo 1: verifiche preliminari, in lettura**

```bash
ssh uat.forestas 'cd /var/www/html/forestas && git log --oneline -1 && ls scripts/drupal-dump-*.sh && ss -ltn | grep -c ":3307 " ; which docker ssh scp gzip'
```

Atteso: commit che contiene oc:8706; i tre script; `0` (porta 3307 libera); i quattro comandi in
`/usr/bin`.

- [ ] **Passo 2: host Acquia nel `~/.ssh/config` di root** (copia di sicurezza prima)

```bash
ssh uat.forestas 'cp /root/.ssh/config /root/.ssh/config.bak-oc8706 && cat >> /root/.ssh/config <<EOF

# Dump Drupal di produzione (oc:8706), solo lettura
Host prod.sardegnasentieri
    HostName sardegnasentieriuyyrag83f9.ssh.devcloud.acquia-sites.com
    User sardegnasentieri.prod
    IdentityFile /root/.ssh/id_rsa_acquia_uat
    IdentitiesOnly yes
EOF
ssh -o BatchMode=yes prod.sardegnasentieri "ls -1 prod/backups/"'
```

Atteso: i tre nomi dei dump.

- [ ] **Passo 3: cartella dei dump**

```bash
ssh uat.forestas 'mkdir -p /var/www/html/forestas/storage/drupal-dump'
```

- [ ] **Passo 4: accendere la copia**

```bash
ssh uat.forestas 'cd /var/www/html/forestas && docker compose -f sardegnasentieri-dump.compose.yml up -d && docker port mysql-sardegnasentieri-dump && docker inspect -f "{{range \$k,\$v := .NetworkSettings.Networks}}{{\$k}} {{end}}{{.HostConfig.RestartPolicy.Name}}" mysql-sardegnasentieri-dump'
```

Atteso: solo `127.0.0.1:3307`; rete `forestas_default`; `unless-stopped`.

- [ ] **Passo 5: primo giro a mano**

```bash
ssh uat.forestas 'cd /var/www/html/forestas && until docker exec -e MYSQL_PWD=readonly mysql-sardegnasentieri-dump mysqladmin -uroot ping >/dev/null 2>&1; do sleep 3; done; scripts/drupal-dump-sync.sh >> storage/logs/drupal-dump-sync.log 2>&1; echo "exit=$?"; tail -n 12 storage/logs/drupal-dump-sync.log'
```

Atteso: `Scaricato: prod-…`, `Tabelle: 972` (circa), `Ultima modifica dei nodi: …`, `fine
(uscita 0)`, `exit=0`.

- [ ] **Passo 6: Forestas di UAT legge la copia**

```bash
ssh uat.forestas 'docker exec php-forestasuat php artisan tinker --execute="var_dump(DB::connection(\"sardegnasentieri\")->select(\"select count(*) as n from node_field_data\"));"'
```

Atteso: un conteggio maggiore di zero.

- [ ] **Passo 7: riga nel crontab di root** (copia di sicurezza prima)

```bash
ssh uat.forestas 'crontab -l > /root/crontab.bak-oc8706 && (crontab -l; echo "0 10 * * * cd /var/www/html/forestas && scripts/drupal-dump-sync.sh >> storage/logs/drupal-dump-sync.log 2>&1") | crontab - && crontab -l'
```

Atteso: le tre righe di prima più quella nuova.

- [ ] **Passo 8: la riga del cron con l'ambiente minimo di cron**

```bash
ssh uat.forestas 'env -i HOME=/root PATH=/usr/bin:/bin SHELL=/bin/sh /bin/sh -c "cd /var/www/html/forestas && scripts/drupal-dump-sync.sh >> storage/logs/drupal-dump-sync.log 2>&1"; echo "exit=$?"; tail -n 5 /var/www/html/forestas/storage/logs/drupal-dump-sync.log'
```

Atteso: `Ricarica saltata: nessun dump nuovo`, `fine (uscita 0)`, `exit=0`.

- [ ] **Passo 9: il giorno dopo**, dopo le 10:00 UTC, controllare `tail -n 15` del log: deve esserci
      il giro con `Scaricato: …-<data di oggi>.sql.gz` e la ricarica.
