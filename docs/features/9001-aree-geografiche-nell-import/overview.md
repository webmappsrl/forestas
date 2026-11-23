> Ticket: oc:9001

# Aree geografiche nella fase di import, come le aree catastali

## Cosa cambia

`sardegnasentieri:import` porta nello shard le 39 aree geografiche di Geohub con lo stesso
percorso con cui oggi si creano le 7 aree catastali, ma senza passi manuali:

1. **Taxonomy Where.** `TaxonomyWhereLayersImportService`, lo stesso service dell'action Nova
   «Import TaxonomyWhere (Cadastral areas)», viene reso parametrico sulla sorgente. Per le aree
   geografiche legge `AreeGeograficheConLayers.geojson` e la config dell'app 32 (`32.json`), crea o
   aggiorna le 39 Taxonomy Where e ricollega le tracce alle aree, come fa oggi per le catastali.
2. **Layer.** Per ogni area senza Layer, il passo crea il Layer con la logica dell'action Nova
   «Crea Layer» del wm-package (`CreateLayerFromTaxonomyWhere`), spostata in un metodo di
   `LayerService` perché l'import la possa richiamare.
3. **Overlay.** I 39 Layer vengono collegati alla FeatureCollection `aree_geografiche`, che passa
   da `upload` a `generated`, come «Aree Catastali»: i poligoni portano il `layer_id` del Layer
   dello shard, e il click su un'area apre i suoi percorsi.

Il passo gira a ogni esecuzione dell'import; `--only=aree` lo lancia da solo.

Le aree catastali restano come sono, con le action Nova.

Gli overlay sulla mappa prendono i loro colori invece di uscire neri: la correzione è nel
wm-package (vedi `wm-package/docs/features/9001-aree-geografiche-nell-import/overview.md`).

## Perché

Il cliente vuole che, sulla mappa, il click su un'area geografica apra i percorsi di quell'area,
come succede già per le aree catastali; le stesse aree servono al nuovo sito per le pagine delle
zone e per il filtro della ricerca.

Oggi le aree geografiche esistono nello shard solo come disegno: la FeatureCollection
`aree_geografiche` è in modalità `upload` e i suoi poligoni portano i `layer_id` di Geohub
(206–246), che nello shard non esistono. Non ci sono né Taxonomy Where né Layer.

Allo scrum del 17/11 (14:36) Giuseppe ha chiesto di fare le aree geografiche nella fase di import
allo stesso modo delle aree catastali, senza creare niente a mano, e senza prendere le zone da
Drupal.

## Requisiti

- [x] Il passo delle aree geografiche è parte di `sardegnasentieri:import` e gira in ogni
      esecuzione, con e senza `--reset`, prima che vengano accodati i job di tracce e POI
- [x] `--only=aree` esegue solo il passo delle aree geografiche
- [x] `TaxonomyWhereLayersImportService` accetta la sorgente (geojson e chiave `source`); per le
      catastali il comportamento e i dati restano identici (`source = geohub_conf_32`, stessi
      identifier), e l'action Nova delle catastali continua a funzionare com'è
- [x] Le 39 aree geografiche nascono come Taxonomy Where con `source =
      geohub_conf_32_geographic_areas`, distinta da quella delle catastali (`geohub_conf_32`), così
      sito e app possono distinguere i due insiemi con un dato e non dal nome
- [x] Nome, `subtitle`, `description` e `feature_image` dell'area vengono da `32.json`, come per
      le catastali
- [x] Il passo crea il Layer di un'area solo se l'area non ne ha già uno, così i giri successivi
      non creano doppioni
- [x] Il Layer nasce con la stessa logica dell'action «Crea Layer», che passa in un metodo di
      `LayerService` del wm-package; l'action usa lo stesso metodo. Il layer riceve dalla Taxonomy
      Where nome, descrizione, sottotitolo e immagine, con tutte le traduzioni salvate, così il
      click sull'area mostra il testo che l'area ha su Geohub (38 aree su 39 hanno una descrizione)
- [x] L'import crea il Layer e lo collega all'area con gli eventi spenti, così nascono vuoti e
      senza job per ogni riga; li riempie `RecalculateSardegnaSentieriLayersJob`, come i layer
      delle catastali: a fine batch nel giro normale, accodato dal passo con `--only=aree`.
      L'action «Crea Layer» resta con gli eventi accesi
- [x] I layer delle aree geografiche contengono solo percorsi, come su Geohub: il passo li crea
      con la modalità dei POI su manuale (`setPoiMode('manual')`), così né `assignPoisByTaxonomy()`
      né il ricalcolo di fine import ci mettono i POI. Senza, le 9 aree con soli POI farebbero
      rigenerare ogni notte i PBF dell'intera app (`PBFGeneratorService.php:523-525`)
- [x] I 39 Layer sono collegati alla FeatureCollection `aree_geografiche`, che passa a
      `generated`; i suoi poligoni portano il `layer_id` del Layer dello shard
