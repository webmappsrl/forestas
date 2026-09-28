> Ticket: oc:8539

# Mirror del registro catastale sulla scheda della traccia

> Il titolo del ticket parla di «scheda della traccia»: in fase di analisi il mirror è stato
> spostato sulla scheda del **codice del Catasto Sentieri**. La parte generica, che rende
> estendibile l'intero dominio Catasto, è descritta in
> `wm-package/docs/features/8539-mirror-del-registro-catastale-sulla-scheda-della-traccia/overview.md`.

## Cosa cambia

Ogni notte, finito l'import da Sardegna Sentieri e il ricalcolo del catasto, forestas scarica il
registro catastale che Forestas mantiene con il CAI (un file Google con un foglio per area
territoriale) e ne conserva una copia fedele.

Ogni riga del foglio finisce in **uno solo** di due posti:

- **consistente** (agganciata a un codice, nessuna discrepanza) → nella scheda Nova del codice,
  in una Tab **«Registro»** con le colonne del foglio disposte in righe e il valore accanto, in
  sola lettura;
- **non consistente** → fra le **anomalie** del Catasto, distinte per provenienza da quelle già
  calcolate dal catasto. Se la riga di un codice è finita in anomalia, la Tab «Registro» di quel
  codice lo dice e porta all'anomalia.

È l'infrastruttura su cui si appoggerà oc:8540 (import selettivo dei campi).

## Perché

Forestas smetterà di aggiornare il foglio e vuole conservarne l'ultima versione dentro la
piattaforma (call tecnica del 14/09/2026, Alessio Saba: «posso buttare questo file Excel e mi terrò
lì l'ultima vista disponibile»). Il foglio non verrà distrutto, solo non più aggiornato. Il mirror
sta sul codice e non sulla traccia perché il registro è un elenco di numeri: la scheda del codice è
dove lo cerca chi lavora sul catasto.

La fonte di verità è **Drupal** (Sardegna Sentieri): il foglio è una copia compilata a mano che può
sbagliare, e chi lo compila può correggere Drupal. Il foglio non corregge nulla, segnala soltanto.

## Requisiti

**Quando parte**

- [ ] L'import del foglio parte **quando l'import da Sardegna Sentieri e il normalize del catasto
      sono finiti**: la callback del batch, dopo un normalize riuscito, accoda un job separato
      (`ImportRegistroCatastaleJob`) con timeout, retry e log propri. La callback non fa altro
      lavoro, per non allungare il processo del worker oltre il timeout della coda.
- [ ] Se il normalize viene saltato (soglia dei job falliti, `--only`) il job non parte e il log lo
      dice: aggancerebbe il foglio a un catasto incompleto.
- [ ] L'evento «import finito» deve esistere in entrambi i regimi: oggi c'è solo con `--reset`,
      quindi anche l'import orario deve raggruppare i job in un batch.
- [ ] Comando `forestas:registro-import` per rilanciare solo il foglio a mano.

**Lettura della sorgente**

- [ ] URL del file in `config/forestas.php`, letto da variabile d'ambiente. Download con l'export
      CSV pubblico (file condiviso con chi ha il link), nessuna credenziale Google.
- [ ] I fogli (`gid`) si ricavano **a ogni giro** dalla pagina pubblica `htmlview` del file; non
      c'è un elenco fisso. Se non se ne ricava nessun foglio, l'import si ferma senza toccare
      nulla. A ogni giro il log riporta i fogli letti, con nome e numero di righe.
- [ ] **Lettura dinamica delle colonne.** Un foglio è un registro se ha le quattro colonne chiave
      — area (`Cod. Provincia e Area`), `SETTORE`, `Numero`, link a Sardegna Sentieri — cercate
      per intestazione normalizzata (senza spazi, maiuscole e punteggiatura), non per lettera. Due
      colonne chiave con la stessa intestazione → foglio rifiutato e segnalato. Oggi: 7 fogli
      riconosciuti, 3 legende scartate.
- [ ] La riga si salva **per posizione**, come elenco ordinato di coppie (intestazione del foglio,
      valore): nessuna mappa fissa, nessun valore perso quando un'intestazione si ripete (il foglio
      Gallura ha `Comuni di appartenenza` due volte).
- [ ] **Tutto o niente:** si azzera e si ricopia solo dopo aver scaricato e validato tutti i fogli,
      in una transazione; se un foglio non si scarica o non è un CSV, non si tocca nulla e l'errore
      si registra. Nessuna soglia sul numero di righe: un foglio svuotato è responsabilità di chi
      lo mantiene.
- [ ] La tabella del mirror ha l'aggancio al codice come foreign key: si svuota con il reset notturno
      insieme ai codici e si ricostruisce nella stessa catena. Una riga sparita dal foglio sparisce
      dal mirror al giro successivo.

**Aggancio riga → codice**

- [ ] Strada principale, colonna del link → traccia → codice:
  - `/node/<id>` (con `http`/`https`, con o senza barra finale) → `properties->forestas->source_id`;
  - URL parlante → `properties->forestas->url`, dopo normalizzazione (schema, `www`, barra finale,
    decodifica, prefissi `/en/` e `/index.php/`).
