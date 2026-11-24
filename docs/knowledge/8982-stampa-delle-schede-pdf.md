# Stampa delle schede PDF

> Ticket: oc:8982

Come Forestas genera la scheda PDF di sentieri e POI. La stampa del package (flusso, rotte, contratto delle
view, sicurezza) è del package: [wm-package/docs/resources/Print.md](../../wm-package/docs/resources/Print.md).
Qui solo ciò che è specifico di questo shard.

## TeX Live e Work Sans nell'immagine PHP

- TeX Live e i font si installano in `docker/configs/phpfpm/Dockerfile` (installazione generica:
  Print.md del package). **php e horizon condividono l'immagine**: la rotta `print.show` accoda il Job e la
  compilazione gira nel worker Horizon, ma `php` deve poter compilare per `PrintService::generate()` da
  tinker e per i test di compilazione.
- **Work Sans è installata come TTF statici** (Light, Regular, Medium, SemiBold, Bold, Italic, con sha256),
  non come file variabili: XeLaTeX non usa i font variabili (`Invalid font` in `xdvipdfmx`).
- Con `\setmainfont{Work Sans}` il `\bfseries` risolve al **SemiBold (600)**, giusto per i titoli. Il
  **Bold 700** del logotipo va chiamato per nome (`\ForestasLogoBold`), non con `\bfseries`.

## Rebuild nel deploy

- `.github/workflows/deploy.yml` esegue **sempre** `docker compose build php horizon`, senza confrontare i
  puntatori prima e dopo il pull: con la cache è quasi istantanea, e un deploy rilanciato dopo una build fallita
  non chiude verde con l'immagine vecchia. L'ordine è build, `up -d php`, `scripts/deploy.sh` (che termina
  Horizon), `up -d horizon`: Horizon non riparte con vendor vecchio e database non migrato.
- I file compose da usare non sono quelli indovinati dal workflow: li legge dal container `php-<APP_NAME>`
  con `scripts/compose-args.sh` (lo stesso usa `scripts/wordpress-up.sh`).
- `command_timeout: 30m` sull'ssh-action: la build di TeX Live supera i 10 minuti di default.
- Sugli host servono `bash` (lo script remoto usa `source` e array) e Docker Compose >= 2.17
  (`additional_contexts`). Non verificato su dev e UAT da chi ha scritto il codice.

## Coda `print` in Horizon

`config/horizon.php`, `supervisor-print`: coda `print`, 2 worker, `timeout` 420 (sotto `retry_after` 660).
Il supervisor c'è in ogni ambiente: dal 24/11 la stampa non ha più un interruttore nello shard
(`WM_PRINT_ENABLED` è stato tolto) e la decide solo l'opzione dell'App.
`stop_grace_period` di `horizon` è 8m in `compose.yml` e 11m in `develop.compose.yml` (UAT, `APP_ENV=production`):
Horizon in chiusura aspetta il timeout più lungo fra i supervisor, che è 420 s per `print` e 600 s per
`geohub-import-supervisor` (local e production, dal package). Le altre code di `config/horizon.php` hanno
timeout da 120 a 360 s.
Per fare posto, `default` scende da 30 a 10 worker in local, develop e staging (in production resta a 3, base
del package). `supervisor-aws` non è usato da nessun ambiente e resta a 30.

Il `memory` 256 del supervisor non limita xelatex: i figli di xelatex sono limitati da `prlimit` con
`compile.memory_mb` (1024). **Da misurare su UAT**: il picco di xelatex con le foto. In locale le foto non
si scaricano, quindi 2 worker e questi limiti sono quelli del piano, non misurati.

## Schede generate dal design system, sul modello del sito (R16-R20)

- **Niente token né `.sty` dello shard**: colori, font, stili di testo e macro stanno nel preambolo delle
  view, `resources/views/vendor/wm-package/print/partials/preamble.blade.php`, scritto a partire dal design
  system SARDEGNA Sentieri (artifact K7Z7K9PsveXuD7TNBspac7: `tokens.json`, README §3-§5, componenti). I colori
  hanno i valori esatti di `tokens.json`; se il design system cambia, si aggiorna il preambolo a mano. Il package
  allega solo `wm-print.sty` (macro di base), che il preambolo ridefinisce.
