> Ticket: oc:8333

# Notes — Branch API SUS con autenticazione dedicata

## Deviazioni dal piano

**Task 1, Step 7 (env var nello step CI): non eseguito.** Il piano prevedeva di
replicare il fix di `camminiditalia` aggiungendo `DB_HOST`/`DB_DATABASE`/`REDIS_HOST`
allo step `Laravel Tests` del workflow. Non eseguito perché **la CI di forestas
non arriva a quello step**: si ferma prima, all'avvio del servizio Postgres (vedi
"Bug trovati"). Aggiungere quelle variabili non avrebbe avuto alcun effetto
osservabile e avrebbe toccato un workflow già rotto per altra causa.

## Bug trovati

**Stato di partenza della suite (Task 1, Step 10): 21 falliti, 11 passati.**
Tutti i fallimenti sono preesistenti e indipendenti dall'isolamento del
database — servono come riferimento per distinguere le regressioni dei task
successivi:

- **20 test** in `tests/Feature/Import/SardegnaSentieriImportServiceTest.php`
  falliscono con `ArgumentCountError: Too few arguments to function
  App\Services\Import\SardegnaSentieriImportService::__construct(), 1 passed
  [...] and exactly 2 expected`. Il costruttore
  (`app/Services/Import/SardegnaSentieriImportService.php:65`) richiede
  `SardegnaSentieriClient` **e** `SardegnaSentieriMediaSyncService`, il test ne
  passa uno solo: il secondo parametro e' stato aggiunto senza aggiornare i
  test. Fuori scope.
- **1 test** `tests/Feature/ExampleTest.php`, scheletro di default di Laravel
  che chiama `/` e si aspetta 200. Fuori scope.

**`.env.testing` sostituisce `.env`, non lo integra.** Scoperto in esecuzione:
Laravel carica `.env.testing` **al posto** di `.env` quando esiste, quindi le
variabili non ripetute li' spariscono. La prima stesura conteneva solo le
variabili `DB_*` e `APP_ENV`, e la suite falliva con `MissingAppKeyException`.
Il file deve contenere anche `APP_NAME`, `APP_KEY`, `APP_DEBUG`, `APP_URL`,
`REDIS_HOST`, `REDIS_PORT`, `SCOUT_DRIVER`. Da tenere presente se in futuro si
aggiungono variabili a `.env`: quelle che servono ai test vanno replicate qui.


**La CI `run-tests.yml` di forestas è rotta da prima di questo ticket: il
container Postgres non si avvia e la suite non è mai stata eseguita.**

Evidenza — `gh run list --workflow=run-tests.yml` restituisce `failure` su tutti
i run disponibili (dal 2026-04-20 al 2026-09-03). Dal log del run `33770876753`:

```
-e "POSTGRES_PASSWORD=${DB_PASSWORD}" -e "POSTGRES_DB=${DB_DATABASE}" -e "POSTGRES_USER=${DB_USERNAME}"
##[error]Failed to initialize container postgis/postgis:17-3.5-alpine
##[error]One or more containers failed to start.
```

Causa: in `.github/workflows/run-tests.yml:11-15` le variabili sono scritte come
`${DB_PASSWORD}`, che in GitHub Actions **non è interpolazione** (servirebbe
`${{ }}` con secrets o vars). Il container riceve le stringhe letterali e
l'inizializzazione fallisce.

Fuori scope per questo ticket. Vedi Follow-up.

## Decisioni

**`APP_KEY` e `JWT_SECRET` di `.env.testing` sono generate apposta per i test.**
La prima stesura copiava `APP_KEY` da `.env`: versionandola si sarebbe
pubblicata la chiave di cifratura dell'ambiente di sviluppo. Segnalato dal
riepilogo del diff e corretto — entrambe le chiavi ora sono distinte da quelle
di sviluppo e cifrano solo dati di un database effimero.

