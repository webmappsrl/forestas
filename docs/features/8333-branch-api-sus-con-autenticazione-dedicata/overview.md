> Ticket: oc:8333

# Branch API SUS con autenticazione dedicata

## Cosa cambia

Il Catasto Sentieri espone un branch di API riservato all'integrazione con il
SUS (Sportello Unico Sentieri, realizzato da Engineering per la Regione
Sardegna), sotto il prefisso `/api/v1/sus/*`, protetto dall'autenticazione JWT
già in uso sulla piattaforma.

Il ticket produce tre cose e nient'altro:

1. **Un indirizzo dedicato** — il gruppo di route `/api/v1/sus/*`, che oggi
   contiene un solo endpoint di prova. È il posto dove nasceranno le API di
   business.
2. **Un modo per Engineering di autenticarsi** — un utente dedicato al client
   SUS, creato da Nova con il ruolo `Sus`, che fa login su
   `POST /api/v1/sus/auth/login` e usa il token sulle chiamate al branch. Il
   branch ha endpoint di autenticazione propri: il client non esce mai dal
   prefisso `/api/v1/sus/`.
3. **Qualcosa da consegnare a Engineering** — `GET /api/v1/sus/ping`, che
   risponde con l'identità del client autenticato, più la documentazione
   generata di come chiamarlo.

Nessuna logica di business, nessun dato in transito. Come dichiarato in sede di
scrum: *«non è implementazione dell'API, è vuota, però sai che la vai a
implementare lì sopra»*.

## Perché

Il SUS è lo sportello pubblico dove viene presentata l'istanza; il Catasto è il
proprietario del dato — *«il SUS deve agire come sportello pubblico senza
persistere i dati in modo permanente, lasciando al Catasto il ruolo di owner»*
(Ivan, Engineering, call Forestas RES/SUS del 30/07/2026). Senza cooperazione
applicativa ogni istanza raccolta dal SUS andrebbe reinserita a mano nel
Catasto, e viceversa per le manutenzioni.

Le API che nasceranno su questo branch serviranno due procedimenti definiti in
quella call: la **procedura 02** (proposta di nuovo sentiero) e la **procedura
05** (comunicazione di manutenzione). Il flusso concordato prevede una fase di
**pre-validazione** — il cittadino carica il GPX sul SUS, il Catasto risponde
con una scheda del sentiero, anche cartografica — e, in quella fase, l'assegnazione
di un **numero di sentiero provvisorio** che diventa definitivo solo a istruttoria
conclusa.

A Engineering è stato promesso un branch API REST con un client e una chiave di
accesso propri, con il modo di esporlo delegato a noi: *«i dati sono i vostri,
quindi esponete voi l'API nella maniera che riterrete più opportuna»* (Ivan). La
documentazione è parte della consegna: *«Così a loro diamo la documentazione»*
(Alessio Piccioli).

Ordine vincolante dei ticket dello stesso tag `[RDO][FORESTAS][2026]1`: questo,
poi l'algoritmo di generazione del codice sentiero (ticket da creare), poi le API
di preistruttoria e accatastamento. Go-live del Catasto settembre/ottobre 2026,
integrazione operativa entro dicembre 2026.

## Requisiti

