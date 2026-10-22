> Ticket: oc:8833

# Piano di implementazione — Messa in sicurezza PHP2Shell

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** chiudere le CVE-2026-109618/109619 su forestas (immagini PHP aggiornate, configurazione
ridotta), raccogliere le evidenze prima che i rebuild le distruggano, ruotare i segreti economici,
e produrre la procedura replicabile per gli altri shard.

**Architecture:** modifiche di configurazione in due repo (forestas, wp-forestas) + una pagina di
procedura in wm-package. Nessun codice applicativo nuovo, nessun test nuovo: la suite Pest si
lancia solo come conferma di non-regressione. Ordine vincolante: evidenze → modifiche → rebuild.

**Tech Stack:** Docker (immagini `php:*-fpm` / `php:*-apache`), PHP-FPM, Laravel 12, Pest.

**Spec:** `docs/features/8833-messa-in-sicurezza-php2shell-shard-wm-package/overview.md` (forestas)
e `wp-forestas/docs/features/8833-messa-in-sicurezza-php2shell-shard-wm-package/overview.md`.

## Global Constraints

- Nessuna operazione git autonoma: i passi di commit sono istruzioni per il dev, che committa dopo
  aver letto il diff. Vale in tutti e tre i repo.
- Su UAT solo letture (`ssh uat.forestas`, comandi singoli); ogni scrittura su UAT è del team.
- I repo sono **pubblici**: nessun dato sensibile (log, IP, hostname interni, esiti grezzi delle
  evidenze) nei file committati. Le evidenze vivono fuori dal repo.
- Suite Pest solo dopo la verifica di isolamento del DB di test (`.claude/rules/test.md`); se non
  verificabile, non si lancia e si chiede al dev.
- Versioni obiettivo: PHP ≥ 8.4.27 (forestas), ≥ 8.3.36 (WordPress). Limiti:
  `upload_max_filesize = 32M`, `post_max_size = 64M`, `memory_limit` invariato (1024M).
- Prerequisito operativo (dev): snapshot Hetzner dell'host UAT completato prima di ogni intervento
  sul server.

## Review Focus

1. Upload oltre il limite → rifiuto pulito, upload legittimo (immagine/GPX) → successo: test
   funzionale nel Task 7 (ambiente FPM) e criterio di done su UAT.
2. Horizon dopo il rebuild: un worker vecchio ignora il codice nuovo e una coda ferma è silenziosa
   → verifica coda attiva nel Task 6.
3. Evidenze o loro estratti committati per errore nel repo pubblico → le evidenze stanno fuori dal
   repo (Task 1) e `notes.md` riporta solo l'esito sintetico (Task 2); controllo finale nel diff.
4. Il riscontro storage smentisce 32M/64M → gate esplicito nel Task 3: ci si ferma e si ridecide
   col dev, non si applica un valore che rompe.
5. `php-fpm` che non parte per un errore di sintassi in `www.conf` → `php-fpm -tt` nel Task 6
   prima di dichiarare il rebuild riuscito.

---

### Task 1: Raccolta evidenze su UAT (prima di ogni rebuild)

**Files:**
- Create (fuori repo): `~/Documents/geobox2/evidenze-8833-uat/` (directory locale, mai committata)

- [ ] **Step 1: verifica che lo snapshot Hetzner sia completato** — chiedi conferma al dev. Senza
  snapshot non si procede oltre il Task 2.
- [ ] **Step 2: scarica le evidenze (sola lettura)** in `~/Documents/geobox2/evidenze-8833-uat/`:
  log Apache dell'host (`forestas.access.log`/`error.log`, `wp-forestas.*.log`, incluse le
  rotazioni che coprono dal 20/10), e per i container `php-forestasuat`, `horizon-forestasuat`,
  `wordpress-forestasuat`: `ps aux`, file modificati negli ultimi 3 giorni nelle directory
  scrivibili (`find ... -mtime -3`), crontab, `docker logs --since 2026-10-20`.
- [ ] **Step 3: verifica risultato** — la directory contiene i log con copertura 20–22/10; in caso
  di log mancanti, annotalo come lacuna nell'esito del Task 2.

