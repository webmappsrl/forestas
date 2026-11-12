> Ticket: oc:8968
> Stato: chiusa il 2026-11-12 · approvata il 2026-11-11

# La compilazione di un'istanza: la parte di forestas

La feature vive quasi tutta nel `wm-package`, dove stanno il modello `TrailApplication`, lo schema
dei campi, i service e i controller che espongono le operazioni del fascicolo. **L'overview
completa è lì:** `wm-package/docs/features/8968-compilazione-istanza-endpoint-sus/overview.md`.

Questa pagina copre la sola parte che resta nello shard, e il motivo per cui ci resta.

## Cosa cambia

Lo Sportello Unico dei Servizi della Regione Sardegna (**SUS**), realizzato da Engineering,
acquisisce quattro chiamate per presentare una domanda di accatastamento e rileggerla: crea
l'istanza con i dati dichiarati e la traccia, carica un allegato per chiamata, chiude la
compilazione, rilegge lo stato della domanda. Prima di
questo lavoro sotto `api/v1/sus/` esistevano solo `auth/login`, `auth/refresh` e `ping`.

In forestas si aggiungono le quattro rotte, con il loro prefisso, i middleware e il throttle. I
controller che le servono stanno nel package.

## Perché

Perché il confine passa di qui: **il dominio sta nel package, il canale verso lo sportello sta
nello shard.** Creare un fascicolo, aggiungergli un allegato e chiuderlo quando è completo sono
operazioni del Catasto Sentieri, e varranno uguali per qualunque ente monterà il package. Il nome
«SUS», il prefisso `api/v1/sus`, il client dedicato e i suoi middleware sono invece di questo
cliente: un altro shard avrà un altro sportello.

Per la stessa ragione nel package il nome dello sportello non compare mai, come già dichiara la
migration di oc:8960: «mai il nome dello sportello: questo codice servirà anche Lombardia e
Toscana». È `routes/sus.php` di forestas a dare al canale il nome del cliente.

## Punto di partenza

- In `routes/sus.php` il limite di frequenza (`throttle:100,1`) sta solo sul gruppo `auth`; il
  gruppo autenticato non ne ha.

## Requisiti

- <a id="r1"></a>**R1** — Le quattro rotte — creazione, lettura, upload di un allegato, conferma —
  in `routes/sus.php`, dentro il gruppo autenticato che già esiste, puntate ai controller del
  package.
  - Verifica: [SusApplicationRoutesTest.php](../../../tests/Feature/Sus/SusApplicationRoutesTest.php) › `espone le quattro rotte sotto il prefisso del canale`
- <a id="r2"></a>**R2** — **Un throttle sulle quattro rotte**, con un conteggio unico per utente.
  Senza, gli endpoint più cari che avremmo — upload su S3 e chiamata sincrona al servizio DEM —
  nascerebbero senza limite di frequenza.
  - Verifica: [SusApplicationRoutesTest.php](../../../tests/Feature/Sus/SusApplicationRoutesTest.php) › `limita la frequenza delle chiamate su tutte e quattro le rotte`
- <a id="r3"></a>**R3** — I middleware sono quelli del canale — `RestrictSusClient`, appeso al
  gruppo `api` in `bootstrap/app.php`, e `LogSusRequest` per rotta — più il ruolo `Sus` su tutto il
  gruppo autenticato, `ping` e `refresh` compresi: il canale è riservato al suo client.
  - Verifica: [SusApplicationRoutesTest.php](../../../tests/Feature/Sus/SusApplicationRoutesTest.php) › `riserva al client anche ping e refresh`
- <a id="r4"></a>**R4** — Gli endpoint compaiono su `/docs/api/sus`, che pubblica le sole rotte con
  URI sotto `api/v1/sus/` (`AppServiceProvider.php:92`).
  - Verifica: [AppServiceProvider.php](../../../app/Providers/AppServiceProvider.php) › `str_starts_with($route->uri(), 'api/v1/sus/')`
- <a id="r5"></a>**R5** — I test di Feature in `tests/Feature/Sus/`, accanto ai sei già presenti —
  `SusAuthTest`, `SusPingTest`, `SusClientRestrictionTest`, `SusRequestLogTest` e gli altri — con
  il servizio DEM fakato.
  - Verifica: [SusApplicationRoutesTest.php](../../../tests/Feature/Sus/SusApplicationRoutesTest.php) › `lascia passare il client del canale fino alla validazione`
- <a id="r6"></a>**R6** — **Nessuna sottoclasse dei controller**, finché non serve davvero
  personalizzare qualcosa: il package li espone già pronti, e si estende solo quando il format di
  Engineering diverge dal default.
  - Verifica: [routes/sus.php](../../../routes/sus.php) › `use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\StoreApplicationController;`
- <a id="r7"></a>**R7** — Sotto `api/v1/sus/` chi non è il client del canale riceve la stessa
  risposta su ogni indirizzo, `{error}` con il messaggio «Accesso non consentito: serve un token
  valido del client dello sportello.»: `401` senza un token valido, `403` con il token di un altro
  utente. Il `404` risponde `{error}` senza nominare classi interne, e una domanda d'ufficio e un id
  inesistente ricevono lo stesso corpo.
  - Verifica: [SusApplicationRoutesTest.php](../../../tests/Feature/Sus/SusApplicationRoutesTest.php) › `da a chi non e il client la stessa risposta su una domanda che esiste e su una che non esiste`

## Rischi

- Il reset notturno di UAT, che tronca `trail_applications`, non è un rischio: è il comportamento
  del collaudo, documentato nella pagina delle API ([config/scramble.php](../../../config/scramble.php)).
- Gli altri rischi stanno nell'overview del package, perché riguardano il codice che vive lì.

## Out of scope

- Tutto ciò che riguarda il dominio del fascicolo: vedi l'overview del package.
- La prosa esaustiva della pagina `/docs/api/sus`: è la macro area 8 del tag 709. Qui si documenta
  ciò che si scrive.

## Moduli toccati

Ricavati da git sul branch, esclusa la cartella della feature.

- **Rotte e canale** — `routes/sus.php`, `bootstrap/app.php`, `app/Http/Middleware/LogSusRequest.php`,
  `app/Http/Controllers/Api/Sus/SusPingController.php`.
- **Documentazione dell'API** — `app/Providers/AppServiceProvider.php`, `config/scramble.php`,
  `resources/views/vendor/scramble/docs.blade.php`.
- **Traduzioni** — `lang/en.json`, `lang/it.json`, `lang/it/validation.php`.
- **Test** — `tests/Feature/Sus/SusApplicationRoutesTest.php`,
  `tests/Feature/Sus/SusRequestLogTest.php`.
- **Documentazione** — `docs/knowledge/client-sus.md`.
- **Altro** — `CLAUDE.md` e il puntatore del submodule `wm-package`.