- [ ] Route group `/api/v1/sus/*` in `forestas`, registrato via service provider (`Route::group()` in `boot()`, stesso pattern di `WmPackageServiceProvider:112-121`) — `routes/api.php` non è caricato da `bootstrap/app.php`
- [ ] Endpoint `GET /api/v1/sus/ping` protetto da `auth:api`, che risponde con l'identità del client autenticato
- [ ] Endpoint di autenticazione **propri del branch**: `POST /api/v1/sus/auth/login` (con `throttle:100,1`) e `POST /api/v1/sus/auth/refresh`. Non si riusa `POST /api/auth/login` di `wm-package` per due motivi: quel contratto è condiviso con le app mobile e può evolvere senza preavviso, e accetta un parametro `referrer` — che serve a registrare lo sku dell'app mobile chiamante, è irrilevante per un canale server-to-server, ma comparirebbe nella documentazione consegnata a Engineering come se andasse valorizzato
- [ ] Il login del branch verifica il ruolo `Sus` e risponde 403 a un utente della piattaforma con credenziali valide: il branch non deve diventare una porta di accesso alternativa
- [ ] Utente dedicato al client SUS creato da un'**azione Nova** sulla resource Users, visibile solo agli Administrator: crea l'utenza, assegna il ruolo `Sus` e invalida la cache dei permessi. La password è un campo del form precompilato con un valore casuale, copiabile prima di confermare. Nessun comando artisan di provisioning, e nessuna dipendenza da `WM_SUPER_ADMIN_EMAILS` — che governa anche `AppPolicy` e lo scope dell'import POI, quindi non è allargabile per questo scopo
- [ ] Nuovo ruolo Spatie `Sus` (guard `web`), senza il permesso `access-nova`, assegnato al client dal comando di creazione
- [ ] Esclusione esplicita del ruolo `Sus` dal gate Nova in `app/Providers/NovaServiceProvider.php:129` → `! $user->hasAnyRole(['Guest', 'Sus'])`. Il client SUS è un canale programmatico e non deve accedere alla piattaforma: nella call del 30/07/2026 gli unici accessi umani previsti sono dell'operatore Forestas al **backoffice del SUS**, non al Catasto. Aggiungere `Sus` alla blacklist esistente non altera il comportamento di nessun altro ruolo (`Validator` e `Contributor` continuano a passare)
- [ ] Middleware di restrizione **a whitelist** per gli utenti con ruolo `Sus`: è consentito **solo** `/api/v1/sus/*`. Ogni altra route risponde 403, comprese quelle di autenticazione di `wm-package` — il branch ha le proprie. Il client deve vedere solo ciò che può usare: un token valido sul guard `api` raggiunge altrimenti 297 route registrate sotto `api`
- [ ] La restrizione è appesa al **gruppo di middleware `api`** (`$middleware->appendToGroup('api', ...)` in `bootstrap/app.php`), non al gruppo di route SUS: le route da escludere vivono in `wm-package` e non passano dal nostro gruppo, quindi quello è l'unico punto di intercettazione comune. Non è un middleware globale — le route web e Nova non lo attraversano
- [ ] Il middleware risolve l'utente con `auth('api')->user()` e **non** dipende dall'ordinamento rispetto ad `auth:api`: i middleware di gruppo girano prima di quelli di route, quindi `auth()->user()` sarebbe sempre `null`; il guard JWT legge l'header `Authorization` da sé. Token assente o invalido: la richiesta passa e a rispondere 401 resta `auth:api`
- [ ] Test di guardia obbligatorio: un utente **senza** ruolo `Sus` non deve subire alcuna restrizione. È il rischio principale del task — il middleware attraversa tutte le API della piattaforma
- [ ] La restrizione è una **whitelist per prefisso, non una blacklist**: le route che `wm-package` aggiungerà in futuro al gruppo `auth:api` restano escluse per costruzione, senza manutenzione
- [ ] Procedura operativa documentata in `CLAUDE.md`: creazione del client da Nova, rotazione della password dalla stessa schermata, e snippet tinker per la revoca di un token via blacklist JWT (`JWTAuth::invalidate()`), senza toccare `JWT_SECRET`
- [ ] Il middleware di log è invece applicato al **solo gruppo di route SUS**: deve registrare le chiamate al branch, non tutto il traffico della piattaforma
- [ ] Canale di log dedicato in `config/logging.php` (stesso pattern del canale `import` esistente) con una riga per ogni richiesta a `/api/v1/sus/*`: utente, endpoint, timestamp, esito. **Token e header `Authorization` non vengono mai loggati**
- [ ] `dedoc/scramble` in `forestas` — stesso strumento gia' in uso in **orchestrator**, dove il pattern e' maturato con il ticket oc:8287. Scoping via `Scramble::routes()` sul branch SUS piu' `auth/login` e `auth/refresh`; `RestrictedDocsAccess` rimosso dal middleware perche' bloccherebbe l'accesso fuori da `local`; schema di sicurezza `jwt` dichiarato a mano e applicato alle sole route SUS (Scramble riconosce `auth:sanctum`, non il guard `api` di tymon). Documentazione su `/docs/api/sus`, non sul default `/docs/api`: il path lascia spazio a documentazioni separate per gli altri partner (Infomont CAI, PDND) senza rinegoziare l'URL con Engineering
- [ ] **In `wm-package`:** `throttle:100,1` su `POST /auth/login` (`routes/api.php:22`), stessa soglia già in uso su `signup` nella riga adiacente — oggi il login non ha alcun limite di tentativi e `withMiddleware()` è vuoto, quindi in Laravel 12 il gruppo `api` non porta più `throttle:api` per default
- [ ] `JWT_TTL` impostato in `.env` e documentato in `.env-example` con la motivazione. **Non è una scelta ma un requisito tecnico** (vedi Rischi). Anche `JWT_SECRET` va documentato: non era presente in nessuno dei due file, quindi l'autenticazione API non poteva funzionare
- [ ] Voce **SUS API documentation** nella sezione `Tools` del menu Nova, che punta a `/docs/api/sus`. La sezione è quella già usata da `wm-package` per Horizon, Minio e Kibana: va dichiarata nel progetto con nome `Tools` e icona `briefcase`, e la visibilità va impostata sul singolo `MenuItem` — il package ricostruisce la sezione conservando solo `icon` e `collapsable`
- [ ] Nessuna logica di business su EcTrack, POI o tassonomie

