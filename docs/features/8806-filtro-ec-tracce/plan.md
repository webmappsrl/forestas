> Ticket: oc:8806

# Piano — Filtro EC Tracce

Riferimento: [overview.md](overview.md). Tutto il codice è nel repo **forestas**, branch
`feature/oc-8806-filtro-ec-tracce`; il wm-package non si modifica.

**Nessun commit automatico**: i commit indicati a fine piano sono istruzioni per la dev, da
eseguire solo dopo la sua conferma esplicita.

## Task 1 — Costante pubblica per la sorgente delle aree catastali

**File:** `app/Services/Import/TaxonomyWhereLayersImportService.php`

- `private const SOURCE_KEY = 'geohub_conf_32'` diventa `public const SOURCE_KEY`.
- I due filtri la usano invece di riscrivere la stringa: la sorgente delle aree resta definita in
  un punto solo, quello che la scrive.

## Task 2 — Filtro dei layer di Forestas

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2--filtro-dei-layer-di-forestas)

**File nuovo:** `app/Nova/Filters/EcTrackLayerFilter.php`

- Estende `Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter`; `apply()` e `key()` restano quelli
  del package (chiave `features_by_layer_<relazione>` invariata).
- `name()` restituisce `'Features By Layer Filter'`: senza, Nova ricaverebbe l'etichetta dal nome
  della nuova classe (`vendor/laravel/nova/src/Filters/Filter.php:64-67`).
- `options()` elenca i layer che **non** hanno una `TaxonomyWhere` con
  `properties->source = TaxonomyWhereLayersImportService::SOURCE_KEY`:

  ```php
  Layer::query()
      ->whereDoesntHave('taxonomyWheres', fn ($q) => $q->where('properties->source', TaxonomyWhereLayersImportService::SOURCE_KEY))
      ->get()
      ->pluck('id', 'name')
      ->toArray();
  ```

  Il criterio è più stretto di «senza `TaxonomyWhere` associata» dell'overview: un layer legato a
  una `TaxonomyWhere` di altra sorgente (osm2cai, OSMFeatures) resta una tipologia e deve restare
  nel filtro. È uno dei cleanup della review del 21/10.

## Task 3 — Filtro «Aree catastali»

**File nuovo:** `app/Nova/Filters/EcTrackCadastralAreaFilter.php`

- Estende `Laravel\Nova\Filters\BooleanFilter` (checkbox).
- `name()`: `__('Cadastral areas')`.
- `options()`: le `TaxonomyWhere` con `properties->source = SOURCE_KEY`, nella forma
  `nome nella lingua corrente => id`, ordinate per nome.
- `apply($request, $query, $value)`:
  1. dagli elementi di `$value` tiene solo gli id con valore `true`; se non ne resta nessuno
     restituisce `$query` invariata (Nova chiama `apply()` anche con tutti i valori `false`,
     `FilterDecoder.php:52-54`);
  2. aggiunge un gruppo `where(fn ($q) => ...)` con un `orWhereRaw` per area spuntata: la traccia
     passa se in `properties->'taxonomy_where'` c'è la chiave dell'area **e** quella voce ha
     sorgente `SOURCE_KEY`:

     ```sql
     jsonb_exists(properties->'taxonomy_where', ?)
     AND COALESCE(properties->'taxonomy_where'->?->>'_source',
                  properties->'taxonomy_where'->?->>'source') = ?
     ```

     `jsonb_exists()` al posto dell'operatore `?`, che PDO scambierebbe per un segnaposto. La
     sorgente si legge nelle due forme del package (`_source` di oc:8588, `source` di oc:8487),
     come `TaxonomyWhereDisplayService::sourceOf()`.
  3. gli id ricevuti si convertono in `int` prima di usarli come chiave: arrivano dalla query
     string del client.

## Task 4 — Registrazione dei filtri sulla risorsa

