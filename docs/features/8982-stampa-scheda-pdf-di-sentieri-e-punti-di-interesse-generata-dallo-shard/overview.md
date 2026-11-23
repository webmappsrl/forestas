> Ticket: oc:8982
> Stato: approvata il 2026-11-18

# Stampa: scheda PDF di sentieri e punti di interesse generata dallo shard

Questo overview copre la parte di Forestas. La generazione dei PDF, il motore di rendering, le rotte,
la rigenerazione e la grafica di default sono nel package:
`wm-package/docs/features/8982-…/overview.md`, che contiene anche i motivi delle scelte. Il sito
WordPress non è toccato da questo ticket.

## Cosa cambia

- Lo shard installa **TeX Live** (`xelatex`) nella propria immagine PHP, con lo script del
  package, e ricostruisce l'immagine nel deploy quando il package ne cambia le dipendenze. La
  **mappa** non la disegna lo shard: la chiede via HTTP al servizio di rendering unico di oc:9011,
  di cui configura indirizzo e token. Niente container nuovi nel compose.
- Le schede PDF di Forestas hanno la **grafica del design system di SARDEGNA Sentieri** invece
  della scheda neutra del package:
  - lo stile si genera dal `tokens.json` del design system (artifact «SARDEGNA Sentieri Design
    System»), versionato in questo repo;
  - l'impaginazione A4 della scheda di `EcTrack` e di `EcPoi` è un override delle viste del package.
- Il JSON di dettaglio dei sentieri e dei POI di Forestas espone l'URL della scheda, come quello del
  package.

## Perché

Il cliente chiede la scheda stampabile dal nuovo sito (WEBSITE-18, WEBSITE-19). Il team ha deciso
(scrum 18/11) che il package ha una versione di default e che ogni shard la personalizza; Forestas
è il primo shard a farlo, con il proprio design system. Rubens ha completato il design system il
18/11 (`lastChange` del suo `design-system.json`).

## Risposta alla domanda aperta (18/11)

La domanda resta posta nell'overview del package, con la risposta sotto. Per Forestas si traduce
in una cosa sola: **lo shard installa TeX Live e basta**.

- **La mappa non la disegna lo shard.** Il rendering va in un servizio unico sull'host che serve
  già le tile (ticket **oc:9011**): il Job chiede il PNG via HTTP e lo riceve. Qui non entrano né
  `@maplibre/maplibre-gl-native` né le librerie grafiche, quindi nessuna dipendenza nativa legata
  all'ABI di Node o all'architettura.
- **Il PDF lo compone lo shard**, con `xelatex` nell'immagine PHP: **353 MB** misurati per
  `texlive-xetex` con `texlive-fonts-recommended` e le loro dipendenze. L'immagine è una sola per
  i container `php` e `horizon` (stesso `build:`, stessa `image: wm-phpfpm:8.4`), quindi si
  installa e si conta una volta. Il Job gira nel worker Horizon e compila dove i file già sono.
- **Niente container nuovi** nel compose di questo repo: la soluzione B, quella del servizio di
  rendering per shard, non si fa.

Ne discendono i requisiti qui sotto, che sostituiscono la scelta fra soluzione A e soluzione B.

## Requisiti

- <a id="r1"></a>**R1** — Installazione di `texlive-xetex` e `texlive-fonts-recommended` in
      `docker/configs/phpfpm/Dockerfile`, richiamando lo script del package
      (`COPY --from=wmpackage install-deps.sh` più `RUN`, vedi R2), così le dipendenze restano
      versionate con il codice che le usa.
- <a id="r2"></a>**R2** — Secondo contesto di build (challenge del 18/11): il contesto resta
      `./docker/configs/phpfpm` e lo script del package arriva con `additional_contexts`
      (`wmpackage: ./wm-package/docker/render`) e `COPY --from=wmpackage install-deps.sh`. Vale per
      tutti i servizi che costruiscono `wm-phpfpm:8.4`: `php` e `horizon` in `compose.yml`,
      `horizon` in `develop.compose.yml`, `php`, `scout-init` e `laravel` in `local.compose.yml`.
      Richiede Docker Compose 2.17 o successivo con BuildKit, da verificare sugli host di dev e UAT.
  - ~~Build context alla root: `context: .` e `dockerfile: docker/configs/phpfpm/Dockerfile`.~~
    Superato: gli `ADD` del Dockerfile (`.bashrc`, `www.conf`, `zz-docker.conf`, `php.ini`) sono
    relativi alla cartella attuale e si sarebbero rotti, e senza `.dockerignore` ogni build avrebbe
    mandato al daemon anche `storage` (4,5 GB in locale), `vendor` e `.git`.
