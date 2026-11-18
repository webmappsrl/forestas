> Ticket: oc:8982

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

## Requisiti

- [ ] Motore di rendering installato secondo la decisione aperta:
  - soluzione A: TeX Live ridotto, `@maplibre/maplibre-gl-native` e le sue librerie in
    `docker/configs/phpfpm/Dockerfile`;
  - soluzione B: servizio in `compose.yml` e `develop.compose.yml` (e in `local.compose.yml` per lo
    sviluppo), con `build` da `wm-package/docker/render`, `mem_limit`, nessun `env_file`, nessuna
    porta pubblicata.
- [ ] Configurazione del package nel `.env-example` e in `.env-deploy` (attivazione del motore e,
      con B, URL del servizio).
- [ ] `tokens.json` del design system nel repo, con il suo stile LaTeX generato dal comando del
      package; la procedura per riallinearlo quando il design system cambia.
- [ ] Il logotipo «SARDEGNA Sentieri» composto in Work Sans, con le tinte per lettera e la coda
      «Sentieri» come nel componente `LogotipoSardegna`; mai un'immagine ricomposta a mano.
- [ ] Font Work Sans (SIL OFL, pesi 300–700 e corsivo 400) disponibile al motore di rendering.
- [ ] Override A4 della scheda di `EcTrack`, con i contenuti nell'ordine del template
      `SchedaSentiero.dc.html` del design system che hanno senso su carta: testata, stato, dati
      principali, descrizione, attività e difficoltà, mappa, foto, punti di interesse correlati.
      Lo disegna Rubens oppure Alessandro; la revisione è di Alessio sul PDF generato.
- [ ] Override A4 della scheda di `EcPoi`, sullo stesso linguaggio grafico.
- [ ] I dati che Forestas aggiunge in `properties.forestas` (registro catastale, inclusività,
      sentieri correlati) entrano nella scheda di Forestas dove servono.
- [ ] Colori di stato e di tipo usati solo per il loro ruolo, come prescrive il design system (le
      tinte del marchio non indicano stati).

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
