> Ticket: oc:8333

# Branch API SUS con autenticazione dedicata — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** esporre un branch di API riservato al SUS (`/api/v1/sus/*`) autenticato con il JWT esistente, con un solo endpoint di prova, il client ristretto a quel branch, un log dedicato e la documentazione generata da consegnare a Engineering.

**Architecture:** il route group vive in `forestas` e viene registrato da `AppServiceProvider::boot()` con lo stesso pattern di `WmPackageServiceProvider` (`Route::middleware('api')->prefix(...)->group(...)`), perché `routes/api.php` non è caricato da `bootstrap/app.php`. Il client SUS è un `User` con ruolo Spatie `Sus`, creato da Nova. Un middleware appeso al gruppo `api` riconosce il client dal token e gli consente solo login, refresh e `/api/v1/sus/*`. Un secondo middleware, applicato al solo gruppo SUS, scrive una riga di audit su un canale di log dedicato.

**Tech Stack:** Laravel 12, PHP 8.4, `tymon/jwt-auth` (guard `api`), `spatie/laravel-permission`, `knuckleswtf/scribe`, Pest, PostgreSQL + PostGIS.

**Spec:**
- `docs/features/8333-branch-api-sus-con-autenticazione-dedicata/overview.md` (repo `forestas`)
- `wm-package/docs/features/8333-branch-api-sus-con-autenticazione-dedicata/overview.md` (repo `wm-package`)

## Global Constraints

- **Nessun commit, nessun `git add`, nessun `git push`, nessun branch creato dall'agente.** I blocchi `git` in questo piano sono istruzioni testuali per il developer, che le esegue lui.
- **Commit convention:** `feat(oc:8333): ...`, `fix(oc:8333): ...`, `docs(oc:8333): ...`.
- **Due repo, ordine di merge vincolante:** la PR di `wm-package` (Task 2) va mergiata **prima** del bump del submodule pointer in `forestas`. Tutto il resto è custom in `forestas`.
- **Non eseguire `vendor/bin/pest` prima del Task 1:** `phpunit.xml` non ha isolamento DB e la suite girerebbe sul database reale `forestas`, che contiene i dati importati da Sardegna Sentieri.
- **Prefisso:** `/api/v1/sus/*`. Il `v1` appartiene a `wm-package` (`routes/api.php:211`) e **non** versiona il contratto SUS: non scriverlo nella documentazione.
- **Nessun comando artisan di provisioning:** creazione del client e rotazione password si fanno da Nova.
- **Non loggare mai il token né l'header `Authorization`**, nemmeno troncati.
- **Non assegnare mai `Administrator` al client SUS.**
- PHPStan livello 5 (`phpstan.neon.dist`) deve restare verde sui file modificati.

---

### Task 1: Isolamento del database di test

Prerequisito di ogni test del ticket. Oggi `phpunit.xml` ha `DB_CONNECTION` e
`DB_DATABASE` commentati, quindi la suite usa la connessione di `.env` — il
database reale `forestas`, che contiene i dati importati da Sardegna Sentieri.

**Si adotta il pattern gia' in uso in `camminiditalia`**, per non inventare una
terza soluzione in casa: `.env.testing` **versionato** + `DB_DATABASE` in
`phpunit.xml` + variabili d'ambiente esplicite nello step CI dei test. Il file
versionato e' cio' che rende l'isolamento valido per tutti i dev senza setup
manuale; le env var nello step CI servono perche' `.env.testing` verrebbe
caricato anche la' (`APP_ENV=testing` e' impostato da `phpunit.xml`) portandosi
dietro hostname Docker validi solo in locale. Le variabili di uno step GitHub
Actions sono variabili reali del processo e `Illuminate\Support\Env` non le
sovrascrive mai con un valore da file, quindi vincono sempre.

Riferimento: `camminiditalia/phpunit.xml`, `camminiditalia/.env.testing`,
`camminiditalia/.github/workflows/run-tests.yml` (step "Laravel Tests"), dove il
commento in-line documenta l'incidente che quel fix ha risolto — 40 test falliti
in CI con `getaddrinfo for redis failed`.

**Files:**
- Create: `.env.testing` (versionato)
- Modify: `phpunit.xml:23-24`
- Modify: `tests/Pest.php:15`
- Modify: `.github/workflows/run-tests.yml` (step "Laravel Tests")
- Modify: `CLAUDE.md` (sezione "Setup progetto" e "Regole obbligatorie per i test")
- Test: `tests/Feature/DatabaseIsolationTest.php` (create)

**Interfaces:**
- Consumes: niente.
- Produces: suite Pest eseguibile su `forestas_testing` in locale e sul database del container in CI, con `RefreshDatabase` attivo sui test in `tests/Feature`.

- [ ] **Step 1: Creare il database di test con PostGIS**

```bash
docker exec -i postgres-forestas psql -U forestas -d postgres -c "CREATE DATABASE forestas_testing;"
docker exec -i postgres-forestas psql -U forestas -d forestas_testing -c "CREATE EXTENSION IF NOT EXISTS postgis;"
docker exec -i postgres-forestas psql -U forestas -d forestas_testing -c "SELECT PostGIS_Version();"
```

L'ultimo comando deve stampare la versione di PostGIS: le migration di
`wm-package` usano colonne geometriche, quindi SQLite in memoria non e'
utilizzabile.

