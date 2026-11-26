# oc:9056 — Note di lavorazione

## Impronta dell'overview approvata

Overview approvata dal dev il **26/11/2026**, dopo una lettura critica indipendente e le correzioni
che ne sono seguite. Contiene 13 requisiti, da [R1](overview.md#r1) a [R13](overview.md#r13).

Impronta della versione approvata: impronta `c4bceb05acfcdff0af8c884558dad8f30d0b6e57`.

Il piano è stato scritto lo stesso giorno, su quella versione.

## Decisioni

- **26/11/2026 — Il tag `forestas` (id 676) è stato associato al ticket.** Gli altri due tag già
  presenti sono `26Q4` e `[SUS][FORESTAS][2026][SECONDA-REVISIONE]` (id 724).
- **26/11/2026 — Il campo del riferimento del SUS si chiama `reference`.** Scartati `code`, già
  occupato dal numero del catasto, e `sus_application_id`, perché «SUS» non esiste nel package, che
  è condiviso. Scartato anche `reference_id`: il suffisso `_id` in Laravel annuncia una foreign key.
- **26/11/2026 — La chiave di idempotenza è il `reference` stesso**, non un identificatore separato
  della chiamata. Il SUS si riaggancia alla propria domanda con il proprio codice, senza dover
  cercare per un `id` nostro.
- **26/11/2026 — Una seconda creazione con lo stesso `reference` e un tracciato diverso risponde
  `422`.** «La create va chiamata una volta sola per identificativo SUS».
- **26/11/2026 — `name` resta obbligatorio alla creazione.** Nel format il sentiero non ha una
  denominazione — la scheda parte dal campo 29 *Origine (da)* — ma il SUS un nome lo compone già e
  ce lo manda, e in Nova `name` è `$title` e unico campo di `$search`.
- **26/11/2026 — I campi legati fra loro li manda insieme il SUS**, non li ricompone il catasto:
  `activities`, `primary_activity`, `difficulty`. «Non è un problema nostro».
- **26/11/2026 — Su un GPX nuovo il DEM si ricalcola sempre.** Si ottiene azzerando `dem_data` nello
  stesso `UPDATE` della geometria, così `needsDem()` è vero per costruzione.
- **26/11/2026 — La documentazione della creazione cita il draft IETF `Idempotency-Key`**, con il
  link a `https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/`. Verificato:
  revisione 09 del 21/11/2026, Standards Track del working group `httpapi`, scadenza 25/05/2027.

## Correzioni all'overview dopo la lettura critica

Tutte verificate sul codice prima di applicarle.

- La motivazione del `_method` era falsa su questo stack: PHP 8.4.27 con `symfony/http-foundation`
  7.4.7 chiama `request_parse_body()` anche su `PATCH`. Lo spoofing si tiene per portabilità verso
  PHP 8.2, che `composer.json` ammette.
- Il campo vuoto arriva come `null`, non come `''`: `TrimStrings` e `ConvertEmptyStringsToNull` sono
  middleware globali e `bootstrap/app.php` non li toglie.
- La riga `Verifica` di [R12](overview.md#r12) era smentita da un test esistente: dichiarare
  `_schema_version` riceve `422` da `rejectUndeclaredKeys()`.
- `replaceNumber()` non serve a [R8](overview.md#r8): copia il settore dal codice vecchio. Servono
  `release()` e `reserve()`.
- Mancavano del tutto: l'azzeramento di `dem_data`, il lock e il job da accodare a mano (l'hook
  `created` non scatta su una patch), e la sostituzione di `original_geometry`.
- [R3](overview.md#r3) copriva solo due creazioni in sequenza, non due sovrapposte — che è il caso
  reale del timeout.
- Tolto il rischio sull'impronta che cambia dopo una patch sulla geometria: per fare una patch il
  SUS deve avere l'`id`, quindi quel caso non è mai una riconciliazione da timeout.

## Modifiche ai requisiti

### Niente impronta del tracciato: la creazione ripetuta si rigetta e basta

- **Requisito:** [R3](overview.md#r3)
- **Effetto:** modificato
- **Decide:** dev, 26/11/2026, durante T1
- **Testo nuovo:**
  > Una creazione con un `reference` già presente non crea nulla e risponde `422`, dicendo che quel
  > riferimento è già usato e nominando l'`id` della domanda esistente. Vale sempre, qualunque
  > tracciato porti la chiamata e in qualunque stato sia la domanda trovata: il SUS riceve il nome
  > del proprio fascicolo e da lì prosegue con le patch. Il caso da reggere è la concorrenza, non la
  > sequenza: il ritentativo dopo un timeout parte mentre la prima chiamata è ancora dentro la sua
  > transazione, quindi una ricerca fatta prima non vede nulla, e la violazione dell'indice unico va
  > intercettata e tradotta nello stesso `422`.

[R3](overview.md#r3) prevedeva di distinguere un ritentativo onesto (stesso `reference`, stesso
tracciato → si restituiva la domanda già nata) da un riferimento riusato per un tracciato diverso
(`422`). Per distinguerli serviva conservare un'impronta del tracciato consegnato alla creazione, in
una colonna nuova.

Decisione del dev: **«la soluzione è unica: rigetti tutto dicendo che quella reference c'è già»**, e
nel `422` ci va l'`id` della domanda esistente.

Il caso d'uso resta coperto, per una via più corta: il `422` è già l'informazione che al SUS manca
dopo un timeout — la domanda è nata, e si chiama così — e da lì riprende con le patch senza
aspettare l'endpoint di ricerca.

Cosa cade: la colonna `original_geometry_hash`, già scritta e poi tolta dallo stub, dalla copia
pubblicata e dal database locale; il task T2 per intero; il confronto delle impronte in T4.

Cosa resta più semplice: la concorrenza si chiude catturando la violazione dell'indice unico e
rispondendo con lo stesso `422`, senza niente da confrontare.


### La creazione rifiuta i campi del modulo, non li accetta come facoltativi

- **Requisito:** [R1](overview.md#r1)
- **Effetto:** modificato
- **Decide:** dev, 26/11/2026, guardando la pagina delle API
- **Testo nuovo:**
  > Il SUS apre una domanda mandando `reference`, `name` e `geometry`, e soltanto quelli: un campo
  > del modulo mandato all'apertura viene rifiutato con `422`. Il modulo si consegna con una o più
  > modifiche.

[R1](overview.md#r1) diceva che gli altri campi «diventano facoltativi in quel momento», e così
l'avevo implementato: le regole li accettavano ancora. Il dev, vedendo la pagina delle API mostrare
22 campi sull'apertura: **«NO, non devono essere presenti»**.

Cosa cambia rispetto a come l'avevo fatto: `StoreApplicationRequest` dichiara i soli tre campi, e
`rejectUndeclaredKeys()` — che c'era già — rifiuta tutto il resto. Sono spariti da lì la decodifica
dei campi strutturati, i vincoli fra campi e il payload da conservare: `api_data` nasce con la sola
versione dello schema. I diciannove `#[BodyParameter]` dei campi del modulo si sono spostati sul
controller della modifica, insieme alle sezioni sulle unità di misura e sulla forma dei campi
strutturati in `multipart`.

Il guadagno è che ogni chiamata ha un compito solo: chi integra non sceglie fra due strade che
fanno la stessa cosa, e la pagina non promette più su una chiamata campi che quella chiamata
rifiuta.

## Divergenze dal piano

### T1 — le due colonne sono `nullable`, il piano diceva «non nulla»

Una domanda nata d'ufficio da
Nova ha `source = office` e non ha nessun sistema esterno dietro: un riferimento esterno non può
averlo. L'indice unico regge lo stesso, perché PostgreSQL non confronta fra loro i `NULL`.

### T1 — l'obbligatorietà di `reference` passa dalla configurazione

Non è scritta fissa. Nel package nasce la chiave `features.trail_registry.api.require_reference`, spenta di default: chi
accende il Catasto Sentieri per lavorarci solo da Nova non subisce le regole di un canale che non
usa. Forestas la accende con `WM_TRAIL_REGISTRY_REQUIRE_REFERENCE=true`. Insieme a quella chiave si
accende anche il comportamento della creazione ritentata, che senza un riferimento non avrebbe
appiglio.

### T3 — il gruppo di campi legati si controlla già alla creazione

Il piano metteva i campi legati di [R6](overview.md#r6) in T6 e T7, cioè nell'estrazione del
validatore e nella patch. Alla creazione però il controllo serve subito, perché tre test esistenti
lo davano per scontato attraverso i `required` dello schema, che questo ticket toglie: senza, una
creazione con `primary_activity` e senza `activities` sarebbe passata.

`requireLinkedFields()` nasce quindi in `StoreApplicationRequest`, già con il parametro dei campi
disponibili, che è ciò che serve alla patch per contare anche quelli salvati. T6 la sposterà dove
possono usarla anche la patch e la conferma.

### T3 — tre test esistenti cambiano, perché cambia il comportamento

`StoreApplicationRequestTest` affermava che la creazione pretende i campi obbligatori dello schema.
Ora afferma il contrario, e accanto c'è un test nuovo che tiene il punto che resta vero: un campo
obbligatorio **mandato vuoto** è ancora un errore di forma. Gli altri due test — «esige la primaria»
e «con difficulty presente chiede activities» — passano senza modifiche grazie a
`requireLinkedFields()`.

### T4 — i test della creazione sono scritti sulla regola, non sull'implementazione

Un primo giro di test provava a riprodurre due creazioni sovrapposte, con una sottoclasse del
controller che la prima volta non vedeva la domanda esistente. Indicazione del dev: **«riscrivi i
test da zero sapendo ciò, non cercando di far funzionare i vecchi»**.

I test dicono ora quello che la regola dice: una create per riferimento, e la seconda dà errore —
con lo stesso tracciato, con un tracciato diverso, e senza toccare la domanda già aperta. La
sottoclasse di supporto è stata cancellata.

La doppia difesa nel controller resta: la ricerca preventiva e la cattura della violazione
dell'indice unico, che serve a chi chiama mentre l'altra transazione è ancora aperta. La seconda non
ha un test suo, perché la sovrapposizione non è riproducibile nella suite — una riga scritta sulla
connessione del test sparisce con il rollback, una connessione separata non vede le tabelle — ma è
l'indice unico del database a garantirla, non il codice.

## Comandi eseguiti sugli ambienti

Lo stub della tabella esce subito se `trail_applications` esiste, quindi dove il Catasto è già
installato le due colonne vanno aggiunte a mano. **Locale: fatto il 26/11/2026.** Restano dev e
UAT, che aggiorna il team.

```sql
ALTER TABLE trail_applications ADD COLUMN IF NOT EXISTS reference VARCHAR(255) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS trail_applications_reference_unique ON trail_applications (reference);
```

### La chiave di configurazione: fatta su dev e UAT il 26/11/2026

`WM_TRAIL_REGISTRY_REQUIRE_REFERENCE=true` è stata aggiunta al `.env` dei due ambienti, subito
sotto `WM_TRAIL_REGISTRY_ENABLED`, prima del deploy. Di ciascun file resta una copia
`.env.backup-oc9056-<data>` accanto all'originale.

Metterla prima del deploy è la cosa giusta e non è rischiosa: finché il codice nuovo non è in
linea, quella chiave non la legge nessuno e viene ignorata. Al contrario, aggiungerla dopo
costringerebbe a ricordarsene, e su UAT `deploy_prod.sh` esegue `php artisan optimize`, che congela
la configurazione: una chiave che arriva dopo quel passo non ha effetto finché la cache non viene
rifatta. Su nessuno dei due ambienti la configurazione risultava cachata al momento della modifica.

Senza quella chiave il canale resta com'era: `reference` facoltativo e nessun controllo sulla
domanda già aperta.

## Bug trovati

Nessuno finora.

## Follow-up

- **L'endpoint di ricerca per `reference`** va a un ticket suo, da scrivere nel tag
  `[SUS][FORESTAS][2026][SECONDA-REVISIONE]` (id 724) dopo questo. La macro area è già nel tag.
- **Lo stato del ticket su Orchestrator risulta `todo`**: il passaggio a `progress` non è andato a
  buon fine e va rifatto.
