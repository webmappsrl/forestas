> Ticket: oc:8711

# WordPress in Docker per lo shard Forestas — piano di implementazione (repo `forestas`)

> **Per chi esegue:** usare superpowers:executing-plans (o subagent-driven-development) task per
> task. **Nessun `git commit`, `git add`, `git push` né creazione di branch in autonomia**: i passi
> di commit sono istruzioni per lo sviluppatore. Questo piano parte **dopo** il Task 5 del piano
> gemello `wp-forestas/docs/features/8711-…/plan.md`, quando `wp-forestas` ha un commit su GitHub.

**Obiettivo:** WordPress entra nello shard: submodule, `include` nei compose, script sull'host per
aggiornarlo e per azzerarlo, procedura di messa in opera su UAT.

**Architettura:** `wp-forestas` è un submodule in `forestas/wp-forestas/`. `compose.yml`,
`develop.compose.yml` e `local.compose.yml` includono `wp-forestas/compose.yml`, così ogni modo di
avviare lo shard porta su anche WordPress. `scripts/wordpress-up.sh` ricava da Docker con quali
file compose gira lo shard (label del container `php-${APP_NAME}`) e aggiorna solo `wordpress` e
`mariadb`; `scripts/wordpress-reset.sh` cancella solo i due volumi di WordPress e lo ricrea.

**Stack:** Docker Compose ≥ 2.24 (UAT ha 2.26.1, in locale v5), bash, Apache e certbot sull'host
di UAT.

**Spec:** [overview.md](overview.md) di questa cartella e l'overview principale in `wp-forestas`.

## Vincoli globali

- I container dello shard mantengono nomi, porte e volumi.
- Il `.env` di `forestas` non riceve variabili di WordPress.
- Nessuna variabile nuova per sapere con quali file compose gira lo shard: si legge la label
  `com.docker.compose.project.config_files` di `php-${APP_NAME}`.
- `wordpress-reset.sh` tocca solo i volumi `wordpress-${APP_NAME}` e `mariadb-${APP_NAME}`, e non
  va usato in produzione.
- Su UAT non si scrive da qui: DNS, virtual host, certificato, `.env` li applica il team con la
  procedura.
- Hash del submodule solo da `git rev-parse`, mai scritti a mano (oc:8567).
- Documentazione e commenti in italiano.

## Attenzione in review

1. **`wp-forestas/.env` mancante su una macchina già esistente**: `docker compose -f
   local.compose.yml up -d` deve avviare comunque lo shard. Verifica nel Task 2, passo 3.
2. **`local.compose.yml` estende servizi di `develop.compose.yml`**: l'`include` non deve produrre
   servizi duplicati né conflitti. Verifica nel Task 2, passo 2.
3. **`wordpress-up.sh` con lo shard spento**: si ferma con un messaggio chiaro, senza avviare nulla.
   Verifica nel Task 3, passo 3.
4. **`wordpress-reset.sh` lanciato per sbaglio**: senza `--conferma` non cancella niente. Verifica
   nel Task 4, passo 2.
5. **Reset su una macchina con altri volumi**: dopo il reset i volumi dello shard (Postgres,
   Elasticsearch, la copia Drupal) sono intatti. Verifica nel Task 4, passo 3.

---

### Task 1: submodule `wp-forestas`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-submodule)

**File:**
- Modifica: `.gitmodules`
- Crea: `wp-forestas/` (puntatore del submodule)

- [ ] **Passo 1: aggiungi il submodule**

Dalla root di `forestas`, sul branch della feature:

```bash
git submodule add https://github.com/webmappsrl/wp-forestas.git wp-forestas
git -C wp-forestas checkout <branch pubblicato nel Task 5 del piano wp-forestas>
git -C wp-forestas rev-parse HEAD     # l'hash che il puntatore registrerà
```

`git submodule add` modifica l'indice: è un'operazione che lo sviluppatore deve vedere. Se chi
esegue non è autorizzato a toccare l'indice, si ferma e chiede allo sviluppatore di lanciarla.