## Rischi

- **`JWT_TTL` nullo rende il login inutilizzabile, non perpetuo.** L'assunzione su cui si era deciso di non introdurre una scadenza — «`JWT_TTL` non impostato → token privi di `exp`, validi per sempre» — **è falsa** su `tymon/jwt-auth 2.3.0`: il token emesso non supera la validazione del claim `exp` e il login stesso risponde `TokenExpiredException` (`vendor/tymon/jwt-auth/src/Claims/Expiration.php:31`). Verificato in esecuzione: senza `JWT_TTL` il login del client SUS falliva. La scadenza è quindi **obbligatoria**, e con essa il rischio del «token eterno» non esiste più. Rischio residuo: la finestra di validità è quella del TTL e il client rinnova via `POST /api/auth/refresh` (in whitelist). Da notare che `JWT_SECRET` non era presente né in `.env` né in `.env-example`: l'autenticazione API non era mai stata configurata in questo progetto.
- **La revoca esiste ma va saputa eseguire.** `config/jwt.php:220` → `blacklist_enabled` default `true`, quindi `JWTAuth::invalidate($token)` revoca un singolo token: **non va ruotato `JWT_SECRET`**, che invaliderebbe i token di tutti gli utenti della piattaforma. Il rischio è di runbook, non tecnico — da cui lo snippet documentato in `CLAUDE.md`. Cambiare la password (da Nova) blocca i login futuri ma non invalida il token già emesso: servono entrambe le azioni.
- **Nova: protezione spostata dal package al gate del progetto.** `app/Providers/NovaServiceProvider.php:129` è `! $user->hasRole('Guest')` — una blacklist, non un controllo di permesso — quindi un utente con un ruolo qualsiasi diverso da `Guest` **passa** `Gate::allows('viewNova')`. Finora il client sarebbe stato fermato solo da `EnforceNovaAccessOnLogin` (`wm-package`), che intercetta l'evento `Login` sul guard `web` usato da Nova (`config/nova.php:77`, `NOVA_GUARD` non impostata): protezione efficace ma residente in codice condiviso, mentre il gate di questo progetto autorizzava. Risolto aggiungendo `Sus` alla blacklist del gate — la protezione diventa esplicita e locale. Resta aperto, come debito noto e **fuori scope**, il fatto che il gate sia una blacklist di ruoli invece di un controllo su `access-nova`: correggerlo escluderebbe da Nova `Validator` e `Contributor`, che oggi passano, ed è un cambio di comportamento su utenti reali da fare in un ticket dedicato.
- **Il middleware di restrizione attraversa tutte le API della piattaforma.** È appeso al gruppo `api` perché le route da escludere vivono in `wm-package`: un errore nella risoluzione dell'utente bloccherebbe ogni chiamata API, non solo quelle del client SUS. Mitigazione: il test di guardia sugli utenti senza ruolo `Sus` è un deliverable, non un extra.
- **Perimetro reale di ciò che la whitelist esclude.** Verificato che nessuna delle route del gruppo `auth:api` consente di agire su dati di terzi: `auth/delete` e `auth/user` operano sull'utente autenticato, e `UgcController::_destroy` chiama `validateUser($model)` prima di cancellare. Il client potrebbe quindi danneggiare solo la propria integrazione — cancellandosi o cambiandosi l'email — non la piattaforma né altri utenti. La whitelist serve perciò a **rendere esplicito il contratto** (3 route consentite su 297) più che a prevenire un danno diffuso, ed è la base su cui poggeranno le API di scrittura dei ticket successivi.
- **Rischio residuo della whitelist.** Un endpoint SUS creato in futuro fuori dal prefisso `/api/v1/sus/*` verrebbe bloccato senza che la causa sia evidente. La direzione dell'errore è però sicura: il fallimento è un 403 visibile, non un accesso concesso per sbaglio.
- **Non sostituire il ruolo `Sus` con `Administrator`.** Il client è escluso da Nova per ruolo (gate esteso a `hasAnyRole(['Guest','Sus'])`): assegnargli `Administrator` gli darebbe il permesso `access-nova` e quindi accesso completo al backoffice del Catasto, con credenziali in mano a un fornitore esterno. Vale anche se in futuro il resource Nova `User` esporrà un campo `roles`, oggi assente.
- **Email del client su dominio non instradabile.** Nova espone il reset password pubblico (`app/Providers/NovaServiceProvider.php:116`, `withPasswordResetRoutes()`): un reset innescato per errore su quell'indirizzo cambierebbe la password del client, producendo un 401 da diagnosticare. Non è una difesa contro Engineering, è igiene contro un incidente banale.

