> Ticket: oc:8999
> Stato: approvata il 2026-11-26

# Bozze di Drupal importate senza lo stato di bozza: l'import le deve saltare

## Cosa cambia

L'import da Sardegna Sentieri smette di portare nello shard i nodi che in Drupal sono in bozza, e
dei nodi pubblicati le traduzioni in bozza. Dopo l'import notturno con `--reset`, dall'indice
Elasticsearch delle tracce si cancellano le voci che non corrispondono più a una traccia, così nella
lista non restano documenti della notte prima. Sul sito WordPress di dev e UAT i sentieri e il POI
pubblicati che avevano un doppione in bozza prendono l'indirizzo senza `-2`. Nello stesso lavoro si
correggono i testi che descrivono una produzione con l'import orario e due documenti rimasti
indietro da ticket precedenti (oc:9018, oc:8947).

## Perché

Sul nuovo sito e nell'app compaiono contenuti che in Drupal non sono pubblicati, e alcuni di loro
tolgono l'indirizzo pulito al contenuto pubblicato con lo stesso nome (`/sentiero/monte-cresia-t-513a-2/`).
Allo scrum del 17/11 Giuseppe ha deciso che le bozze nello shard non ci vanno: restano nella copia SQL
di Drupal e, se il cliente le chiederà, si riaggiungono. Lo shard non ha una gestione delle bozze.

L'import serve solo a tenere dev e UAT allineati a Drupal durante il lavoro: in produzione non ci
sarà nessun import, resterà l'ultima copia importata (Rubens, 26/11).

## Punto di partenza

- `changedList()` in `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` filtra solo
  `type` e `default_langcode = 1`, senza `status`; costruisce gli elenchi di tracce (`sentiero`,
  `itinerario`), POI ed enti. Il docblock della classe dice «nessun filtro su `status`: le API
  esponevano anche le bozze».
- In Drupal `status` è per traduzione. Nella stessa classe i campi tradotti passano tutti da
  `languages()`, che elenca la lingua originale e ogni traduzione del nodo senza guardare `status`;
  `mediaReferences()` prende immagini e allegati dell'inglese quando l'originale non ne ha.
- La regola di oc:8900 in `.claude/rules/import-sardegna-sentieri.md` vieta di filtrare
  `status = 1`; `docs/knowledge/copia-database-drupal.md` dice in tre punti che l'import non filtra.
- Copia Drupal di UAT, oggi: in bozza 3 sentieri su 617 (nid 545, 560, 561), 1 itinerario su 161
  (nid 3686), 23 POI su 978, nessun ente su 93. Hanno almeno una traduzione 322 sentieri, 114
  itinerari, 594 POI e 1 ente; nessun nodo ha una traduzione in uno stato diverso dall'originale.
- Su dev e UAT (`SARDEGNASENTIERI_DAILY_RESET` acceso) l'import delle 06:00 gira con `--reset`:
  `resetData()` in `app/Console/Commands/ImportSardegnaSentieriCommand.php` tronca `ec_tracks` ed
  `ec_pois` con `RESTART IDENTITY` e reimporta tutto, quindi le tracce riprendono gli id da 1.
- L'indice Elasticsearch viene solo aggiornato: `RegenerateTaxonomyWhereOutputsJob` del package
  chiama `$tracks->searchable()` sulle tracce che esistono, e nessun passo cancella i documenti.
  Su UAT l'indice `ec_tracks_1795431071` (alias `ec_tracks`) è stato creato il 23/11 e ha 778
  documenti, come le tracce nel DB.
- Un nodo che sparisce dall'elenco, con l'import orario senza reset, viene marcato
  `properties.forestas.deleted_from_source`. Per tracce e POI lo leggono solo l'API degli avvisi
  (`NoticeController`) e quella degli enti (`EnteController`); l'indice Elasticsearch, l'API della
  traccia e `pois.geojson` no.
- Lo schedule in `routes/console.php` (oc:8489) lancia l'import ogni ora dove
  `SARDEGNASENTIERI_DAILY_RESET` è spento, e il suo commento lo attribuisce alla produzione; oggi il
  ramo orario non gira da nessuna parte. `docs/knowledge/copia-database-drupal.md` (righe 29 e 601)
  dice che la copia serve anche alla futura produzione.
- Il sito usa `wp-package` 0.3.0 (oc:8983, già su dev e UAT): chi ha un indirizzo lo tiene, e un
  indirizzo si libera solo quando il suo elemento sparisce. Oggi dallo storico dei percorsi del
  plugin (`wp wmwp detail-paths list`) i pubblicati con `-2` sono Monte Cresia (nid 699) su dev,
  Nolau (nid 694) e il POI «Bivacco fisso lungo il sentiero D 105C» (nid 3111) su dev e UAT; gli
  indirizzi puliti sono delle bozze. Su UAT Monte Cresia ha già l'indirizzo pulito.
  `wp wmwp detail-paths forget <percorso>` cancella la riga di quel percorso e toglie lo stato di
  attuale agli altri percorsi con la stessa radice (`includes/detail-history.php`,
  `wmwp_detail_history_forget()`).