- [ ] **Step 2: Scrivere il test che verifica l'isolamento**

`tests/Feature/DatabaseIsolationTest.php`:

```php
<?php

it('non gira sul database reale', function () {
    expect(config('database.connections.pgsql.database'))->not->toBe('forestas');
});
```

L'asserzione e' in negativo e non `toBe('forestas_testing')`: in CI il database
si chiama `forestas` sul container effimero, dove non c'e' nulla da proteggere,
ma questo test deve passare in entrambi gli ambienti. Cio' che conta e' che in
locale non sia il database reale.

**Nota:** in CI questo specifico test fallirebbe, perche' li' il database si
chiama davvero `forestas`. Marcarlo come locale:

```php
<?php

it('non gira sul database reale', function () {
    expect(config('database.connections.pgsql.database'))->toBe('forestas_testing');
})->skip(fn () => env('CI') === 'true', 'In CI il database e\' un container effimero.');
```

- [ ] **Step 3: Eseguire il test e verificare che fallisca**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/DatabaseIsolationTest.php`
Expected: FAIL — la suite sta girando su `forestas`.

- [ ] **Step 4: Creare `.env.testing` (versionato)**

`.env.testing`, sul modello di quello di `camminiditalia` — solo le variabili
che devono differire, non una copia di `.env`:

```
APP_ENV=testing
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=forestas_testing
DB_USERNAME=forestas
DB_PASSWORD=<lo stesso valore di DB_PASSWORD in .env>
```

`DB_HOST=db` e' l'hostname del servizio nella rete Docker locale: e' il motivo
per cui in CI questo file va neutralizzato con le env var dello Step 7.

**Il file va versionato** — e' quello che rende l'isolamento valido per tutti i
dev senza setup manuale. Verificare che `.gitignore` non lo escluda:

```bash
git check-ignore -v .env.testing
```

Expected: nessun output (il file non e' ignorato). In `.gitignore` sono elencati
`.env`, `.env.backup`, `.env.production`, non `.env.testing`.

- [ ] **Step 5: Impostare il database in `phpunit.xml`**

Sostituire le due righe commentate:

```xml
        <!-- <env name="DB_CONNECTION" value="sqlite"/> -->
        <!-- <env name="DB_DATABASE" value=":memory:"/> -->
```

con:

```xml
        <env name="DB_DATABASE" value="forestas_testing"/>
```

Una sola riga, come in `camminiditalia`: `DB_CONNECTION` resta quello di
`.env`/`.env.testing`, e SQLite non e' utilizzabile per via di PostGIS.

- [ ] **Step 6: Attivare `RefreshDatabase` sui test Feature**

In `tests/Pest.php` decommentare la riga 15:

```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```

**Differenza consapevole dal pattern di `camminiditalia`**, dove `RefreshDatabase`
e' ancora commentato: i test di questo ticket creano utenti e ruoli, e senza
refresh i record si accumulerebbero fra un'esecuzione e l'altra facendo fallire
i vincoli di unicita' sull'email. E' sicuro perche' opera sul database di test.
Se dovesse rallentare troppo la suite, l'alternativa e' `DatabaseTransactions`:
annotare la scelta in `notes.md`.

- [ ] **Step 7: Neutralizzare `.env.testing` in CI**

In `.github/workflows/run-tests.yml`, allo step `Laravel Tests`, aggiungere il
blocco `env` — stessa soluzione di `camminiditalia`:

```yaml
      - name: Laravel Tests
        # DB_HOST/DB_DATABASE/REDIS_HOST espliciti: senza, .env.testing (committato per
        # l'uso locale, con hostname Docker validi solo sulla rete locale: DB_HOST=db)
        # verrebbe caricato anche qui perche' APP_ENV=testing e' impostato da phpunit.xml.
        # Le env var di uno step GitHub Actions sono variabili reali del processo:
        # Illuminate\Support\Env (repository "immutable") non le sovrascrive mai con un
        # valore da file, quindi vincono sempre su .env.testing.
        run: php artisan test --log-events-verbose-text storage/logs/test.log
        env:
          DB_HOST: localhost
          DB_DATABASE: ${DB_DATABASE}
          REDIS_HOST: localhost
```

`DB_DATABASE` deve valere quanto `POSTGRES_DB` del servizio `db` gia' definito
nel workflow: **non** creare un database nuovo in CI e non rinominare quello
esistente. Verificare il valore effettivo prima di scrivere la riga.

`REDIS_HOST` va incluso anche se `phpunit.xml` forza gia' `CACHE_STORE=array`:
in `camminiditalia` un job usava `Cache::store('redis')` hardcoded bypassando
quell'override, e la CI falliva con `getaddrinfo for redis failed`. Verificare se
lo stesso accade qui e annotarlo in `notes.md`.

- [ ] **Step 8: Eseguire il test e verificare che passi**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/DatabaseIsolationTest.php`
Expected: PASS.

Verificare che il database reale sia intatto:

```bash
docker exec -i postgres-forestas psql -U forestas -d forestas -c "SELECT count(*) FROM ec_tracks;"
```

Expected: il conteggio non nullo dei track importati.

- [ ] **Step 9: Documentare in `CLAUDE.md`**

Nella sezione "Setup progetto", dopo il passo `php artisan migrate`, aggiungere:

