> Ticket: oc:8539

# Notes — Mirror del registro catastale sulla scheda della traccia

## Divergenze dal piano, task per task

### Task 2: Reader del foglio Google

- **La pagina `htmlview` non ha elementi `sheet-button-<gid>`**: l'elenco dei fogli è un array JavaScript
  (`items.push({name: "…", pageUrl: "…", gid: "…"})`). Regex e fixture sono scritte su quel formato,
  con la decodifica di `\/`, `\"` e `\xHH` nei nomi.
- **Il file ha 12 fogli, non 10**, e i nomi sono quelli delle aree (`AREA D (Cagliari+OR)`, …) più
  `POI`, `Elenchi predefiniti` e simili: quali siano del registro lo decide il parser dalle intestazioni.

### Task 7: Job, comando e aggancio all'import di Sardegna Sentieri

- **`$timeout = 300`, non `600` come nel piano.** Il supervisor
  `supervisor-sardegnasentieri-import` in `config/horizon.php` ha un timeout di `360`: un job a
  `600` verrebbe ucciso dal supervisor prima di poter fallire in modo pulito, invece che dal suo
  stesso timeout. `300` resta sotto i `360` con margine.
- **`WithoutOverlapping` invece di un lock scritto a mano.** Un solo giro alla volta, con
  `dontRelease()` e `expireAfter($this->timeout + 60)`: il giro che trova il lock occupato si
  scarta (non si rimette in coda), il prossimo import ne accoda comunque un altro. Il comando
  manuale `forestas:registro-import` prende lo stesso lock (calcolato dal middleware del job, non
  ricopiato a mano) prima di importare, cosi' un rilancio a mano non si incrocia con quello
  accodato dall'import.

## Bug trovati

## Decisioni

- **Stima (28/09/2026).** `wm-estimate` ha proposto Misurato 1,44h + Stimato 27,7h = 29,1h, con
  voci da sviluppatore che scrive a mano. Il dev l'ha respinta e ha fissato 3h totali (circa 1,5h
  di esecuzione più la pianificazione misurata), scritte su Orchestrator.
- **Mirror sul codice del catasto, non sull'`EcTrack`** (dev, in dialogo): la description del
  ticket parla ancora di JSON sull'`EcTrack` ed è superata.
- **Tutto il dominio Catasto estendibile in questo ticket** (dev): delegarlo a un ticket successivo
  è stato giudicato bloccante.
- **Un link rotto ma un numero che il ripiego trova è consistente, senza anomalia.** È la regola
  del ripiego dell'overview: se sbagliata, un link rotto nel foglio resta non segnalato quando il
  numero coincide comunque.
- **Il registro si scarica dal foglio Google a ogni giro dell'import**, anche nel regime orario
  (~24 volte al giorno): voluto dallo spec, non solo dal reset notturno. Dalla review finale
  (dev): anche un giro orario senza nessun job da accodare fa partire il job del registro,
  direttamente dal comando; con `--reset` senza job invece no.
- **`RIPIEGO_AMBIGUO` si collega dalla Tab «Registro» di ciascun candidato.** Superata la scelta
  iniziale di non collegarlo: dalla review finale il contesto porta i codici completi dei
  candidati invece degli id, e la Tab di ogni codice candidato trova l'anomalia fra quelli. Oggi
  0 casi nei dati reali.
- **Una colonna chiave duplicata ferma l'intero import, non solo quel foglio** (review finale,
  scelta consapevole): il parser non sceglie a caso quale delle due colonne leggere, e un mirror
  parziale sembrerebbe completo. Il costo è che un errore di impaginazione su un foglio blocca
  l'aggiornamento di tutti finché non si corregge; il log sul canale `import` dice quale foglio e
  quale colonna.

## Follow-up

- **Per un futuro ticket — numeri a 2 o 4 cifre nel campo `Numero`.** `RegistroCatastaleRowParser`
  riconosce solo il formato a 3 cifre (`182` → settore 1, numero 82); un numero a 2 cifre non è
  riconosciuto, uno a 4 cifre viene troncato (`1001` → settore 1, numero 0). Nessun caso nei 7
  fogli reali oggi, ma non è una garanzia per i prossimi.
- **Per un futuro ticket — `\uXXXX` nei nomi dei fogli.** `RegistroCatastaleSheetReader::
  unescapeJsString()` decodifica `\/`, `\"` e `\xHH`, non `\uXXXX`: Google non lo usa oggi nei nomi
  dei fogli di questo file, ma un nome con caratteri non ASCII in futuro romperebbe la decodifica
  silenziosamente.
- **Per un futuro ticket — `SETTORE` contro la prima cifra del `Numero`.** Il settore si ricava
  dalla prima cifra del `Numero` (`182` → settore 1); la colonna `SETTORE` esiste ma si usa solo
  di ripiego quando il numero non si legge, senza controllo di coerenza fra i due valori.
- **Per un futuro ticket — `pest --filter` nel package rotto da un test preesistente.** Nella
  verifica finale del Task 6, l'esecuzione filtrata di Pest nel `wm-package` falliva per un test
  preesistente non legato a questo ticket: da investigare separatamente, non ha bloccato la
  review.

- **Per oc:8540 — il mirror salva il codice solo per le righe consistenti, non la traccia
  agganciata.** `registro_catastale_rows.trail_registry_code_id` è valorizzato solo quando il
  matcher trova un codice; una riga agganciata a una traccia senza codice attivo (anomalia
  `TRACCIA_SENZA_CODICE`) o con link orfano non porta con sé quale traccia il matcher aveva
  comunque trovato. oc:8540 dovrà decidere se e dove scrivere anche quel dato.
- **Per oc:8540 — la normalizzazione delle intestazioni è privata nel parser.**
  `RegistroCatastaleRowParser::keyColumns()` (le intestazioni chiave normalizzate, insensibili a
  grafia/accenti/maiuscole) è `private`: se oc:8540 deve riconoscere altre colonne con lo stesso
  criterio, dovrà esporla o riusarla, non riscriverla.

- **Per oc:8540 — i fogli del registro non hanno le stesse colonne.** Verificato il 28/09/2026 sul
  file reale: nel foglio Gallura (`gid=1888031845`) le colonne M e N sono spostate (`Sentieri
  correlati` e `Comuni di appartenenza` al posto di `Itinerari sovrapposti` e `Sentieri
  correlati`), quindi `Comuni di appartenenza` compare due volte (N e O). L'intestazione del link
  ha due grafie (`Link SardegnaSENTIERI` / `Link Sardegna SENTIERI`), l'ultima colonna pure (`@` /
  `OSSERVAZIONI/annotazioni`). oc:8540 lavora colonna per colonna (D, E, F, G, H, I): deve
  cercare le colonne per intestazione, come fa il mirror, non per lettera.
