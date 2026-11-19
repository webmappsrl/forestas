> Ticket: oc:8982
> Stato: approvata il 2026-11-18

# Stampa: scheda PDF di sentieri e punti di interesse generata dallo shard

Questo overview copre la parte di Forestas. La generazione dei PDF, il motore di rendering, le rotte,
la rigenerazione e la grafica di default sono nel package:
`wm-package/docs/features/8982-…/overview.md`, che contiene anche i motivi delle scelte. Il sito
WordPress non è toccato da questo ticket.

## Cosa cambia

- Lo shard installa il **motore di rendering** del package (LaTeX e renderer delle mappe) e
  configura il package per usarlo. Dove gira, se nell'immagine PHP dello shard o in un container
  separato, è una domanda aperta: vedi «Domanda aperta» nell'overview del package.
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
      (`COPY wm-package/docker/render/install-deps.sh` più `RUN`), così le dipendenze restano
      versionate con il codice che le usa.
- <a id="r2"></a>**R2** — Build context alla root: `context: .` e `dockerfile: docker/configs/phpfpm/Dockerfile` in
      `compose.yml`, `develop.compose.yml` e `local.compose.yml`. Oggi è `build: ./docker/configs/phpfpm`,
      e da lì la `COPY` dal submodule non è possibile.
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
- La produzione di Forestas: oggi non esiste; il motore va solo negli ambienti del repo (locale,
  dev, UAT).

## Moduli toccati

Repo `forestas`:

- `docker/configs/phpfpm/Dockerfile` (soluzione A) oppure `compose.yml`, `develop.compose.yml`,
  `local.compose.yml` (soluzione B).
- `.env-example`, `.env-deploy` : configurazione del motore.
- `resources/design-system/tokens.json` (nuovo, percorso da confermare nel piano) e lo stile
  generato.
- `resources/views/vendor/wm-package/…` : override delle view della scheda di `EcTrack` e `EcPoi`.
- `lang/it.json`, `lang/en.json` : eventuali etichette proprie di Forestas.
- `app/Support/ForestasTrackDetail.php`, `app/Http/Controllers/Api/EcPoiDetailController.php` :
  solo se l'URL della scheda deve comparire anche dentro `properties.forestas`.
- `docs/knowledge/` : pagina sulla stampa delle schede.
