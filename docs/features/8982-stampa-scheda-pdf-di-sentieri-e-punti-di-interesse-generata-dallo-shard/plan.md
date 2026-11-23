> Ticket: oc:8982

# Stampa PDF di sentieri e punti di interesse — piano di Forestas

> **Per chi esegue:** sotto-skill richiesta `superpowers:subagent-driven-development` (consigliata)
> oppure `superpowers:executing-plans`. Gli step usano le checkbox (`- [ ]`). **Nessun commit
> automatico**: gli step «Commit» sono istruzioni per il dev, da eseguire solo su sua richiesta.
> Questo piano viene **dopo** quello del package
> ([wm-package/…/plan.md](../../../wm-package/docs/features/8982-stampa-scheda-pdf-di-sentieri-e-punti-di-interesse-generata-dallo-shard/plan.md)):
> usa il dominio `print`, la view di default, il comando `wm:print-style` e `install-deps.sh`.

**Obiettivo:** Forestas compila le schede PDF con TeX Live nella propria immagine e con la grafica
del design system di SARDEGNA Sentieri.

**Architettura:** l'immagine `wm-phpfpm:8.4` installa TeX Live con lo script del package, che
arriva da un secondo contesto di build; il deploy ricostruisce l'immagine quando cambia il puntatore
del package. Lo stile LaTeX si genera dal `tokens.json` versionato qui, e l'impaginazione è un
override delle view del package.

**Stack:** Docker Compose (≥ 2.17, BuildKit), GitHub Actions, Laravel 12, Horizon, Pest, XeLaTeX.

**Spec:** [overview.md](overview.md) di questa cartella e l'overview del package.

## Vincoli globali

- Leggere `.claude/rules/test.md` prima di lanciare la suite: il DB reale contiene dati importati.
- Identificatori in inglese, documentazione e commenti in italiano.
- Nessun segreto in file tracciati: il token del servizio delle mappe sta solo in `.env`.
- Colori di stato e di tipo solo per il loro ruolo; le tinte «solo grafica» non diventano testo (R12).

## Review Focus

1. Build su un host con Docker Compose più vecchio di 2.17: errore chiaro, non un'immagine senza TeX (Task 1).
2. Sentiero senza dati di `properties.forestas` (registro, inclusività): le relative parti dell'override non compaiono (Task 4).
3. Testo con caratteri non coperti da Work Sans: nessun errore di compilazione (Task 4).
4. Deploy con un Job lungo in corso su Horizon: il Job finisce prima che il container venga ricreato (Task 2).
5. Override non allineato al contratto della view del package: lo segnala la CI (Task 4).

---

### Task 1: Immagine PHP con TeX Live e Work Sans

**Files:**
- Modify: `docker/configs/phpfpm/Dockerfile`
- Modify: `compose.yml` (`php`, `horizon`), `develop.compose.yml` (`horizon`), `local.compose.yml` (`php`, `scout-init`, `laravel`)

- [ ] **Step 1:** verificare `docker compose version` sugli host di dev e UAT (≥ 2.17); se più vecchio, fermarsi e chiedere al dev (R2).
- [ ] **Step 2:** in ogni servizio che costruisce `wm-phpfpm:8.4`, `build.context: ./docker/configs/phpfpm` e `build.additional_contexts: { wmpackage: ./wm-package/docker/render }` (R2).
- [ ] **Step 3:** nel Dockerfile `COPY --from=wmpackage install-deps.sh VERSION /tmp/wm-print/` e `RUN bash /tmp/wm-print/install-deps.sh` (R1); installazione di Work Sans (pesi 300–700 e corsivo 400, licenza OFL) in `/usr/local/share/fonts/work-sans` più `fc-cache -f` (R8).
- [ ] **Step 4:** `stop_grace_period: 6m` sul servizio `horizon` di `compose.yml` (R3).
- [ ] **Step 5:** verificare: `docker compose -f local.compose.yml build php` riuscito; `docker exec php-forestas xelatex --version`; `docker exec php-forestas fc-list | grep "Work Sans"`; `cat /usr/local/share/wm-print/version` uguale a `wm-package/docker/render/VERSION`.
- [ ] **Step 6 (dev):** `git commit -m "feat(oc:8982): TeX Live e Work Sans nell'immagine PHP"`

### Task 2: Rebuild nel deploy e coda di stampa

**Files:**
- Modify: `.github/workflows/deploy.yml`, `config/horizon.php`, `.env-example`, `.env-deploy`

