> Ticket: oc:8947

# Notes — WordPress di sviluppo sullo shard dev di forestas

## Esito

Il 06/11/2026 `https://wp.forestas.dev.maphub.it` è in opera sull'host di dev:

- record `A` `wp.forestas.dev` verso lo stesso IP di `forestas.dev`, nella zona `maphub.it`
  (Hosting Solutions);
- `wordpress-forestasdev` e `mariadb-forestasdev` avviati con `scripts/wordpress-up.sh`, init
  completo, `config/` applicata in automatico, nessuna licenza;
- virtual host HTTP e HTTPS abilitati, certificato Let's Encrypt in modalità webroot (scadenza
  04/02/2027, `certbot renew --dry-run` riuscito);
- 44 pagine, 99 news, 14 eventi, 121 «da sapere» importati dalla copia Drupal di UAT attraverso il
  tunnel; `applica --conferma` sui menu; `allinea` chiude con `STATO allineato`;
- cron di `allinea` alle 05:00 UTC nel crontab di `root` (backup prima dell'aggiunta in
  `/root/crontab-backup-20261106131734`, prima della correzione del commento in
  `/root/crontab-backup-20261106133433`);
- `/wp-admin` accetta l'amministratore del `.env` (provato con `wp_authenticate()`);
- review finale: nessun problema grave, 2 importanti e 11 minori, tutti nella documentazione e tutti
  corretti (Decisioni e Divergenze, Task 7).

In `wp-forestas` il lavoro è su `develop`: commit `7b6fe2e` (8947), `60b65e6` (8760) e `d384dce`
(8783) sul branch del ticket, PR #17 chiusa con squash and merge il 06/11 → `b84a917`. Resta il giro
del puntatore di `wp-forestas` in `forestas`, da `b160727` a `b84a917` (Task 8 del piano, commit del
dev): fino ad allora sull'host i virtual host sono copie di quelli in `wp-forestas/docker/dev/`.

## Divergenze dal piano, task per task

### Task 1: virtual host e stato del cron

Oltre alla riga `STATO` di `cron/allinea.crontab`, ho aggiornato lo stato del cron anche nella
trappola di `wp-forestas/CLAUDE.md` e in `wp-forestas/docs/howto/cron-sincronizzazione-pagine.md`
(§3 e «Va messo solo dove…»): dicevano «non installata su nessun server», falso dal 02/11, quando
il cron è stato installato su UAT. La riga di dev nel crontab versionato è commentata: il file ha
una sola riga attiva, quella di UAT.

### Task 3: `.env`, zip e primo avvio

Le password del `.env` sono state generate con `openssl rand -hex 24` e non con `-base64 24` come
diceva il piano: l'esadecimale non contiene `/`, `+` e `=`, che rompono il `sed` usato per scriverle.

### Task 7: documentazione

Oltre alla procedura per dev, alla pagina di conoscenza e al `CLAUDE.md`, ho corretto tre pagine che
questo lavoro rendeva false (review finale): in `docs/knowledge/wordpress-nello-shard.md` il cron
«non ancora installato», in `docs/howto/copia-database-drupal.md` il `.env` di Laravel come «unico
posto» che punta al tunnel (ora c'è anche `wp-forestas/.env` di dev), in
`docs/howto/messa-in-opera-wordpress-uat.md` «il cron quando ci sarà». In `wp-forestas`: riga
`docker/dev/` nella tabella del README, e lo stato del cron lasciato solo in `cron/allinea.crontab`
(il `CLAUDE.md` e la procedura del cron ora rimandano lì, invece di ripeterlo).

## Bug trovati

- **Verifica dell'accesso:** `/wp-admin` provato con `wp_authenticate()` nel container, con le
  credenziali del `.env` senza stamparle: `webmapp`, ruolo `administrator`.
- **Due media mancanti alla fonte:** la galleria della news 4606 (it ed en) punta a
  `Panorama lungo il sentiero 410 - Armungia.jpg`, che dà 404 anche su `www.sardegnasentieri.it`.
  Non è un problema di dev: ogni giro lo segnala come `MANCANTE`.

## Decisioni

- **Tag Orchestrator:** nessuno associato, per scelta del dev (06/11). I candidati trovati erano
  `forestas` (676) e `Wordpress Impreza` (298).
- **Nessuna licenza su dev** (06/11): niente chiavi di Impreza e WPML nel `.env` di dev, per non
  registrare un terzo dominio presso UpSolution e WPML. Da rivedere se emergono limiti.
- **Cron di `allinea` anche su dev** (06/11), alle 05:00 UTC (challenge): di giorno su dev gli
  import si lanciano a mano, e con un giro a mano in corso quello del cron esce e chiude non
  allineato. Nella challenge il motivo dato al dev era «`bin/wordpress-config.sh` non ha un lock»:
  **era falso**, il lock c'è (`wpf_blocca_pagine()` e `wpf_blocca_apply()` in
  `wp-forestas/docker/scripts/config/comune.php`), lo avevo cercato solo nello script bash. L'ha
  trovato la review finale; l'orario resta valido col motivo corretto, che ora è in tutta la
  documentazione.
