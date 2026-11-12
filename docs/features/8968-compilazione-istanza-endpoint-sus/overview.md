> Ticket: oc:8968
> Stato: approvata il 2026-11-11

# La compilazione di un'istanza: la parte di forestas

La feature vive quasi tutta nel `wm-package`, dove stanno il modello `TrailApplication`, lo schema
dei campi, i service e i controller che espongono le operazioni del fascicolo. **L'overview
completa è lì:** `wm-package/docs/features/8968-compilazione-istanza-endpoint-sus/overview.md`.

Questa pagina copre la sola parte che resta nello shard, e il motivo per cui ci resta.

## Cosa cambia

Lo Sportello Unico dei Servizi della Regione Sardegna (**SUS**), realizzato da Engineering,
acquisisce tre chiamate per presentare una domanda di accatastamento: crea l'istanza con i dati
dichiarati e la traccia, carica un allegato per chiamata, chiude la compilazione. Oggi sotto
`api/v1/sus/` esistono solo `auth/login`, `auth/refresh` e `ping`.

In forestas si aggiungono le tre rotte, con il loro prefisso, i middleware e il throttle. I
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

- <a id="r1"></a>**R1** — Le tre rotte in `routes/sus.php`, dentro il gruppo autenticato che già
  esiste, puntate ai controller del package.
- <a id="r2"></a>**R2** — **Un throttle sulle tre rotte.** Senza, gli endpoint più cari che avremmo
  — upload su S3 e chiamata sincrona al servizio DEM — nascerebbero senza limite di frequenza.
- <a id="r3"></a>**R3** — I middleware restano quelli del canale: `RestrictSusClient`, appeso al
  gruppo `api` in `bootstrap/app.php`, e `LogSusRequest` per rotta.
- <a id="r4"></a>**R4** — Gli endpoint compaiono su `/docs/api/sus`, che pubblica le sole rotte con
  URI sotto `api/v1/sus/` (`AppServiceProvider.php:92`).
- <a id="r5"></a>**R5** — I test di Feature in `tests/Feature/Sus/`, accanto ai sei già presenti —
  `SusAuthTest`, `SusPingTest`, `SusClientRestrictionTest`, `SusRequestLogTest` e gli altri — con
  il servizio DEM fakato.
- <a id="r6"></a>**R6** — **Nessuna sottoclasse dei controller**, finché non serve davvero
  personalizzare qualcosa: il package li espone già pronti, e si estende solo quando il format di
  Engineering diverge dal default.

## Rischi

- **Su UAT il canale non è collaudabile finché esiste il reset notturno.** `--reset` tronca
  `trail_applications`, e la regola del repo lo dice: «finché il reset notturno esiste, un'istanza
  presentata su collaudo non sopravvive alla notte». Questo canale è fatto di chiamate distanti nel
  tempo: Engineering crea un'istanza il pomeriggio e la mattina dopo riceve 404 su un id che gli
  abbiamo dato noi. Non è codice di questo ticket — è una condizione da soddisfare prima di
  consegnare l'ambiente, e va in mano a chi rilascia.
- Gli altri rischi stanno nell'overview del package, perché riguardano il codice che vive lì.

## Out of scope

- Tutto ciò che riguarda il dominio del fascicolo: vedi l'overview del package.
- La prosa esaustiva della pagina `/docs/api/sus`: è la macro area 8 del tag 709. Qui si documenta
  ciò che si scrive.

## Moduli toccati

- `routes/sus.php` — le tre rotte nuove, con prefisso, middleware e throttle.
- `tests/Feature/Sus/` — i test dei tre endpoint.