**File:** `app/Nova/EcTrack.php`, metodo `filters()`

- Parte da `parent::filters($request)`; scorre l'elenco e, al posto dell'elemento che è
  esattamente `FeaturesByLayerFilter` (`get_class() ===`, non `instanceof`), mette
  `new EcTrackLayerFilter($relazione)` seguito da `new EcTrackCadastralAreaFilter`. La relazione è
  `$this->resource->getLayerRelationName()`, come nel package.
- Il resto dell'elenco resta in ordine; `EcTrackRuoloFilter` resta in coda come oggi.
- Se il package non restituisce filtri (richieste fuori da `/nova-api/`), non si aggiunge nulla:
  il filtro per area compare solo dove compare quello dei layer.

## Task 5 — Traduzioni

**File:** `lang/en.json`, `lang/it.json`

- `"Cadastral areas": "Cadastral areas"` in inglese, `"Cadastral areas": "Aree Catastali"` in
  italiano, con la stessa forma di `lang/it.json:38`.

## Task 6 — Feature test

**File nuovo:** `tests/Feature/EcTrack/EcTrackFiltersTest.php` (Pest, `RefreshDatabase`)

Preparazione comune:

- ruoli con `RolesAndPermissionsService::seedDatabase()`, richieste come utente `Administrator`;
- aree catastali come `TaxonomyWhere` con `properties.source = geohub_conf_32` e geometrie PostGIS
  reali (poligoni scritti con `ST_GeomFromText`), un layer per area legato con
  `taxonomyWheres()->attach()`, più due layer «tipologia» senza aree;
- tracce con geometria PostGIS, poi `GeometryComputationService::make()->syncTaxonomyWhere()` per
  produrre `taxonomy_where` nella forma attuale (`_source`);
- filtri passati a `/nova-api/ec-tracks?filters=<base64 del JSON>`.

Casi:

1. Il filtro dei layer elenca solo i layer tipologia, ha etichetta «Features By Layer Filter» e
   chiave `features_by_layer_<relazione>` (letti da `/nova-api/ec-tracks/filters`).
2. Un layer legato a una `TaxonomyWhere` di altra sorgente resta nel filtro dei layer.
3. «Aree catastali» elenca le aree ordinate per nome.
4. Un'area spuntata → le tracce che la attraversano, anche per un tratto breve.
5. Due aree spuntate → le tracce che ne attraversano almeno una (OR).
6. Nessuna casella spuntata (tutti `false`) → nessuna restrizione.
7. Layer tipologia + area → combinazione in AND.
8. Una `TaxonomyWhere` osm2cai con `osm2cai_id` uguale all'id di un'area: una traccia che attraversa
   solo quella non esce filtrando per l'area.
9. Una traccia con la voce dell'area nella forma di oc:8487 (`source`, scritta a mano) esce.

**Prima di lanciarli**, verificare l'isolamento come da `.claude/rules/test.md`: `.env.testing`
esiste e punta a `forestas_testing`, e il database `forestas_testing` esiste. Se non è verificabile,
non si lanciano e si chiede alla dev.

```bash
docker exec php-forestas vendor/bin/pest --filter=EcTrackFiltersTest
```

## Task 7 — Verifiche finali

```bash
docker exec php-forestas vendor/bin/pest            # dopo il controllo di isolamento
docker exec php-forestas vendor/bin/phpstan analyse
docker exec php-forestas composer format
```

Verifica manuale in Nova sui dati locali: lista EC Tracce, «Sentieri» + «Area C», poi Area B + G + T.

## Commit (solo su conferma della dev)

```
feat(oc:8806): filtri dei layer e delle aree catastali sulle EC Tracce
```

Con `docs/features/8806-filtro-ec-tracce/` (overview, plan, notes) e l'eventuale aggiornamento di
`docs/knowledge/` e del `CLAUDE.md`. Poi push e PR #29 da draft a pronta, verso `develop`.