- <a id="r3"></a>**R3** — Rebuild esplicito dell'immagine quando il package cambia le dipendenze: il deploy fa
      `git submodule update --init wm-package` e poi `docker exec` nel container esistente, senza
      ricostruire nulla.
  - **Precisato (18/11): il rebuild è automatico, dentro il workflow di deploy.** In
    `.github/workflows/deploy.yml` si confronta il puntatore del submodule prima e dopo il pull,
    con lo stesso pattern già usato per `wp-forestas` (`deploy.yml:43-53`), e si ricostruisce solo
    quando è cambiato. Va **prima** del `docker exec`, perché il container su cui gira
    `scripts/deploy.sh` è quello che si sta ricostruendo:

    ```bash
    WM_BEFORE=$(git rev-parse HEAD:wm-package)
    git pull --ff-only origin "$BRANCH"
    git submodule update --init wm-package
    WM_AFTER=$(git rev-parse HEAD:wm-package)
    if [ "$WM_BEFORE" != "$WM_AFTER" ]; then
      docker compose build php horizon
      docker compose up -d php horizon
    fi
    docker exec "php-$APP_NAME" bash scripts/deploy.sh
    ```

    Costa poco: il blocco `COPY install-deps.sh` più `RUN` si invalida solo quando quello script
    cambia, quindi nei deploy normali la build dura secondi e non rifà nessun `apt`; e `up -d`
    ricrea i container solo se l'immagine è cambiata davvero, quindi Horizon non si riavvia per
    niente. Se la build fallisce, `set -e` ferma il deploy: è il comportamento voluto, perché
    un'applicazione aggiornata con dipendenze di sistema vecchie è il disallineamento che questo
    requisito esiste per evitare. Il marcatore di versione nell'immagine resta come rete di
    sicurezza per le ricostruzioni fatte a mano.
  - **Precisato nella challenge (18/11):** `stop_grace_period: 6m` sul servizio `horizon` di
    `compose.yml`. Quando `up -d` ricrea il container, Docker manda SIGKILL dopo 10 secondi, e le
    code in `config/horizon.php` hanno `timeout` fino a 360 secondi: senza questa riga un Job
    dell'import o del DEM morirebbe a metà, mentre `horizon:terminate` di `scripts/deploy.sh`
    aspetta che finisca.
- <a id="r4"></a>**R4** — Coda Horizon dedicata alla stampa, con pochi worker, e `maxProcesses` delle code esistenti
      rivisto: oggi 47 worker fermi occupano 2,4 GB di PSS.
- <a id="r5"></a>**R5** — Configurazione dell'indirizzo e del token del servizio di rendering delle mappe (oc:9011) in
      `.env-example` e `.env-deploy`.

- <a id="r6"></a>**R6** — `tokens.json` del design system nel repo, con il suo stile LaTeX generato dal comando del
      package; la procedura per riallinearlo quando il design system cambia.
- <a id="r7"></a>**R7** — Il logotipo «SARDEGNA Sentieri» composto in Work Sans, con le tinte per lettera e la coda
      «Sentieri» come nel componente `LogotipoSardegna`; mai un'immagine ricomposta a mano.
- <a id="r8"></a>**R8** — Font Work Sans (SIL OFL, pesi 300–700 e corsivo 400) disponibile al motore di rendering.
- <a id="r9"></a>**R9** — Override A4 della scheda di `EcTrack`, con i contenuti nell'ordine del template
      `SchedaSentiero.dc.html` del design system che hanno senso su carta: testata, stato, dati
      principali, descrizione, attività e difficoltà, mappa, foto, punti di interesse correlati.
      Lo disegna Rubens oppure Alessandro; la revisione è di Alessio sul PDF generato.
- <a id="r10"></a>**R10** — Override A4 della scheda di `EcPoi`, sullo stesso linguaggio grafico.
- <a id="r11"></a>**R11** — I dati che Forestas aggiunge in `properties.forestas` (registro catastale, inclusività,
      sentieri correlati) entrano nella scheda di Forestas dove servono.
