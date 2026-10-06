> Ticket: oc:8705

# Copia del database Drupal di Sardegna Sentieri — piano di implementazione

> **Per chi esegue:** sotto-skill richiesta: `superpowers:executing-plans` (o
> `superpowers:subagent-driven-development`). I passi usano le checkbox (`- [ ]`).
> **Nessun `git add`, `git commit`, `git push` né creazione di branch**: i blocchi «Commit» sono
> istruzioni testuali per il dev, che committa lui dopo la review.

**Obiettivo:** rendere la copia locale del database Drupal di Sardegna Sentieri ricaricabile con
uno script, davvero in sola lettura, non raggiungibile dalla rete, e documentata in una pagina di
conoscenza che permetta di scrivere le query dei prossimi import.

**Architettura:** il container MySQL 8 già esistente (`sardegnasentieri-dump.compose.yml`) perde
il caricamento automatico all'avvio: il dump si carica solo con `scripts/drupal-dump-reload.sh`,
che lavora come root dentro il container con `docker exec`, ricrea il database e l'utente
`readonly` con il solo `SELECT`. Laravel raggiunge la copia attraverso la rete Docker; la porta
verso l'host è legata al loopback. La conoscenza sullo schema va in una pagina di
`docs/knowledge/`, indicizzata dal `CLAUDE.md`.

**Stack:** Docker Compose, MySQL 8.0, bash (compatibile con la 3.2 di macOS e con la 5 di
Ubuntu), Laravel 12 (`config/database.php`).

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Compose, connessione e script funzionano identici su macOS (Docker Desktop) e su Ubuntu (UAT).
- Lo script usa solo bash portabile: niente `mapfile`, `declare -A`, `${var,,}` (assenti in bash
  3.2 di macOS), niente `stat -f`/`stat -c`, `sed -i`, `date -d`/`date -j`.
- Porta solo su loopback: `127.0.0.1:${DOCKER_DRUPAL_DUMP_PORT:-3307}:3306`.
- `readonly` ha solo `SELECT` su `sardegnasentieri.*`.
- Le password di `root` e `readonly` restano `readonly`, nel compose (decisione dell'overview,
  rischio «Copia raggiungibile dalla rete»).
- Niente test Pest: la feature è infrastruttura e documentazione. Le verifiche sono comandi
  `docker`/`mysql` con l'output atteso. Non lanciare `vendor/bin/pest`.
- La copia locale è sacrificabile: si può cancellare e ricaricare senza chiedere.
- Documentazione in italiano, termini tecnici in inglese; nessun dato personale (righe di
  `users*`, `webform_submission*`, email, nomi) nei file versionati; nessun conteggio nella
  pagina di conoscenza.

## Review focus

1. **Dump che finisce in `.sql` e in `.sql.gz` con la stessa data** — lo script ne carica uno
   solo, senza errori. Coperto nel Task 2, passo 4.
2. **Cartella senza dump, o con file dal nome senza data** — lo script si ferma con un messaggio
   che dice cosa manca, senza toccare il database. Task 2, passo 3.
3. **Container spento** — lo script si ferma prima di cancellare qualsiasi cosa e dice come
   accenderlo. Task 2, passo 3.
4. **Volume già esistente con il vecchio `readonly` a `ALL PRIVILEGES`** — dopo la ricarica i
   permessi sono solo `SELECT`, anche se l'utente esisteva già. Task 2, passo 5.
5. **Lettere accentate** — un titolo con accenti esce identico da shell e da Laravel. Task 2,
   passo 6.

---

### Task 1: compose e connessione Laravel

**File:**
- Modifica: `sardegnasentieri-dump.compose.yml`
- Modifica: `config/database.php:100-111`
- Elimina: `docker/sardegnasentieri-init/01-readonly.sh` (e la cartella, che resta vuota)