- `docs/howto/messa-in-opera-wordpress-dev.md` dice che sullo storico del dev restano 170 righe con
  chiave per id da togliere: il 25/11 erano già 0. Le note e il piano di oc:8947 dicono ancora che
  resta da aggiornare l'host di dev, fatto il 06/11.

## Requisiti

- <a id="r1"></a>**R1** — L'import da Sardegna Sentieri non porta nello shard i nodi che in Drupal
  sono in bozza nella lingua originale. Su dev e UAT, dopo l'import delle 06:00, i nodi in bozza
  (oggi 4 tracce e 23 POI) non sono in Nova, nella lista Elasticsearch né in `pois.geojson`. Un nodo
  che in Drupal passa da bozza a pubblicato entra all'import successivo.
- <a id="r2"></a>**R2** — Di un nodo pubblicato l'import porta solo le traduzioni pubblicate: una
  traduzione in bozza non dà né testi né immagini e allegati. Un nodo con la lingua originale in
  bozza resta fuori anche se ha una traduzione pubblicata (Rubens, 26/11).
- <a id="r3"></a>**R3** — Dopo ogni import con `--reset`, l'indice Elasticsearch delle tracce
  contiene solo le tracce dello shard, con i loro id attuali: le voci che non corrispondono più a
  una traccia si cancellano. L'indice non si svuota né si ricostruisce da zero, quindi la lista del
  sito e delle app non resta mai vuota né a metà.
- <a id="r4"></a>**R4** — Sul sito WordPress di dev e UAT i tre elementi pubblicati che avevano un
  doppione in bozza hanno l'indirizzo senza `-2`, in italiano e in inglese:
  `/sentiero/monte-cresia-t-513a/` (nid 699), `/sentiero/nolau-t-513/` (nid 694),
  `/punti-di-interesse/bivacco-fisso-lungo-il-sentiero-d-105c/` (nid 3111), e i corrispondenti
  `trail/…` in inglese. I vecchi indirizzi con `-2` portano lì con un 301. Su UAT vale dopo il merge
  di `develop` in `main`.
- <a id="r5"></a>**R5** — Docblock, regola dell'import e pagina sulla copia Drupal dicono che
  l'import salta le bozze, anche delle traduzioni, e che dopo il reset si cancellano le voci in più
  dell'indice; la regola di oc:8900 che vietava il filtro è sostituita, non lasciata accanto alla
  nuova. Il commento dello schedule in `routes/console.php` e la pagina sulla copia dicono che in
  produzione non è previsto nessun import e che al passaggio lo schedule orario va spento; lo
  schedule non cambia.
- <a id="r6"></a>**R6** — La procedura di WordPress su dev e i documenti di oc:8947 dicono lo stato
  vero: niente più «170 righe» da togliere, host di dev già aggiornato.

## Rischi

- **Cancellazione delle voci in più fallita.** Per quella notte restano nella lista i documenti
  vecchi ([R3](#r3) non vale): il fallimento deve finire nel log dell'import.
- **Ordine e percorso dei passi su WordPress.** `forget` va lanciato sugli indirizzi puliti delle
  bozze, non sui `-2`: sul `-2` cancellerebbe la riga del pubblicato e il vecchio link darebbe 404.
  E va lanciato solo dopo che le bozze sono sparite dall'indice dei dettagli del plugin
  (`wmwp_detail_index`), e per il POI dopo la rigenerazione di `pois.geojson`: prima, alla
  ricostruzione la bozza riprenderebbe l'indirizzo pulito. Dalla prima notte senza bozze fino a quel
  passo gli indirizzi puliti danno 404.
- **Catasto di UAT.** Spariscono le 2 anomalie `codice_gia_assegnato` fra pubblicati e bozze
  (699↔561, 694↔560). La bozza Sa Crabarissa (545) ha il codice ZNUT511: se il foglio del registro
  la cita, compare un'anomalia `registro_link_orfano`, che è un dato vero. Il foglio non è stato
  controllato.

## Out of scope

- Nascondere da Elasticsearch, API della traccia e `pois.geojson` le tracce e i POI marcati
  `deleted_from_source`: succede solo con l'import orario, che non gira e in produzione non ci sarà.
- Spegnere lo schedule orario di `routes/console.php`: è lavoro di oc:8489, si documenta e non si
  tocca.
- Una gestione delle bozze nello shard o in Nova: se il cliente le chiederà, si riaggiungono.

## Moduli toccati

Previsti, tutti in `forestas`:

- `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` — filtro dei nodi e delle
  traduzioni, docblock
- `app/Console/Commands/ImportSardegnaSentieriCommand.php` o un job della catena dell'import —
  cancellazione delle voci in più dell'indice dopo il reset
- `tests/Feature/Import/` — test dei filtri (tabella `node_field_data` finta, lettore della copia
  finto per le traduzioni) e della cancellazione
- `routes/console.php` — solo il commento dello schedule
- `.claude/rules/import-sardegna-sentieri.md`, `docs/knowledge/copia-database-drupal.md`
- `docs/howto/messa-in-opera-wordpress-dev.md`, `docs/features/8947-…/notes.md` e `plan.md`

Sui server, senza file versionati: `wp wmwp detail-paths forget` sul WordPress di dev e di UAT.