**Scribe sostituito da Scramble: strumento sbagliato scelto per un errore di
lettura.** In fase di pianificazione il dev aveva indicato «scrabble», che e'
stato interpretato come *Scribe* e corretto come tale; l'intento era
`dedoc/scramble`, **gia' in uso in orchestrator** (`^0.13`, con il pattern
maturato nel ticket oc:8287). L'implementazione con `knuckleswtf/scribe` e'
stata rimossa e rifatta con Scramble. Cosa cambia in pratica:

- **Login e refresh non sono piu' documentati a mano.** Con Scribe erano
  descritti in `.scribe/endpoints/custom.0.yaml`, un file non generato dal
  codice che sarebbe andato aggiornato a mano a ogni cambio di
  `AppAuthController`. Scramble li deduce dal codice del package — inclusi i
  parametri `email`, `password`, `referrer` letti dalla validazione — quindi
  restano allineati da soli.
- **Scoping** con `Scramble::routes()` invece dei `prefixes` di Scribe: stessa
  logica di orchestrator, che esclude le route di `wm-package` e riammette
  singole eccezioni.
- **Schema di sicurezza dichiarato a mano.** Scramble deduce l'autenticazione
  dal middleware ma riconosce `auth:sanctum`, non il guard `api` di
  tymon/jwt-auth: lo schema `jwt` viene registrato in
  `afterOpenApiGenerated()` e applicato **solo** alle route SUS. Applicarlo
  globalmente con `$openApi->secure()` marcherebbe come protetti anche login e
  refresh, che sono il modo per ottenere il token.
  Nota di implementazione: `$openApi->paths` e' una **lista** di oggetti `Path`,
  non una mappa indicizzata per URI — va iterata confrontando `$path->path`.
- **`RestrictedDocsAccess` rimosso** dal middleware di `config/scramble.php`:
  fuori da `local` bloccherebbe l'accesso alla documentazione, che deve essere
  raggiungibile da Engineering su UAT. Stessa scelta di orchestrator.
- **La documentazione non e' piu' in italiano.** Con Scribe la pagina era
  generata da view Blade e da un file di lingua, entrambi traducibili. Scramble
  serve lo spec OpenAPI attraverso Stoplight Elements, una UI di terze parti:
  titolo e descrizione sono in italiano, le etichette dell'interfaccia
  (`Request`, `Response`, `Try it`) restano in inglese. Da valutare se
  accettabile per la consegna a Engineering.
- **URL della documentazione**: `/docs/api/sus` invece del default `/docs/api`, impostato con `Scramble::configure()->expose()` in `AppServiceProvider::register()` — va dichiarato li' perche' le route di Scramble sono registrate durante il boot del suo provider. Il path lascia spazio a documentazioni separate per gli altri partner previsti dall'analisi Forestas (Infomont CAI, PDND). `api.json` e' generato ed escluso
  dal versionamento (`.gitignore`), come in orchestrator.

**`APP_URL` corretto da `http://localhost` a `http://localhost:8000`.** Era
privo della porta su cui il server risponde. Emerso perche' il "Try it out"
della documentazione falliva con `Failed to fetch`, ma il valore serve a Laravel
per generare qualunque URL assoluto: era un errore di configurazione locale
indipendente da questo ticket.


**`JWT_TTL` e `JWT_SECRET` aggiunti: la "terza via" decisa in pianificazione
non era realizzabile.** In fase di challenge si era deciso di non introdurre
alcuna scadenza sul token del client SUS, sul presupposto che `JWT_TTL` nullo
producesse token perpetui — comportamento documentato di `tymon/jwt-auth`.
Verificato in esecuzione che su **2.3.0** non e' cosi': con TTL nullo il token
non supera la validazione del claim `exp` e il **login stesso** risponde
`TokenExpiredException`
(`vendor/tymon/jwt-auth/src/Claims/Expiration.php:31`). L'autenticazione API
era quindi inutilizzabile in questo progetto. Impostato `JWT_TTL=60` in `.env`
e documentato in `.env-example` con la spiegazione.