**Interfacce:**
- Produce: container `mysql-sardegnasentieri-dump` con `/drupal-dump` montato in sola lettura,
  binlog spento, porta su `127.0.0.1`; connessione Laravel `sardegnasentieri` verso
  `mysql-sardegnasentieri-dump:3306`.

- [ ] **Passo 1: riscrivere `sardegnasentieri-dump.compose.yml`**

```yaml
# Copia del database Drupal di Sardegna Sentieri (oc:8705).
# Il dump NON si carica all'avvio: si carica con scripts/drupal-dump-reload.sh, che crea anche
# l'utente readonly con il solo SELECT. La porta è legata al loopback: la copia non è
# raggiungibile dalla rete, solo dall'host o da un tunnel SSH.
services:
  db-sardegnasentieri:
    image: mysql:8.0
    container_name: "mysql-sardegnasentieri-dump"
    command: ["--skip-log-bin"]
    environment:
      MYSQL_ROOT_PASSWORD: readonly
      MYSQL_DATABASE: sardegnasentieri
    volumes:
      - "./storage/drupal-dump:/drupal-dump:ro"
      - "sardegnasentieri-pgdata:/var/lib/mysql"
    ports:
      - "127.0.0.1:${DOCKER_DRUPAL_DUMP_PORT:-3307}:3306"

volumes:
  sardegnasentieri-pgdata:
```

Il nome del volume resta `sardegnasentieri-pgdata`: rinominarlo costringerebbe a ricaricare la
copia senza alcun beneficio.

- [ ] **Passo 2: aggiornare i default della connessione in `config/database.php`**

```php
        'sardegnasentieri' => [
            'driver' => 'mysql',
            // Copia del DB Drupal (oc:8705): si raggiunge per nome sulla rete Docker,
            // host.docker.internal su Linux non esiste.
            'host' => env('DB_SARDEGNASENTIERI_HOST', 'mysql-sardegnasentieri-dump'),
            'port' => env('DB_SARDEGNASENTIERI_PORT', '3306'),
```

Il resto del blocco resta invariato.

- [ ] **Passo 3: eliminare lo script PostgreSQL mai montato**

```bash
rm docker/sardegnasentieri-init/01-readonly.sh
rmdir docker/sardegnasentieri-init
```

- [ ] **Passo 4: ricreare il container (il volume resta)**

```bash
docker compose -f sardegnasentieri-dump.compose.yml up -d
```

Atteso: `Container mysql-sardegnasentieri-dump Recreated` e poi `Started`. Nessun volume rimosso.

- [ ] **Passo 5: verificare porta, binlog e mount**

```bash
docker port mysql-sardegnasentieri-dump
docker exec -e MYSQL_PWD=readonly mysql-sardegnasentieri-dump mysql -uroot -N -e "SELECT @@log_bin"
docker exec mysql-sardegnasentieri-dump ls /drupal-dump
```

Atteso: solo `3306/tcp -> 127.0.0.1:3307` (nessun `0.0.0.0`, nessun `[::]`); `0`; il file
`prod-sardegnasentieri-…-2026-10-05.sql`.

- [ ] **Passo 6: verificare la connessione Laravel per nome**

```bash
docker exec php-forestas php artisan tinker --execute="var_dump(DB::connection('sardegnasentieri')->select('select 1 as x'));"
```

Atteso: `["x"]=> int(1)`. Se fallisce con «getaddrinfo» il container non è sulla rete
`forestas_default`: controllare con
`docker inspect mysql-sardegnasentieri-dump --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'`.

- [ ] **Passo 7: commit (istruzione per il dev)**

```bash
git add sardegnasentieri-dump.compose.yml config/database.php docker/sardegnasentieri-init
git commit -m "feat(oc:8705): copia Drupal su loopback, senza binlog né caricamento automatico"
```

---

### Task 2: script di ricarica

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-verifica-degli-accenti)

**File:**
- Crea: `scripts/drupal-dump-reload.sh` (eseguibile)