## Out of scope

- Endpoint di business: preistruttoria e accatastamento definitivo (ticket successivi).
- Algoritmo di generazione del codice sentiero — ticket ancora da creare, propedeutico alle API di business.
- ~~TTL/scadenza del token SUS: valutato e scartato~~ — **rientrato in scope**: `JWT_TTL` è tecnicamente obbligatorio (vedi Rischi).
- Migrazione a Sanctum: valutata e scartata — l'unico vantaggio (revoca puntuale) è già disponibile via blacklist JWT, a fronte di una migration e di un secondo guard.
- Whitelisting IP: proposto da Alessio in call, **scartato da Ivan** («magari eviterei questo tipo di policy… sarà a tempo debito»).
- Server OAuth2 (Passport, league/oauth2-server): riusato JWT.
- Ambiente di collaudo dedicato: le verifiche di Engineering avvengono su UAT, ambiente già esistente.
- Rate-limiting dedicato su `/api/v1/sus/*` oltre al throttle sul login: da valutare con carico reale.
- Riscrittura del gate Nova da blacklist di ruoli a controllo del permesso `access-nova`: escluderebbe `Validator` e `Contributor`, ticket dedicato (vedi Rischi). In questo ticket il gate viene solo **esteso** con il ruolo `Sus`, senza cambiarne la forma.
- Ramo mobile/referrer di `AppAuthController::login`: `$user->app` non è una relazione definita su `User` (esiste solo `apps(): HasMany`, e `users` non ha colonna `app_id`), quindi quel ramo è rotto per qualunque utente, non solo per il client SUS. Va affrontato in un ticket sul package, dopo aver verificato se qualche consumer dipende da quel comportamento. Irrilevante per il SUS, che non invia `referrer`.
- Audit su tabella interrogabile da Nova: rimandato ai ticket di business, quando esisteranno istanze da consultare e lo schema sarà noto.

## Moduli toccati

Ticket a cavallo di due repo. **Ordine di merge vincolante:** prima la PR di `wm-package`, poi il bump del submodule pointer in `forestas`.