Scoperto inoltre che **`JWT_SECRET` non era mai stato generato** (assente da
`.env` e da `.env-example`): il guard `api` e' configurato come `jwt` in
`config/auth.php` ma nessun login API poteva funzionare. Generato con
`php artisan jwt:secret` e documentato.

**Voce di menu nella sezione Tools esistente, non una sezione nuova.**
`wm-package/src/WmPackageServiceProvider.php:519-556` cerca una `MenuSection`
chiamata `Tools` nel menu del progetto e vi appende Horizon, Minio, Kibana e i
comandi DB; se non la trova la crea con icona `briefcase`. Dichiararla nel
progetto permette di aggiungere voci proprie. **Attenzione:** il package
ricostruisce la sezione conservando solo `icon` e `collapsable`, quindi
`canSee()` e `collapsedByDefault()` sulla sezione vengono scartati — la
visibilita' va impostata sul singolo `MenuItem`.


- **Isolamento del database di test secondo il pattern di `camminiditalia`**
  (`.env.testing` versionato + `DB_DATABASE` in `phpunit.xml`), scelto rispetto a
  un `.env.testing` locale non versionato perché l'isolamento deve valere per
  tutti i dev senza setup manuale.
- **`RefreshDatabase` attivato**, a differenza di `camminiditalia` dove resta
  commentato: i test di questo ticket creano utenti e ruoli e senza refresh il
  vincolo di unicità sull'email fallirebbe alla seconda esecuzione. Sicuro
  perché opera sul database di test.
- **Il test `DatabaseIsolationTest` ha uno `skip` condizionato a `env('CI')`**:
  in CI il database è un container effimero con un altro nome, e l'asserzione
  fallirebbe pur essendo tutto corretto.
- `RestrictSusClient` risolve l'utente con `auth('api')->user()` invece di
  affidarsi a `auth()->user()`: appeso al gruppo `api`, viene eseguito prima del
  middleware di route `auth:api`. L'overview parlava di "eseguito dopo
  `auth:api`", ipotesi non praticabile — le route da bloccare vivono in
  `wm-package` e l'unico punto di intercettazione comune è il gruppo `api`.

**Endpoint di autenticazione propri del branch invece del login condiviso.**
Emerso in verifica: la documentazione di `POST /api/auth/login` esponeva il
parametro `referrer`, che Scramble deduce correttamente dal codice del package
ma che a Engineering sarebbe sembrato un campo da valorizzare. Il referrer
serve a registrare lo sku dell'app mobile chiamante — ramo mai percorso da un
client server-to-server, e per giunta rotto (`$user->app` non e' una relazione
esistente). Invece di documentarlo come "da ignorare", il branch ha ora
`SusAuthController` con login e refresh propri. Tre effetti: il contratto con
Engineering e' interamente nostro e indipendente dall'evoluzione di
`AppAuthController`; la whitelist si stringe al solo `/api/v1/sus/*`, come da
regola posta dal dev; il login del branch verifica il ruolo `Sus` e risponde
403 a un utente della piattaforma, cosi' il branch non diventa una porta di
accesso alternativa.

**Rifiniture della documentazione consegnabile.** Emerse provando la pagina:

- **Nessun server dichiarato nel documento OpenAPI.** Il server derivato da
  `APP_URL` faceva partire le chiamate del "Try it" verso un altro origin
  quando la pagina veniva aperta da un host diverso (`127.0.0.1` invece di
  `localhost`), e il browser le bloccava per CORS. Perche' i path restassero
  completi senza il prefisso nel server, `api_path` in `config/scramble.php` e'
  un wildcard (`api/*`): con un include statico Scramble sposta `/api` nel
  server e accorcia i path.
- **Gruppi rinominati con `#[Group]`** (`Auth`, `Ping`) invece del nome
  derivato dal controller (`SusAuth`, `SusPing`): l'attributo **sostituisce** il
  tag di default, mentre `@tags` vi si **aggiunge**, producendo due tag per
  endpoint.
