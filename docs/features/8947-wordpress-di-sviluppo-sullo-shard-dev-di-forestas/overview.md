> Ticket: oc:8947

# WordPress di sviluppo sullo shard dev di forestas (wp.forestas.dev.maphub.it)

## Cosa cambia

Il WordPress dello shard, oggi in opera solo su UAT (`https://wp.forestas.uat.maphub.it`), va in
opera anche sull'host di dev, all'indirizzo `https://wp.forestas.dev.maphub.it`. Gira sulla stessa
macchina di `forestas.dev.maphub.it` (`DEV-forestas`, Hetzner), nei container
`wordpress-forestasdev` e `mariadb-forestasdev`. Lo stack ci era già arrivato con il pull di oc:8833:
i container esistevano ma erano fermi, perché mancava `wp-forestas/.env` (in opera dal 06/11/2026).

Il sito parte vuoto e si popola come su UAT: l'init lo installa e lo configura, poi
`bin/wordpress-config.sh` applica `config/` e importa pagine, news, eventi e «da sapere» dalla copia
Drupal, letta da UAT attraverso il tunnel già attivo su dev. Un cron giornaliero di `allinea` lo
tiene allineato.

Nel repo cambiano solo i virtual host Apache per dev e la documentazione: il resto è
configurazione dell'host, che non si versiona.

## Perché

Allo scrum del 06/11 (call delle 08:31) Giuseppe ha chiesto di non usare UAT per le prove: UAT lo
guarda il cliente e lo aggiorna lui, mentre su dev si può «buttare giù, rimanere offline, lanciare
2000 migrazioni». Collegare il WordPress di UAT al Laravel di `develop` per provare rischia che il
cliente trovi il sito rotto. Serve quindi un WordPress anche su dev, da poter rompere e azzerare
senza conseguenze. Nella stessa call si è deciso di non copiare il database da UAT: il sito si
popola con l'import, così si verifica anche che l'installazione da zero funzioni.

## Requisiti

- [x] **DNS:** record `A` di `wp.forestas.dev.maphub.it` verso lo stesso IP di `forestas.dev.maphub.it`, creato dal pannello
  di Hosting Solutions nel browser del dev; la schermata si mostra al dev prima di salvare.
  Verifica: `dig +short` dei due nomi restituisce lo stesso indirizzo.
- [x] **Zip commerciali** di Impreza e WPML copiati dal locale in `wp-forestas/docker/themes/` e
  `wp-forestas/docker/plugins/` sull'host di dev, prima del primo avvio (oggi le cartelle non li
  contengono, e senza Impreza il sito partirebbe col tema di default).
- [x] **`wp-forestas/.env` sull'host di dev** con: `WP_URL=https://wp.forestas.dev.maphub.it`,
  `WP_PORT=8090` (libera, verificato), password del database e dell'amministratore robuste,
  **chiavi di licenza vuote** (`WPML_SITE_KEY`, `IMPREZA_LICENSE_SECRET`, `IMPREZA_MAINTENANCE_KEY`),
  `DRUPAL_SYNC=on`, `DRUPAL_DB_HOST=172.24.0.1`, `DRUPAL_DB_PORT=3307` e utente/password della copia
  Drupal.
- [x] **Avvio:** `scripts/wordpress-up.sh` sull'host; il log di `wordpress-forestasdev` arriva a
  «WordPress pronto» e `127.0.0.1:8090` risponde 200 con l'host di dev.
- [x] **Virtual host versionati** in `wp-forestas/docker/dev/` (HTTP con challenge ACME, HTTPS con
  reverse proxy verso `127.0.0.1:8090`), ricavati da quelli di `docker/uat/`. Si scrivono in
  locale; commit e push li fa il dev dal locale, l'host li riceve con il pull. Mai commit o push
  dall'host.
- [x] **Apache e certificato** sull'host: virtual host abilitati, `apache2ctl configtest` prima di
  ogni reload, certificato Let's Encrypt per `wp.forestas.dev.maphub.it`.
- [x] **Contenuti:** `wordpress-config.sh applica --conferma`, `pagine --conferma`, di nuovo
  `applica --conferma` per i menu, come in `wp-forestas/docs/howto/ricreare-wordpress-da-zero.md`.
