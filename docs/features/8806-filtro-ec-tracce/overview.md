> Ticket: oc:8806

# Filtro EC Tracce

## Cosa cambia

Nella lista Nova delle EC Tracce il filtro dei layer («Features By Layer Filter») oggi elenca
insieme le tipologie di percorso (Sentieri, Itinerari, Cammini di Sardegna, Sentieri accessibili)
e le aree catastali (Area B, C, D, E, F, G, T), perché su Forestas sono tutte Layer. Dopo questo
lavoro le due cose stanno in due filtri distinti, combinabili fra loro:

- **filtro dei layer**: stesso nome di oggi, ma elenca solo i layer che **non** sono legati a
  un'area catastale (in locale: le 4 tipologie);
- **nuovo filtro «Aree catastali»**: checkbox a scelta multipla con le `TaxonomyWhere` che hanno
  `properties->source = 'geohub_conf_32'` (in locale: le 7 aree), lette dal database e non scritte
  nel codice. Spuntate una o più aree, restano le tracce che ne attraversano geometricamente
  almeno una, anche solo per un tratto, secondo il dato salvato dal calcolo delle aree
  (`properties->taxonomy_where`).

Esempi: «Sentieri» nel filtro dei layer + «Area C (Sud-EST)» in «Aree catastali» → i sentieri
che passano per l'area C; «Itinerari» + Area B, G e T → gli itinerari che toccano almeno una delle
tre aree.

I due filtri compaiono dovunque Nova mostri la lista delle EC Tracce, quindi anche nei pannelli
delle tracce del dettaglio Ente («Track gestiti», «Track manutentore», …: `app/Nova/Ente.php:79-87`
usa `App\Nova\EcTrack`).

## Perché

Il cliente vuole selezionare le tracce combinando tipologia e area («i sentieri dell'area C»),
o più aree insieme («i cammini delle aree B, G e T»), cosa impossibile con un'unica tendina a
scelta singola. La soluzione è stata decisa allo scrum del 21/10 (12:55) da Giuseppe Bonfanti: due
filtri custom che vivono solo in Forestas, niente modifiche al wm-package. Nella review
dell'overview del 26/10 Alessandro Peci ha scelto la selezione multipla delle aree e il criterio
«passa per l'area» anche per un tratto breve, senza soglie di percentuale; le aree spuntate
valgono in OR.

Un limite è una scelta consapevole:

- **«Cammini di Sardegna» e «Sentieri accessibili» non si combinano ancora correttamente con
  l'area.** Il filtro dei layer restituisce per loro tutte le tracce (in locale «Cammini di
  Sardegna» + «Area B» dà 130 tracce invece di 1). È un problema già presente nel filtro dei layer,
  tracciato a parte in oc:8825: finché non è chiuso, l'esempio del cliente funziona per Sentieri e
  Itinerari, non per i cammini. Deciso il 26/10: oc:8806 va in develop appena approvato, ma in
  produzione esce insieme a oc:8825, e la risposta al cliente copre i due ticket insieme.

## Requisiti

- [ ] Il filtro dei layer sulle EC Tracce elenca solo i layer senza una `TaxonomyWhere` con
  sorgente `geohub_conf_32` (relazione `Layer::taxonomyWheres()`, pivot `taxonomy_whereables`): un
  layer legato a una `TaxonomyWhere` di altra sorgente resta una tipologia. Il comportamento di
  `apply()` resta quello di `FeaturesByLayerFilter`
- [ ] L'etichetta del filtro dei layer resta «Features By Layer Filter»: la sottoclasse ridefinisce
  `name()`, perché Nova ricava l'etichetta dal nome della classe
  (`vendor/laravel/nova/src/Filters/Filter.php:66`) e una sottoclasse con un altro nome la
  cambierebbe; il test delle opzioni controlla anche l'etichetta
- [ ] La chiave del filtro dei layer (`features_by_layer_<relazione>`) resta invariata
- [ ] `App\Nova\EcTrack::filters()` parte da `parent::filters()`, **sostituisce** solo l'elemento
  `FeaturesByLayerFilter` con il filtro di Forestas e aggiunge «Aree catastali» subito dopo: se il
  filtro venisse aggiunto invece che sostituito comparirebbero due tendine con la stessa chiave, e
  Nova applicherebbe quella del package, la prima che trova
  (`vendor/laravel/nova/src/Filters/FilterDecoder.php:43-45`)