- **Risposte di errore con l'attributo `#[Response]`.** Scramble legge **solo il
  primo** `@response` di un metodo e lo usa come tipo di ritorno: i
  `@response 401 array{...}` in stile Scribe vengono ignorati (anche quelli
  presenti in orchestrator sono di fatto decorativi). L'attributo e' ripetibile
  e accetta status, descrizione e tipo.
- **Validazione del login in `SusLoginRequest`** invece che inline: e' cio' che
  fa comparire la sezione "Schemas" nella documentazione, oltre a essere la
  convenzione Laravel.
- **`info.contact` non e' supportato da Scramble** (`InfoObject` non ha quel
  campo): il riferimento a cui scrivere e' una sezione della descrizione.

**Nessun dettaglio interno nella documentazione pubblica.** La pagina e'
consegnata a Engineering e a Regione Sardegna, che non devono conoscere
strumenti, package o ticket usati. I docblock delle classi esposte finiscono nel
documento generato: le motivazioni tecniche sono quindi commenti `//`, che il
generatore non legge, e i docblock contengono solo cio' che serve al lettore
esterno. Verificato che nel documento non compaiano `Scramble`, `orchestrator`,
`wm-package`, `tymon`, `referrer`, `middleware`, `blacklist` ne' codici ticket.
**Da ricontrollare quando si aggiungono endpoint.**

## Follow-up

- **Errore PHPStan preesistente** in `app/Http/Clients/SardegnaSentieriClient.php:160`
  (`getTaxonomyWarnings()` dichiara `array<string, array<string, mixed>>` ma
  restituisce `array<int, ...>`), non presente in `phpstan-baseline.neon`. File
  non toccato da questo ticket.
- **Bug preesistente in `wm-package`:** `POST /api/auth/user` crasha con
  `TypeError` se manca l'header `app-id`
  (`AppAuthController::filterUserPrivacyByAppId()`, chiamato da
  `AppAuthController.php:269`, definito a `:363`, richiede `string` e riceve
  `null`). Emerso scrivendo il test di guardia del Task 5, che usa `auth/me` per
  questo motivo.
- **`.env-example` non documenta le variabili JWT** — corretto in questo ticket,
  ma verificare che gli ambienti gia' deployati abbiano `JWT_SECRET` e `JWT_TTL`
  impostati, altrimenti il login API non funziona.

- **Riparare la CI `run-tests.yml` di forestas** (ticket dedicato). Quando lo si
  farà: `.env.testing` è ora versionato e verrà caricato anche in CI, perché
  `APP_ENV=testing` è impostato da `phpunit.xml`, portandosi dietro `DB_HOST=db`
  (hostname valido solo sulla rete Docker locale). Vanno quindi aggiunte le env
  var esplicite allo step `Laravel Tests`, come già fatto in
  `camminiditalia/.github/workflows/run-tests.yml`. Includere `REDIS_HOST`:
  `wm-package/src/Jobs/BuildAppPoisGeojsonJob.php:70` usa `Cache::store('redis')`
  hardcoded, bypassando `CACHE_STORE=array` di `phpunit.xml` — è ciò che in
  camminiditalia causò 40 test falliti con `getaddrinfo for redis failed`.
- Gate Nova da blacklist di ruoli a controllo del permesso `access-nova`:
  escluderebbe `Validator` e `Contributor`, ticket dedicato.
- Ramo mobile/referrer di `AppAuthController::login`: `$user->app` non è una
  relazione definita su `User` (esiste solo `apps(): HasMany`, `users` non ha
  colonna `app_id`), quindi il ramo è rotto per qualunque utente. Ticket sul
  package.
- Verificare i flussi di login degli altri consumer di `wm-package` al momento
  del bump del submodule pointer (throttle sul login).
- Tre sezioni `## Decisioni architetturali` duplicate in `wm-package/CLAUDE.md`
  (righe 23, 162, 204).