- **Puntatore di `wp-forestas`** (06/11, challenge): i virtual host versionati arrivano sull'host
  solo dopo l'aggiornamento del puntatore in `forestas`, commit a parte del dev.
- **Virtual host versionati** in `wp-forestas/docker/dev/` (06/11); i file si scrivono in locale e
  il push lo fa il dev, mai dall'host.
- **Sito di dev aperto, senza basic auth** (06/11, challenge): senza licenza Impreza non c'è la
  modalità manutenzione che su UAT nasconde il sito; il dev ha scelto di lasciarlo raggiungibile.
- **DNS dal pannello di Hosting Solutions** (06/11), dal browser del dev, con conferma prima del
  salvataggio.
- **Amministratore, email e titolo come su UAT** (`webmapp`, `team@webmapp.it`, `Forestas`);
  password generate sull'host, solo nel `.env`.
- **PHPStan non eseguito** (06/11, confermato dal dev, sua responsabilità): il diff di oc:8947 in
  `forestas` non contiene file PHP, e la CI stessa (`.github/workflows/phpstan.yml`, `paths: **.php`)
  non lo esegue su una PR così; in locale non era eseguibile (Docker spento, PHP 7.4).
- **Stima** 5h (Misurato 1,3h + Stimato 3,7h), valore del dev sulla proposta di 4h dell'agente.

## Info per altri ticket

- **Host di dev:** le verifiche del DNS conviene farle dal Mac: dal server alcuni servizi esterni
  non si raggiungono, e l'host non risponde al ping (HTTP, HTTPS e SSH sì).
- **Host di dev:** nel crontab di `root` e fra i certificati ci sono avanzi di un progetto che su
  quella macchina non c'è più. Non toccati.
- **Host di dev:** `scripts/wordpress-up.sh` segnala come orfani `minio-forestasdev` e
  `mailpit-forestasdev` e ha creato il volume vuoto `forestas_sardegnasentieri-pgdata` dichiarato
  nel compose. Non toccati.
- **Copia Drupal dal container WordPress:** `mysqli` (quello dell'import) la legge; il client
  `mariadb` a riga di comando rifiuta il certificato TLS della copia, per prove a mano serve
  `--skip-ssl`.
- **Rinnovo dei certificati webroot:** nessun hook ricarica Apache dopo `certbot renew`; lo fa il
  `postrotate` di `/etc/logrotate.d/apache2` ogni giorno, come per gli altri siti.
- **Resolver del Mac:** dopo un `dig` fatto prima della creazione del record, la risposta negativa
  resta in cache per un po'; per le prove serve `curl --resolve`.

## Review interna (06/11, altra sessione)

Nessun bloccante, cinque correzioni fatte:

- **IP del server tolto dai file versionati** (i due repo sono pubblici e su `main` non ce n'è
  nessuno): la procedura di dev, l'overview, il piano e queste note dicono «lo stesso IP di
  `forestas.dev.maphub.it`»; ridotte le «Info per altri ticket» sull'infrastruttura dell'host.
- **Modifiche di altri ticket in `wp-forestas`** (`8760…/notes.md`, `8783…/decisioni.md`): nei
  commit si aggiungono i file uno per uno, mai `git add -A`. Su indicazione del dev le due modifiche
  sono andate sul branch di 8947, ciascuna in un commit col proprio ticket (`docs(oc:8760)`,
  `docs(oc:8783)`), invece che su due branch solo per la documentazione; la nota di 8760 dice ora
  che due dei suoi tre punti li ha chiusi oc:8947. Con lo squash della PR #17 su `develop` sono
  nel commit `b84a917`, col titolo di 8947 e le loro voci nel corpo.
- **Overview allineate:** in quella di `forestas` il rischio delle due copie dei virtual host e
  l'out of scope su init, `bin/` e child theme, che c'erano solo in quella di `wp-forestas`.
- **Cartella dei comandi** spostata in testa alla procedura di dev, nella tabella dei valori.
- **Righe oltre 100 caratteri** riallineate nei paragrafi toccati di
  `docs/howto/messa-in-opera-wordpress-uat.md` e `docs/knowledge/wordpress-nello-shard.md`, e in
  tre paragrafi di `docs/howto/messa-in-opera-wordpress-dev.md` (restano solo le righe delle
  tabelle).

## Follow-up

- Puntatore di `wp-forestas` in `forestas` da `b160727` a `b84a917` (commit a parte del dev), merge
  su `develop` di `forestas`, poi aggiornamento di dev e `scripts/wordpress-up.sh` (Task 8).
- Titolo e introduzione di `wp-forestas/docs/howto/cron-sincronizzazione-pagine.md` estesi a eventi
  e «da sapere» (punto rimasto della nota di oc:8760).