**Interfacce:**
- Consuma: container e mount del Task 1.
- Produce: comando `scripts/drupal-dump-reload.sh`, che accetta la variabile d'ambiente
  `DRUPAL_DUMP_DIR` (default `storage/drupal-dump` del repo) per poterlo provare su una cartella
  diversa.

- [ ] **Passo 1: scrivere lo script**

```bash
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
```

```bash
chmod +x scripts/drupal-dump-reload.sh
```

- [ ] **Passo 2: controllo di sintassi con bash 3.2 (macOS) e bash 5 (Linux)**

```bash
/bin/bash -n scripts/drupal-dump-reload.sh && echo OK-3.2
docker run --rm -v "$PWD/scripts:/s:ro" bash:5 bash -n /s/drupal-dump-reload.sh && echo OK-5
```

Atteso: `OK-3.2` e `OK-5`.

- [ ] **Passo 3: casi di arresto, senza toccare il database**

```bash
EMPTY="$(mktemp -d)"; touch "$EMPTY/senza-data.sql"
DRUPAL_DUMP_DIR="$EMPTY" scripts/drupal-dump-reload.sh; echo "exit=$?"
docker stop mysql-sardegnasentieri-dump
scripts/drupal-dump-reload.sh; echo "exit=$?"
docker start mysql-sardegnasentieri-dump
rm -r "$EMPTY"
```

Atteso: primo caso `ERRORE: nessun dump in …` ed `exit=1`; secondo caso `ERRORE: il container
mysql-sardegnasentieri-dump non è acceso: …` ed `exit=1`. Dopo il `docker start`, aspettare che
MySQL risponda (`docker exec -e MYSQL_PWD=readonly mysql-sardegnasentieri-dump mysqladmin -uroot ping`
→ `mysqld is alive`).

- [ ] **Passo 4: scelta del dump con stessa data in `.sql` e `.sql.gz`**

```bash
cp ~/Downloads/prod-sardegnasentieri-sardegnasentieriuyyrag83f9-2026-10-05.sql.gz storage/drupal-dump/
ls storage/drupal-dump
```

Il passo 5 deve caricarne uno solo. Dopo il passo 5 togliere il `.gz` dalla cartella: il `.sql`
basta.

- [ ] **Passo 5: ricarica reale con il dump del 05/10 e permessi**

```bash
time scripts/drupal-dump-reload.sh
docker exec -e MYSQL_PWD=readonly mysql-sardegnasentieri-dump mysql -ureadonly -N -e "SHOW GRANTS"
docker exec -e MYSQL_PWD=readonly mysql-sardegnasentieri-dump mysql -ureadonly sardegnasentieri -e "CREATE TABLE prova_scrittura (id INT)"; echo "exit=$?"
rm storage/drupal-dump/prod-sardegnasentieri-sardegnasentieriuyyrag83f9-2026-10-05.sql.gz
```

Atteso: lo script stampa `Caricato: prod-…-2026-10-05.sql…`, `Tabelle: 972`,
`Ultima modifica dei nodi: 2026-10-04 10:00:01`; `SHOW GRANTS` riporta solo `GRANT USAGE ON *.*` e
`GRANT SELECT ON sardegnasentieri.*`; il `CREATE TABLE` fallisce con `ERROR 1142` ed `exit=1`.

- [ ] **Passo 6: lettura da Laravel con accenti**

```bash
docker exec php-forestas php artisan tinker --execute="echo DB::connection('sardegnasentieri')->table('node_field_data')->where('type','sentiero')->where('langcode','it')->where('title','like','%à%')->value('title');"
docker exec -e MYSQL_PWD=readonly mysql-sardegnasentieri-dump mysql --default-character-set=utf8mb4 -ureadonly -N sardegnasentieri -e "SELECT title FROM node_field_data WHERE type='sentiero' AND langcode='it' AND title LIKE '%à%' LIMIT 1"
```

Atteso: lo stesso titolo, con la `à` leggibile, nei due output.

- [ ] **Passo 7: commit (istruzione per il dev)**