- `resources/views/vendor/wm-package/print/`: `ec-track`, `ec-poi` e i partial `head` (classe, stili, preambolo),
  `header` (testata), `grid` (dati brevi in tre colonne), `preamble` e `logotype`. I dati e il QR code a destra
  stanno nell'ambiente `ForestasConRimando` del preambolo (URL vuoto: niente QR, tutta la larghezza). I dati
  li prepara `App\Support\ForestasPrintSheet` (già escaped), costruiti da `ForestasTrackDetail` /
  `ForestasPoiDetail`: la logica sta fuori da Blade. Una direttiva Blade attaccata a un comando LaTeX
  (`\noindent@foreach`) non si compila: i pezzi ripetuti dentro una cella si compongono nel `@php`.
- **Impaginazione: un foglio fronte e retro** (R20, call del 23/11: il volantino dell'infopoint). La scheda del
  percorso ha due pagine esatte. **Pagina 1**: testata (logotipo, tipo con codice, area, badge della validazione,
  nome, località, filetto oro); l'avviso di percorribilità se pubblicato (nel colore dello stato); il blocco di
  stato nell'opzione A (fascia «Percorribilità», Validazione catastale e Percorribilità affiancate, Accessibilità a
  tutta larghezza con la scala AT / AE / AEE e le tre icone); «Sentiero per tutti» se c'è la classe, con i punteggi
  in griglia; «Il sentiero in breve»; i dati catastali e altimetrici che non sono già sopra (Partenza, Via, Arrivo,
  Andata e ritorno, Difficoltà; per i sentieri Codice catastale e Data del rilievo; senza righe vuote), in griglia,
  con a destra il QR code e sotto l'indirizzo della pagina del sito. **Pagina 2**: la sola mappa. Niente
  descrizione, informazioni utili, roadbook, attività e avvertenze né galleria. Itinerario e cammino cambiano
  titoli come sul sito; l'itinerario non ha il blocco di stato né i dati del catasto. Il POI (`wp-package/poi.php`, R21),
  anche lui su due pagine: in pagina 1 testata, «Il punto in breve» con il QR code e l'indirizzo, al massimo sei
  foto; in pagina 2 la mappa. Niente descrizione né «come arrivare».
  **Le schede non hanno** i punti di interesse correlati né i percorsi correlati o vicini.
- **La pagina 1 non trabocca** (traccia e POI): tutto il contenuto sta nell'ambiente `ForestasPaginaUnica` (preambolo), che lo
  compone in un box e, se è più alto dello specchio (avviso, inclusività e nome lungo insieme), lo riduce in scala
  quanto basta. Il box ha altezza zero sopra la linea di base (minipage `[t]`): il limite toglie il `\topskip`,
  altrimenti LaTeX lo manda alla pagina dopo e la pagina 1 resta bianca. Nelle griglie (`ForestasGriglia`,
  `\ForestasDato`) serve `\leavevmode` in testa alla cella: un `\color` in modo verticale fa scendere la cella di
  una riga.
- **Rimando al sito**: l'URL è `<forestas.website_url>/?wmwp_detail=track|poi&wmwp_id=<id>` (variabile
  `FORESTAS_WEBSITE_URL`, solo schema e dominio: in produzione `https://www.sardegnasentieri.it`, su dev
  `https://wp.forestas.dev.maphub.it`). Il plugin wp-package lo risolve nella pagina di dettaglio, che porta lo slug:
  l'indirizzo non cambia se l'elemento viene rinominato e c'è anche per gli elementi creati in Nova. Non si usa
  `properties.forestas.url`: è l'indirizzo di Drupal, che porta alle pagine vecchie e serve ai redirect dal vecchio
  sito (oc:9018), quindi non va sovrascritto. Il QR contiene l'id dello shard: un PDF stampato resta valido finché
  l'id non cambia (su UAT cambia a ogni reset notturno). Senza dominio, o se non è http(s), niente QR né indirizzo. Il QR code è del pacchetto `qrcode`, che vuole escaped `# % & _ ~ $ ^ { } \`.