- [ ] Ripiego, solo se il link è vuoto o non porta a nessuna traccia: area + numero + variante,
      **senza provincia**, confrontati con i codici del catasto. Più di un codice trovato → anomalia.
- [ ] La provincia non entra mai nel confronto: nel catasto si ricava dalla geometria, nel foglio è
      quella storica.
- [ ] Pulizia del campo `Numero` e dell'area, sui formati trovati nei dati:
  - spazi, note e punteggiatura (`182 A`, `100 (tappa Sentiero Italia)`, `210S.I.`, `800.`);
  - variante con la barra (`302/A`);
  - area vuota nelle righe successive, ereditata dalla riga sopra;
  - due aree nella stessa cella (`Z-SU-D Z-CA-D`);
  - righe di servizio (`34 numeri disponibili`) ignorate.

**Anomalie del registro** (tipi indicativi, da fissare nel piano)

- [ ] Link che non porta a nessuna traccia: righe sfasate, testo al posto del link, slug vecchi,
      nodi inesistenti.
- [ ] Traccia agganciata il cui codice ha numero o variante diversi dal foglio (es. `602C` contro
      `ZSSG506C` al nodo 2576; `106`, `122`, `332` nel foglio `Z-SU-D`).
- [ ] Traccia agganciata senza codice nel catasto, **solo se** non ha già un'anomalia del catasto.
- [ ] Ripiego ambiguo.
- [ ] Le righe con link vuoto e nessun codice corrispondente **non** sono anomalie: sono i numeri
      prenotati, senza scheda su Sardegna Sentieri (comportamento concordato col cliente).
- [ ] Ogni anomalia porta foglio, riga, valore della colonna link e, quando c'è, la scheda Drupal.

**Interfaccia Nova**

- [ ] `App\Nova\TrailRegistryCode` estende la Resource del package, come si fa per `EcTrack`, e
      aggiunge la Tab «Registro»: elenco ordinato intestazione → valore, foglio e riga di
      provenienza, data dell'ultimo import. Se la riga del codice è in anomalia, al posto della
      tabella un avviso con il link all'anomalia, calcolato in lettura (gli ID delle anomalie
      cambiano a ogni giro).
- [ ] `App\Nova\TrailRegistryAnomaly` estende la Resource del package:
  - testo in testa che spiega le due provenienze;
  - titolo con foglio e riga per le anomalie del registro (`Z-SU-D · riga 34`);
  - per le anomalie senza traccia, al posto del sentiero il valore trovato nella colonna link;
  - filtro per provenienza (catasto / registro).
- [ ] Anche `App\Nova\TrailApplication` esiste come sottoclasse, perché il package non registra più
      le Resource del dominio.
- [ ] Voce di menu: nessun gruppo nuovo, le anomalie del registro stanno nella Resource Anomalie.
- [ ] Visibilità: quella delle Resource del Catasto, nessun ruolo nuovo.
- [ ] Testi in italiano (`APP_LOCALE=it`), con traduzione in `lang/en.json` e `lang/it.json`.

## Rischi

Nessun rischio reale resta aperto in questo repo: i casi emersi nella challenge (reset notturno,
timeout del worker, colonne diverse fra i fogli, fogli da scoprire, pagina `htmlview`) sono
coperti dai requisiti.

Resta un vincolo di rilascio: **bump del submodule e sottoclassi Nova del Catasto nello stesso
commit**. Dopo il bump il package non registra più le proprie Resource, e senza le sottoclassi il
Catasto sparisce da Nova (vedi l'overview del package).

## Out of scope

- Import selettivo di colonne nei campi della traccia (`from`, `to`, `manual`, …): è oc:8540.
- Qualsiasi correzione automatica di Drupal o del catasto a partire dal foglio.
- Gruppo di menu «Anomalie» con due voci: scartato.
- Soglia di sicurezza sul calo di righe di un foglio: scartata.
- Salvataggio del CSV grezzo su disco: scartato, il foglio resta consultabile.
- Upload manuale del foglio o Google API con service account.
- Invio automatico dell'elenco anomalie al cliente: si consulta in Nova.

## Moduli toccati

Repo `forestas`:

- `config/forestas.php`, `.env-example`, `.env-deploy` — URL del file.
- `app/Console/Commands/ImportSardegnaSentieriCommand.php` — batch anche nel regime orario, job del
  registro accodato dopo il normalize.
- Nuovi: comando `forestas:registro-import`, `ImportRegistroCatastaleJob`, service di download,
  lettura e aggancio in `app/`.
- Nuova migration: tabella del mirror (riga come elenco ordinato, foglio, posizione, chiavi di
  aggancio, codice agganciato).
- `app/Nova/TrailRegistryCode.php`, `app/Nova/TrailRegistryAnomaly.php`,
  `app/Nova/TrailApplication.php` (nuovi).
- Dichiarazione in config dei modelli del dominio sostituiti, se servono sottoclassi di modello.
- Tipi di anomalia del registro, dichiarati al package, con il loro dettaglio.
- `lang/en.json`, `lang/it.json`.
- Test in `tests/`.
- Bump del submodule `wm-package`, dopo il merge della parte del package.