- [ ] **Step 1:** in `deploy.yml`, prima del `docker exec`, il confronto di `HEAD:wm-package` prima e dopo il pull e, se cambiato, `docker compose build php horizon` e `docker compose up -d php horizon`, come nello snippet di R3.
- [ ] **Step 2:** in `config/horizon.php` un supervisor per la coda `print` con `maxProcesses` 2, `timeout` 180 e `memory` 256 (il limite di `xelatex` lo dà `prlimit`); `maxProcesses` di `default` e `aws` portati a 10 (oggi 30 ciascuno; 47 worker fermi occupano circa 2,4 GB di PSS, R4). I valori finali dipendono dalla misura del picco di `xelatex` con le foto, da fare in questo task.
- [ ] **Step 3:** in `.env-example` e `.env-deploy`: `WM_PRINT_ENABLED=true`, `WM_PRINT_QUEUE=print`, `WM_PRINT_MAP_DRIVER=fake`, `WM_PRINT_MAP_SERVICE_URL=` e `WM_PRINT_MAP_SERVICE_TOKEN=` vuoti: finché oc:9011 non c'è la mappa è il mock del package (R5).
- [ ] **Step 4:** verificare: `docker exec php-forestas php artisan horizon:list` mostra il supervisor `print`; `php artisan config:show wm-package.features.print`.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): coda di stampa e rebuild dell'immagine nel deploy"`

### Task 3: Stile dal design system

**Files:**
- Create: `resources/print/design-system/tokens.json` (copia dal design system SARDEGNA Sentieri), `resources/print/forestas.sty` (generato)
- Create: `docs/howto/riallineare-lo-stile-di-stampa.md`

- [ ] **Step 1:** copiare `tokens.json` dall'artifact del design system.
- [ ] **Step 2:** `docker exec php-forestas php artisan wm:print-style resources/print/design-system/tokens.json resources/print/forestas.sty`.
- [ ] **Step 3:** verificare che lo `.sty` contenga `\definecolor{sar-blu}{HTML}{254877}` e `\setmainfont{Work Sans}`.
- [ ] **Step 3b:** in `app/Providers/AppServiceProvider.php`, come per `trail_registry`, `config(['wm-package.features.print.style_path' => resource_path('print/forestas.sty')])`: senza, il Job compila con `wm-print.sty` del package (chiave nata con la review finale del package).
- [ ] **Step 4:** scrivere la procedura di riallineamento: copia del nuovo `tokens.json`, comando, PDF di prova, commit (R6).
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): stile di stampa dal design system"`

### Task 4: Override delle schede di `EcTrack` ed `EcPoi`

**Files:**
- Create: `resources/views/vendor/wm-package/print/ec-track.blade.php`, `resources/views/vendor/wm-package/print/ec-poi.blade.php`, `resources/views/vendor/wm-package/print/partials/logotype.blade.php`
- Modify: `lang/it.json`, `lang/en.json` (etichette proprie: registro catastale, inclusività)
- Modify: `app/Http/Controllers/Api/EcPoiDetailController.php` (`properties.print_url` con `PrintService::sheetUrl('ec-poi', $id)`: il package non ha un dettaglio POI proprio)
- Test: `tests/Feature/Print/ForestasPrintOverrideTest.php`

**Interfaces:**
- Consumes: il contratto della view del package (docblock della view di default); i dati di
  `App\Support\ForestasTrackDetail::build()` per `properties.forestas`.

- [ ] **Step 1:** scrivere il test (R15), che fa il render degli override in stringa con un `EcTrack` e un `EcPoi` di prova, senza compilare:
  - contiene `\usepackage{forestas}`;
  - il logotipo è composto in testo, con le tinte per lettera di `LogotipoSardegna` e la coda «Sentieri» in `ink-3` peso 300, senza `\includegraphics` (R7);
  - la sezione del registro catastale compare solo se `forestas_registry_code` è valorizzato, l'inclusività solo se c'è il punteggio (R11);
  - nessuna variabile non definita: la view riceve solo le variabili del contratto più `$forestas`, che l'override calcola con `ForestasTrackDetail::build($model)`.
- [ ] **Step 1b:** test: `GET api/ec/poi/{id}` di Forestas contiene `properties.print_url` a dominio acceso e non lo contiene a dominio spento.
- [ ] **Step 2:** eseguire `vendor/bin/pest tests/Feature/Print/ForestasPrintOverrideTest.php` dopo il controllo di isolamento, atteso FAIL.
- [ ] **Step 3:** scrivere gli override. Ordine dei contenuti di `EcTrack` come nel template `SchedaSentiero.dc.html`: testata, stato, dati principali, descrizione, attività e difficoltà, mappa, foto, punti di interesse correlati (R9). `EcPoi` con lo stesso linguaggio grafico (R10). Finché il layout A4 non è disegnato, si parte dalla struttura della view di default con lo stile di Forestas.
- [ ] **Step 4:** test PASS; poi un PDF reale per un sentiero con descrizione lunga e uno senza foto, da far rivedere ad Alessio.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): schede PDF con la grafica di SARDEGNA Sentieri"`

### Task 5: Documentazione

