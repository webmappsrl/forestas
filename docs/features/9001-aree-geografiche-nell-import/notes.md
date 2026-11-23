> Ticket: oc:9001

# Notes — Aree geografiche nella fase di import

## Divergenze dal piano, task per task

### Task 2: eventi spenti solo sul collegamento all'area

Il piano faceva avvolgere al chiamante tutta la creazione in `TaxonomyWhereable::withoutEvents()`.
`withoutEvents()` però spegne il dispatcher di **tutti** i model (lo dice anche
`RecalculateSardegnaSentieriLayersJob`), e il Layer nasceva senza il `rank` che gli dà
`LayerObserver::creating()`. `createLayerFromTaxonomyWhere()` ha quindi un terzo parametro,
`bool $withPivotEvents = true`, che spegne gli eventi solo sull'`attach()` all'area. Il test lo
verifica: layer vuoto con il rank.

### Task 3: una transazione per area

Il test sull'area rifiutata da PostGIS ha trovato un difetto che c'era già: l'area veniva creata e
solo dopo riceveva la geometria. Con la geometria rifiutata restava un'area senza geometria e,
dentro una transazione, Postgres annullava tutte le query successive (`25P02`), compreso il
ricollegamento delle tracce. Ogni area ora sta in una `DB::transaction()` sua: verificato che il
test fallisce senza e passa con. Il poligono con un punto solo previsto dal piano invece PostGIS lo
accetta: il test usa un tipo di geometria sconosciuto.

### Task 5: nessun test parla con la rete

