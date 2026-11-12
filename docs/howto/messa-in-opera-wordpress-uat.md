# Mettere in opera WordPress su UAT

> Ticket: oc:8711, oc:8717, oc:8757, oc:8758, oc:8760. Cos'è e come funziona l'ambiente: README di
> [`wp-forestas`](https://github.com/webmappsrl/wp-forestas).

Questa pagina copre ciò che riguarda l'host e lo shard (DNS, `.env`, Apache, certificato). Zip
commerciali, chiavi di licenza e configurazione del sito sono di `wp-forestas`: li descrive il
[README di `wp-forestas`, «Messa in opera su UAT»](https://github.com/webmappsrl/wp-forestas#messa-in-opera-su-uat).
Esistono solo da quando il puntatore di `wp-forestas` include oc:8717: con un puntatore precedente
`bin/wordpress-config.sh` e `config/` non ci sono, e i passi che li citano non si applicano.

WordPress gira sull'host di UAT come parte dello shard, nei container `wordpress-forestasuat` e
`mariadb-forestasuat`, ed è pubblicato su `https://wp.forestas.uat.maphub.it` dall'Apache
dell'host. Questi passi si fanno **una volta sola**, a mano, dal team.

## 1. DNS

Record `A` di `wp.forestas.uat.maphub.it` verso lo stesso IP di `forestas.uat.maphub.it`.
Verifica: `dig +short wp.forestas.uat.maphub.it` restituisce l'IP dell'host.

## 2. Codice e configurazione

> Finché `wp-forestas/.env` non esiste, `wordpress-forestasuat` e `mariadb-forestasuat` si
> fermano subito con un messaggio nei log e Docker li riavvia a intervalli crescenti: il resto
> dello shard funziona normalmente. MariaDB, senza password, non inizializza il suo volume, quindi
> creare il `.env` dopo il primo aggiornamento non lascia nulla da sistemare.

Sull'host, in `/var/www/html/forestas`, dopo l'aggiornamento abituale (che fa già
`git submodule update --init --recursive`):

```bash
ls wp-forestas/compose.yml                       # il submodule c'è
cp wp-forestas/.env-example wp-forestas/.env     # poi compila:
#   WP_PORT=8090        (verifica che sia libera: ss -ltn | grep :8090 non deve stampare nulla)
#   WP_URL=https://wp.forestas.uat.maphub.it
#   password del database e dell'amministratore: valori robusti, solo in questo file
```

Prima del primo avvio copia gli zip commerciali e aggiungi le chiavi di licenza al
`wp-forestas/.env`, come dal [README di `wp-forestas`, «Messa in opera su UAT»](https://github.com/webmappsrl/wp-forestas#messa-in-opera-su-uat).

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
- Aspetto → Temi mostra `forestas-child` attivo, con Impreza come tema padre; con le chiavi nel
  `.env` la licenza di Impreza e la registrazione di WPML si riattivano da sole.
- Il sito mostra header e footer di Sardegna Sentieri (logo, blocco FESR, IT EN), non quelli demo di
  Impreza, anche in inglese sotto `/en/`: arrivano con l'apply automatico della configurazione. Se
  manca, nei log di `wordpress-forestasuat` c'è il motivo.

## Aggiornamenti successivi

Nella procedura abituale di aggiornamento di UAT, **dopo** `git submodule update` e i passi nel
container, lancia sull'host:

```bash
scripts/wordpress-up.sh
```

Senza questo passo i container di WordPress restano quelli vecchi, e un errore nel compose incluso
emergerebbe solo al primo riavvio della macchina, bloccando l'intero shard.

Se il nuovo puntatore porta una configurazione del sito diversa (`wp-forestas/config/`), su un sito
esistente non si applica da sola: `wp-forestas/bin/wordpress-config.sh applica` mostra le differenze,
`applica --conferma` le applica dopo un backup. Dettagli nel [README di `wp-forestas`, «Messa in opera su UAT»](https://github.com/webmappsrl/wp-forestas#messa-in-opera-su-uat).

Child theme e configurazione si rilasciano insieme: il child cambia con il puntatore, header e footer
solo con `applica --conferma`, da lanciare subito dopo. Con uno solo dei due il sito mostra header e
footer demo, o gli shortcode del child come testo. Conserva il percorso del backup creato dal primo
`applica --conferma` del rilascio (`wp-forestas/backup/<data-ora>`): è quello che serve per tornare
indietro, perché ogni apply successivo crea un backup nuovo che contiene già le modifiche. Rilascio e rollback a passi nella
[pagina di conoscenza 8757 di `wp-forestas`](../../wp-forestas/docs/knowledge/8757-header-e-footer-di-wordpress-forestas-secondo-il-mockup.md),
«Come funziona oggi».

Le pagine statiche (oc:8758), le news (oc:8759), gli eventi e i «da sapere» (oc:8760) importati da
Drupal si scrivono con un comando a parte, dopo `applica --conferma`: `wp-forestas/bin/wordpress-config.sh pagine`, poi `pagine --conferma` (il primo
giro con le news dura una ventina di minuti: scarica dal sito Drupal circa 300 file), poi di
nuovo `applica --conferma`, che collega alle pagine appena create le voci di menu. Al primo
rilascio servono nel `wp-forestas/.env` `DRUPAL_DB_USER`, `DRUPAL_DB_PASSWORD` e `DRUPAL_SYNC=on`, poi
`scripts/wordpress-up.sh`; passi e avvisi nel README di `wp-forestas`, «Messa in opera su UAT». Gli
ultimi tre passi (pagine, anteprima, menu) li fa da solo `wp-forestas/bin/wordpress-config.sh allinea`,
il comando da mettere nel cron, ma solo su un sito con `config/` già applicata: il primo
`applica --conferma` di un rilascio resta a mano, e `allinea` lo segnala con `STATO non allineato`. La
riga del cron è in `wp-forestas/cron/allinea.crontab`, la procedura in
`wp-forestas/docs/howto/cron-sincronizzazione-pagine.md`.

### Ricerca unica e plugin `wp-package` (oc:8952)

Al rilascio che porta la ricerca:

1. Nel `wp-forestas/.env`, `WP_PACKAGE_SHARD` e `WP_PACKAGE_APP_ID` dello shard di UAT
   (`WP_PACKAGE_DIR` resta vuoto: serve solo in locale), poi `scripts/wordpress-up.sh`. Nei log
   dell'init: «scarico wp-package al commit …», «attivo il plugin wp-package», «imposto wmwp_shard
   dal .env», «imposto wmwp_app_id dal .env». Finché il repo GitHub del plugin non esiste c'è solo
   «AVVISO: wp-package non ancora pubblicato, nessun download», e `/cerca-nel-sito` mostra un campo di
   ricerca semplice.
2. `applica --conferma` (crea il template `template-pagina-ricerca`), poi `pagine --conferma` (crea
   `/cerca-nel-sito` in italiano e in inglese), come per ogni rilascio di `config/`.
3. Il vecchio plugin `wp-geohub` (cartella `wm-package`) resta installato: l'init non disinstalla niente.
   Si toglie a mano:

   ```bash
   docker exec -u www-data wordpress-<APP_NAME> wp --path=/var/www/html plugin deactivate wm-package
   docker exec -u www-data wordpress-<APP_NAME> wp --path=/var/www/html plugin delete wm-package
   ```

4. Verifica: `https://wp.forestas.uat.maphub.it/cerca-nel-sito/?search_api_fulltext=perda+liana` ha i
   tab «Percorsi» e «Contenuti del sito» con i numeri, e la ricerca dell'header porta lì.

Un aggiornamento del plugin arriva con il nuovo hash in `WP_PACKAGE_REF` (`init-wordpress.sh`) e
`scripts/wordpress-up.sh`: l'init lo riscarica perché il commit è diverso da quello in `.wpf-ref`.

**Tornare indietro** a un puntatore senza la ricerca: `pagine` non cancella le pagine tolte da
`config/pagine.json`, quindi `/cerca-nel-sito` resterebbe e mostrerebbe lo shortcode `[forestas_search]`
come testo. Si cancella la pagina nelle due lingue dal pannello (Pagine, cestino, «Elimina
definitivamente»: da WP-CLI WPML non toglie la riga di traduzione), poi `applica --conferma` con il
`config/` del puntatore vecchio. Il campo «Cerca nel sito» dell'header manda sempre a `?s=` (il `config/post.json`
di oc:8952 aggiunge solo il template della pagina di ricerca): è il 302 del child nuovo, con il plugin attivo, a
portarlo a `/cerca-nel-sito`; con il child vecchio resta la ricerca di WordPress.

## Azzerare WordPress

```bash
scripts/wordpress-reset.sh --conferma
```

Cancella database e file di WordPress e lo ricrea da zero; non tocca gli altri volumi. Cosa ha il
sito ricreato e cosa si perde: [WordPress nello shard](../knowledge/wordpress-nello-shard.md),
«Azzerare». Su UAT servono gli zip commerciali e le chiavi nel `wp-forestas/.env` (messa in opera
nel README di `wp-forestas`). Dopo il reset l'init rimette configurazione, header, footer e menu,
non le pagine statiche di oc:8758, le news di oc:8759 né eventi e «da sapere» di oc:8760: le crea
`wp-forestas/bin/wordpress-config.sh allinea` (a mano, o il cron di
`wp-forestas/cron/allinea.crontab`), che collega anche i menu. Oggi il reset si lancia solo a mano:
il ciclo giornaliero su UAT non è ancora attivo. **In produzione non va usato**.