```bash
# 3-bis. Database di test (una volta per ambiente)
docker exec -i postgres-${APP_NAME} psql -U ${DB_USERNAME} -d postgres -c "CREATE DATABASE forestas_testing;"
docker exec -i postgres-${APP_NAME} psql -U ${DB_USERNAME} -d forestas_testing -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

Il file `.env.testing` e' versionato, quindi non va creato: basta il database.

Nella sezione "Regole obbligatorie per i test", sostituire l'avvertimento
sull'isolamento assente con lo stato attuale: l'isolamento e' garantito da
`.env.testing` versionato e da `DB_DATABASE` in `phpunit.xml`; resta da creare
il database di test la prima volta su ogni macchina, e la verifica prima di
lanciare la suite resta buona prassi.

- [ ] **Step 10: Eseguire la suite completa e annotare lo stato di partenza**

Run: `docker exec -it php-forestas vendor/bin/pest`

I test preesistenti (`tests/Feature/Import/SardegnaSentieriImportServiceTest.php`,
`tests/Unit/Dto/*`) possono fallire per motivi indipendenti da questo ticket:
erano scritti quando la suite girava senza isolamento. **Annotare i fallimenti in
`notes.md` e non correggerli** — sono fuori scope. Serve solo conoscere lo stato
di partenza per distinguere le regressioni introdotte dai task successivi.

- [ ] **Step 11: Commit (istruzione per il developer)**

```bash
git add .env.testing phpunit.xml tests/Pest.php tests/Feature/DatabaseIsolationTest.php \
        .github/workflows/run-tests.yml CLAUDE.md
git commit -m "feat(oc:8333): isola il database di test seguendo il pattern camminiditalia"
```

---

### Task 2: Throttle sul login API (repo `wm-package`)

Unica modifica al package. Va mergiata prima del bump del submodule.

**Files:**
- Modify: `wm-package/routes/api.php:22`
- Modify: `wm-package/CLAUDE.md` (già fatto in fase di pianificazione — verificare che la sezione "Throttle sul login API (oc:8333)" sia presente)

**Interfaces:**
- Consumes: niente.
- Produces: `POST /api/auth/login` risponde 429 dopo 100 tentativi al minuto dallo stesso IP.

- [ ] **Step 1: Applicare il throttle**

In `wm-package/routes/api.php` sostituire:

```php
Route::post('/auth/login', [AppAuthController::class, 'login'])->name('auth.login');
```

con:

```php
Route::middleware('throttle:100,1')->post('/auth/login', [AppAuthController::class, 'login'])->name('auth.login');
```

La forma replica quella già usata sulla riga successiva per `signup`. Nessun rate limiter da registrare: l'alias `throttle` è fornito dal framework.

- [ ] **Step 2: Verificare che la route abbia il middleware**

Run: `docker exec -it php-forestas php artisan route:list --path=api/auth/login --json | jq '.[].middleware'`
Expected: l'array contiene `throttle:100,1`.

- [ ] **Step 3: Verificare che il login continui a funzionare**

Run:

```bash
docker exec -it php-forestas curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8000/api/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"inesistente@example.org","password":"password"}'
```

Expected: `401` — credenziali errate, non `429` e non `500`. Conferma che il middleware non rompe il percorso normale.

- [ ] **Step 4: Commit (istruzione per il developer, nel repo `wm-package`)**

```bash
cd wm-package
git add routes/api.php CLAUDE.md docs/features/8333-branch-api-sus-con-autenticazione-dedicata/
git commit -m "feat(oc:8333): applica throttle al login API"
```

---

### Task 3: Route group SUS ed endpoint di ping

**Files:**
- Create: `routes/sus.php`
- Create: `app/Http/Controllers/Api/Sus/SusPingController.php`
- Modify: `app/Providers/AppServiceProvider.php` (metodo `boot()`)
- Test: `tests/Feature/Sus/SusPingTest.php` (create)

**Interfaces:**
- Consumes: `Task 1` (suite eseguibile in isolamento).
- Produces: route con nome `sus.ping`, URI `api/v1/sus/ping`, metodo GET, middleware `api` + `auth:api`. Risposta JSON con chiavi `client`, `roles`, `timestamp`.

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/Feature/Sus/SusPingTest.php`:

```php
<?php

use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\User;

it('rifiuta il ping senza token', function () {
    $this->getJson('/api/v1/sus/ping')->assertUnauthorized();
});

it('risponde al ping con l identita del client autenticato', function () {
    Role::findOrCreate('Sus', 'web');

    $client = User::factory()->create([
        'name' => 'SUS Client',
        'email' => 'sus@example.invalid',
    ]);
    $client->assignRole('Sus');

    $token = auth('api')->login($client);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sus/ping')
        ->assertOk()
        ->assertJsonPath('client.email', 'sus@example.invalid')
        ->assertJsonPath('roles', ['Sus']);
});
```

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Sus/SusPingTest.php`
Expected: FAIL — entrambi con 404, la route non esiste.

- [ ] **Step 3: Creare il controller**

`app/Http/Controllers/Api/Sus/SusPingController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Sus;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SusPingController extends Controller
{
    /**
     * Conferma al client SUS che la connessione e l'autenticazione funzionano.
     */
    public function __invoke(): JsonResponse
    {
        $client = auth('api')->user();

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
            ],
            'roles' => $client->getRoleNames(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
```

- [ ] **Step 4: Creare il file di route**

`routes/sus.php`:

```php
<?php

use App\Http\Controllers\Api\Sus\SusPingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {
    Route::get('/ping', SusPingController::class)->name('ping');
});
```

- [ ] **Step 5: Registrare il route group nel provider**

In `app/Providers/AppServiceProvider.php` aggiungere l'import:

```php
use Illuminate\Support\Facades\Route;
```

e in fondo a `boot()`:

```php
        // Branch API riservato al SUS (oc:8333). Registrato qui e non in
        // routes/api.php perche' bootstrap/app.php non carica quel file:
        // stesso pattern di WmPackageServiceProvider.
        Route::name('sus.')
            ->middleware('api')
            ->prefix('api/v1/sus')
            ->group(base_path('routes/sus.php'));