**Repo `wm-package`:**
- `routes/api.php:22` — `throttle:100,1` su `POST /auth/login`
- `docs/features/8333-branch-api-sus-con-autenticazione-dedicata/overview.md`

**Repo `forestas`:**
- `app/Providers/AppServiceProvider.php` (o nuovo provider dedicato) — registrazione del route group `/api/v1/sus`
- `routes/sus.php` (nuovo) — route del branch
- `app/Http/Controllers/Api/Sus/SusPingController.php` (nuovo)
- `app/Http/Controllers/Api/Sus/SusAuthController.php` (nuovo) — login e refresh del branch
- `app/Http/Requests/Api/Sus/SusLoginRequest.php` (nuovo) — validazione del login, genera lo schema nella documentazione
- `app/Nova/Actions/CreateSusClient.php` (nuovo) + registrazione in `app/Nova/User.php` — provisioning del client
- `app/Http/Middleware/RestrictSusClient.php` (nuovo) — whitelist per il ruolo `Sus`, eseguito dopo `auth:api`
- `app/Http/Middleware/LogSusRequest.php` (nuovo)
- Setup ruoli: aggiunta del ruolo `Sus` al processo documentato in `CLAUDE.md` → Setup progetto
- `bootstrap/app.php` — alias dei due middleware (primi middleware custom del progetto)
- `config/logging.php` — canale `sus`
- `config/scramble.php` (pubblicato) + `composer.json` — dipendenza `dedoc/scramble`
- `app/Providers/AppServiceProvider.php` — `Scramble::routes()` per lo scoping e `afterOpenApiGenerated()` per lo schema di sicurezza
- `CLAUDE.md` — procedura operativa (creazione client da Nova, rotazione password, snippet di revoca token), sezioni "Feature disponibili" e "Decisioni architetturali"

## Divergenze dalla descrizione del ticket

La `description` del ticket oc:8333 contiene già un'overview tecnica dettagliata.
Le scelte qui divergono in alcuni punti, sempre su evidenza verificata nel
codice o nella trascrizione della call del 30/07/2026. Le divergenze riguardano
il **come**, non il **cosa**: nessun requisito funzionale del ticket è stato
rimosso.

### Requisiti riformulati

**Il blocco delle route self-service diventa una whitelist, appesa al gruppo `api`.**
Il ticket chiede di bloccare esplicitamente `auth/user`, `auth/delete`,
`auth/logout`, `auth/refresh`. Tre correzioni:

1. Un elenco di route da negare va aggiornato ogni volta che `wm-package`
   aggiunge una route al gruppo `auth:api`, e la dimenticanza è silenziosa. La
   whitelist per prefisso esclude per costruzione anche le route future.
2. `refresh` figura tra le route da bloccare, ma serve al client per rinnovare
   il token: nella whitelist è consentito.
3. Il ticket colloca il middleware in `bootstrap/app.php` → `withMiddleware()`.
   Registrarlo come middleware globale lo farebbe girare anche su Nova e sul
   frontend; applicarlo al solo gruppo di route SUS non bloccherebbe **nulla** di
   ciò che deve bloccare, perché le route da escludere vivono in `wm-package`.
   La forma corretta è `appendToGroup('api', ...)`, e poiché i middleware di
   gruppo girano prima di quelli di route l'utente va risolto con
   `auth('api')->user()` invece di attendere `auth:api`.

Nota sul perimetro: verificato che nessuna delle route escluse permette di
agire su dati di terzi (`auth/delete` e `auth/user` operano sull'utente
autenticato, `UgcController::_destroy` chiama `validateUser($model)`). La
whitelist rende quindi esplicito il contratto — 3 route consentite su 297
registrate sotto `api` — più che prevenire un danno diffuso.

**Il ruolo `Sus` serve a uno scopo diverso da quello implicito nel ticket.**
Nel ticket il ruolo accompagna il middleware che "verifica il ruolo `Sus`" sulle
route del gruppo. Verificato che per quello basta `auth:api` più la whitelist: il
ruolo serve invece come etichetta su cui la whitelist riconosce il client, e —
questo è l'uso che il ticket non prevede — come esclusione esplicita dal gate
Nova (`! $user->hasAnyRole(['Guest','Sus'])`). Senza quest'ultima, il client
sarebbe autorizzato dal gate del progetto e fermato solo da un listener che vive
in `wm-package`.