- [x] **Cron** di `allinea` nel crontab di `root` dell'host di dev **alle 05:00 UTC** (l'ora
  dell'host): fuori dall'orario di lavoro, perché `pagine` e `applica` prendono un blocco e, con un
  giro lanciato a mano in corso, quello del cron esce («un'altra sincronizzazione») e chiude non
  allineato; lontano anche dal giro di UAT (11:00) e dal reload della copia Drupal su UAT (10:00).
- [x] **Puntatore del submodule:** dopo il merge di `wp-forestas` su `develop`, il puntatore di
  `wp-forestas` in `forestas` si aggiorna con l'hash letto da `git rev-parse` e va su `develop` di
  `forestas`, in un commit a parte che fa il dev; solo dopo si aggiorna lo shard di dev. Fino ad
  allora i virtual host sull'host si copiano dal locale in `/etc/apache2/sites-available/`.
- [x] **Verifica finale:** `https://wp.forestas.dev.maphub.it` risponde con certificato valido,
  mostra header e footer di Sardegna Sentieri (child theme e `config/` applicati), le news e le
  pagine importate ci sono, `/wp-admin` accetta l'amministratore del `.env`.
- [x] **Documentazione:** procedura di messa in opera su dev in `forestas`, rimandi aggiornati nella
  pagina di conoscenza di WordPress nello shard e nel `CLAUDE.md`, stato del cron aggiornato in
  `wp-forestas/cron/allinea.crontab`; corrette le pagine che questo lavoro rendeva false (elenco in
  «Moduli toccati»).

## Rischi

- **Apache serve già lo shard di dev.** Su `DEV-forestas` Apache ha abilitati solo i virtual host
  di `forestas.dev.maphub.it`: un errore nei file nuovi, al reload, fermerebbe anche il backend di
  dev. Mitigazione: `apache2ctl configtest` prima di ogni reload, file nuovi accanto a quelli
  esistenti senza toccarli.
- **Cartella della challenge ACME assente.** I virtual host di UAT usano `/var/www/letsencrypt`,
  che su dev non esiste: va creata prima di `certbot`, altrimenti il certificato non si emette.
  Come su UAT: prima il solo virtual host HTTP, poi `certbot certonly --webroot` (mai
  `certbot --apache`, che scriverebbe un suo `-le-ssl.conf` con lo stesso nome di quello
  versionato), infine il virtual host HTTPS.
- **Sito aperto a tutti.** Senza licenza Impreza non c'è la modalità manutenzione che su UAT
  nasconde il sito: chiunque apre l'indirizzo vede il sito di dev, anche quando è rotto.
  Accettato dal dev (challenge del 06/11), niente basic auth.
- **Sito senza licenze.** Impreza e WPML funzionano senza aggiornamenti né servizi cloud, come in
  locale; non è attiva la modalità manutenzione, che scatta solo con una licenza di tipo «dev».
  Da rivedere se emergono limiti nell'uso.
- **Due copie quasi identiche dei virtual host** (`wp-forestas/docker/uat/` e `docker/dev/`): una
  modifica a una va riportata sull'altra. Accettato finché gli ambienti sono due.
- **Import attraverso il tunnel.** La copia Drupal si legge da UAT via SSH: se il tunnel cade il
  giro si ferma sul controllo di integrità senza scrivere, e il sito resta ai contenuti del giro
  precedente. Collegamento provato il 06/11: da un container temporaneo con l'immagine
  `wm-wordpress-forestasdev:php8.3` sulla rete `forestas_default`, una connessione `mysqli` come
  quella di `wpf_drupal_connessione()` su `172.24.0.1:3307`, con le credenziali usate da
  `php-forestasdev`, legge la copia (976 poi, 614 sentieri, 95 news, 72 «da sapere», 20 pagine).
  Il client `mariadb` a riga di comando invece rifiuta il certificato TLS della copia: per prove a
  mano dal container serve `--skip-ssl`.

## Out of scope

- Codice dell'init, di `bin/` e del child theme di `wp-forestas`: non cambiano.
- Da quale shard Laravel leggono i dati i plugin di WordPress (`wp-geohub` e il plugin che lo
  sostituirà): ticket separato.
- Licenze di Impreza e WPML su dev.
- Ciclo di azzeramento periodico di dev (`wordpress-reset.sh` resta un'operazione a mano).

## Moduli toccati

**`forestas`**
- `docs/howto/messa-in-opera-wordpress-dev.md` — nuovo: procedura per dev, con rimandi a quella di
  UAT per i passi identici
- `docs/knowledge/wordpress-nello-shard.md` — riga su dev accanto a quella su UAT; il cron di
  `allinea` non più «non ancora installato»
- `docs/howto/copia-database-drupal.md` — anche `wordpress-forestasdev` legge la copia dal tunnel, e
  `wp-forestas/.env` di dev va aggiornato insieme al `.env` di Laravel se cambia il gateway
- `docs/howto/messa-in-opera-wordpress-uat.md` — il cron di `allinea` esiste, non «quando ci sarà»
- `CLAUDE.md` — riga nella tabella `## Procedure`

**`wp-forestas`** (submodule)
- `docker/dev/wp.forestas.dev.maphub.it.conf` — nuovo
- `docker/dev/wp.forestas.dev.maphub.it-le-ssl.conf` — nuovo
- `cron/allinea.crontab` — stato dell'installazione (UAT e dev), riga commentata per dev
- `docs/howto/cron-sincronizzazione-pagine.md`, `CLAUDE.md` — rimandano allo stato del crontab invece
  di dire «non installata»
- `README.md` — `docker/dev/` nella tabella dei contenuti

**Host `DEV-forestas`** (non versionato)
- `/var/www/html/forestas/wp-forestas/.env`, zip commerciali, `/etc/apache2/sites-available/`,
  `/var/www/letsencrypt`, certificato, crontab di `root`
- **DNS** (Hosting Solutions): record `A` di `wp.forestas.dev.maphub.it`