```

- [ ] **Step 6: Eseguire i test e verificare che passino**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Sus/SusPingTest.php`
Expected: PASS, 2 test.

- [ ] **Step 7: Verificare la route registrata**

Run: `docker exec -it php-forestas php artisan route:list --path=api/v1/sus`
Expected: una riga `GET api/v1/sus/ping ... sus.ping`.

- [ ] **Step 8: Commit (istruzione per il developer)**

```bash
git add routes/sus.php app/Http/Controllers/Api/Sus/SusPingController.php \
        app/Providers/AppServiceProvider.php tests/Feature/Sus/SusPingTest.php
git commit -m "feat(oc:8333): aggiunge il route group SUS e l endpoint di ping"
```

---

### Task 4: Ruolo `Sus` ed esclusione dal gate Nova

**Files:**
- Modify: `app/Providers/NovaServiceProvider.php:126-131`
- Modify: `CLAUDE.md` (sezione "Setup progetto", elenco dei ruoli da creare)
- Test: `tests/Feature/Sus/SusNovaAccessTest.php` (create)

**Interfaces:**
- Consumes: `Task 3` (nessuna dipendenza di codice, solo ordine).
- Produces: `Gate::forUser($client)->allows('viewNova') === false` per un utente con ruolo `Sus`.

- [ ] **Step 1: Scrivere il test che fallisce**

`tests/Feature/Sus/SusNovaAccessTest.php`:

```php
<?php

use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\User;

it('nega l accesso a Nova al client SUS', function () {
    Role::findOrCreate('Sus', 'web');

    $client = User::factory()->create(['email' => 'sus@example.invalid']);
    $client->assignRole('Sus');

    expect(Gate::forUser($client)->allows('viewNova'))->toBeFalse();
});

it('non cambia l accesso a Nova per gli altri ruoli', function () {
    Role::findOrCreate('Validator', 'web');
    Role::findOrCreate('Guest', 'web');

    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    $guest = User::factory()->create();
    $guest->assignRole('Guest');

    expect(Gate::forUser($validator)->allows('viewNova'))->toBeTrue();
    expect(Gate::forUser($guest)->allows('viewNova'))->toBeFalse();
});
```

Il secondo test è una guardia di regressione: fissa il comportamento attuale di `Validator` (passa) e `Guest` (bloccato), così una futura riscrittura del gate da blacklist a controllo di permesso non passa inosservata.

- [ ] **Step 2: Eseguire i test e verificare che il primo fallisca**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Sus/SusNovaAccessTest.php`
Expected: il primo test FAIL (`viewNova` restituisce `true` per il client SUS), il secondo PASS.

- [ ] **Step 3: Estendere il gate**

In `app/Providers/NovaServiceProvider.php` sostituire:

```php
        Gate::define('viewNova', function (User $user) {
            return ! $user->hasRole('Guest');
        });
```

con:

```php
        Gate::define('viewNova', function (User $user) {
            // Il client SUS (oc:8333) e' un canale programmatico: non deve
            // accedere al backoffice. Il gate resta una blacklist di ruoli —
            // portarlo a can('access-nova') escluderebbe Validator e
            // Contributor, che oggi passano.
            return ! $user->hasAnyRole(['Guest', 'Sus']);
        });
```

- [ ] **Step 4: Eseguire i test e verificare che passino**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Sus/SusNovaAccessTest.php`
Expected: PASS, 2 test.

- [ ] **Step 5: Aggiungere `Sus` ai ruoli del setup in `CLAUDE.md`**

Nella sezione "Setup progetto", passo 4, sostituire:

```
foreach (['Administrator', 'Editor', 'Validator', 'Guest'] as \$name) {
```

con:

```
foreach (['Administrator', 'Editor', 'Validator', 'Guest', 'Contributor', 'Sus'] as \$name) {
```

`Contributor` esiste già nel database ma non era documentato.

- [ ] **Step 6: Creare il ruolo nell'ambiente locale**

```bash
docker exec -it php-forestas php artisan tinker --execute="
\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Sus', 'guard_name' => 'web']);
\Illuminate\Support\Facades\Artisan::call('permission:cache-reset');
echo \Spatie\Permission\Models\Role::pluck('name')->implode(', ');
"
```

Expected: l'elenco stampato contiene `Sus`. Il `permission:cache-reset` è necessario perché la cache dei permessi di Spatie è condivisa con i queue worker.