**I middleware sono alias applicati al gruppo, non middleware globali.**
Il ticket chiede di registrarli in `bootstrap/app.php` → `withMiddleware()`, che
li fa eseguire su ogni richiesta della piattaforma — Nova, API mobile e frontend
inclusi. Un middleware scritto per un singolo client diventerebbe così un punto
di guasto per l'intera applicazione: un errore nella risoluzione dell'utente
restituirebbe 403 a tutti. Registrati come alias e applicati al gruppo di route,
un loro difetto resta confinato al branch SUS.

**I tre comandi artisan di provisioning non servono: l'operazione si fa da Nova.**
Il ticket chiede «un comando artisan idempotente per il provisioning del
client» e «un comando/flag di rotazione credenziali isolato». Verificato che
`wm-package/src/Nova/AbstractUserResource.php` espone `Password::make()` (riga
66) e `RoleBooleanGroup::make(__('Roles'), 'roles')` (riga 71, da
`vyuldashev/nova-permission`), entrambi riservati a chi passa
`RolesAndPermissionsService::allowsUser()`: creare l'utente, assegnargli il
ruolo `Sus` e cambiargli la password si fa dalla schermata utente di Nova, senza
scrivere codice. Il ticket riporta che il campo `roles` è «oggi assente» dal
resource Nova — informazione superata: è stato aggiunto con oc:8072.

Il ticket non indica **chi** dovrebbe eseguire quei comandi. L'unica indicazione
implicita è l'avviso di «non eseguirlo in pipeline o log condivisi», che
identifica uno sviluppatore Webmapp su un terminale — cioè la stessa persona che
ha accesso a Nova come Administrator con l'email nell'allowlist. I comandi non
abiliterebbero quindi nessuno che non possa già fare l'operazione, e per una via
peggiore: la password stampata in un terminale resta nello scrollback e in
`.bash_history`.

Nota sull'idempotenza richiesta dal ticket («se l'utente esiste, aggiorna la
password»): così definita sarebbe stata pericolosa a prescindere — un comando
rieseguito per errore avrebbe cambiato la password del client rompendo
l'integrazione **con exit code di successo**, senza alcun segnale.

Restano documentate in `CLAUDE.md` le procedure operative: creazione del client
da Nova, rotazione della password dalla stessa schermata, e uno snippet tinker
per la revoca del token — l'unica operazione senza interfaccia, perché la
blacklist JWT non è esposta da Nova.

**Il prefisso `/api/v1/sus/*` è confermato, ma non per la ragione indicata.**
Il ticket lo presenta come "prefisso versionato" del branch SUS. Verificato che
`/api/v1` **esiste già** e appartiene a `wm-package`
(`routes/api.php:211` → `Route::prefix('v1')`, commentato come *FRONTEND API
VERSION 1*): sono live `/api/v1/app/all` e `/api/v1/app/{id}/pois.geojson`. Il
branch SUS si innesta quindi in un namespace esistente e `v1` non è una versione
del contratto SUS. Il prefisso resta perché è la convenzione REST più
riconoscibile per Engineering e perché il progetto non è ancora in produzione,
quindi resta modificabile; ma la documentazione non deve dichiarare che quel
`v1` versioni l'integrazione.

### Requisiti aggiunti

**Documentazione generata (Scramble).**
Assente dal ticket e dalla stima originale di 5.25h, ma richiesta esplicitamente
in sede di scrum: *«Così a loro diamo la documentazione»*. Engineering deve poter
integrare leggendo un documento, senza chiederci nulla. Lo strumento è
`dedoc/scramble`, già in uso in **orchestrator**: genera lo spec OpenAPI dal
codice, quindi la documentazione di `login` e `refresh` — che vivono in
`wm-package` — resta allineata da sola se quel contratto cambia.

