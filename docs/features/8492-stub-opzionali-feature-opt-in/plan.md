> Ticket: oc:8492

# Adozione del gate stub wm-package in forestas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: usa `superpowers:executing-plans`. Gli step usano checkbox (`- [ ]`).

**Goal:** forestas verifica in CI che il proprio schema sia allineato agli stub di wm-package, come gia' fa maphub, e dichiara l'adesione al dominio `trail_registry`.

**Architecture:** due righe nel workflow dei test, replicate da maphub, piu' la variabile del dominio nei file di configurazione che la CI e la suite leggono. Prima va risolto l'unico disallineamento pregresso, altrimenti il gate nasce rosso.

**Tech Stack:** Laravel 12, PHP 8.4, GitHub Actions, PostgreSQL/PostGIS.

**Spec:** `docs/features/8492-stub-opzionali-feature-opt-in/overview.md` (questo repo) e `wm-package/docs/features/8492-stub-opzionali-feature-opt-in/overview.md` (il meccanismo).

## Global Constraints

- **Va eseguito dopo il merge del piano di wm-package**: usa `--with`, che prima non esiste.
- **Nessun commit automatico.** I passi "Commit" sono istruzioni per il developer.
- **Non lanciare la suite di test senza aver verificato l'isolamento del database** (`phpunit.xml` e `.env.testing` devono puntare a `forestas_testing`, che deve esistere). Il database reale contiene dati importati da processi lunghi.
- Commit: `feat(oc:8492): ...` / `fix(oc:8492): ...`

---

## Task 1: Risolvere il disallineamento di `create_users_table`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-disallineamento-create-users-table)


Misurato sul container, due stub disallineati:

- **`create_users_table`** — si aspetta tre colonne che lo schema di forestas non
  ha: `users.balance`, `users.fiscal_code`, `users.app_id`. E' quello con una
  decisione dentro.
- **`zz_2026_07_27_000001_add_surname_to_users_table`** — la colonna
  `users.surname` non esiste, non c'e' nessuna migration corrispondente in
  `database/migrations/` e la tabella `migrations` non ne ha traccia. E'
  arrivato con un aggiornamento del submodule durante questo ciclo, e a
  differenza del primo e' meccanico: una colonna nullable da aggiungere.

Finche' restano, il gate nasce rosso. **Rimisurare prima di intervenire:**
l'elenco cambia a ogni aggiornamento del package.

**Files:**
- Da decidere nel Task, in base all'esito dello Step 2.

**Questo task contiene una decisione, non e' meccanico.** Non va eseguito in autonomia: si raccolgono i dati e si chiede al developer.

- [ ] **Step 1: Riprodurre e delimitare**

```bash
docker exec php-forestas php artisan wm-package:publish-missing-migrations --dry-run
```

Atteso: due stub — `create_users_table` con le tre colonne, e `zz_2026_07_27_000001_add_surname_to_users_table` senza gap elencati (passa dal ramo di fallback che cerca la migration nella tabella `migrations`). L'elenco puo' essere cresciuto ancora: fa fede questo output, non il piano.

- [ ] **Step 2: Capire a chi servono quelle colonne**

```bash
cd wm-package
grep -rn "balance\|fiscal_code" src/ --include=*.php | grep -v node_modules | head -20
grep -rn "'app_id'" src/Models/User.php src/Nova/AbstractUserResource.php
sed -n '/Schema::create..users/,/});/p' database/migrations/create_users_table.php.stub
```

Poi confrontare con la migration reale di forestas:

```bash
cd ..
grep -rln "create.*users" database/migrations/ | head
```

- [ ] **Step 3: Presentare le opzioni al developer e attendere la decisione**

Le possibilita', da valutare con i dati raccolti:

Per `add_surname_to_users_table` non serve una decisione: `publish-migration zz_2026_07_27_000001_add_surname_to_users_table`, poi `migrate`. Per `create_users_table`:

- **Pubblicare lo stub** con `publish-migration create_users_table` e migrare: forestas prende le tre colonne. Corretto se il package le usa davvero in codice che forestas esegue.
- **Aggiungere solo le colonne mancanti** con una migration di forestas: stesso risultato sullo schema, senza importare l'intera `create_users_table` su una tabella che esiste gia'.
- **Segnalare che lo stub del package e' troppo largo** — se `balance` e `fiscal_code` appartengono a un prodotto specifico e non dovrebbero stare in uno stub obbligatorio, la sede giusta e' un ticket su wm-package, e questo piano si ferma qui in attesa.

Non scegliere per conto proprio: l'esito cambia lo schema di una tabella di produzione.

- [ ] **Step 4: Applicare la decisione, migrare, verificare**

```bash
docker exec php-forestas php artisan migrate
docker exec php-forestas php artisan wm-package:publish-missing-migrations --dry-run
```

Atteso: `Tutti gli stub wm-package obbligatori risultano allineati al database.`

- [ ] **Step 5: Commit** (chiedere conferma al developer)

```bash
git add database/migrations/
git commit -m "fix(oc:8492): allinea lo schema users agli stub wm-package"
```

---

## Task 2: Dichiarare l'adesione al dominio `trail_registry`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-dichiarare-ladesione)


**Files:**
- Modify: `.env-deploy`
- Modify: `.env-example`
- Modify: `phpunit.xml`
- Modify: `.env` (locale, non versionato — istruzione per il developer)

- [ ] **Step 1: `.env-deploy`**

E' il file che la CI copia in `.env` (`run-tests.yml:33`). In coda, con il commento:

```
# Attiva il dominio opzionale del Catasto Sentieri nel wm-package.
# Guida: wm-package/docs/resources/OptionalDomains.md
WM_TRAIL_REGISTRY_ENABLED=true
```

- [ ] **Step 2: `.env-example`**

Stesse due righe di commento e la variabile, cosi' chi installa il progetto da zero la trova.

- [ ] **Step 3: `phpunit.xml`**

`php artisan test` forza `APP_ENV=testing`, quindi Laravel carica `.env.testing` e **non** vede `.env-deploy`. La variabile va dichiarata fra gli `<env>`, accanto a `DB_DATABASE`:

```xml
        <env name="WM_TRAIL_REGISTRY_ENABLED" value="true"/>
```

`phpunit.xml` e' preferito a `.env.testing` perche' e' il file che dichiara le condizioni della suite, ed ha la precedenza sui file `.env`: il valore e' garantito indipendentemente dall'ambiente locale di chi lancia i test.

- [ ] **Step 4: Ricordare al developer il `.env` locale e quelli remoti**

Il `.env` locale non e' versionato: il developer deve aggiungere `WM_TRAIL_REGISTRY_ENABLED=true` a mano. Lo stesso vale per il `.env` dei server UAT e produzione, che nessun file di questo repo controlla — e' il punto piu' facile da dimenticare, ed e' il rischio accettato descritto nell'overview.

- [ ] **Step 5: Verificare che il dominio risulti acceso**

```bash
docker exec php-forestas php artisan tinker --execute="var_dump(config('wm-package.features.trail_registry.enabled'));"
```

Atteso: `bool(true)`.

- [ ] **Step 6: Commit** (chiedere conferma al developer)

```bash
git add .env-deploy .env-example phpunit.xml
git commit -m "feat(oc:8492): dichiara l'adesione al dominio trail_registry"
```

---

## Task 3: Aggiungere il gate alla CI

**Files:**
- Modify: `.github/workflows/run-tests.yml` (fra lo step `Migrate` e lo step `Clear the config cache`)

- [ ] **Step 1: Verificare che il comando funzioni con il dominio dichiarato ma senza stub**

Fra il merge di questo ticket e quello di oc:8489 il dominio esiste in configurazione ma non ha ancora stub. Il comando deve passare comunque:

```bash
docker exec php-forestas php artisan wm-package:publish-missing-migrations --with=trail_registry --dry-run
```

Atteso: esito identico a quello senza `--with`, exit code 0. Se fallisce con un errore sul dominio, **fermarsi**: significa che il requisito del piano di wm-package sulla validazione di `--with` non e' stato rispettato, e va corretto li'.