- **Scale come il sito** (registro degli stati del tema, `inc/statuses.php`, branch di oc:9000; lo stato di
  assenza vale 0): validazione 0-5 (non verificato 0, in revisione 2, pre-accatastamento 3, validato 4,
  certificato 5); percorribilità 0-4 (sconosciuta o assente 0, non percorribile 2, parzialmente 3,
  percorribile 4); accessibilità 0-3 (AT, AE, AEE; senza grado «Non classificato»). Il codice del tema nel
  submodule (puntatore attuale) ha ancora la scala 0-6 e la percorribilità segnaposto: vale quello di oc:9000,
  che è quello pubblicato su UAT.
- **Colori per ruolo**: segmenti accesi di validazione e percorribilità nel colore di stato `st-*` (validazione
  2 → st-2, 3 → st-3, 4-5 → st-4; non percorribile → st-1, parzialmente → st-3, percorribile → st-4);
  accessibilità e icone accese in `sar-blu` (elemento attivo); spenti con il bordo `line` o tratteggiati in
  `ink-3`. `sar-terra`/`sar-oro` solo in logotipo e filetti, `tipo-*` solo per etichetta di tipo e filetto di
  sezione. Mai `wmprimary`. Il filetto dell'avviso e della fascia ha il colore dello stato di percorribilità.
- **Icone delle disabilità**: i disegni a tratto del tema (viewBox 24) sono in `resources/print/icons/*.svg` e
  `ForestasPrintSheet::icon()` li traduce in TikZ (libreria `svg.path`, in `texlive-pictures`). Non PNG/PDF
  allegati: il compilatore apre solo i file che gli allega il package (`openin_any=p`), e il contratto delle
  view non ha un modo per allegarne altri.
- **Mappa: una pagina A4 al vivo**: `AppServiceProvider` imposta `print.map.width_mm` 210 e `height_mm`
  297; il servizio delle mappe la disegna in quella misura. `\ForestasMappaPagina` (preambolo) la stende sulla
  pagina intera con `eso-pic` (non chiede una seconda compilazione: il compilatore lancia xelatex una volta sola),
  con `keepaspectratio` e centrata, su una pagina senza testata né piè di pagina; il credito di OpenStreetMap,
  d'obbligo per la licenza, lo disegna il servizio mappe sull'immagine insieme a scala e nord
  (`map_service.map_info`, acceso di serie), e la view non lo ripete. Con `map_info` spento o con il driver
  `fake` la pagina della mappa resta senza credito. `eso-pic` e `qrcode` arrivano con
  `texlive-xetex` (dipendenze di `texlive-latex-recommended` e `texlive-pictures`), già installato dallo script
  del package. La cella dell'accessibilità ha larghezza esplicita (`\multicolumn` con `p{}`): una colonna `X` in
  un `\multicolumn` non prende la larghezza delle due colonne.
- **I test di stampa del package girano nella sua suite** (`Wm\WmPackage\Tests\TestCase`, Testbench, database
  `wm_package`): si lanciano dalla cartella del package con `vendor/bin/pest tests/Feature/Print tests/Unit/Print`,
  non da forestas, dove manca il loro `tests/Pest.php`.
- Il **logotipo è testo**, non immagine: lettere colorate per ruolo (S terra, A verde, R blu, D terra,
  E oro, G blu, N terra, A oro, peso 700) e coda «Sentieri» in Light, omessa nel piè di pagina (corpo piccolo).
- Per il POI la rotta del package carica `Wm\WmPackage\Models\EcPoi`, che non ha le relazioni di Forestas:
  l'override ricarica `App\Models\EcPoi`. Né il dettaglio della traccia né `EcPoiDetailController` hanno
  `properties.print_url`: il plugin del sito costruisce il link da `OPTIONS.ec_pdf_enabled` del
  `config.json` e dalla rotta `/print/{ec-track|ec-poi}/{id}` (scrum del 24/11).