**Files:**
- Create: `docs/knowledge/8982-stampa-delle-schede-pdf.md`
- Modify: `CLAUDE.md` (riga in «Conoscenza»)

- [ ] **Step 1:** scrivere la pagina: TeX Live nell'immagine e perché, rebuild nel deploy, coda `print`, stile da `tokens.json`, override, mock della mappa finché oc:9011 non c'è.
- [ ] **Step 2 (dev):** `git commit -m "docs(oc:8982): pagina sulla stampa delle schede"`, poi il commit separato del puntatore di `wm-package`, nella stessa PR.

### Task 6: Schede generate dal design system, sul modello del sito (R16-R19)

Dopo il Task 21 del package (tema dell'App, mappa configurabile, niente token).

**Files:**
- Delete: `resources/print/design-system/tokens.json`, `resources/print/forestas.sty`,
  `tests/Feature/Print/ForestasPrintStyleTest.php`, `docs/howto/riallineare-lo-stile-di-stampa.md`
- Modify: `app/Providers/AppServiceProvider.php` (via `style_path`; `features.print.map.*` per la
  mappa grande), `resources/views/vendor/wm-package/print/{ec-track,ec-poi}.blade.php`,
  `partials/{preamble,logotype}.blade.php`, `app/Support/ForestasPrintSheet.php`, `lang/*.json`
- Create: icone in `resources/print/icons/` (PDF o PNG convertiti dal tema WordPress)
- Test: `tests/Feature/Print/ForestasPrintOverrideTest.php`

- [ ] **Step 1:** leggere il design system (artifact, `project/tokens.json`, README §3-§5,
  componenti `LogotipoSardegna`, `BadgeStato`, `PillDifficolta`, `FilettoOro`) e il tema WordPress
  (`wp-package/track.php`, `inc/dettaglio.php`, CSS e icone): da qui colori, font, stili di testo
  e la logica delle scale (validazione catastale, percorribilità, accessibilità).
- [ ] **Step 2:** test: l'ordine del `.tex` (mappa prima del blocco di stato, poi «Il sentiero in
  breve», poi il resto come in R17); le tre scale con il livello giusto per casi noti, presi dal
  tema WordPress; «—» per i dati mancanti; le immagini solo se ci sono; nessun `\usepackage{forestas}`;
  la compilazione reale (saltata senza xelatex) produce il PDF.
- [ ] **Step 3:** preambolo con colori, font e stili del design system; view di `EcTrack` e `EcPoi`
  con il nuovo ordine; `ForestasPrintSheet` con i dati delle scale; mappa grande da config.
- [ ] **Step 4:** PDF reali (tracce 1, 42, 768 e un POI, senza scritture sul DB) confrontati a
  vista con la pagina del sito.
- [ ] **Step 5:** pagina di conoscenza e guida aggiornate (niente token; view dal design system).
- [ ] **Step 6 (dev):** `git commit -m "feat(oc:8982): schede di Forestas sul modello del sito"`

### Task 7: Foglio fronte e retro (R20, R21)

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (mappa 210 x 297), `app/Support/ForestasPrintSheet.php` (`keyData`, `link`; via
  roadbook, gruppi, informazioni utili, avvertenze), `resources/views/vendor/wm-package/print/{ec-track,ec-poi}.blade.php`,
  `partials/preamble.blade.php` (`\ForestasMappaPagina`, `ForestasPaginaUnica`, `ForestasGriglia`,
  `\ForestasDato`, `\ForestasQr`, `\ForestasIndirizzo`; via `\ForestasMappa`, `\ForestasGruppo`,
  `\ForestasTappa`), `lang/*.json`
- Test: `tests/Feature/Print/ForestasPrintOverrideTest.php`

- [x] **Step 1:** test: ordine del foglio (pagina 1 fino al rimando, poi la sola mappa); niente
  descrizione, roadbook, attività e avvertenze, galleria; dati brevi senza righe vuote e senza le
  misure ripetute; link da `properties.forestas.url`, assente se manca o non è http(s); compilazione reale del caso
  più pieno in due pagine esatte, con la mappa da sola in pagina 2.
- [x] **Step 2:** preambolo, view e `ForestasPrintSheet`.
- [x] **Step 3:** PDF reali (tracce 1, 42, 768 e POI 80, senza scritture sul DB) guardati a vista.
- [x] **Step 3b (R21):** scheda del POI su due pagine: test dell'ordine, del limite di sei foto e della
  compilazione reale in due pagine esatte.
- [ ] **Step 4 (dev):** `git commit -m "feat(oc:8982): scheda del percorso su un foglio fronte e retro"`

## Verifica finale

- `vendor/bin/pest --filter=Print` dopo il controllo di isolamento; `vendor/bin/phpstan analyse`.
- In locale: `/print/ec-track/<id>?lang=it` mostra la pagina d'attesa, poi il PDF di Forestas.
