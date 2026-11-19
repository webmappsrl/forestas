> Ticket: oc:8997
> Stato: approvata il 2026-11-17

# Versione del formato del JSON della traccia su wmfe — parte forestas

Il meccanismo vive nel package:
[wm-package/docs/features/8997-…/overview.md](../../../wm-package/docs/features/8997-versione-del-formato-del-json-della-traccia-su-wmfe/overview.md).
Qui c'è solo ciò che riguarda questo shard.

## Cosa cambia

Forestas dichiara quali versioni del JSON della traccia scrivere su `wmfe`, ed è lo shard su cui il
meccanismo si prova per la prima volta: `legacy` (il file di oggi,
`forestasdev/tracks/769.json`) e `v1` (il contratto nuovo, `forestasdev/tracks/v1/769.json`, dove
le tassonomie hanno la sola forma `taxonomies`), scritte entrambe.

## Perché

Il package non può decidere per tutti gli shard quando smettere di scrivere il formato vecchio: il
parco app di forestas non è quello di osm2cai2, e i due si rinnoveranno in momenti diversi. La
dichiarazione sta quindi nello shard, e forestas la usa per primo.

## Punto di partenza

Forestas non ha un `config/wm-package.php` proprio: usa la configurazione del package così com'è
pubblicata. Il JSON di una traccia è raggiungibile a
`https://forestas.dev.maphub.it/wmfe/forestasdev/tracks/769.json`; lo legge il sito (`wp-forestas`,
attraverso `properties.forestas`) oltre alle app. Sulle 765 tracce del database, `taxonomy_where` è
presente su tutte e 765.

## Requisiti

- <a id="r1"></a>**R1** — Forestas scrive entrambe le versioni, `legacy` e `v1`, e i due file
  esistono su `wmfe`: il primo con le quattro forme delle tassonomie di oggi, il secondo con la sola
  `taxonomies`.
- <a id="r2"></a>**R2** — Dopo l'import e dopo il reset notturno entrambe le versioni sono presenti
  e aggiornate per tutte le tracce: il meccanismo non introduce un passaggio da lanciare a mano
  ogni notte.
- <a id="r3"></a>**R3** — Il sito `wp-forestas` continua a funzionare come oggi: legge il file
  `legacy`, dove `properties.forestas` è invariato.

## Rischi

- **Il blocco `properties.forestas` sta nel punto che il lavoro riscrive.** Lo costruisce
  `App\Models\EcTrack::getDetailGeojson()` (`app/Models/EcTrack.php:83-101`), che inietta il blocco,
  toglie `sardegnasentieri_id` e aggiunge `layer_name`; da lì passa sia il file senza versione sia
  quello nuovo. Sono 5.958 byte su 761 tracce delle 765, e li legge `wp-forestas`. Il package non
  conosce quel blocco e non può accorgersi di averlo perso: la prova che non sparisca va scritta
  qui, quindi `tests/` entra fra i moduli toccati di questo repo.
- **La dichiarazione delle versioni attive può sparire con un deploy.** `deploy_prod.sh` esegue
  `php artisan optimize` e congela la configurazione: una variabile che manca nel `.env` del server
  fa sparire la feature in silenzio, com'è già successo con `WM_TRAIL_REGISTRY_ENABLED`. Per questo
  l'assenza della dichiarazione deve lasciare attiva la versione senza numero, che è quella che
  tutti leggono oggi.
- **Il bootstrap della versione nuova dipende da una variabile per server.** Oggi il reset
  notturno delle 06:00 è acceso su entrambi i server, quindi i file della v1 nascono da soli alla
  prima notte e l'Action Nova «Update Tracks on AWS» della Resource `EcTrack` serve solo a non
  aspettarla. Chi fa il reset lo
  decide `SARDEGNASENTIERI_DAILY_RESET` nel `.env` di ciascun server (`routes/console.php:34`),
  non il nome dell'ambiente: dove venisse spento, quell'Action diventerebbe un passaggio
  obbligatorio dopo ogni deploy che introduce una versione.
- **Il reset notturno non cancella i file su S3.** `TRUNCATE ... RESTART IDENTITY CASCADE` non
  emette eventi Eloquent, quindi nessun observer cancella i file delle tracce sparite: da questo
  lavoro in poi restano orfani in due copie invece di una. Dichiarato come limite nel package.

## Out of scope

- Smettere di scrivere il `legacy` su questo shard: si decide quando i dati di accesso lo
  permetteranno.
- Il frontend: ticket a sé. `wm-core` continua a leggere il file `legacy` finché punta ad `awsApi`
  senza versione.

## Moduli toccati

Previsti: la dichiarazione delle versioni attive (una variabile d'ambiente, non il file di
configurazione pubblicato), `tests/` per la prova che `properties.forestas` resti nel file senza
versione, più il puntatore del submodule.