```bash
git add scripts/drupal-dump-reload.sh
git commit -m "feat(oc:8705): script di ricarica della copia Drupal con utente in sola lettura"
```

---

### Task 3: pagina di conoscenza sulla copia Drupal

**File:**
- Crea: `docs/knowledge/copia-database-drupal.md`

**Interfacce:**
- Consuma: script e connessione dei Task 1-2; il report di studio
  `/private/tmp/claude-501/-Users-bongiu-Documents-geobox2-forestas/6530a32e-83dd-4702-8d0e-9369701ba6d0/scratchpad/schema-drupal.md`
  (sezioni A-J); il codice Drupal al tag `2024-04-10-bis` in `scratchpad/drupal-code`; i DTO
  `app/Dto/Api/ApiTrackResponse.php`, `app/Dto/Api/ApiPoiResponse.php` e il client
  `app/Http/Clients/SardegnaSentieriClient.php`.
- Produce: la pagina indicizzata dal Task 4.

Il report è materiale di lavoro, non da copiare: ogni affermazione strutturale che entra nella
pagina va riverificata sulla copia appena caricata o sul codice. **Niente conteggi** e nessuna
riga di dati personali.

- [ ] **Passo 1: scrivere la pagina con questa struttura**

```markdown
# Copia del database Drupal di Sardegna Sentieri

## Come funziona oggi

### Come si raggiunge una copia
- Copia locale: verifica (`docker inspect -f '{{.State.Running}}' mysql-sardegnasentieri-dump`),
  accensione (`docker compose -f sardegnasentieri-dump.compose.yml up -d`), caricamento (dump con
  la data nel nome in `storage/drupal-dump/`, poi `scripts/drupal-dump-reload.sh`). Un container
  nuovo è vuoto e senza `readonly` finché non si lancia lo script.
- Copia di UAT dal locale: `ssh -N -L 3308:127.0.0.1:3307 uat.forestas`, poi `127.0.0.1:3308`
  utente `readonly`. Valido da quando il ticket di sync l'avrà messa su UAT.
- Da dove arriva il dump: produzione genera ogni giorno
  `prod-sardegnasentieri-sardegnasentieriuyyrag83f9-AAAA-MM-GG.sql.gz`; oggi lo si porta a mano.

### Come si interroga
- Shell: `docker exec -e MYSQL_PWD=readonly mysql-sardegnasentieri-dump mysql --default-character-set=utf8mb4 -ureadonly sardegnasentieri -e "…"`
  (senza `--default-character-set` gli accenti escono corrotti).
- Laravel: `DB::connection('sardegnasentieri')`, solo `SELECT`.
- Codice Drupal di riferimento: repo Acquia, tag `2024-04-10-bis` (quello in produzione).

### Lo schema
(dal report, sezioni A-G, riverificate) modello nodo + tabelle `node__<campo>`; come si ricava
il valore corrente (join su `entity_id`, `deleted = 0`, langcode solo se il campo è traducibile,
mai su `revision_id`); revisioni e moderazione; traduzioni; per `sentiero`, `poi`, `itinerario`,
`news` la tabella dei campi con tipo, tabella/colonna e destinazione dei riferimenti; tassonomie;
catena file/media fino all'URL pubblico; geometrie; path alias; relazioni fra nodi con la query
di join.

### Corrispondenza con le API usate dall'import
Una tabella per chiamata (`/ss/list-tracks/`, `/ss/track/{id}`, `/ss/listpoi/`, `/ss/poi/{id}`,
`/ss/tassonomia/{vocabolario}`, `/node/{id}`, `/taxonomy/term/{id}`): campo che Forestas legge
(dai DTO e dal service) → tabella.colonna della copia → note (calcolato/trasformato dall'API e
come si ricostruisce; assente dalla copia). Il GPX: URL ricavato da `file_managed`, contenuto
su disco a Drupal.

### Cosa le API non espongono
(dal report, sezione H.2) campi e bundle interi.

### Query di riferimento
Le query del report (sezione I) riverificate, con lo scopo di ciascuna e senza risultati.

### Trappole dello schema
(dal report, sezione J) una riga per trappola, senza conteggi.

## Perché così
- **Copia SQL accanto alle API** (oc:8705): le API non espongono tutto e Drupal verrà dismesso.
- **Script e non caricamento all'avvio** (oc:8705): una strada sola, che crea anche `readonly`.
- **Porta sul loopback, password nel compose** (oc:8705): protegge la rete; la password non
  protegge nulla in più.
- **SQL diretto e non API generate (GraphQL, Directus, Hasura)** (oc:8705): lo schema Drupal non
  dichiara chiavi esterne, l'API generata darebbe tabelle scollegate.
- **Import attuali ancora sulle API** (oc:8705): finché il sync non aggiorna la copia ogni giorno,
  le API sono più fresche.

## Come ci siamo arrivati
- **Dump montato su `/docker-entrypoint-initdb.d` e `MYSQL_USER`** (f0af59d, superata in
  oc:8705): caricava tutti i dump della cartella e dava `ALL PRIVILEGES` a `readonly`.
```