- [ ] **Step 7: Commit (istruzione per il developer)**

```bash
git add app/Providers/NovaServiceProvider.php CLAUDE.md tests/Feature/Sus/SusNovaAccessTest.php
git commit -m "feat(oc:8333): esclude il ruolo Sus dall accesso a Nova"
```

---

### Task 5: Restrizione del client SUS alle sole route consentite

Il task più delicato del piano. Il middleware deve poter bloccare route che vivono in `wm-package` (`auth/user`, `auth/delete`, `ugc/*`, `wallet/buy`), quindi non può essere applicato al solo gruppo SUS: va appeso al gruppo `api`.

**Nota di progettazione — perché non `auth:api` prima:** i middleware di gruppo girano **prima** di quelli di route, quindi un middleware appeso al gruppo `api` viene eseguito prima di `auth:api` e `auth()->user()` sarebbe `null`. La soluzione non è riordinare ma risolvere l'utente dal token direttamente con `auth('api')->user()`: il guard JWT legge l'header `Authorization` da sé, senza bisogno che `auth:api` sia già passato. Se il token è assente o invalido il middretto lascia passare la richiesta, e a rispondere 401 sarà `auth:api` come sempre.

**Files:**
- Create: `app/Http/Middleware/RestrictSusClient.php`
- Modify: `bootstrap/app.php` (blocco `withMiddleware`)
- Test: `tests/Feature/Sus/SusClientRestrictionTest.php` (create)

**Interfaces:**
- Consumes: `Task 3` (route `sus.ping`), `Task 4` (ruolo `Sus` creato).
- Produces: classe `App\Http\Middleware\RestrictSusClient`, appesa al gruppo `api`.

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/Feature/Sus/SusClientRestrictionTest.php`:

```php
<?php

use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    Role::findOrCreate('Sus', 'web');

    $this->client = User::factory()->create(['email' => 'sus@example.invalid']);
    $this->client->assignRole('Sus');
    $this->token = auth('api')->login($this->client);
});

it('nega al client SUS la cancellazione del proprio account', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/auth/delete')
        ->assertForbidden();

    expect(User::find($this->client->id))->not->toBeNull();
});

it('nega al client SUS la modifica del proprio profilo', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/auth/user', ['name' => 'cambiato'])
        ->assertForbidden();
});

it('consente al client SUS le route del proprio branch', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/sus/ping')
        ->assertOk();
});

it('consente al client SUS il refresh del token', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/auth/refresh')
        ->assertOk();
});

it('non limita gli utenti senza ruolo Sus', function () {
    $altro = User::factory()->create();
    $token = auth('api')->login($altro);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/user', ['name' => 'consentito'])
        ->assertSuccessful();
});
```

L'ultimo test è la guardia contro il rischio più grave di questo task: un middleware appeso al gruppo `api` attraversa **tutte** le API della piattaforma, e un errore nella risoluzione dell'utente le bloccherebbe tutte.

- [ ] **Step 2: Eseguire i test e verificare quali falliscono**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Sus/SusClientRestrictionTest.php`
Expected: i primi due FAIL (le route rispondono 200, non 403), gli ultimi tre PASS.

- [ ] **Step 3: Creare il middleware**

`app/Http/Middleware/RestrictSusClient.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Il client SUS (oc:8333) e' un canale programmatico verso un ente esterno:
 * puo' usare solo il proprio branch di API e i due endpoint che gli servono
 * per ottenere un token. Ogni altra route della piattaforma risponde 403.
 *
 * La whitelist e' per prefisso e non una blacklist di route da negare: le
 * route che wm-package aggiungera' al gruppo auth:api restano escluse per
 * costruzione, senza manutenzione.
 */
class RestrictSusClient
{
    /**
     * Prefissi consentiti al client SUS, senza slash iniziale.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'api/v1/sus/*',
        'api/auth/login',
        'api/auth/refresh',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isSusClient()) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED)) {
            return $next($request);
        }

        abort(403, __('Il client SUS non ha accesso a questo endpoint.'));
    }

    /**
     * Risolve l'utente dal token JWT presente nella richiesta.
     *
     * Questo middleware e' appeso al gruppo `api`, quindi viene eseguito
     * prima del middleware di route `auth:api`: auth()->user() sarebbe
     * sempre null. Il guard JWT legge l'header Authorization da se', percio'
     * l'utente e' risolvibile anche in questa posizione.
     *
     * Token assente, scaduto o malformato: la richiesta passa e a rispondere
     * 401 sara' `auth:api`, come per qualunque altro client.
     */
    private function isSusClient(): bool
    {
        try {
            $user = auth('api')->user();
        } catch (Throwable) {
            return false;
        }

        return $user !== null && $user->hasRole('Sus');
    }
}
```

- [ ] **Step 4: Appendere il middleware al gruppo `api`**

In `bootstrap/app.php` sostituire:

```php
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
```

con:

```php
    ->withMiddleware(function (Middleware $middleware) {
        // Il client SUS (oc:8333) deve poter essere bloccato anche sulle route
        // di wm-package (auth/user, auth/delete, ugc/*, wallet/buy), quindi il
        // middleware e' appeso al gruppo `api` e non applicato al solo gruppo
        // SUS. Non e' un middleware globale: le route web e Nova non lo
        // attraversano.
        $middleware->appendToGroup('api', \App\Http\Middleware\RestrictSusClient::class);
    })
```