### Task 2: Verifica di compromissione

**Files:**
- Modify: `docs/features/8833-messa-in-sicurezza-php2shell-shard-wm-package/notes.md` (solo esito
  sintetico, nessun dato sensibile)

**Interfaces:**
- Consumes: evidenze del Task 1.
- Produces: esito (pulito / segnalazioni) usato dalla bozza note-ticket a fine workflow.

- [ ] **Step 1: analizza le evidenze**: pattern dell'exploit pubblico (POST multipart anomali,
  sequenze di errori OOM in `error.log`, user-agent da scanner), processi/file/cron inattesi nei
  tre container. Indicatori pubblicati da Sansec come riferimento.
- [ ] **Step 2: scrivi l'esito in `notes.md`** (sezione «Verifica di compromissione»): esito,
  copertura temporale dei log, lacune. Se emergono segnalazioni concrete: **stop, si scala al
  dev** — l'incident response è fuori ticket.
- [ ] **Step 3: verifica** — rileggi l'esito: nessun IP/hostname interno/estratto di log grezzo
  nel testo (il repo è pubblico).

### Task 3: Riscontro sui limiti di upload

- [ ] **Step 1: misura il file più grosso esistente**: su UAT (sola lettura) il file più grande in
  MinIO (`mc` non disponibile → `du`/`find` nel volume `docker/volumes/minio/data`) e in
  `storage/app`; in locale lo stesso sul volume MinIO.
- [ ] **Step 2: gate** — se il massimo trovato è < 32M, i valori 32M/64M sono confermati e si
  prosegue. Se ≥ 32M, **stop: si riporta il dato al dev e si ridecide il valore** prima del Task 4.

### Task 4: Modifiche repo forestas

**Files:**
- Modify: `docker/configs/phpfpm/Dockerfile:1`
- Modify: `docker/configs/phpfpm/www.conf:541-543`
- Modify: `scripts/wordpress-up.sh:33`

- [ ] **Step 1:** `Dockerfile:1`: `FROM php:8.4.3-fpm` → `FROM php:8.4.27-fpm`.
- [ ] **Step 2:** `www.conf`: sotto le `php_admin_value` esistenti (riga 541) aggiungi
  `php_admin_flag[session.upload_progress.enabled] = off`; cambia
  `php_admin_value[upload_max_filesize]` da `1024M` a `32M` e
  `php_admin_value[post_max_size]` da `1024M` a `64M`. `memory_limit` resta `1024M`.