**Procedura di revoca del token documentata (non un comando).**
Il ticket presenta la rotazione della password come mitigazione del rischio sui
token. Verificato che non lo è: cambiare la password blocca i login futuri ma
**non** invalida il token già emesso. La revoca puntuale esiste — la blacklist
JWT è attiva (`config/jwt.php:220`) — ma non è esposta da Nova, e la strada
apparentemente ovvia (ruotare `JWT_SECRET`) invaliderebbe i token di tutti gli
utenti della piattaforma. Da cui lo snippet in `CLAUDE.md`, che ottiene lo scopo
— nessuno deve leggere la documentazione di tymon durante un incidente — senza
il costo di un comando artisan per un'operazione eccezionale.

**`throttle:100,1` su `POST /auth/login` in `wm-package`.**
Non previsto dal ticket. `auth/login` non ha alcun limite di tentativi mentre
`signup` sulla riga adiacente ce l'ha, e in Laravel 12 il gruppo `api` non porta
più `throttle:api` per default (`bootstrap/app.php` ha `withMiddleware()`
vuoto). Poiché l'unica protezione del branch SUS è una password su quell'endpoint
— l'IP whitelisting è stato scartato in call — l'assenza di throttle è una
lacuna rilevante. La correzione riguarda tutti i consumer del package, quindi
vive lì e non in `forestas`.

### Rischi corretti

**Il TTL non è opzionale: senza, il login non funziona.**
Il ticket accetta il rischio dei token non scadenti «mitigato dal comando di
rotazione isolata». Due correzioni. La prima: rotazione della password e
scadenza del token sono cose diverse — cambiare la password non invalida il
token già emesso. La seconda, emersa in esecuzione e più radicale: su
`tymon/jwt-auth 2.3.0` un `JWT_TTL` nullo **non** produce token perpetui ma
token che falliscono la validazione del claim `exp`, tanto che il login stesso
risponde `TokenExpiredException`. `JWT_TTL` è quindi obbligatorio perché
l'autenticazione API funzioni, e lo scenario descritto dal ticket come «rischio
accettato» non può verificarsi.

**Il ruolo `Sus` senza `access-nova` non è sufficiente a tenere il client fuori
da Nova.** Implicito nel ticket. Il gate del progetto è una blacklist
(`! $user->hasRole('Guest')`), quindi qualunque ruolo diverso da `Guest` lo
supera: la protezione effettiva è oggi solo il listener
`EnforceNovaAccessOnLogin` di `wm-package`. Da cui l'esclusione esplicita di
`Sus` dal gate tra i requisiti.

**Il null-pointer su `$user->app->sku` non è un rischio "possibile" del ramo
mobile: quel ramo non può funzionare per nessun utente.** Il ticket lo cita come
bug preesistente da tenere fuori scope. Verificato che `$user->app` non è una
relazione definita: su `User` esiste solo `apps(): HasMany`, la tabella `users`
non ha colonna `app_id`, e `sku` sull'app è una stringa e non una collection su
cui invocare `contains()`. Resta fuori scope, ma va trattato come codice rotto
da chiarire in un ticket sul package, non come edge case dell'integrazione SUS.

**Il whitelisting IP non è "lasciato aperto da Ivan": Ivan lo ha scartato.**
Il ticket lo registra come punto aperto da parte di Engineering. Nella
trascrizione è Alessio a proporlo («volendo anche con IP dedicato») e Ivan a
declinare: «magari eviterei questo tipo di policy… sarà a tempo debito». Resta
fuori scope, ma per volontà del cliente.

### Perimetro chiarito

**Ambiente di collaudo.** Il ticket non ne parla; le verifiche di connessione di
Engineering avvengono su **UAT**, ambiente già esistente. Nessun lavoro
infrastrutturale.

**Audit log.** Il ticket chiede un log di audit per ogni chiamata: realizzato
come canale di log dedicato, sufficiente all'uso diagnostico di questa fase. Un
audit su tabella interrogabile da Nova serve a domande amministrative («chi ha
trasmesso l'istanza X?») che nessuno può porre finché non transitano istanze, e
il suo schema va deciso conoscendo i dati: rimandato ai ticket di business.
