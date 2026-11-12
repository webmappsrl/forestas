> Ticket: oc:8947

# WordPress di sviluppo sullo shard dev di forestas — piano di implementazione

> **Per chi esegue:** sotto-skill `superpowers:executing-plans`, task per task. I passi usano le
> caselle (`- [ ]`) per tenere traccia dell'avanzamento.

**Obiettivo:** `https://wp.forestas.dev.maphub.it` in opera sull'host di dev, popolato dall'import e
tenuto allineato da un cron notturno.

**Architettura:** lo stack WordPress è già incluso in `develop.compose.yml` e i container
`wordpress-forestasdev` / `mariadb-forestasdev` esistono, fermi. Si aggiungono il `.env` di
`wp-forestas`, gli zip commerciali, i virtual host Apache, il certificato e il cron sull'host; nel
repo cambiano solo i virtual host di riferimento e la documentazione.

**Strumenti:** Docker Compose, Apache 2 (`proxy_http`, `headers`, `rewrite`, `ssl` già attivi su dev),
certbot, WP-CLI tramite `bin/wordpress-config.sh`, pannello DNS di Hosting Solutions.

**Spec:** `docs/features/8947-wordpress-di-sviluppo-sullo-shard-dev-di-forestas/overview.md` (in
`forestas` e in `wp-forestas`).

## Vincoli globali

- Host: `DEV-forestas` (lo stesso di `forestas.dev.maphub.it`), `ssh dev.forestas`, shard in
  `/var/www/html/forestas`.
- Dominio: `wp.forestas.dev.maphub.it`; porta del container: `127.0.0.1:8090` (`WP_PORT=8090`).
- **Nessun commit, push o branch sull'host.** I file versionati si scrivono in locale sui branch
  `feature/oc-8947-wordpress-di-sviluppo-sullo-shard-dev-di-forestas` di `forestas` e
  `wp-forestas`; commit e push li fa il dev dal locale.
- **Nessun commit automatico:** i comandi `git commit` nel piano sono istruzioni per il dev.
- Il puntatore di `wp-forestas` in `forestas` lo committa il dev, in un commit a parte, mai insieme
  al lavoro.
- `wp-forestas` è pubblico: nei file versionati nessuna password, chiave o credenziale.
- Chiavi di licenza **vuote** su dev: `WPML_SITE_KEY`, `IMPREZA_LICENSE_SECRET`,
  `IMPREZA_MAINTENANCE_KEY`. Nessuna basic auth.
- Copia Drupal: `DRUPAL_DB_HOST=172.24.0.1`, `DRUPAL_DB_PORT=3307`, credenziali uguali a quelle di
  `php-forestasdev`.
- `apache2ctl configtest` prima di **ogni** reload di Apache; i virtual host di
  `forestas.dev.maphub.it` non si toccano.
- Certificato: `certbot certonly --webroot`, mai `certbot --apache`.
- Cron di `allinea` su dev alle **05:00**.
- Commit con scope `(oc:8947)`; documentazione e commit in italiano.

## Cosa può andare storto e va controllato

- Il virtual host HTTPS abilitato prima del certificato fa fallire `configtest`: si abilita solo
  dopo `certbot` (Task 4).
- Il DNS non ancora propagato fa fallire la challenge di certbot: prima `dig` (Task 2).
- Il container parte senza Impreza se gli zip non sono sull'host: si controllano prima di
  `wordpress-up.sh` (Task 3).
- Un giro di `pagine` interrotto dal tunnel: il controllo di integrità ferma senza scrivere, si
  rilancia (Task 5).
- Il `.env` con valori sbagliati vale solo alla prima installazione: va controllato prima del primo
  avvio (Task 3).

---

### Task 1: Virtual host di riferimento per dev e stato del cron (locale, `wp-forestas`)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-virtual-host-e-stato-del-cron)

**File:**
- Crea: `wp-forestas/docker/dev/wp.forestas.dev.maphub.it.conf`
- Crea: `wp-forestas/docker/dev/wp.forestas.dev.maphub.it-le-ssl.conf`
- Modifica: `wp-forestas/cron/allinea.crontab:3-4` (riga `STATO`) e aggiunta della riga per dev

- [x] **Passo 1:** copia i due file di `docker/uat/` in `docker/dev/` e sostituisci
  `wp.forestas.uat.maphub.it` con `wp.forestas.dev.maphub.it` e «host di UAT» con «host di dev»
  nei commenti. Porta (`8090`), percorsi dei log e del certificato seguono il dominio.
