> Ticket: oc:8981

# Notes — Avvisi dei sentieri

## Divergenze dal piano, task per task

### Task 2: upload Excel

L'upload Excel in forestas oggi **non scrive** `not_accessible` né `not_accessible_message`: i
`validHeaders` di default (`wm-package/config/wm-excel-ec-import.php:14-28`) non li contengono, e
`EcTrackRowProcessor` scarta le colonne fuori elenco. In challenge il rischio era stato portato come
REALE avendo verificato solo l'export; è in realtà ipotetico. La chiamata alla regola della data è
stata tenuta lo stesso: copre gli shard che aggiungono quelle colonne alla configurazione, e il test
le dichiara via config.

### Task 5: API pubblica degli avvisi

Il filtro sulla presenza della chiave usa `jsonb_exists(properties, 'not_accessible')` invece
dell'operatore `?` previsto dal piano: con PDO il `?` viene letto come segnaposto e la query fallisce
con un 500.

### Fix wave della review finale e cleanup della review del ticket

Modifiche arrivate dopo le review, non scritte nel piano:

- `NoticeController` toglie dal messaggio le lingue vuote o `null` e fa il trim del testo:
  `NovaTabTranslatable` salva `null` per ogni lingua lasciata vuota, e il sito avrebbe ricevuto
  `{"it": "…", "en": null}`.
- A parità di `published_at`, o senza data, l'ordine è per `track_id` decrescente: senza, l'ordine
  dipendeva dal database.
- Il filtro sul flag acceso si fa in SQL (`lower(properties->>'not_accessible') in ('true','1')`) e
  non più sulla sola presenza della chiave; il filtro `deleted_from_source` è condiviso con
  `EnteController` invece di essere copiato.
- In Nova la data si mostra nel fuso Europe/Rome, formato `d/m/Y H:i`; in `properties` e
  nell'API resta in UTC (`+00:00`).
- L'help del messaggio dice «Senza messaggio in italiano…», perché l'API scarta anche i messaggi
  solo in inglese.
- `phpstan-baseline.neon`: il conteggio dell'errore `Field::make()` con array in
  `app/Nova/EcTrack.php` passa da 1 a 2. È lo stesso pattern `NovaTabTranslatable::make([...])`
  già presente nel file.

## Bug trovati

- Nel Task 3 il campo della data era stato messo dentro `NovaTabTranslatable`, che lo avrebbe reso
  traducibile: corretto prima della review.

## Decisioni

- Tag: saltati su richiesta del dev (17/11).
- Stima: saltata su richiesta del dev (17/11); la proposta di `wm-estimate` era
  0,83h misurate + 2,75h stimate = 3,58h, non scritta su Orchestrator.
- API `/api/v1/avvisi` in forestas e non nel wm-package (dev, 17/11), anche se allo scrum del 16/11
  si era detto wm-package.
- API pubblica, senza `VerifyWpApiToken` né throttle, come `api/ec/track` (dev, 17/11, dopo la
  prima approvazione dell'overview).
- La data segue anche le correzioni del testo italiano (dev, 17/11): una correzione riporta
  l'avviso in cima.
- Prova in locale (dev, 17/11): il flag si chiama «Pubblica avviso» e non «Avviso», che è già
  l'Heading del blocco; i tre campi, compreso il contenitore `NovaTabTranslatable`, sono nascosti
  dall'index delle tracce.
- Nel wm-package la data resta legata alla non accessibilità (dev, 17/11, dopo l'apertura delle
  PR): label «Data di non accessibilità» (en «Not Accessible Updated At») invece di «Data
  dell'avviso», commenti senza «avviso», test rinominato in
  `EcTrackFromSpreadsheetNotAccessibleUpdatedAtTest`, pagina in
  `wm-package/docs/knowledge/8981-data-di-non-accessibilita-su-ectrack.md`. Il significato di
  «avviso» è solo di Forestas.

## Follow-up

- In locale PHPStan richiede `JWT_SECRET` nel `.env` (come spiega il workflow `phpstan.yml`) e
  `--memory-limit=2G`: senza il primo segnala 11 errori finti su `JWTAuth::fromUser` nei test Sus.
- Il disco `wmfe` non ha `throw` attivo: con minio pieno `UpdateEcTrackAwsJob` risulta `DONE` ma
  non scrive il file, senza nessun errore. Successo in locale il 17/11.
- La suite completa del wm-package non si carica dal container: 96 file di test usano
  `use Tests\TestCase;` mentre l'autoload mappa solo `Wm\WmPackage\Tests\`. Preesistente, non di
  questo ticket.
- L'import da GeoHub del package non scrive la data e con `fill()` la cancella: limite dichiarato,
  Forestas non lo usa.
