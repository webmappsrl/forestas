---
paths:
  - "app/Services/Import/**"
  - "app/Console/Commands/**"
  - "app/Jobs/Import/**"
  - "app/Http/Clients/**"
  - "routes/console.php"
---

# Trappole: import da Sardegna Sentieri

Forestas usa un import custom da Sardegna Sentieri (piattaforma Drupal), **non** l'import standard
da GeoHub. Comando `sardegnasentieri:import`, service
`app/Services/Import/SardegnaSentieriImportService.php`, client
`app/Http/Clients/SardegnaSentieriClient.php`, job in `app/Jobs/Import/`.

- **Il flusso GeoHub del package (`ImportTaxonomyActivityJob`, `ImportTaxonomyJob`,
  `wm-geohub-import.php`) non è usato qui**: indagando un bug su tassonomie, POI o track si perde
  tempo a leggerlo — la causa sta in `SardegnaSentieriImportService` (oc:8489)
- **`--reset` fa `TRUNCATE ... RESTART IDENTITY CASCADE` globale** su `ec_tracks`, `ec_pois` e le
  tassonomie, non una cancellazione selettiva per `app_id`: su Forestas è innocuo perché l'app è
  una sola (`SardegnaSentieriImportService::IMPORT_APP_ID` = 1), diventerebbe un problema con più
  app nello stesso database (oc:8489)
- **Il troncamento notturno porta via anche ciò che non viene da Drupal**, per vincolo a cascata: la
  storia dei cambi di stato dei codici e le istanze di accatastamento. Con `trail_applications` non
  più vuota, un'istanza presentata su collaudo si ritrova cancellata la notte dopo — a quel punto la
  sincronizzazione va cambiata in rimozione dei soli tracciati spariti alla fonte (oc:8489)
- **Chi fa il troncamento notturno lo decide `SARDEGNASENTIERI_DAILY_RESET`, non `APP_ENV`**: i
  server si chiamano `develop` e `production`, con il collaudo che gira come `production` perché una
  produzione vera non esiste ancora. Legarlo ai nomi degli ambienti farebbe cancellare i dati ogni
  notte alla produzione vera il giorno che nascerà (oc:8489)
- **L'import orario non cancella**: marca i record spariti alla fonte con
  `properties->forestas->deleted_from_source = true`. È il motivo per cui serviva comunque un
  troncamento per avere lo specchio esatto di Drupal, e anche la strada per farne a meno (oc:8489)
- **L'import scrive il codice del sentiero in `properties['ref']`** e il catasto lo legge da lì: il
  service non è parametrizzato, cambiare quella chiave richiede di aggiornare
  `WM_TRAIL_LEGACY_CODE_PROPERTY` — vedi [docs/knowledge/catasto-sentieri.md](../../docs/knowledge/catasto-sentieri.md) (oc:8489)