- [x] **Passo 2: verifica** che fra uat e dev cambino solo dominio e commenti:
  `diff docker/uat/wp.forestas.uat.maphub.it.conf docker/dev/wp.forestas.dev.maphub.it.conf` e lo
  stesso per `-le-ssl.conf`. Atteso: solo righe con `uat`/`dev`.
- [x] **Passo 3:** in `cron/allinea.crontab` aggiorna `STATO` con le due installazioni: UAT (dal
  02/11/2026, ore 11:00) e dev (data di questo lavoro, ore 05:00); aggiungi, commentata, la riga per
  dev con `0 5 * * *` e lo stesso comando, spiegando perché alle 05:00 (nessun lock in
  `bin/wordpress-config.sh`, import a mano di giorno).
- [x] **Passo 4: verifica** `grep -rnE "password|secret|key" docker/dev/` non restituisce valori.
- [x] **Passo 5 (dev):** commit in `wp-forestas` dei due virtual host, di `cron/allinea.crontab`
  e di `docs/features/8947-…/overview.md`, **senza** le modifiche di 8760 e 8783 già presenti nel
  checkout: `feat(oc:8947): virtual host di riferimento per dev e stato del cron di allinea`.

### Task 2: Record DNS (browser del dev)

- [x] **Passo 1:** con il browser del dev, nel pannello di Hosting Solutions, prepara il record `A`
  `wp.forestas.dev.maphub.it` → lo stesso IP di `forestas.dev` (TTL come gli altri record di
  `maphub.it`).
  **Mostra la schermata al dev e salva solo dopo il suo ok.**
- [x] **Passo 2: verifica** `dig +short wp.forestas.dev.maphub.it` restituisce lo stesso IP di `forestas.dev.maphub.it`
  (riprova finché non si propaga).

### Task 3: `.env`, zip e primo avvio (host)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-env-zip-e-primo-avvio)

- [x] **Passo 1:** copia gli zip dal locale:
  `scp wp-forestas/docker/themes/impreza.zip dev.forestas:/var/www/html/forestas/wp-forestas/docker/themes/`
  e `scp wp-forestas/docker/plugins/*.zip dev.forestas:/var/www/html/forestas/wp-forestas/docker/plugins/`.
  Verifica: sull'host ci sono `impreza.zip`, `sitepress-multilingual-cms.zip`,
  `wpml-string-translation.zip`.
- [x] **Passo 2:** sull'host, `cp wp-forestas/.env-example wp-forestas/.env` e imposta:
  `WP_URL=https://wp.forestas.dev.maphub.it`, `WP_PORT=8090`, `WP_DB_PASSWORD` e
  `WP_ADMIN_PASSWORD` da `openssl rand -base64 24` (senza stamparle), `WPML_SITE_KEY`,
  `IMPREZA_LICENSE_SECRET`, `IMPREZA_MAINTENANCE_KEY` vuote, `DRUPAL_SYNC=on`,
  `DRUPAL_DB_HOST=172.24.0.1`, `DRUPAL_DB_PORT=3307`, `DRUPAL_DB_USER` e `DRUPAL_DB_PASSWORD`
  uguali alla connessione `sardegnasentieri` di `php-forestasdev`. Il resto come da `.env-example`.
  `chmod 600 wp-forestas/.env`.
- [x] **Passo 3: verifica** il `.env` con le password oscurate
  (`sed -E 's/(PASSWORD=).+/\1***/' wp-forestas/.env`) e confrontalo con il passo 2.
- [x] **Passo 4:** `scripts/wordpress-up.sh`, poi `docker logs -f wordpress-forestasdev` fino a
  «[init-wordpress] WordPress pronto».
- [x] **Passo 5: verifica**
  `curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: wp.forestas.dev.maphub.it' -H 'X-Forwarded-Proto: https' http://127.0.0.1:8090/`
  → `200`. Nei log nessun «licenza di Impreza» attivata e il tema `forestas-child` attivo
  (`docker exec -u www-data wordpress-forestasdev wp --path=/var/www/html theme list --status=active`).

### Task 4: Apache e certificato (host)

- [x] **Passo 1:** `mkdir -p /var/www/letsencrypt`; copia dal locale il solo virtual host HTTP:
  `scp wp-forestas/docker/dev/wp.forestas.dev.maphub.it.conf dev.forestas:/etc/apache2/sites-available/`.
- [x] **Passo 2:** `a2ensite wp.forestas.dev.maphub.it`, `apache2ctl configtest` (atteso
  `Syntax OK`), poi `systemctl reload apache2`.
- [x] **Passo 3: verifica** che il backend di dev risponda ancora:
  `curl -s -o /dev/null -w '%{http_code}\n' https://forestas.dev.maphub.it/` → `302`.
