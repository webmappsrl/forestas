> Ticket: oc:8672

# Piano — Chiavi di traduzione in inglese nel Catasto Sentieri (parte forestas)

Fonte: [overview.md](overview.md). La «Tabella delle chiavi» in fondo all'overview è l'elenco di
lavoro. La parte del package ha un piano suo, in `wm-package/docs/features/8672-…/plan.md`, e
**si fa il merge prima lì**: questo piano si chiude solo con il puntatore del submodule sul merge
di oc:8672 nel package.

**Repo:** `forestas`. **Branch:** `feature/oc-8672-chiavi-di-traduzione-in-inglese-nel-catasto-sentieri-wm-package-e-in-forestas`,
da `origin/develop` (che contiene già oc:8567, puntatore su `bdd9c059`).

**Durante il lavoro** il submodule sta sul branch di oc:8672 del package, così le chiavi
condivise (`Trail registry`, `Trail`) hanno già la voce. Il puntatore definitivo si fissa al
Task 7.

**Comandi:** `docker exec -it php-forestas vendor/bin/pest --filter=<nome>`. Prima di lanciare la
suite verifica l'isolamento come da `.claude/rules/test.md`: `.env.testing` punta a
`forestas_testing` e il database esiste. Se non è verificabile, non si lancia e si chiede.

**Commit:** nessun commit durante l'esecuzione. I messaggi qui sotto sono proposte per il dev.

---

## Task 1 — Test nuovo sulle chiavi, prima del codice (deve fallire)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-test-nuovo-sulle-chiavi)

File: `tests/Feature/TranslationKeysTest.php`.

1. L'elenco atteso `chiave inglese => testo italiano`, **copiato dalla «Tabella delle chiavi»
   dell'overview**, che riporta il testo di oggi. Mai ricavarlo da `lang/it.json`.
2. Estrazione delle chiavi letterali di `__()` in `app/` con `token_get_all`, decodificando il
   valore come stringa PHP: `CreateSusClient.php:43-46` e `:62-65` hanno `__(` su più righe,
   `SusAuthController.php:70` ha apici con escape.
3. Le voci si cercano nell'unione di `wm-package/resources/lang/<lingua>.json` e
   `lang/<lingua>.json`, con forestas che vince, come in `FileLoader::loadJsonPaths`.
4. Test:
   - **ogni chiave dell'elenco atteso** ha la voce in `en` e in `it` (unione), e il valore
     italiano è uguale al testo atteso;
   - **nessuna chiave italiana della tabella** compare ancora come chiave letterale in `app/`;
   - **le altre chiavi letterali senza voce**, già inglesi (`Admin`, `Files`, `Icons`,
     `Vocabulary`, `Info`, `Forestas`, `DEM`, `Email`, `Password`), e la chiamata dinamica di
     `RegistroRowColumnFilter.php:30` si scrivono su `STDERR`: non fanno fallire il test.
5. Lancia il test: deve fallire.

## Task 2 — Test nuovo sul menu

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-verifica)

File: `tests/Feature/TrailRegistry/TrailRegistryMenuSectionTest.php`.

Con `app()->setLocale('en')`, il dominio acceso (`phpunit.xml:31`) e un utente **Administrator**
(dall'oc:8700 la sezione si nasconde se nessuna voce è visibile, quindi con un altro ruolo il
test passerebbe senza provare nulla):

- costruisci il menu principale di Nova come fa `wm-package/tests/Feature/TrailRegistry/MainMenuInjectionTest.php`
  (`ServingNova::dispatch` e poi il callback), a partire dal menu di
  `app/Providers/NovaServiceProvider.php`;
- verifica che ci sia **esattamente una** `MenuSection` con nome `__('Trail registry')`;
- verifica che contenga sia le voci di forestas (righe del registro, documentazione API SUS) sia
  quelle del package (`Applications`, `Code registry`, `Anomalies`).

Con le chiavi di oggi deve fallire: forestas crea «Catasto» e il package cerca `Trail registry`.

## Task 3 — Voci nei JSON

File: `lang/it.json`, `lang/en.json`.

1. **Chiavi con la voce già nel package con lo stesso testo: non si ricopiano.** Sono `Trail`,
   `Trail registry` (nuove nel package con oc:8672), `Name` («Nome») e `Municipality`
   («Comune»). `SUS API documentation` e `Registry` hanno già la voce in forestas.