- [ ] **Passo 2: verifica**

```bash
cat .gitmodules                      # sezione [submodule "wp-forestas"] con l'URL GitHub
git submodule status                 # wm-package e wp-forestas
ls wp-forestas/compose.yml
```

- [ ] **Passo 3: commit (lo esegue lo sviluppatore)**

```bash
git add .gitmodules wp-forestas
git commit -m "feat(oc:8711): wp-forestas come submodule dello shard"
```

---

### Task 2: `include` nei tre compose

**File:**
- Modifica: `compose.yml` (in testa)
- Modifica: `develop.compose.yml` (in testa)
- Modifica: `local.compose.yml` (in testa)

- [ ] **Passo 1: aggiungi in testa a ciascuno dei tre file, prima di `services:`**

```yaml
# Servizi WordPress dello shard (oc:8711): wordpress-${APP_NAME} e mariadb-${APP_NAME}.
# La configurazione di WordPress sta in wp-forestas/.env, non in questo .env.
include:
  - wp-forestas/compose.yml

```

- [ ] **Passo 2: verifica dei servizi, senza duplicati**

```bash
for f in compose.yml develop.compose.yml local.compose.yml; do
  echo "== $f"
  docker compose -f "$f" config --quiet && echo "valido"
  docker compose -f "$f" config --services | sort | uniq -c | awk '$1>1{print "DUPLICATO: "$2}'
  docker compose -f "$f" config --services | grep -E '^(wordpress|mariadb)$'
done
```

Atteso: per ogni file `valido`, nessun `DUPLICATO`, `wordpress` e `mariadb` presenti. Prima di
modificare i file salva l'elenco dei servizi di ciascuno (`config --services`) e confrontalo dopo:
deve essere lo stesso, più `wordpress` e `mariadb`.

- [ ] **Passo 3: lo shard parte anche senza `wp-forestas/.env`**

```bash
ls wp-forestas/.env 2>/dev/null && echo "ATTENZIONE: rinominalo per la prova"
docker compose -f local.compose.yml up -d
docker ps --format '{{.Names}}' | grep -E "^(php|laravel|postgres)-forestas$"
docker logs wordpress-forestas 2>&1 | grep "variabili mancanti"
```

Atteso: i container dello shard attivi; `wordpress-forestas` segnala le variabili mancanti ed
esce, senza bloccare il resto.

- [ ] **Passo 4: avvio completo in locale**

```bash
cp wp-forestas/.env-example wp-forestas/.env    # compila i valori
docker compose -f local.compose.yml up -d
docker logs -f wordpress-forestas               # attendi «WordPress pronto», poi Ctrl+C
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8090/                       # → 200
docker exec wordpress-forestas curl -s -o /dev/null -w '%{http_code}\n' http://laravel:8000/   # → 200 o 302
```

L'ultimo comando prova che dal container WordPress le API dello shard si raggiungono per nome di
container, sulla rete del progetto compose.

- [ ] **Passo 5: commit (lo esegue lo sviluppatore)**

```bash
git add compose.yml develop.compose.yml local.compose.yml
git commit -m "feat(oc:8711): i compose dello shard includono i servizi WordPress"
```

---

### Task 3: `scripts/wordpress-up.sh`

**File:**
- Crea: `scripts/wordpress-up.sh`

**Interfacce:**
- Produce: `scripts/wordpress-up.sh` (nessun argomento), usato dal Task 4 e dalla procedura di UAT.

- [ ] **Passo 1: `scripts/wordpress-up.sh`**

```bash
#!/bin/bash
# Aggiorna e riavvia solo i servizi WordPress dello shard (wordpress, mariadb), con gli stessi
# file compose con cui gira lo shard. I file li dice Docker, dalla label del container
# php-<APP_NAME>: nessuna variabile da configurare, vale in locale, su UAT e in produzione.
# Va lanciato sull'host, dalla root del repo o da qualsiasi cartella (oc:8711).
set -euo pipefail

cd "$(dirname "$0")/.."

APP_NAME=$(grep -E '^APP_NAME=' .env | head -1 | cut -d= -f2- | tr -d '"'"'")
if [ -z "$APP_NAME" ]; then
    echo "APP_NAME non trovato nel .env di forestas" >&2
    exit 1
fi

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
```