- [ ] **Passo 2: verificare ogni query della pagina sulla copia**

Eseguire ogni query della sezione «Query di riferimento» con il comando di shell della pagina.
Atteso: nessun errore SQL, risultato non vuoto. Una query che fallisce si corregge o si toglie.

- [ ] **Passo 3: controllo dati personali e numeri**

```bash
grep -n -i -E "@|users_field_data .*[0-9]|webform_submission_data .*[0-9]" docs/knowledge/copia-database-drupal.md
grep -n -E "\b[0-9]{2,}\b (nodi|sentieri|poi|righe|tabelle|file|termini)" docs/knowledge/copia-database-drupal.md
```

Atteso: nessuna riga che contenga email o righe di dati personali; nessun conteggio. Le
occorrenze legittime (nomi di tabella, porte, date di tag) si valutano a mano.

- [ ] **Passo 4: commit (istruzione per il dev)**

```bash
git add docs/knowledge/copia-database-drupal.md
git commit -m "docs(oc:8705): schema della copia Drupal e corrispondenza con le API dell'import"
```

---

### Task 4: indice nel CLAUDE.md e trappole path-scoped

**File:**
- Modifica: `CLAUDE.md` (tabella `## Conoscenza`)
- Modifica: `.claude/rules/import-sardegna-sentieri.md`

**Interfacce:**
- Consuma: la pagina del Task 3.

- [ ] **Passo 1: riga nell'indice `## Conoscenza` del `CLAUDE.md`**

```markdown
| Copia del DB Drupal | dove sta il dump, come si ricarica e si interroga (va accesa a mano), schema, corrispondenza con le API | [docs/knowledge/copia-database-drupal.md](docs/knowledge/copia-database-drupal.md) |
```

- [ ] **Passo 2: trappole in `.claude/rules/import-sardegna-sentieri.md`**

Aggiungere in fondo alla lista, una riga ciascuna:

```markdown
- **La copia Drupal non c'è per forza**: prima di scrivere codice su
  `DB::connection('sardegnasentieri')` verifica che il container `mysql-sardegnasentieri-dump`
  sia acceso e caricato — vedi docs/knowledge/copia-database-drupal.md (oc:8705)
- **Un campo Drupal si legge su `entity_id` e `deleted = 0`, non su `revision_id`**: alcune righe
  hanno un `revision_id` vecchio e il join le perde (oc:8705)
- **I riferimenti fra nodi possono puntare a nodi cancellati e cambiano fra `it` e `en`**: un
  import va in join su `node_field_data` e filtra la lingua (oc:8705)
```

- [ ] **Passo 3: commit (istruzione per il dev)**

```bash
git add CLAUDE.md .claude/rules/import-sardegna-sentieri.md
git commit -m "docs(oc:8705): indice e trappole della copia Drupal"
```