- [ ] Nuovo filtro «Aree catastali» (chiave di traduzione inglese `Cadastral areas`, traduzione
  italiana in `lang/it.json`), checkbox a scelta multipla: estende
  `Laravel\Nova\Filters\BooleanFilter`, che passa ad `apply()` un array `id area => true/false`
- [ ] Con nessuna casella spuntata il filtro non restringe nulla: Nova chiama `apply()` anche con
  tutti i valori `false`, perché scarta solo gli array vuoti
  (`vendor/laravel/nova/src/Filters/FilterDecoder.php:52-54`)
- [ ] Le opzioni di «Aree catastali» sono le `TaxonomyWhere` con source `geohub_conf_32`, con il
  nome nella lingua corrente, lette a runtime e ordinate per nome (per id uscirebbero F, B, T, C,
  D, E, G)
- [ ] Spuntate più aree, valgono in OR: esce la traccia che passa per almeno una di esse
- [ ] Per ogni area spuntata, il filtro accetta le tracce con la chiave dell'area in
  `properties->taxonomy_where` (scritta dal calcolo geometrico; per `geohub_conf_32` la chiave è
  l'id della `TaxonomyWhere`) **e** con sorgente `geohub_conf_32` in quella voce: la sola chiave
  collide con gli `osm2cai_id` (in locale ZNUB2 ha `osm2cai_id = 81`, l'id di Area T)
- [ ] La sorgente della voce si legge in tutte e due le forme in cui il package l'ha salvata,
  `COALESCE(voce->>'_source', voce->>'source')`, come fa già
  `TaxonomyWhereDisplayService::sourceOf()` (`wm-package/src/Services/TaxonomyWhereDisplayService.php:101-103`):
  il package oggi scrive `_source` (oc:8588), i dati calcolati prima del 25/09 hanno `source`
  (oc:8487)
- [ ] I due filtri si combinano (AND), insieme a quelli già presenti (Ruolo, include/exclude by
  ids)
- [ ] Tutto il codice è in Forestas e il wm-package non si modifica: il filtro dei layer estende
  `FeaturesByLayerFilter`, «Aree catastali» estende `Laravel\Nova\Filters\BooleanFilter` (il
  package non ha un filtro sulle aree per le tracce)
- [ ] Feature test su `/nova-api/ec-tracks`: opzioni del filtro layer, opzioni di «Aree
  catastali», risultati del filtro con un'area, con più aree (OR) e con nessuna casella spuntata,
  combinazione dei due filtri, caso di una
  `TaxonomyWhere` osm2cai con `osm2cai_id` uguale all'id di un'area; `taxonomy_where` delle tracce
  prodotto da `GeometryComputationService::syncTaxonomyWhere()` con geometrie PostGIS reali (forma
  attuale, `_source`), più una traccia con la voce dell'area scritta nella forma di oc:8487
  (`source`), per coprire i dati non ancora ricalcolati

## Rischi

- **Il filtro per area è affidabile quanto il calcolo delle aree.** Legge
  `properties->taxonomy_where`, scritto da `GeometryComputationService::syncTaxonomyWhere()`, e
  non ricalcola la geometria a ogni ricerca. In locale la traccia 730 («Iscuvudè – Santu Jorzi –
  Attalai (G 606)») sta tutta nell'area G ma ha solo voci OSMFeatures e nessuna area catastale: filtrando
  per «Area G» non esce (166 tracce invece di 167). Le altre 6 aree coincidono con `ST_Intersects`.
  Mitigazione: nessuna correzione in questo ticket; una traccia con il dato vecchio torna corretta
  rilanciando il ricalcolo delle aree.
- **«Passa per l'area» non è «è assegnato all'area».** Il filtro usa l'intersezione geometrica,
  mentre il codice catastale di un sentiero porta la lettera della sua area (`Z-NU-G-126` → G). In
  locale, sulle 404 tracce con codice in quel formato, 8 attraversano anche un'altra area e 2 non
  risultano nella propria; le altre 394 coincidono. Mitigazione: si resta sull'intersezione
  geometrica, l'unico criterio valido anche per cammini e itinerari, che non hanno codice e
  attraversano più aree (23 tracce in più di un'area); confermata nella review del 26/10, anche
  quando il tratto nell'area è breve.
- **Il filtro dipende dal formato di `properties->taxonomy_where`, che è del package, ed è
  cambiato due volte.** oc:8487 l'ha portato a `{"name": {…}, "admin_level", "source"}`; oc:8588
  (commit `54a38103`, 25/09, già nel nostro puntatore di `wm-package`) l'ha riportato a
  `{<lingue>, _admin_level, _source}` (`GeometryComputationService.php:191-194`,
  `wm-package/docs/knowledge/localita-mostrate-taxonomy-where.md:7`). I dati locali sono ancora
  nella forma di oc:8487 perché l'ultimo calcolo è del 23/09: al primo ricalcolo (fine import,
  `wm:resync-taxonomy-where`, job della singola traccia) passano a `_source`, e per un periodo le
  due forme convivono. Un filtro che leggesse solo `source` restituirebbe liste vuote o parziali
  senza errori. Mitigazione: la sorgente si legge in tutte e due le forme (vedi Requisiti), e nei
  feature test `taxonomy_where` è prodotto da `syncTaxonomyWhere()` con geometrie PostGIS reali,
  così un prossimo cambio di formato fa fallire i test di Forestas.
- **Verificato solo sui dati locali.** Conteggi e collisioni di chiavi (`osm2cai_id` uguale all'id
  di un'area) possono essere diversi su UAT e produzione. Mitigazione: le opzioni sono lette a
  runtime e il filtro controlla la sorgente della voce, quindi non dipende dai numeri locali.
- **`taxonomy_where` ha una voce sola per chiave.** Il package usa come chiave
  `COALESCE(osmfeatures_id, osm2cai_id, id)`: se una traccia attraversa un'area e anche una
  `TaxonomyWhere` osm2cai con `osm2cai_id` uguale all'id dell'area, resta una voce sola, e se è
  quella osm2cai la traccia non esce filtrando per l'area. Inoltre il ricalcolo completo mantiene
  il vecchio `taxonomy_where` di una traccia che non interseca più nessuna `TaxonomyWhere`. In
  locale nessuna traccia è nel primo caso (ZNUB2 e Area T non si sovrappongono). Mitigazione: nessuna in questo ticket, la causa è nel
  package (follow-up in [notes.md](notes.md)).

## Out of scope

- La lista dei POI: lì il filtro dei layer resta com'è (il ticket nomina solo le tracce).
- Il filtro dei layer restituisce tutte le tracce per «Cammini di Sardegna» e «Sentieri
  accessibili» (in locale 779 su 779), perché `onLayer()` seleziona per aree e attività del layer e
  non per le tracce associate: è un problema già presente, tracciato in oc:8825. Lì va verificato
  anche se in produzione quei due layer hanno un'attività associata, che eviterebbe il problema.
- La risposta al cliente, che si scrive con oc:8825.
- Il nome del filtro dei layer, che resta quello di oggi.
- Il layer locale 7 con nomi `en`/`it` incoerenti, legato all'area G come il layer 1: è un dato
  locale, e il nuovo filtro lo esclude comunque.

## Moduli toccati

Tutti nel repo **forestas**:

- `app/Nova/Filters/` — nuovo filtro dei layer che estende `FeaturesByLayerFilter`, nuovo filtro
  «Aree catastali»
- `app/Nova/EcTrack.php` — `filters()` parte da `parent::filters()`, sostituisce il filtro dei
  layer del package con quello di Forestas e aggiunge «Aree catastali» subito dopo
- `app/Services/Import/TaxonomyWhereLayersImportService.php` — `SOURCE_KEY` diventa pubblica, per
  i due filtri
- `lang/it.json`, `lang/en.json` — traduzione di `Cadastral areas`
- `tests/Feature/` — feature test dei due filtri