- **Data di generazione** nel piè di pagina, a destra del copyright («Scheda generata il 24 novembre 2026»,
  `$generatedOn` del package): la scheda è un'istantanea. Le schede si rigenerano solo quando qualcuno le chiede
  e non sono più aggiornate rispetto a `updated_at`, mai al salvataggio.
- `tests/Feature/Print/ForestasPrintOverrideTest.php` passa da `PrintService::generate()` con compilatore
  finto, così le variabili sono quelle vere del package e un contratto cambiato lì rompe il test.

## La stampa è accesa sull'App

Nel package la scheda vale solo per gli elementi di un'App con `properties.ec_pdf_enabled` acceso (campo «Print
PDF» nel tab `frontend` della Resource `App`, vedi la sezione «L'opzione dell'App» di
[Print.md](../../wm-package/docs/resources/Print.md)). Manca finché qualcuno non la accende, e nessuna
migration lo fa: in ogni ambiente l'opzione va accesa a mano da Nova sull'App di Forestas, altrimenti il
pulsante sparisce, `/print/...` risponde 404 e l'action non si esegue.

«Via» fra Partenza e Arrivo è `properties.via`, la meta intermedia scritta dal registro catastale
([registro-catastale.md](registro-catastale.md)): la riga esce solo se c'è.

## Mappa e foto

- La mappa la disegna il servizio di stampa di wm-tiles (oc:9011): nel `.env` `WM_PRINT_MAP_DRIVER=http`,
  `WM_PRINT_MAP_SERVICE_URL` e il token di Forestas in `WM_PRINT_MAP_SERVICE_TOKEN`. La pagina 2 è un A4
  verticale a 300 dpi (circa 6 s per la traccia 119, provata il 24/11), con scala, nord e attribuzioni disegnati
  dal servizio. `AppServiceProvider` alza `map_service.max_zoom` a 18: con il 16 di serie un sentiero breve
  restava piccolo in mezzo alla pagina (traccia 119: 1:21.500 con 16, 1:5.400 con 18). Azzera anche `print.map.padding_mm`
  (42 mm nel package, per la copertina con il riquadro del titolo del template di default): la pagina della
  mappa di Forestas non ha riquadro e tiene il margine del servizio. Con il driver `fake`
  la mappa è il segnaposto del package.
- Il colore della traccia sulla mappa è quello del suo layer (il primo per rank), altrimenti il rosso di default
  di `EcTrack`; `WM_PRINT_MAP_STROKE_COLOR` nel `.env` lo fissa per tutte le tracce e vince sul layer. In locale
  (24/11) solo 4 layer su 11 hanno un colore, e la traccia 119 esce rossa.
- In locale le foto non si scaricano: gli URL puntano a `localhost:9000` e dal container non è raggiungibile
  (warning «Foto saltata» nel log `print-*`). Il layout delle foto non è mai stato visto in un PDF.

## Provare in locale

- Prima: «Print PDF» acceso sull'App 1 (Nova, tab `frontend`), nel `.env` le tre variabili del servizio mappe
  e `FORESTAS_WEBSITE_URL` (per il QR code), poi `php artisan config:clear` e riavvio di `horizon-forestas`: i
  worker tengono in memoria il codice e la configurazione con cui sono partiti. Poi
  `http://localhost:8000/print/ec-track/<id>?lang=it`: pagina d'attesa e redirect al PDF su MinIO
  (`localhost:9000`).
- Senza scrivere sul DB: da tinker, `PrintService::generate($model, 'it')` con il modello caricato
  (`App\Models\EcTrack::find(...)`); è sincrono e scrive solo il PDF sullo storage `wmfe`. La rotta `/print/...` invece accoda il Job: serve un worker sulla coda `print`.
- Per vedere la scheda **senza la personalizzazione**:
  `View::replaceNamespace('wm-package', base_path('wm-package/resources/views'))` prima di `generate()`, così
  non si usano gli override di Forestas.