Poi `chmod +x scripts/wordpress-up.sh`.

- [ ] **Passo 2: verifica con lo shard avviato**

```bash
scripts/wordpress-up.sh
```

Atteso: stampa `File compose dello shard: …/local.compose.yml`, ricostruisce e riavvia solo
`wordpress-forestas` e `mariadb-forestas` (controlla con `docker ps` che gli altri container non
abbiano un `Created` recente).

- [ ] **Passo 3: verifica con lo shard spento**

```bash
docker stop php-forestas
scripts/wordpress-up.sh; echo "uscita: $?"     # → messaggio «non è avviato», uscita 1
docker start php-forestas
```

- [ ] **Passo 4: verifica di un compose rotto**

Aggiungi temporaneamente una riga non valida in `wp-forestas/compose.yml` (per esempio
`  rotto: [` sotto `services:`), lancia `scripts/wordpress-up.sh` e verifica che si fermi su
`config --quiet` senza riavviare nulla. Ripristina il file.

- [ ] **Passo 5: commit (lo esegue lo sviluppatore)**

```bash
git add scripts/wordpress-up.sh
git commit -m "feat(oc:8711): script per aggiornare solo i servizi WordPress dello shard"
```

---

### Task 4: `scripts/wordpress-reset.sh`

**File:**
- Crea: `scripts/wordpress-reset.sh`

**Interfacce:**
- Consuma: `scripts/wordpress-up.sh` (Task 3).
- Produce: `scripts/wordpress-reset.sh --conferma`, il punto d'aggancio del futuro ciclo
  giornaliero.

- [ ] **Passo 1: `scripts/wordpress-reset.sh`**

```bash
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
```

Poi `chmod +x scripts/wordpress-reset.sh`.

- [ ] **Passo 2: senza `--conferma` non fa nulla**

```bash
scripts/wordpress-reset.sh; echo "uscita: $?"   # → istruzioni, uscita 1
docker ps --format '{{.Names}}' | grep wordpress-forestas   # ancora attivo
```

- [ ] **Passo 3: reset completo, il resto dello shard intatto**

```bash
docker volume ls --format '{{.Name}}' | sort > /tmp/volumi-prima
docker exec wordpress-forestas wp --allow-root --path=/var/www/html option update blogname "da cancellare"
scripts/wordpress-reset.sh --conferma
docker logs -f wordpress-forestas      # attendi «WordPress pronto», poi Ctrl+C
docker exec wordpress-forestas wp --allow-root --path=/var/www/html option get blogname   # → WP_TITLE
docker volume ls --format '{{.Name}}' | sort | diff /tmp/volumi-prima - && echo "stessi volumi"
```

Atteso: titolo tornato a `WP_TITLE`; l'elenco dei volumi identico (i due di WordPress sono stati
ricreati con lo stesso nome); Postgres e gli altri container non riavviati.

- [ ] **Passo 4: commit (lo esegue lo sviluppatore)**

```bash
git add scripts/wordpress-reset.sh
git commit -m "feat(oc:8711): script per azzerare e ricreare WordPress"
```

---

### Task 5: procedura per UAT e indice nel CLAUDE.md

**File:**
- Crea: `docs/howto/messa-in-opera-wordpress-uat.md`
- Modifica: `CLAUDE.md` (tabella «Procedure»)

- [ ] **Passo 1: `docs/howto/messa-in-opera-wordpress-uat.md`**

````markdown
# Mettere in opera WordPress su UAT

