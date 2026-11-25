> Ticket: oc:9039
> Stato: chiusa il 2026-11-25 · approvata il 2026-11-25

# Soggetti dell'istanza — la parte di Forestas

Il lavoro vive nel package: la descrizione completa è in
[wm-package/docs/features/9039-soggetti-dell-istanza/overview.md](../../../wm-package/docs/features/9039-soggetti-dell-istanza/overview.md).
Qui resta solo ciò che è di questo shard.

## Cosa cambia

Niente nel codice di forestas, salvo un test: cambia la **pagina delle API del canale SUS**, la cui
prosa vive qui e non nel package.

Dove la pagina elencava i valori ammessi di tre select di categoria, ora descrive quattro
liste di soggetti — `managing_subjects`, `surveying_subjects`, `maintaining_subjects`,
`keeping_subjects` — ciascuna di oggetti con `name`, `email` e `subject` (`organization` o
`person`).

## Perché

`config/scramble.php` di questo shard contiene per esteso le sezioni dei campi dell'istanza, e
`resources/views/vendor/scramble/docs.blade.php` contiene il modulo precompilato del bottone di
prova. Gli attributi `#[BodyParameter]` sui controller stanno invece nel package: la pagina si
compone dalle due parti. Un campo nuovo nel payload che non venga descritto qui resta invisibile a
Engineering, che implementa la maschera del SUS leggendo quella pagina — e un esempio rimasto alla
forma vecchia consegna a Engineering una chiamata che il canale rifiuta con `422`.

## Punto di partenza

Letto sul codice il 25/11/2026. In `config/scramble.php`: le tre tabelle dei valori ammessi dei
campi 47, 49 e 53 (da riga 279), la tabella «Se… allora sono obbligatori» che elenca i quattro campi
condizionati (da riga 345) e l'esempio `curl` della pagina (da riga 380).

In `resources/views/vendor/scramble/docs.blade.php`: la costante `SAMPLE` (da riga 147), il modulo
precompilato del bottone di prova, che contiene gli stessi sei campi del `curl`.

In `tests/Feature/Sus/SusRequestLogTest.php`: `municipality_email` è usato in tre righe (67, 71, 72)
come campo d'errore di esempio. Il campo sparisce, il test va riscritto su un campo che resta.

**Omonimia da non confondere.** `app/Services/Import/SardegnaSentieriImportService.php` e
`app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` usano le chiavi `soggetto_gestore`,
`soggetto_manutentore` e `soggetto_rilevatore`: sono i nomi dei campi di **Drupal** dentro
`ente_istituzione_societa`, mappati sui ruoli degli enti dei POI. Non hanno nulla a che vedere con
l'istanza del SUS e **non vanno toccate**.

## Requisiti

- <a id="r1"></a>**R1** — La pagina delle API descrive le quattro liste di soggetti: la forma
  dell'oggetto, quanti soggetti servono per ruolo, il significato di `organization` e `person`, la
  regola della e-mail (obbligatoria e valida per un ente, facoltativa per una persona) e il fatto
  che la posizione citata in un errore parte da zero. L'esempio `curl` e il modulo precompilato del
  bottone di prova restano chiamate che il canale accetta.
  - Verifica: [tests/Feature/Sus/SusApiDocumentationTest.php](../../../tests/Feature/Sus/SusApiDocumentationTest.php) › `spiega le quattro liste di soggetti, custode compreso` e `pubblica due esempi identici, e che il canale accetterebbe`
- <a id="r2"></a>**R2** — Nessun riferimento ai campi spariti resta nella pagina: le tre tabelle dei
  valori dei campi 47, 49 e 53 e le righe dei campi condizionati che li nominano.
  - Verifica: [tests/Feature/Sus/SusApiDocumentationTest.php](../../../tests/Feature/Sus/SusApiDocumentationTest.php) › `non nomina piu i campi delle categorie e dei comuni`

- <a id="r3"></a>**R3** — La pagina avverte che un campo o un valore non concordato viene rifiutato
  con `422`, elenca i dieci tematismi e dichiara i limiti di lunghezza dei testi; e il documento
  OpenAPI pubblica la forma vera dei campi strutturati, non una lista di stringhe.
  *Fonte: [R8](../../../wm-package/docs/features/9039-soggetti-dell-istanza/overview.md#r8) del
  package, deciso dal dev il 25/11/2026, e la review finale.*
  - Verifica: [tests/Feature/Sus/SusApiDocumentationTest.php](../../../tests/Feature/Sus/SusApiDocumentationTest.php) › `avverte che un campo o un valore non concordato viene rifiutato`, `elenca i dieci tematismi concordati`, `pubblica la forma vera degli elementi di una lista di soggetti` e `non lascia il corpo della creazione come allOf`

Gli altri requisiti del lavoro sono nel package: vedi
[la sua overview](../../../wm-package/docs/features/9039-soggetti-dell-istanza/overview.md#requisiti).

## Rischi

**La pagina si compone da due repo.** Aggiornare solo gli attributi `#[BodyParameter]` del package
lascia la prosa di questo shard a descrivere la forma vecchia, e viceversa: la contraddizione non la
segnala nessun test.

**L'esempio e il modulo di prova sono due copie dello stesso payload.** Il `curl` in
`config/scramble.php` e la costante `SAMPLE` in `docs.blade.php` contengono gli stessi campi:
aggiornarne uno solo lascia il bottone di prova a mandare una chiamata rifiutata.

## Out of scope

Tutto ciò che è elencato come fuori perimetro nell'overview del package, in particolare la
leggibilità di `api_data` in Nova e il segnaposto di privacy per i soggetti `person`.

**L'import da Sardegna Sentieri**, che usa gli stessi nomi per campi di Drupal che non c'entrano.

## Moduli toccati

- `config/scramble.php` — la sezione dei soggetti, quella dei campi e valori concordati, la
  tabella dei dieci tematismi, i campi condizionati, l'esempio `curl`
- `resources/views/vendor/scramble/docs.blade.php` — la costante `SAMPLE` del bottone di prova
- `app/Providers/AppServiceProvider.php` — il transformer di Scramble fonde lo schema ricavato
  dalle regole con quello degli attributi, invece di scartare il primo
- `app/Http/Middleware/LogSusRequest.php` — il commento sui dati che il log non deve registrare
- `tests/Feature/Sus/SusApiDocumentationTest.php` — sei test nuovi sulla pagina e sul documento
  OpenAPI
- `tests/Feature/Sus/SusRequestLogTest.php` — non usa più un campo sparito come esempio d'errore