- [ ] **Step 2: Aggiungere lo step**

In `.github/workflows/run-tests.yml`, subito dopo lo step `Migrate` (righe 39-42) e prima di `Clear the config cache`:

```yaml
      - name: Check wm-package stub vs schema DB
        run: php artisan wm-package:publish-missing-migrations --with=trail_registry --dry-run
```

Stesso nome di step e stessa posizione di maphub (`maphub/.github/workflows/run-tests.yml:50-51`); l'unica differenza e' `--with=trail_registry`. Nessun `continue-on-error`: il gate deve fermare la pipeline.

- [ ] **Step 3: Verificare sulla PR**

Aprire la PR verso `develop` e controllare che lo step compaia e sia verde. E' l'unica verifica reale: il `--dry-run` locale gira su un dump di produzione, la CI costruisce lo schema da zero e puo' dare un esito diverso.

Se la CI e' rossa per disallineamenti che in locale non si vedevano, tornare al Task 1 con i nuovi dati e ripresentare la decisione al developer.

- [ ] **Step 4: Commit** (chiedere conferma al developer)

```bash
git add .github/workflows/run-tests.yml
git commit -m "feat(oc:8492): gate stub wm-package nella CI"
```

---

## Task 4: Aggiornare `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md` (sezioni "Feature disponibili" e "Decisioni architetturali")

- [ ] **Step 1: Riga in "Feature disponibili"**

```markdown
| Adozione gate stub wm-package + dominio trail_registry | oc:8492 | `.github/workflows/run-tests.yml`, `.env-deploy`, `.env-example`, `phpunit.xml`, `database/migrations/` | Forestas e' il secondo repo (dopo maphub) con `publish-missing-migrations --dry-run` in CI. Dichiara l'adesione al dominio opzionale del Catasto Sentieri |
```

- [ ] **Step 2: Blocco in "Decisioni architetturali"**, in cima

```markdown
### Adozione gate stub wm-package (oc:8492)
- Il gate `publish-missing-migrations --dry-run` era attivo in **1 repo su 16**
  (solo maphub, da oc:8218). Forestas e' il secondo: non e' la coda del ticket
  del package ma il primo passo per farne uno standard
- `WM_TRAIL_REGISTRY_ENABLED` va tenuta allineata in cinque posti, di cui **uno
  fuori dal repository**: `.env`, `.env-deploy`, `.env-example`, `phpunit.xml`,
  e il `.env` del server. Solo i primi quattro si vedono in un diff
- **Se il Catasto Sentieri smette di rispondere senza motivo apparente, la causa
  va cercata qui per prima.** `scripts/deploy_prod.sh` esegue `php artisan
  optimize`, che congela la configurazione: se il `.env` del server perde quella
  chiave, route e Nova resource del catasto spariscono mentre la tabella resta
  piena di dati, e il SUS riceve 404. Una verifica automatica al deploy e' stata
  valutata e scartata come non necessaria: rischio accettato consapevolmente
- Lo step di CI e' copiato verbatim da maphub tranne `--with=trail_registry`,
  che non e' necessario (il flag in `.env-deploy` basta) ma rende leggibile cosa
  quello step controlla. `--with` puo' solo aggiungere domini alla verifica, mai
  toglierne, quindi la ridondanza non puo' mascherare nulla
- Gli stub di un dominio opzionale **non** si pubblicano con `vendor:publish`
  (la scoperta delle migration di Spatie non e' ricorsiva): serve
  `publish-migration trail_registry/<stub>`. Riguardera' oc:8489, che porta il
  primo stub del dominio
```

- [ ] **Step 3: Commit** (chiedere conferma al developer)

```bash
git add CLAUDE.md
git commit -m "docs(oc:8492): documenta l'adozione del gate e il dominio trail_registry"
```

---

## Ordine e dipendenze

Task 1 → 2 → 3 → 4, in sequenza stretta. Il Task 1 e' un prerequisito assoluto: aggiungere il gate prima di aver risolto `create_users_table` significa aprire una PR rossa. Il Task 3 dipende dal merge del piano di wm-package, perche' usa `--with`.