- [x] **Passo 4:** `certbot certonly --webroot -w /var/www/letsencrypt -d wp.forestas.dev.maphub.it`.
- [x] **Passo 5:** copia `wp.forestas.dev.maphub.it-le-ssl.conf` in `/etc/apache2/sites-available/`,
  `a2ensite wp.forestas.dev.maphub.it-le-ssl`, `apache2ctl configtest`, `systemctl reload apache2`.
- [x] **Passo 6: verifica** `curl -sI https://wp.forestas.dev.maphub.it/` → `200` con certificato
  valido, `https://forestas.dev.maphub.it/` → `302`, `certbot renew --dry-run --cert-name
  wp.forestas.dev.maphub.it` riuscito.

### Task 5: Contenuti da Drupal (host)

Sequenza di `wp-forestas/docs/howto/ricreare-wordpress-da-zero.md`, passi 2-5.

- [x] **Passo 1: verifica** che l'apply automatico sia avvenuto:
  `docker exec -u www-data wordpress-forestasdev wp --path=/var/www/html option get wp_forestas_config_applicata`.
- [x] **Passo 2:** da `/var/www/html/forestas/wp-forestas`, `bin/wordpress-config.sh pagine`
  (anteprima: pagine, news, eventi e «da sapere» «creata»), poi `pagine --conferma`.
- [x] **Passo 3:** `bin/wordpress-config.sh applica` (anteprima dei menu da ricollegare), poi
  `applica --conferma`.
- [x] **Passo 4: verifica** `bin/wordpress-config.sh allinea` chiude con `STATO allineato`; nel
  browser la Home mostra header e footer di Sardegna Sentieri, `/news/` ha le news, `/wp-admin`
  accetta `WP_ADMIN_USER`.

### Task 6: Cron notturno (host)

- [x] **Passo 1:** nel crontab di `root` dell'host aggiungi la riga per dev di
  `wp-forestas/cron/allinea.crontab` (`0 5 * * *`), con il log in
  `/var/www/html/forestas/storage/logs/wordpress-pagine-cron.log`. Le righe esistenti non si toccano.
- [x] **Passo 2: verifica** `crontab -l | grep allinea` mostra una sola riga, alle 05:00.

### Task 7: Documentazione (locale, `forestas`)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-documentazione)

**File:**
- Crea: `docs/howto/messa-in-opera-wordpress-dev.md`
- Modifica: `docs/knowledge/wordpress-nello-shard.md` (riga su dev accanto a «UAT»)
- Modifica: `CLAUDE.md` (riga nella tabella `## Procedure`)
- Modifica: `docs/features/8947-wordpress-di-sviluppo-sullo-shard-dev-di-forestas/notes.md` con l'esito

- [x] **Passo 1:** scrivi la procedura per dev con la struttura di
  `docs/howto/messa-in-opera-wordpress-uat.md` (DNS, codice e configurazione, primo avvio, virtual
  host e certificato, verifica, aggiornamenti, cron), rimandando a quella di UAT per i passi
  identici e riportando solo le differenze: host, dominio, chiavi vuote, copia Drupal dal tunnel,
  `certbot certonly --webroot`, cron alle 05:00, giro del puntatore del submodule.
- [x] **Passo 2:** aggiorna la pagina di conoscenza e il `CLAUDE.md` (mostrando al dev prima e
  dopo).
- [x] **Passo 3: verifica** i link relativi della nuova pagina (`ls` di ogni percorso citato).
- [x] **Passo 4 (dev):** commit in `forestas`, **senza** i file di `docs/features/8916-…` in
  staging né il puntatore dei submodule:
  `docs(oc:8947): messa in opera di WordPress su dev`.

### Task 8: Puntatore del submodule (dev)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-8-puntatore-del-submodule)

- [x] **Passo 1 (dev):** dopo il merge della PR di `wp-forestas` su `develop` (fatto: PR #17,
  `b84a917`), in `forestas`
  aggiorna il puntatore con l'hash letto da `git -C wp-forestas rev-parse HEAD` e committalo da
  solo: `chore(oc:8947): puntatore di wp-forestas con i virtual host di dev`.
- [ ] **Passo 2:** dopo il merge su `develop` di `forestas`, aggiorna lo shard di dev con la
  procedura abituale e `scripts/wordpress-up.sh`.
- [ ] **Passo 3: verifica** sull'host `ls wp-forestas/docker/dev/` mostra i due file, e
  `diff` con quelli in `/etc/apache2/sites-available/` non dà differenze.