> Ticket: oc:8711. Cos'è e come funziona l'ambiente: README di
> [`wp-forestas`](https://github.com/webmappsrl/wp-forestas).

WordPress gira sull'host di UAT come parte dello shard, nei container `wordpress-forestasuat` e
`mariadb-forestasuat`, ed è pubblicato su `https://wp.forestas.uat.maphub.it` dall'Apache
dell'host. Questi passi si fanno **una volta sola**, a mano, dal team.

## 1. DNS

Record `A` di `wp.forestas.uat.maphub.it` verso lo stesso IP di `forestas.uat.maphub.it`.
Verifica: `dig +short wp.forestas.uat.maphub.it` restituisce l'IP dell'host.

## 2. Codice e configurazione

Sull'host, in `/var/www/html/forestas`, dopo l'aggiornamento abituale (che fa già
`git submodule update --init --recursive`):

```bash
ls wp-forestas/compose.yml                       # il submodule c'è
cp wp-forestas/.env-example wp-forestas/.env     # poi compila:
#   WP_PORT=8090        (verifica che sia libera: ss -ltn | grep :8090 non deve stampare nulla)
#   WP_URL=https://wp.forestas.uat.maphub.it
#   password del database e dell'amministratore: valori robusti, solo in questo file
```

Facoltativo: copia lo zip di Impreza in `wp-forestas/docker/themes/impreza.zip`.

## 3. Primo avvio

```bash
scripts/wordpress-up.sh
docker logs -f wordpress-forestasuat     # attendi «WordPress pronto»
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: wp.forestas.uat.maphub.it' -H 'X-Forwarded-Proto: https' http://127.0.0.1:8090/   # → 200
```

## 4. Virtual host e certificato

I file di riferimento sono in `wp-forestas/docker/uat/`.

```bash
sudo cp wp-forestas/docker/uat/wp.forestas.uat.maphub.it.conf /etc/apache2/sites-available/
sudo a2ensite wp.forestas.uat.maphub.it
sudo apache2ctl configtest && sudo systemctl reload apache2

sudo certbot certonly --webroot -w /var/www/letsencrypt -d wp.forestas.uat.maphub.it

sudo cp wp-forestas/docker/uat/wp.forestas.uat.maphub.it-le-ssl.conf /etc/apache2/sites-available/
sudo a2ensite wp.forestas.uat.maphub.it-le-ssl
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Se `WP_PORT` non è `8090`, cambia la porta di `ProxyPass` nel file HTTPS.

## 5. Verifica

- `https://wp.forestas.uat.maphub.it` risponde con certificato valido e mostra il sito.
- `https://wp.forestas.uat.maphub.it/wp-admin` accetta l'utente amministratore del `.env`.
- Se c'era lo zip: Aspetto → Temi mostra Impreza attivo; la licenza si attiva da Impreza →
  Attivazione.

## Aggiornamenti successivi

Nella procedura abituale di aggiornamento di UAT, **dopo** `git submodule update` e i passi nel
container, lancia sull'host:

```bash
scripts/wordpress-up.sh
```

Senza questo passo i container di WordPress restano quelli vecchi, e un errore nel compose incluso
emergerebbe solo al primo riavvio della macchina, bloccando l'intero shard.

## Azzerare WordPress

```bash
scripts/wordpress-reset.sh --conferma
```

Cancella database e file di WordPress e lo ricrea da zero; non tocca gli altri volumi. Su UAT è
il comando del ciclo giornaliero; **in produzione non va usato**.
````

- [ ] **Passo 2: riga nella tabella «Procedure» del `CLAUDE.md`**

Aggiungi in fondo alla tabella:

```markdown
| Mettere in opera, aggiornare o azzerare WordPress su UAT | [docs/howto/messa-in-opera-wordpress-uat.md](docs/howto/messa-in-opera-wordpress-uat.md) |
```

- [ ] **Passo 3: commit (lo esegue lo sviluppatore)**

```bash
git add docs/howto/messa-in-opera-wordpress-uat.md CLAUDE.md
git commit -m "docs(oc:8711): procedura per WordPress su UAT"
```