- [x] Come su Geohub, i layer delle aree geografiche non compaiono nella home dell'app: il passo
      non tocca `config_home`, che su dev e UAT elenca i layer per id; i layer stanno in
      `MAP.layers`, come su Geohub, perché il click sull'area li apra
- [x] Se Geohub non risponde, il passo lo scrive nel log e l'import prosegue
- [x] Un'area che non si riesce a importare finisce nel log dell'import (`Log::channel('import')`,
      `storage/logs/import-AAAA-MM-GG.log`) con `layer_id` di Geohub, nome e messaggio d'errore;
      oggi il service la conta soltanto. A fine passo, nello stesso log, il riepilogo dei
      contatori (create, aggiornate, saltate, errori)
- [x] Test sul service per la sorgente delle aree geografiche e sulla creazione dei Layer senza
      doppioni
- [x] La pagina di conoscenza descrive come tornare indietro, senza un comando apposta: rimettere
      `aree_geografiche` in `upload` e ricaricare il file da `external_url` (la generazione
      sovrascrive il file caricato, stesso percorso in `StorageService::storeFeatureCollection()`),
      cancellare layer e Taxonomy Where con `source = geohub_conf_32_geographic_areas`, poi un
      import normale, che ricalcola luoghi e layer di tutte le tracce
- [ ] Verifica su dev e UAT con i dati veri: 39 Taxonomy Where con la loro `source`, un Layer per
      area, la FeatureCollection `aree_geografiche` in `generated`, il click su un'area
      geografica nella webapp apre i suoi percorsi, gli overlay hanno i loro colori

## Rischi

- **Job per ogni riga alla creazione dei layer.** Con gli eventi accesi, il collegamento Layer ↔
  Taxonomy Where fa partire `TaxonomyWhereablesObserver`, che riempie subito il layer, e
  `LayerableObserver` accoda per ogni percorso o POI la geometria del layer e la rigenerazione dei
  PBF (`LayerableObserver.php:63-70`): con le tracce già presenti (`--only=aree`, import orario)
  sono centinaia di job. Mitigato creando i layer con gli eventi spenti e lasciando il
  riempimento a `RecalculateSardegnaSentieriLayersJob` (oc:8827).
- **Le aree geografiche compaiono fra i comuni nel filtro della ricerca del sito.** Come su
  Geohub, le aree geografiche finiscono in `taxonomyWheres` di tracce (indice di ricerca) e POI
  (`pois.geojson`) insieme a regione, province, comuni, aree catastali e settori CAI. Il tema di
  wp-forestas divide quella lista per nome (`themes/forestas-child/inc/search.php:604-628`) e mette
  fra i comuni ogni nome che non riconosce. Si risolve nel sito con oc:9018 (filtri delle aree
  dalle FeatureCollection e da `layers`, scrum del 23/11; oc:9020 scartato), da portare su UAT
  insieme a questo ticket; su dev si vede finché oc:9018 non è chiuso.
- **Home vuota su un ambiente nuovo.** Se `config_home` è vuota, `AppConfigService` costruisce la
  home con tutti i layer dell'app, e i 39 delle aree geografiche ci finirebbero. Su dev e UAT
  `config_home` è compilata; un ambiente nuovo va configurato come gli altri.

## Out of scope

- Le aree catastali: restano create dalle action Nova, come oggi.
- La cancellazione delle aree sparite da Geohub e il salto delle scritture quando Geohub non è
  cambiato: annotati in `notes.md`, da valutare in un ticket separato che allinei aree catastali
  e geografiche.
- Modifiche al sito WordPress (`wp-forestas`): aree catastali e aree geografiche devono essere due
  filtri separati. Le modifiche necessarie si annotano in `notes.md`, e a fine lavoro si decide se
  aprire un ticket nuovo o aggiungerle a oc:9018.
- I filtri delle aree sul sito: oc:9018, con le FeatureCollection e `layers` come la webapp
  (scrum del 23/11). `taxonomyWheres` resta com'è, con tutti i luoghi, come su Geohub.
- Le altre FeatureCollection in modalità `upload` (`aree_forestali`, `aree_montane`,
  `aree_no_caccia`): restano come sono.
- Il filtro per layer dell'app (`app_filter_layers`): resta com'è.

## Moduli toccati

Repo **forestas**:

- `app/Services/Import/TaxonomyWhereLayersImportService.php` — parametrico sulla sorgente (oggi ha
  l'URL delle catastali cablato in `private const`)
- `app/Console/Commands/ImportSardegnaSentieriCommand.php` — passo delle aree geografiche,
  `--only=aree`
- `tests/Feature/Import/TaxonomyWhereLayersImportServiceTest.php` — test del service
- `docs/knowledge/` e `.claude/rules/import-sardegna-sentieri.md` — a fine lavoro

Repo **wm-package**: vedi `wm-package/docs/features/9001-aree-geografiche-nell-import/overview.md`.
