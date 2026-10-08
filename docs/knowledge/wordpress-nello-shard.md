# WordPress nello shard

## Come funziona oggi

Il WordPress che sostituisce la parte editoriale del Drupal di Sardegna Sentieri (news, post) fa
parte dello shard: gira sulla stessa macchina, parte con lo stesso `docker compose up` e si
aggiorna insieme a `forestas`. L'ambiente — immagine, compose, inizializzazione — vive nel repo
[`wp-forestas`](https://github.com/webmappsrl/wp-forestas) (il suo README spiega come funziona);
qui conta come entra nello shard.

- **Submodule.** `wp-forestas` è un submodule in `forestas/wp-forestas/`, come `wm-package`.
  L'aggiornamento di UAT (`git submodule update --init --recursive`) lo porta su già oggi.
- **Include.** `compose.yml`, `develop.compose.yml` e `local.compose.yml` includono
  `wp-forestas/compose.yml`. I servizi sono `wordpress` e `mariadb`, i container
  `wordpress-${APP_NAME}` e `mariadb-${APP_NAME}` (su UAT `wordpress-forestasuat`), i volumi hanno
  gli stessi nomi dei container. Stanno sulla rete del progetto compose: dal container WordPress le
  API dello shard rispondono per nome (in locale `http://laravel:8000`).
- **Due `.env`.** `APP_NAME` arriva dal `.env` di `forestas`; la configurazione di WordPress (URL,
  porta, database, amministratore) sta in `wp-forestas/.env`, che Laravel non legge mai. Le chiavi
  di sicurezza di WordPress le genera WP-CLI in `wp-config.php`, sul volume.
- **`wp-forestas/.env` mancante non blocca lo shard.** Il compose incluso non ha variabili
  obbligatorie e carica il `.env` con `required: false`; senza password MariaDB esce **senza
  inizializzare il volume**, e WordPress si ferma dicendo quali variabili mancano.
- **Aggiornare solo WordPress:** `scripts/wordpress-up.sh`, sull'host. I file compose li legge dalla
  label `com.docker.compose.project.config_files` del container `php-${APP_NAME}`.
  `scripts/deploy_dev.sh` non può farlo: gira dentro il container PHP, dove Docker non c'è.
- **Azzerare:** `scripts/wordpress-reset.sh --conferma`, che tocca solo i volumi di WordPress. Il
  sito ricreato ha già temi, plugin, licenze e configurazione, che `wp-forestas` rimette dagli zip,
  dal suo `.env` e da `config/` (oc:8717), purché zip e chiavi siano già sull'host; si perdono contenuti e uploads. Su UAT WordPress verrà
  ripopolato ogni giorno dai dati di Drupal (ticket successivo); in produzione i dati saranno
  permanenti.
- **UAT** (`https://wp.forestas.uat.maphub.it`): messa in opera, aggiornamenti e azzeramento in
  [docs/howto/messa-in-opera-wordpress-uat.md](../howto/messa-in-opera-wordpress-uat.md).

Vincoli:

- Il plugin `wp-geohub` oggi legge gli shard dalla lista pubblica di `wm-types`
  (`forestasuat` → `https://forestas.uat.maphub.it`): in locale legge i dati di UAT, non lo shard
  locale. Le chiamate locali sono un lavoro successivo.
- Un `wp-forestas/compose.yml` non valido fa fallire `docker compose up` dell'intero shard:
  `wordpress-up.sh` lo valida a ogni aggiornamento, così l'errore emerge subito e non al primo
  riavvio della macchina.

## Perché così

- **Submodule e non un compose che punta a `../wp-forestas`** (oc:8711): ogni commit di
  `forestas` dice quale versione di WordPress va con lui, e sulla macchina dello shard non serve
  clonare a mano un secondo repo.
- **`include` in tutti e tre i compose** (oc:8711): `develop.compose.yml` e `local.compose.yml`
  riprendono da `compose.yml` solo singoli servizi con `extends`, quindi un `include` in un file solo
  non arriverebbe agli altri; così la futura produzione su `compose.yml` è già pronta.
- **File compose letti dalla label del container** (oc:8711): il comando di aggiornamento è lo
  stesso in locale, su UAT e in produzione, senza variabili da tenere allineate su ogni macchina.
- **`.env` di WordPress separato** (oc:8711): le chiavi del generatore di WordPress contengono
  `$`, `#` e apici, che Compose interpreta come variabili e Laravel tronca come commenti.

## Come ci siamo arrivati

- **nginx in un container separato** (oc:8711, superata): proposto come in uno stack PHP-FPM, scartato
  perché il team usa Apache.
- **Solo `wp-content/uploads` su volume** (oc:8711, superata): una ricostruzione del container
  avrebbe perso core, plugin, tema e lingua mentre il database li dava ancora per installati; ora il
  volume copre l'intera cartella di WordPress.
- **Variabile `COMPOSE_FILE` nel `.env` di ogni macchina** (oc:8711, superata): avrebbe funzionato,
  ma era una variabile in più da ricordare; Docker conosce già i file dalla label del container.
- **`depends_on` con `service_healthy` e password di MariaDB opzionale** (oc:8711, superata): al
  primo avvio senza `wp-forestas/.env` MariaDB inizializzava l'utente senza password e ignorava il
  `.env` creato dopo (`Access denied`), scoperto provando l'`include` nello shard locale.