2. **Chiavi italiane che hanno già una voce in `lang/en.json`** (`RegistroTabRenderer`,
   `AnomalyTypes`, `TrailRegistryAnomaly.php:62-63`, `Foglio`, `Riga`, `Righe`, `Colonna`,
   `Valore`, `Importato il`, `Codici candidati`, `Codice del foglio`, …): la voce inglese di oggi
   diventa la chiave. In `en.json` la voce diventa `"<chiave>": "<chiave>"`, in `it.json`
   `"<chiave>": "<testo italiano di oggi>"`. Le voci che dicevano «Catasto» si allineano a
   «Trail registry», come da tabella (`Trail registry code`, «…in Trail registry › Anomalies…»,
   «…from the trail registry», il testo di aiuto di `Nova/TrailRegistryAnomaly.php:63`).
3. **Chiavi senza nessuna voce** (`TipoEnte`, `SusAuthController`, `CreateSusClient`,
   `ImportTaxonomyWhereFromLayersAction`): voce nuova in tutti e due i file.
4. Togli le voci con chiave italiana sostituite, compresa `"Catasto": "Catasto"`.
5. **Non toccare** le sovrascritture di forestas: `Where` e `Wheres` («Zona geografica», «Zone
   geografiche»).
6. Valida i due file e controlla che non ci siano chiavi duplicate.

## Task 4 — Conversione delle chiavi nel codice

Per ogni file sostituisci solo l'argomento di `__()`, secondo la tabella:

1. `app/Enums/TipoEnte.php`
2. `app/Http/Controllers/Api/Sus/SusAuthController.php`
3. `app/Nova/Actions/CreateSusClient.php`, `app/Nova/Actions/ImportTaxonomyWhereFromLayersAction.php`
4. `app/Nova/Filters/TrailAnomalySourceFilter.php` (`Catasto` → `Trail registry`, `Registro` →
   `Registry`; le label sono le chiavi dell'array di `options()`, quindi devono restare diverse
   fra loro)
5. `app/Nova/RegistroTabRenderer.php`, `app/Nova/TrailRegistryAnomaly.php`
6. `app/Providers/NovaServiceProvider.php` (`Catasto` → `Trail registry` alla riga 56,
   `Documentazione API SUS` → `SUS API documentation` alla riga 65)
7. `app/Services/RegistroCatastale/AnomalyTypes/*.php`

Alla fine i test dei Task 1 e 2 devono passare.

## Task 5 — Test esistenti

Aggiorna `tests/Feature/RegistroCatastale/RegistroAnomalyDetailRowsTest.php` e
`tests/Feature/TrailRegistry/RegistroTabTest.php` con le chiavi inglesi. Rifai la ricerca su
`tests/` con il tokenizer e con `grep` sulle stringhe italiane della tabella: l'elenco
dell'overview non è esaustivo. Verifica anche che `SusAuthTest` non confronti il testo dei
messaggi.

## Task 6 — Verifica

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-verifica)

1. Suite di forestas, dopo il controllo sull'isolamento: tutta verde.
2. `vendor/bin/phpstan analyse`: nessun errore nuovo sui file toccati.
3. `composer format`, poi `git status`: scarta i file fuori dal lavoro.
4. Controllo a mano in Nova con `APP_LOCALE=it`: una sola sezione «Catasto» con le voci di
   forestas e del package, il filtro provenienza delle anomalie («Catasto», «Registro»), la tab
   del registro su un codice, il dettaglio di un'anomalia del registro, l'action «Crea client
   SUS». I testi devono essere quelli di oggi, salvo le eccezioni dichiarate (`Details`, `Detail`,
   `Status`, `Row`).

## Task 7 — Puntatore del submodule (dopo il merge del package)

Quando la PR del package di oc:8672 è entrata in `develop`:

1. `git -C wm-package fetch origin`
2. `git -C wm-package checkout <commit-del-merge-di-oc:8672>`: **mai scrivere l'hash a mano**
   (con oc:8567 un hash scritto a mano e inesistente ha richiesto la correzione `cb16427`)
3. Verifica:
   - `git -C wm-package merge-base --is-ancestor <commit-del-merge-di-oc:8672> HEAD` risponde sì;
   - `git -C wm-package merge-base --is-ancestor bdd9c059 HEAD` risponde sì (oc:8567 resta);
   - `git -C wm-package branch -r --contains HEAD` mostra `origin/develop`.
4. Rilancia i test dei Task 1 e 2 con il puntatore definitivo.

## Task 8 — Documentazione

1. `notes.md` del cantiere.
2. La descrizione della PR di forestas, che va verso `develop`, deve dire che il puntatore va sul
   merge di oc:8672 del package e che contiene ancora oc:8567.

Commit proposto: `fix(oc:8672): chiavi di traduzione in inglese in forestas e puntatore di wm-package`.