- [ ] **Step 5: Eseguire i test e verificare che passino tutti**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Sus/SusClientRestrictionTest.php`
Expected: PASS, 5 test.

- [ ] **Step 6: Verificare che le altre API non siano regredite**

Run: `docker exec -it php-forestas vendor/bin/pest`
Expected: nessun fallimento nuovo rispetto allo stato annotato nel Task 1 Step 7.

- [ ] **Step 7: Aggiungere le traduzioni del messaggio 403**

Verificare le lingue disponibili:

```bash
docker exec -it php-forestas ls lang/
```

Per ogni lingua presente, aggiungere la chiave del messaggio nel file JSON corrispondente (es. `lang/it.json`):

```json
{
    "Il client SUS non ha accesso a questo endpoint.": "Il client SUS non ha accesso a questo endpoint."
}
```

Se `lang/` non esiste o non contiene file JSON, il testo base in italiano resta quello del codice e non serve alcun file: annotarlo in `notes.md`.

- [ ] **Step 8: Commit (istruzione per il developer)**

```bash
git add app/Http/Middleware/RestrictSusClient.php bootstrap/app.php \
        tests/Feature/Sus/SusClientRestrictionTest.php lang/
git commit -m "feat(oc:8333): restringe il client SUS alle sole route consentite"
```

---

### Task 6: Log di audit delle chiamate al branch SUS

**Files:**
- Create: `app/Http/Middleware/LogSusRequest.php`
- Modify: `config/logging.php` (nuovo canale `sus`, dopo il canale `import`)
- Modify: `routes/sus.php` (applicazione del middleware al gruppo)
- Test: `tests/Feature/Sus/SusRequestLogTest.php` (create)

**Interfaces:**
- Consumes: `Task 3` (route group), `Task 5` (client con ruolo `Sus`).
- Produces: canale di log `sus` che scrive su `storage/logs/sus.log`; classe `App\Http\Middleware\LogSusRequest`.

- [ ] **Step 1: Scrivere il test che fallisce**

`tests/Feature/Sus/SusRequestLogTest.php`:

```php
<?php

use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\User;

it('registra la chiamata al branch SUS senza mai loggare il token', function () {
    Role::findOrCreate('Sus', 'web');

    $client = User::factory()->create(['email' => 'sus@example.invalid']);
    $client->assignRole('Sus');
    $token = auth('api')->login($client);

    Log::shouldReceive('channel')->with('sus')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use ($client, $token) {
        expect($context['user_id'])->toBe($client->id);
        expect($context['endpoint'])->toBe('api/v1/sus/ping');
        expect($context['status'])->toBe(200);
        expect($context)->toHaveKey('timestamp');

        $serializzato = json_encode($context);
        expect($serializzato)->not->toContain($token);
        expect(array_keys($context))->not->toContain('authorization');

        return true;
    });

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sus/ping')
        ->assertOk();
});
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Sus/SusRequestLogTest.php`
Expected: FAIL — `Log::info` non viene mai chiamato sul canale `sus`.

- [ ] **Step 3: Aggiungere il canale di log**

In `config/logging.php`, subito dopo il blocco `'import' => [...]`, aggiungere:

```php
        'sus' => [
            'driver' => 'daily',
            'path' => storage_path('logs/sus.log'),
            'level' => 'info',
            'days' => 90,
            'replace_placeholders' => true,
        ],
```

Ritenzione a 90 giorni e non 30 come `import`: è il registro delle chiamate di un ente esterno, e in caso di contestazione la finestra utile è più lunga.

- [ ] **Step 4: Creare il middleware**

`app/Http/Middleware/LogSusRequest.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registra ogni chiamata al branch SUS (oc:8333) sul canale di log dedicato.
 *
 * Scopo diagnostico: quando Engineering segnala che una chiamata non risponde,
 * questo log dice se e' arrivata e con che esito.
 *
 * Il token e l'header Authorization non vengono mai scritti, nemmeno troncati.
 */
class LogSusRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        Log::channel('sus')->info('Richiesta al branch SUS', [
            'user_id' => $this->userId(),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'ip' => $request->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);

        return $response;
    }

    private function userId(): ?int
    {
        try {
            return auth('api')->user()?->id;
        } catch (Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 5: Applicare il middleware al gruppo SUS**

In `routes/sus.php` sostituire:

```php
Route::middleware('auth:api')->group(function () {
```

con:

```php
Route::middleware(['auth:api', \App\Http\Middleware\LogSusRequest::class])->group(function () {
```

Questo middleware è applicato al solo gruppo SUS, non al gruppo `api`: deve registrare le chiamate al branch, non tutto il traffico della piattaforma.

- [ ] **Step 6: Eseguire il test e verificare che passi**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Sus/SusRequestLogTest.php`
Expected: PASS.

- [ ] **Step 7: Verificare il log reale**

```bash
docker exec -it php-forestas php artisan tinker --execute="
\Illuminate\Support\Facades\Log::channel('sus')->info('verifica manuale', ['endpoint' => 'test']);
"
docker exec -it php-forestas tail -2 storage/logs/sus-$(date +%Y-%m-%d).log
```

Expected: la riga appena scritta. Conferma che il canale scrive su file e non solo nel mock del test.

- [ ] **Step 8: Commit (istruzione per il developer)**

```bash
git add app/Http/Middleware/LogSusRequest.php config/logging.php routes/sus.php \
        tests/Feature/Sus/SusRequestLogTest.php
git commit -m "feat(oc:8333): registra le chiamate al branch SUS su un canale dedicato"
```

---

### Task 7: Documentazione generata con Scribe

**Files:**
- Modify: `composer.json` (dipendenza `knuckleswtf/scribe`)
- Create: `config/scribe.php` (pubblicato dal package)
- Modify: `app/Http/Controllers/Api/Sus/SusPingController.php` (annotazioni Scribe)
- Modify: `.gitignore` (verificare se escludere `public/docs`)

**Interfaces:**
- Consumes: `Task 3` (route `sus.ping`).
- Produces: pagina HTML su `/docs`, spec OpenAPI e collection Postman, limitate alle route `api/v1/sus/*`.

- [ ] **Step 1: Installare Scribe**

```bash
docker exec -it php-forestas composer require --dev knuckleswtf/scribe
docker exec -it php-forestas php artisan vendor:publish --tag=scribe-config
```

- [ ] **Step 2: Configurare lo scoping e disattivare le response calls**

In `config/scribe.php`:

```php
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/v1/sus/*'],
                'domains' => ['*'],
            ],
            'include' => [],
            'exclude' => [],
        ],
    ],
```

Nella sezione `apply`, disattivare le chiamate reali:

```php
            'response_calls' => [
                'methods' => [],
            ],
```

Senza token Scribe catturerebbe un 401 come risposta d'esempio, che non serve a nessuno: le risposte vengono dichiarate nel controller.

Impostare inoltre:

```php
    'title' => 'Catasto Sentieri — API SUS',
    'description' => 'API di integrazione tra il Catasto Sentieri di Forestas e lo Sportello Unico Sentieri.',
    'auth' => [
        'enabled' => true,
        'default' => false,
        'in' => 'bearer',
        'name' => 'Authorization',
        'use_value' => null,
        'extra_info' => 'Il token si ottiene con POST /api/auth/login usando le credenziali del client SUS.',
    ],
```

- [ ] **Step 3: Annotare il controller**

In `app/Http/Controllers/Api/Sus/SusPingController.php` aggiungere il docblock sul metodo `__invoke`:

```php
    /**
     * Ping
     *
     * Conferma che la connessione e l'autenticazione funzionano, restituendo
     * l'identita' del client autenticato. Non tocca alcun dato del Catasto.
     *
     * @authenticated
     *
     * @response 200 {
     *   "client": {"id": 42, "name": "SUS Client", "email": "sus@example.invalid"},
     *   "roles": ["Sus"],
     *   "timestamp": "2026-09-07T10:00:00+00:00"
     * }
     * @response 401 {"message": "Unauthenticated."}
     */
```

Il gruppo della documentazione va dichiarato a livello di classe:

```php
/**
 * @group API SUS
 */
class SusPingController extends Controller
```

- [ ] **Step 4: Generare la documentazione**

```bash
docker exec -it php-forestas php artisan scribe:generate
```

Expected: output che elenca **una sola** route (`GET api/v1/sus/ping`). Se ne elenca altre, lo scoping dei prefissi è sbagliato e va corretto prima di procedere: la documentazione consegnata a Engineering non deve contenere le route di `wm-package`.

- [ ] **Step 5: Verificare la pagina e gli artefatti**

```bash
docker exec -it php-forestas curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/docs
docker exec -it php-forestas ls -la public/docs/ storage/app/private/scribe/ 2>/dev/null
```

Expected: `200` sulla pagina; tra gli artefatti la collection Postman e lo spec OpenAPI (i percorsi esatti dipendono dalla configurazione pubblicata — annotarli in `notes.md` per la consegna).

- [ ] **Step 6: Decidere se versionare gli artefatti generati**

```bash
git status --porcelain public/ storage/ .scribe/ 2>/dev/null | head -20
```

Gli artefatti generati vanno versionati **solo** se la generazione non è parte del deploy. Poiché `scribe:generate` va lanciato a mano (non in CI, per non toccare il database), versionarli è la scelta corretta: altrimenti dopo un deploy `/docs` risponderebbe 404. Annotare la decisione in `notes.md`.

- [ ] **Step 7: Verificare PHPStan**

Run: `docker exec -it php-forestas vendor/bin/phpstan analyse app/Http/Controllers/Api/Sus app/Http/Middleware`
Expected: nessun errore.

- [ ] **Step 8: Commit (istruzione per il developer)**

```bash
git add composer.json composer.lock config/scribe.php \
        app/Http/Controllers/Api/Sus/SusPingController.php .scribe/ public/docs/
git commit -m "docs(oc:8333): genera la documentazione delle API SUS con Scribe"
```

---

### Task 8: Procedura operativa e chiusura documentale

**Files:**
- Modify: `CLAUDE.md` (sezione "Comandi utili" e "Decisioni architetturali" — verificare quanto già scritto in fase di pianificazione; aggiungere la procedura di creazione del client)
- Create: `docs/features/8333-branch-api-sus-con-autenticazione-dedicata/notes.md`

**Interfaces:**
- Consumes: tutti i task precedenti.
- Produces: procedura eseguibile da un collega senza contesto; `notes.md` compilato.

- [ ] **Step 1: Documentare la procedura di creazione del client**

In `CLAUDE.md`, dopo la sezione "Gestione del client SUS (oc:8333)" già presente, aggiungere:

```markdown
**Creazione del client SUS (da Nova, non da comandi):**

1. Nova → Users → Create User
2. Nome: `SUS Client`. Email: un indirizzo su dominio **non instradabile**
   (es. `sus@catasto.invalid`) — Nova espone il reset password pubblico e un
   reset innescato per errore cambierebbe la password del client
3. Password: generata lunga e casuale (il login non ha throttle prima del
   merge della PR wm-package di oc:8333)
4. Roles: selezionare **solo** `Sus`. Mai `Administrator`: porterebbe il
   permesso `access-nova` e quindi accesso al backoffice a un fornitore esterno
5. Consegnare a Engineering, su canali separati: l'URL della documentazione
   (`/docs`) e le credenziali. Mai nello stesso messaggio
6. Ripetere su ogni ambiente (UAT per il collaudo, produzione al go-live) con
   credenziali distinte

I campi Password e Roles del resource utente sono in sola lettura per chi non
passa `RolesAndPermissionsService::allowsUser()` (allowlist di email).
```

- [ ] **Step 2: Aggiungere la feature all'elenco in `CLAUDE.md`**

Nella tabella della sezione "Feature disponibili" aggiungere la riga:

```markdown
| Branch API SUS | oc:8333 | `routes/sus.php`, `app/Http/Controllers/Api/Sus/`, `app/Http/Middleware/RestrictSusClient.php`, `LogSusRequest.php`, `config/logging.php`, `config/scribe.php`, `wm-package/routes/api.php` | Branch `/api/v1/sus/*` autenticato JWT per l'integrazione con lo Sportello Unico Sentieri. Solo scaffolding: nessuna logica di business |
```

- [ ] **Step 3: Compilare `notes.md`**

`docs/features/8333-branch-api-sus-con-autenticazione-dedicata/notes.md`:

```markdown
> Ticket: oc:8333

# Notes — Branch API SUS con autenticazione dedicata

## Deviazioni dal piano
[Compilare durante l'esecuzione. Se nessuna: "Nessuna deviazione rilevante."]

## Bug trovati
[Annotare qui i fallimenti dei test preesistenti rilevati nel Task 1 Step 7,
con l'indicazione che sono fuori scope]

## Decisioni
- `RestrictSusClient` risolve l'utente con `auth('api')->user()` invece di
  affidarsi a `auth()->user()`: appeso al gruppo `api`, viene eseguito prima
  del middleware di route `auth:api`. L'overview parlava di "eseguito dopo
  `auth:api`", ipotesi rivelatasi non praticabile — le route da bloccare
  vivono in `wm-package` e l'unico punto di intercettazione comune è il gruppo
  `api`
- Ritenzione del canale di log `sus` a 90 giorni invece dei 30 di `import`
- [Annotare la decisione sul versionamento degli artefatti Scribe, Task 7 Step 6]

## Follow-up
- Gate Nova da blacklist di ruoli a controllo del permesso `access-nova`:
  escluderebbe `Validator` e `Contributor`, ticket dedicato
- Ramo mobile/referrer di `AppAuthController::login`: `$user->app` non e' una
  relazione definita su `User`, quindi il ramo e' rotto per qualunque utente.
  Ticket sul package
- Verificare i flussi di login degli altri consumer di `wm-package` al momento
  del bump del submodule pointer (throttle sul login)
- Tre sezioni `## Decisioni architetturali` duplicate in `wm-package/CLAUDE.md`
  (righe 23, 162, 204)
```

- [ ] **Step 4: Verifica finale end-to-end**

```bash
docker exec -it php-forestas vendor/bin/pest
docker exec -it php-forestas vendor/bin/phpstan analyse
docker exec -it php-forestas php artisan route:list --path=api/v1/sus
docker exec -it php-forestas curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/docs
```

Expected: suite senza fallimenti nuovi rispetto al Task 1 Step 7; PHPStan verde sui file del ticket; la route del ping registrata; `/docs` risponde 200.

- [ ] **Step 5: Prova manuale del flusso completo**

Creare il client da Nova secondo la procedura dello Step 1, poi:

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"sus@catasto.invalid","password":"<password-scelta>"}' | jq -r '.access_token // .token')

# atteso 200 con l'identita' del client
curl -s -w "\n%{http_code}\n" http://localhost:8000/api/v1/sus/ping -H "Authorization: Bearer $TOKEN"

# atteso 403
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8000/api/auth/delete -H "Authorization: Bearer $TOKEN"

# atteso: una riga con endpoint api/v1/sus/ping e status 200, nessuna traccia del token
tail -3 storage/logs/sus-$(date +%Y-%m-%d).log
```

La chiave della risposta di login (`access_token` o `token`) va verificata su `AppAuthController::loginResponse` e annotata nella documentazione Scribe.

- [ ] **Step 6: Commit (istruzione per il developer)**

```bash
git add CLAUDE.md docs/features/8333-branch-api-sus-con-autenticazione-dedicata/
git commit -m "docs(oc:8333): documenta la procedura operativa del client SUS"
```