- <a id="r12"></a>**R12** — Colori di stato e di tipo usati solo per il loro ruolo, come prescrive il design system (le
      tinte del marchio non indicano stati).
- <a id="r15"></a>**R15** — Test che fa il render dell'override (Blade → `.tex`) con un `EcTrack`
      di prova, senza compilare (challenge del 18/11): una variabile cambiata nel package fa
      fallire la CI al primo aggiornamento del puntatore, invece che il Job di stampa davanti
      all'utente. Non richiede `xelatex` in CI.

### Revisione del 20/11: view generate dal design system, niente token

Decisa dal dev: le view della scheda si generano con un'IA a partire dal design system, senza il
passaggio dai token allo `.sty` (R46-R50 del package). **Supera R6, la parte di R9 «si parte dalla
struttura della view di default», e l'ampliamento con pagina e anteprima (R16-R17 di prima, mai
implementati).**

- <a id="r16"></a>**R16** — Via `resources/print/design-system/tokens.json`,
      `resources/print/forestas.sty`, il test di allineamento, la procedura
      `docs/howto/riallineare-lo-stile-di-stampa.md` e la riga `style_path` in `AppServiceProvider`.
      Colori, font e stili di testo della scheda stanno nel preambolo delle view di Forestas,
      generato dal design system ([SARDEGNA Sentieri Design System](https://claude.ai/artifact/K7Z7K9PsveXuD7TNBspac7)).
- <a id="r17"></a>**R17** — La scheda di `EcTrack` prende a modello la pagina di dettaglio del
      sentiero del sito (tema `wp-forestas/themes/forestas-child`, `wp-package/track.php` e
      `inc/dettaglio.php`; esempio: `https://wp.forestas.uat.maphub.it/sentiero/untanas-de-su-tassu-su-tassu-g-111b/`),
      nell'ordine:
  1. testata: logotipo «SARDEGNA Sentieri», nome, località, badge di stato;
  2. **mappa in apertura, grande, quasi una pagina intera** (dimensioni chieste al servizio delle
     mappe con R49 del package);
  3. subito sotto, **il blocco di stato** come sul sito: fascia «Percorribilità» con il testo
     (es. «Dato non ancora disponibile»); poi tre colonne con scala a segmenti:
     «Validazione catastale» (livello su scala, es. «3 su 6 · In pre-accatastamento o
     manutenzione»), «Percorribilità» (scala, es. «Dato non ancora disponibile»), «Accessibilità»
     (AT, AE, AEE, es. «Non classificato», con le tre icone Motoria, sensoriale, psichica
     cognitiva);
  4. **«Il sentiero in breve»**: Lunghezza, Dislivello totale, Tempo di percorrenza, Quota min —
     max (con «—» se il dato manca);
  5. poi come il sito: descrizione, descrizione delle tappe (roadbook), attività e avvertenze,
     dati catastali e altimetrici (Percorso, Misure, Catasto), immagini se ci sono, punti di
     interesse correlati.

  Le scale, le etichette e la logica dei livelli si prendono dal tema WordPress, che legge gli
  stessi dati di `properties.forestas`: la scheda e la pagina web devono dire la stessa cosa.
- <a id="r18"></a>**R18** — Le icone dell'accessibilità e gli altri segni grafici vengono dal tema
      WordPress o dal design system, convertiti una volta in PDF o PNG in `resources/print/`
      (XeLaTeX non legge SVG). Il logotipo resta composto in testo (R7).
- <a id="r19"></a>**R19** — La scheda di `EcPoi` segue lo stesso linguaggio grafico, con la mappa in
      apertura.

### Revisione del 23/11: foglio fronte e retro (call del team)

Decisa nella call del 23/11 (Alessio, Peppe) e precisata dal dev. **Supera R17 nei punti 2 e 5 e
la mappa in apertura di R19.** Il caso d'uso è il volantino dell'infopoint: un foglio A4 stampato
fronte e retro, che il turista si mette in tasca e da cui torna al sito con il QR code. La versione
lunga, con la descrizione, si rimanda a quando la chiederà il cliente.

- <a id="r20"></a>**R20** — La scheda di `EcTrack` è di **due pagine esatte**:
  1. pagina 1: la testata come prima; l'avviso di percorribilità se pubblicato; il blocco di stato e
     «Sentiero per tutti» come in R17, al più più compatti; «Il sentiero in breve»; pochi dati
     catastali e altimetrici, solo quelli che non sono già sopra e che ci stanno (Partenza, Arrivo,
     Andata e ritorno, Difficoltà; per i sentieri Codice catastale e Data del rilievo; le righe
     senza dato non escono); il **rimando alla pagina del sito**, QR code e indirizzo. Se il
     contenuto non ci sta, si riduce in scala quanto basta invece di traboccare;
  2. pagina 2: **la sola mappa, A4 al vivo** (210 x 297 mm, senza margini, testata né piè di
     pagina), nella misura che restituisce il servizio delle mappe, con il credito di
     OpenStreetMap piccolo nell'angolo (lo chiede la licenza).

  Escono descrizione, informazioni utili, roadbook, attività e avvertenze, le tabelle complete dei
  dati catastali e la galleria. La scheda di `EcPoi` tiene i suoi contenuti, con il rimando al sito
  in fondo e la mappa nell'ultima pagina, da sola, come quella del percorso.

### Requisiti superati dalla risposta del 18/11

Restano qui per memoria di cosa era stato approvato prima delle misure. Li sostituiscono i primi
cinque requisiti qui sopra.

- <a id="r13"></a>**R13** — ~~Motore di rendering installato secondo la decisione aperta:~~
  - ~~soluzione A: TeX Live ridotto, `@maplibre/maplibre-gl-native` e le sue librerie in
    `docker/configs/phpfpm/Dockerfile`;~~
  - ~~soluzione B: servizio in `compose.yml` e `develop.compose.yml` (e in `local.compose.yml` per
    lo sviluppo), con `build` da `wm-package/docker/render`, `mem_limit`, nessun `env_file`,
    nessuna porta pubblicata.~~
- <a id="r14"></a>**R14** — ~~Configurazione del package nel `.env-example` e in `.env-deploy` (attivazione del motore e,
      con B, URL del servizio).~~

## Rischi

- **Il layout A4 di Forestas non esiste ancora.** Il design system ha solo il template web della
  scheda. Mitigazione: si parte dalla scheda neutra del package, e l'override di Forestas arriva
  quando è disegnato, senza bloccare il resto.
- **I loghi dell'ente** (Fo.Re.S.T.A.S., RAS) nel design system ci sono solo come PNG nella cartella
  della versione vecchia. Se il piè di pagina deve portarli, servono in vettoriale.
- **`tokens.json` copiato nel repo può restare indietro** rispetto al design system. Mitigazione:
  la procedura di riallineamento è scritta e il comando la rende un'operazione sola.

## Out of scope

- Tutto il lato WordPress (`wp-forestas` e plugin `wp-package`): il pulsante «Stampa» del
  dettaglio e i pulsanti della ricerca li fa un altro ticket.
- La produzione di Forestas: oggi non esiste; TeX Live va solo negli ambienti del repo (locale,
  dev, UAT).
- Il servizio di rendering delle mappe: è il ticket oc:9011.

## Moduli toccati

Repo `forestas`:

- `docker/configs/phpfpm/Dockerfile` : richiamo di `wm-package/docker/render/install-deps.sh`.
- `compose.yml`, `develop.compose.yml`, `local.compose.yml` : `additional_contexts` nei servizi
  che costruiscono `wm-phpfpm:8.4`.
- `.github/workflows/deploy.yml` : rebuild di `php` e `horizon` quando cambia il puntatore di
  `wm-package`.
- `config/horizon.php` : coda dedicata alla stampa e `maxProcesses` delle code esistenti.
- `.env-example`, `.env-deploy` : indirizzo e token del servizio di rendering di oc:9011.
- `resources/design-system/tokens.json` (nuovo, percorso da confermare nel piano) e lo stile
  generato.
- `resources/views/vendor/wm-package/…` : override delle view della scheda di `EcTrack` e `EcPoi`.
- `lang/it.json`, `lang/en.json` : eventuali etichette proprie di Forestas.
- `app/Support/ForestasTrackDetail.php`, `app/Http/Controllers/Api/EcPoiDetailController.php` :
  solo se l'URL della scheda deve comparire anche dentro `properties.forestas`.
- `docs/knowledge/` : pagina sulla stampa delle schede.