Tre test che lanciano l'import intero (`SardegnaSentieriImportServiceTest`,
`ImportSardegnaSentieriEntiCommandTest`, `DrupalDumpLockTest`) non fingevano l'HTTP e con il passo
nuovo avrebbero chiamato Geohub davvero. `tests/TestCase.php` ora chiama
`Http::preventStrayRequests()`, accanto ai dischi remoti finti: una richiesta non finta fallisce, e
il passo delle aree registra l'errore e lascia proseguire l'import. Suite intera: 48 test falliti,
tutti già falliti prima (test SUS senza `JWT_SECRET` perché manca `.env.testing`, e test che
confrontano testi in un'altra lingua): verificato rilanciando senza le modifiche.

La regola vale anche per i test del package lanciati da qui (i 98 file con `Tests\TestCase`), che
usano lo stesso `TestCase`. La review interna del 22/11 li ha lanciati tutti: 36 falliscono per
l'ambiente (`JWT_SECRET` assente, lingua `en`, schema), nessuno per `preventStrayRequests()`, e
nell'output non c'è nessun «without a matching fake». Un test del package rosso da qui non dipende
da questa riga, a meno che il messaggio non nomini una richiesta senza fake.

### Task 4: FeatureCollection salvata senza eventi

Il piano portava la FeatureCollection a `generated` con `save()`, lasciando all'observer del modello
la generazione. L'observer però la accoderebbe al salvataggio, prima che i layer siano collegati:
il codice salva con `saveQuietly()` e accoda `GenerateFeatureCollectionJob` dopo il collegamento.
Se nessuna area ha un layer (per esempio `32.json` cambiato e tutte le aree saltate) la
FeatureCollection non si tocca e il passo lo scrive nel log: la generazione sovrascriverebbe il
file caricato con un overlay vuoto. Un layer di area rimasto senza modalità dei POI (giro
interrotto fra creazione e `setPoiMode()`) viene rimesso in manuale al giro dopo.

### Task 5: reindex dopo `--only=aree`

Il piano accodava solo `RecalculateSardegnaSentieriLayersJob`. La review finale ha trovato che
l'API di ricerca filtra per layer sul campo `layers` dell'indice
(`ElasticsearchController.php:213-218`), che si aggiorna solo con il reindex: dopo `--only=aree`
il click su un'area apriva un layer senza percorsi. Con `--only=aree`, e nel giro senza job da
importare se sono nati layer, si accoda la stessa catena di `configure-layers`, più i luoghi dei
POI: `SyncTaxonomyWhereJob`, `RecalculateSardegnaSentieriLayersJob`,
`RegenerateTaxonomyWhereOutputsJob`. `--only=aree` esce con errore se il passo fallisce.

## Decisioni

- **Approccio «come le aree catastali».** Il 20/11 si è scelto di fare le aree geografiche
  esattamente come le catastali, come aveva chiesto Giuseppe allo scrum del 17/11. Una prima
  versione dell'overview andava oltre; le parti in più restano qui, da valutare in un ticket
  separato che allinei aree catastali e geografiche (vedi «Follow-up»).
- **Tag Orchestrator:** nessun tag associato, su indicazione del dev.
- **`forestas_testing` riallineato** il 20/11: mancava `taxonomy_terms`, e i test del package
  con `DatabaseTransactions` fallivano; un test con `RefreshDatabase` ha rifatto le migration.
- **PHPStan con la cache in `/tmp` del container:** sul disco esterno `build/phpstan/cache` fallisce
  la `rename()` fra worker paralleli. Configurazione temporanea che include `phpstan.neon.dist` e
  imposta `tmpDir`. Esito: nessun errore nei file toccati; 11 errori già presenti nei test SUS
  (`JWTAuth::fromUser()`).
- **Review interna del 22/11** (due sessioni, una per repo), corretto:
  - i due test nuovi del package mancavano in `wm-package/phpunit.xml.dist` (`<exclude>` nella
    suite `package`, `<file>` nella suite `shard`): la suite di default del package sarebbe morta
    in caricamento con «Class … not found»;
  - in `LayerService` un testo semplice, senza lingua, va nella lingua corrente sia per il nome sia
    per descrizione e sottotitolo. Prima il nome andava nella lingua corrente (come faceva
    l'action) e descrizione e sottotitolo in italiano; test
    `puts a plain-text name and description in the same language, the current one`;
  - tolti il controllo sui media già presenti in `syncFeatureImageFromTaxonomyWhere()` (il layer è
    appena nato e non ne ha) e `$appId` inutile nell'action «Crea Layer»;
  - docblock di `createLayerFromTaxonomyWhere()` (il dispatcher spento è di tutti i model, solo
    per l'attach), di `importGeographicAreas()` (con `--only=aree` il chiamante esce con errore) e
    di `RecalculateSardegnaSentieriLayersJob` (larghezza delle righe);
  - documentazione: pagina `docs/knowledge/9001-aree-geografiche-nell-import.md`, riga nel
    `CLAUDE.md`, voci in `.claude/rules/import-sardegna-sentieri.md` e `.claude/rules/test.md`;
    nel package `docs/resources/TaxonomyWhere.md` e `docs/knowledge/layer-e-associazione-contenuti.md`.
- **DB locale allineato a dev** il 20/11 prima di partire: backup del DB locale precedente e dump
  di dev in `/Volumes/WebmappDev/tmp/oc-9001-db/`.

## Follow-up

### Approccio ipotizzato e rinviato: un solo passo per catastali e geografiche

Prima versione dell'overview, superata dalla scelta «come le catastali». Da valutare in un ticket
separato:

- **Catastali nell'import.** Lo stesso passo gestisce anche le 7 aree catastali, che oggi nascono
  solo dalle tre action Nova lanciate a mano su ogni ambiente. Un ambiente nuovo le avrebbe senza
  ripetere le action.
- **Cancellazione delle aree sparite da Geohub**, con il loro Layer, solo per la sorgente del
  passo, e mai se Geohub non risponde o il geojson arriva vuoto o non valido.
- **Nessuna scrittura senza cambi su Geohub**, così l'import orario (locale, futura produzione)
  non riscrive le aree e non ricollega tutte le tracce a ogni giro.
- **FeatureCollection delle catastali riconosciuta dai layer collegati**, non dal nome: su dev si
  chiama `areee_catastali`, su UAT `aree_catastali`.
- **Più Layer sulla stessa area:** il passo non ne crea e non ne cancella, usa quello nella
  FeatureCollection e lo scrive nel log.

### Rilievi minori della review finale, non corretti

- `syncWithoutDetaching()` che collega layer nuovi non conta come cambiamento: dopo un giro
  interrotto fra il passaggio a `generated` e il collegamento, l'overlay non si rigenera.
- Le aree saltate (senza `layer_id`, senza corrispondenza in `32.json`, senza geometria) finiscono
  solo nei contatori del riepilogo, non con il loro `layer_id`.
- Un poligono cambiato su Geohub aggiorna la geometria dell'area ma non rigenera l'overlay né
  ricalcola i layer fuori dalla catena di fine batch: va con il ticket «nessuna scrittura senza
  cambi su Geohub».
- Una FeatureCollection creata da zero dal passo non lascia un avviso nel log: su un ambiente con
  un nome diverso ne nascerebbe una seconda, fuori dagli overlay.
- Mancano test su `syncFeatureImageFromTaxonomyWhere()` e sull'accodamento di
  `GenerateFeatureCollectionJob`.
- I test che lanciano l'import intero scrivono «Aree geografiche non importate» nel log vero
  `storage/logs/import-*.log` (per `Http::preventStrayRequests()`): già oggi i test scrivono lì,
  ma queste righe sembrano guasti di Geohub.

### Dati da sistemare a mano su UAT

- Il layer 7 è collegato all'Area G (Taxonomy Where 85) ma si chiama «Area F (Nord)» in inglese e
  «Area G (Nord-EST)» in italiano, ed è fuori dalla FeatureCollection delle catastali. È un
  doppione lasciato dall'action «Crea Layer»; la home di UAT usa già il layer giusto dell'Area F
  (12).

### `api/v1/app/1/pois.geojson` risponde 500 sul dev

Trovato il 20/11 provando gli endpoint, non riguarda questo ticket: `StorageService::getPoisGeojson():
Argument #1 ($appId) must be of type int, null given`, da
`wm-package/src/Http/Controllers/Api/V1/AppAPIController.php:96`. La v2
(`api/v2/app/1/pois.geojson`) risponde.

### Filtri delle aree sul sito: oc:9018 (oc:9020 scartato)

Il 20/11 si è deciso di lasciare tutti i luoghi in `taxonomyWheres`, come su Geohub (verificato
sul DB di produzione di Geohub: il percorso 14356 ha regione, provincia, quattro comuni e due aree
geografiche), e di dividere i luoghi per tipo in oc:9020 con un campo nuovo nell'indice,
`taxonomyWheresByType`.

Allo scrum del 23/11 Giuseppe ha scartato quella strada: niente dati nuovi in Elastic, il sito
deve funzionare come la webapp. Chiama la ricerca una volta e scarica una volta le
FeatureCollection delle aree. Ogni area del geojson ha il `layer_id` del suo layer, e ogni percorso
nei risultati della ricerca ha già i suoi `layers`: il filtro di un'area tiene i percorsi il cui
`layers` contiene quel `layer_id` (verificato in locale: un percorso con `layers` `[3, 11, 18]` sta
in «Area D» e in «Iglesiente e Marganai»). oc:9020 è `rejected`, e il lavoro è stato aggiunto a
oc:9018 (sito), da portare su UAT insieme a oc:9001.

### L'indice di ricerca del dev ha percorsi che il DB non ha

Il 20/11 `api/v2/elasticsearch?app=geohub_app_1&query=bono` sul dev restituiva il percorso 213
«Cossoine - Bonorva (G 502)», mentre le tracce del DB del dev vanno da 1 a 170: dopo il reset
notturno l'indice sembra tenere documenti di percorsi che non esistono più. Non verificato oltre,
non riguarda questo ticket.

### Dal 20/11 dev e UAT hanno 170 percorsi invece di circa 770

Trovato il 22/11, confrontando il layer 36 «Tacchi - Quirra - Ogliastra costiero» (8 percorsi in
locale) con il layer 241 di Geohub (69). La regola è la stessa: l'area è identica (854 km² su
entrambi) e su Geohub i 69 percorsi del layer sono proprio quelli che toccano l'area
(`ST_Intersects`). La differenza sta nei percorsi presenti:

- Drupal (copia su UAT) ha 614 sentieri e 153 itinerari pubblicati, mentre Geohub ne ha 773
  (utente dell'app 32).
- Dev, UAT e il locale, che è una copia del dev, ne hanno 170.
- Il certificato SSL di `www.sardegnasentieri.it` è scaduto il 19/11/2026 alle 23:59:59 GMT
  (RapidSSL). Dal 20/11 il download dei GPX fallisce (`cURL error 60: SSL certificate problem:
  certificate has expired`) e l'import scarta i percorsi nuovi: «No usable geometry for new track
  … Import skipped».
- Il `--reset` delle 06:00 tronca i percorsi ogni mattina, quindi su dev e UAT 602 job su 1748
  falliscono il 20, il 21 e il 22/11. Il comando dice: «oltre la soglia, anomalie e registro
  catastale NON aggiornati».

Non dipende da questo ticket, ma la verifica di oc:9001 su dev e UAT (Task 6) va fatta con i
percorsi tornati. Spiega probabilmente anche il percorso 213 nell'indice del dev (sezione sopra),
ma non l'ho verificato.

### Modifiche per wp-forestas

Da decidere a fine lavoro se aprire un ticket nuovo o aggiungerle a oc:9018.

- Aree catastali e aree geografiche sono due filtri separati sul sito.
- Il tema (`themes/forestas-child/inc/dettaglio.php:841`) chiama «aree geografiche» le aree il cui
  nome inizia con «Area», che sono le 7 catastali: va distinto per `source` della Taxonomy Where,
  non per nome.