- [ ] **Step 3:** `scripts/wordpress-up.sh:33`: `up -d --build` → `up -d --build --pull always`
  (verifica la sintassi supportata dalla versione di compose: in alternativa, riga separata
  `docker compose "${ARGS[@]}" build --pull wordpress` prima dell'`up`).
- [ ] **Step 4: verifica** — `git diff`: tre file, nessun'altra riga toccata.

### Task 5: Modifica repo wp-forestas

**Files:**
- Modify: `wp-forestas/docker/configs/php/Dockerfile:3`

- [ ] **Step 1:** `FROM php:8.3-apache` → `FROM php:8.3.36-apache`.
- [ ] **Step 2: verifica** — `git -C wp-forestas diff`: una riga.

### Task 6: Rebuild locale e verifiche

**Interfaces:**
- Consumes: modifiche dei Task 4–5.

- [ ] **Step 1: rebuild immagini locali**:
  `docker compose -f local.compose.yml build --pull php` e
  `docker compose -f local.compose.yml up -d` (ricrea php, horizon, laravel, scout-init);
  per WordPress: `scripts/wordpress-up.sh` (ora con `--pull`).
- [ ] **Step 2: verifica versioni** — `docker exec php-forestas php -v` ≥ 8.4.27;
  `docker exec wordpress-forestas php -v` ≥ 8.3.36.
- [ ] **Step 3: verifica configurazione FPM** — `docker exec php-forestas php-fpm -tt 2>&1`:
  configurazione valida e dump contenente `session.upload_progress.enabled = off`,
  `upload_max_filesize = 32M`, `post_max_size = 64M`, `memory_limit = 1024M`.
- [ ] **Step 4: verifica Horizon** — `docker exec horizon-forestas php artisan horizon:status`:
  coda attiva dopo il rebuild.
- [ ] **Step 5: suite Pest** — prima leggi `.claude/rules/test.md` e verifica l'isolamento del DB
  di test; solo se verificato: `docker exec php-forestas vendor/bin/pest`. Expected: verde.
  Isolamento non verificabile → stop e chiedi al dev.

### Task 7: Test funzionale dei limiti (ambiente FPM)

I limiti vivono nel pool FPM: `php artisan serve` (local.compose) non li applica. Il test si fa
dove gira FPM: `develop.compose.yml` in locale, oppure su UAT dopo l'applicazione del team.

- [ ] **Step 1:** upload legittimo (immagine o GPX reale, < 32M) da Nova o API: successo.
- [ ] **Step 2:** richiesta oltre il limite (file > 64M): rifiuto pulito (413/422 o errore PHP
  gestito), il worker non muore (il servizio risponde subito dopo).
- [ ] **Step 3:** annota in `notes.md` dove è stato eseguito il test (develop locale o UAT).

### Task 8: Rotazione precauzionale dei segreti (col dev)

- [ ] **Step 1: token client SUS** — segui `docs/howto/creazione-client-sus.md` (revoca con
  `JWTAuth::invalidate`, poi nuova emissione; comunicazione delle nuove credenziali per il canale
  previsto dalla procedura, mai insieme all'endpoint).
- [ ] **Step 2: password DB UAT** — istruzioni per il team (cambio su Postgres UAT + `.env` +
  riavvio servizi): la scrittura è del team, il testo lo preparo io.
- [ ] **Step 3:** registra in `notes.md` che la rotazione è avvenuta (senza i valori).
- [ ] **Nota:** `JWT_SECRET` non si tocca (regola di progetto).

### Task 9: Procedura di replica in wm-package

**Files:**
- Create: `wm-package/docs/howto/messa-in-sicurezza-php-shard.md`

- [ ] **Step 1: scrivi la procedura** con, nell'ordine: snapshot → evidenze → patch; come
  individuare il Dockerfile sorgente dell'immagine dello shard (il tag `wm-phpfpm:*` può venire da
  più Dockerfile: su forestas è `docker/configs/phpfpm/`, il Dockerfile di wm-package è per lo
  sviluppo del package; annotare anche il tag `wm-phpfpm:8.2.15-fpm` del compose di wm-package);
  la trappola del rebuild con tag mobile senza `--pull` (no-op silenzioso con base in cache); la
  verifica sempre con `php -v` nel container, mai con l'esito della build; versioni minime
  (8.4.27/8.3.36) e pulizia config (upload_progress off, limiti commisurati); rotazione
  precauzionale dei segreti economici; il debito «unificazione dei Dockerfile» come annotazione.
- [ ] **Step 2: verifica** — la pagina non contiene dati di host specifici (anche wm-package è
  condiviso); i riferimenti a forestas sono come esempio.

### Task 10: Commit e PR (istruzioni per il dev — nessuna esecuzione autonoma)

- [ ] **Step 1 (wp-forestas):** `git add docker/configs/php/Dockerfile docs/features/...` →
  `feat(oc:8833): pin PHP 8.3.36 nell'immagine WordPress`
- [ ] **Step 2 (wm-package):** `git add docs/howto/messa-in-sicurezza-php-shard.md` →
  `docs(oc:8833): procedura di messa in sicurezza PHP per gli shard`
- [ ] **Step 3 (forestas):** add di Dockerfile, www.conf, wordpress-up.sh, bump dei puntatori dei
  submodule, `docs/features/...` → `feat(oc:8833): aggiornamento PHP 8.4.27 e riduzione superficie
  (PHP2Shell)`
- [ ] **Step 4:** PR verso `develop` (squash merge, convenzione del team). L'applicazione su UAT
  (build `--pull`, riavvio, `php -v`, test upload) la fa il team seguendo la procedura del Task 9.
