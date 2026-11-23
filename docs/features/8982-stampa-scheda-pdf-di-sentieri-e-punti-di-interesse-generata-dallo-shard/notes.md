> Ticket: oc:8982

# Notes — Stampa PDF di sentieri e POI (Forestas)

Deviazioni dal piano, bug trovati e decisioni prese durante l'esecuzione. Riporta solo lo stato finale;
le deviazioni del package sono nel `notes.md` di `wm-package`.

## Deviazioni dal piano

- **TTF statici di Work Sans invece dei variabili (Task 1).** xelatex non usa il font variabile (xdvipdfmx `Invalid font`). Nel Dockerfile i TTF statici (Light, Regular, Medium, SemiBold, Italic) da `weiweihuanghuang/Work-Sans@b35c810`, con sha256.
- **Supervisor `print`: timeout 420 e `stop_grace_period` 8m/11m (Task 2).** Il piano dava timeout 180, ma il Job dura fino a 390 s: Horizon lo avrebbe ucciso prima. Timeout 420 (sotto `retry_after` 660). Grace period 8m in `compose.yml`, 11m in `develop.compose.yml` (il Job di stampa dura più delle altre code). Il supervisor esiste solo con `WM_PRINT_ENABLED`.
- **Deploy che costruisce sempre, ordine php → deploy.sh → horizon (Task 2, review finale).** Il piano ricostruiva solo al cambio di dipendenze: un deploy rilanciato dopo una build fallita saltava il rebuild. Ora `build php horizon` sempre (cache Docker), `up -d php`, `deploy.sh` nel container, poi `up -d horizon`.
- **`scripts/compose-args.sh`.** Un solo posto che calcola i `-f` dei compose effettivi, usato dal workflow e dagli script (il deploy usava i file sbagliati). Richiede bash e Compose ≥ 2.17.
- **Stile da `tokens.json` (Task 3), poi rimosso.** Generato e versionato, poi tolto con la revisione del 20/11 (R16-R19): `tokens.json`, `forestas.sty`, `ForestasPrintStyleTest` e la guida di riallineamento sono cancellati, `style_path` non c'è più. Sostituisce l'approccio con `style_path` e il fix «stile dello shard dopo wm-print».
- **Schede rifatte dal design system sul modello del sito (Task 6, R17-R19).** Le view in `resources/views/vendor/wm-package/print/` (`ec-track`, `ec-poi`, `partials/preamble`, `partials/logotype`) con colori, font e logotipo del design system (vincolo del dev: rispettarlo sempre); ordine come la pagina di dettaglio del sito.
- **Scala della validazione dal branch oc:9000.** Il puntatore di `wp-forestas` ha «su 6», il branch oc:9000 «su 5»: la scheda segue oc:9000, nessuna modifica per ora.
- **Mappa 174×186 e blocco di stato in opzione A in pagina 2 (impaginazione del 23/11).** Mappa a tutta pagina 1 sotto la testata (`features.print.map.width_mm/height_mm` = 174 × 186 in `AppServiceProvider`), blocco di stato su due righe (opzione A dell'artifact di studio). Sostituisce «mappa ridotta con l'avviso per tenere lo stato in pagina 1».
- **Sezioni correlate tolte.** Via «punti di interesse correlati» e «sentieri correlati» dalla scheda (decisione del 20/11).
- **Icone in TikZ.** Accessibilità e altri segni grafici sono disegnati in TikZ nel preambolo, dal tema WordPress; in `resources/print/icons/` restano gli SVG delle tre disabilità.

## Bug trovati

- **Work Sans variabile inutilizzabile** da xelatex (Critical della review del Task 1): vedi sopra.
- **Task 3:** con lo stile dello shard `Undefined control sequence \PiePagina` (le macro stavano in `wm-print.sty`, sostituito): risolto nel package, poi superato dalla rimozione dello stile.
- **Task 4:** `\endgroup@if` — Blade non riconosce `@` attaccato a una lettera e il codice finiva nel LaTeX; corretto con `\endgroup{}@if`, test che verifica l'assenza di direttive nel `.tex`.
- **Task 4:** test R15 che ricostruiva i dati a mano e view tolleranti (`$stats ??`): test ora dal render reale, view senza `??`.
- **Foto 2 per riga:** riga vuota prima di `@endforeach` (review finale).
- **Deploy:** `develop.compose.yml` senza `stop_grace_period` su horizon e deploy rilanciato dopo build fallita senza rebuild (review finale).
- **PHPStan:** la cache di build non era scrivibile nel container durante i controlli.

## Decisioni con il dev e rischi accettati

- **19/11** — «Procedi con il necessario per far funzionare tutto»: avvio dell'esecuzione; correzione della review finale indipendente dalla discussione sulla semplificazione.
- **20/11** — Approvata la revisione R46-R50 / R16-R19; per Forestas rispettare sempre il design system.
- **20/11** — Si aspetta oc:9000 (scala della validazione) prima del merge.
- **20/11** — Mappa a tutta pagina 1 tranne la testata; via correlati. **23/11** — scelta opzione A (stato su due righe).
- **23/11** — Call del team: foglio fronte e retro per l'infopoint; pagina 1 con i dati senza descrizione, pagina 2 con la sola mappa A4 al vivo (il servizio delle mappe la restituisce già nella misura); pochi dati catastali; QR code e link al sito (R20). Con la riduzione in scala della pagina 1 come rete di sicurezza (scelta mia: piuttosto che una terza facciata).
- **23/11** — Credito di OpenStreetMap tenuto sulla pagina della mappa, piccolo nell'angolo, anche se la mappa va «senza nulla»: lo chiede la licenza ODbL. Si toglie con una riga in `\ForestasMappaPagina`.
- **Tabella di conversione** (da confermare con Alessio): partially-walkable → 3, unknown senza livello, walkable → 4, not-walkable → 1 (dal design system).
- **Parcheggiati (ancora validi):**
  - Build completo di `ForestasTrackDetail` (video, enti…) anche per la stampa: una sola fonte, lavoro in più nel Job.
  - POI ricaricato come modello Forestas: una query.
  - Resa (tabella inclusività non spezzabile, `ref` ripetuto, foto non viste): al giudizio di Alessio sul PDF.
  - Testata su 3+ righe: mappa sotto 80 mm va a pagina 2 e la 1 resta con la sola testata. Caso assente nei dati, nessun test compilato con testata alta.
  - Misura del picco di xelatex non fattibile in locale: 2 worker, memory 256, da ritoccare dopo la misura su UAT.
  - `up -d` senza `--wait` prima di `deploy.sh`; `compose-args.sh` con `exit` dentro `source`; nessuna regressione.

## Prerequisiti del merge

- Merge del package prima di forestas e aggiornamento del puntatore `wm-package` (commit separato).
- oc:9000 mergiato prima o nello stesso rilascio (scala della validazione «su 5»).
- Compose ≥ 2.17 e **bash** sugli host di dev e UAT, non verificabili da qui. Con Compose più vecchio la build fallisce con errore chiaro.
- `WM_PRINT_ENABLED=true` e `WM_PRINT_QUEUE=print` nel `.env` del server, più il servizio mappe (`WM_PRINT_MAP_DRIVER`, `WM_PRINT_MAP_SERVICE_URL`, `WM_PRINT_MAP_SERVICE_TOKEN`): oggi il driver è `fake`.
- `WP_SITE_URL` nel `.env` del server (indirizzo del sito WordPress, base del QR code delle schede).
- Dopo il deploy, rigenerazione dei JSON come in `docs/howto/deploy-automatico.md`.

## Aperti

- **URL della pagina del sito nel QR code**: oggi provvisorio, `<WP_SITE_URL>/?wmwp_detail=<track|poi>&wmwp_id=<nid>` (con `/en/` in inglese), cioè il redirect del plugin wp-package verso lo slug, documentato solo come test manuale nel piano di oc:8969 e non verificato sul codice del plugin. Con il nid di Drupal come chiave (non cambia con il reset notturno). Va confermato, e va aggiunto `WP_SITE_URL` al `.env` dei server.

- **Chiavi di traduzione generiche** (`lang/*.json`) che cambiano le label Nova a dominio spento: decide il dev.
- **Chi può lanciare l'Action Nova** di generazione (oggi chiunque acceda alla Resource).
- **Conferma con Alessio** di «parzialmente percorribile» = 3.
- **Corsivo degli `<em>` redazionali** mantenuto (non si distingue il sardo dall'enfasi), scostamento dal README §4 del design system: decide il dev.
- **Ticket a parte non più necessario:** la pagina del tema di stampa è abbandonata con la revisione del 20/11.
