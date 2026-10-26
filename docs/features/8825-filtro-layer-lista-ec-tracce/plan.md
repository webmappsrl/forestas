> Ticket: oc:8825

# Piano — Filtro layer con le tracce associate

Riferimento: [overview.md](overview.md), approvata dalla dev e rivista da Alessandro Peci il
26/10. Tutto nel repo **forestas**, branch `feature/oc-8825-filtro-layer-lista-ec-tracce`; il
wm-package non si modifica.

**Nessun commit automatico**: i commit indicati a fine piano sono istruzioni per la dev, da
eseguire solo dopo la sua conferma esplicita.

## Task 1 — `apply()` di `EcTrackLayerFilter`

**File:** `app/Nova/Filters/EcTrackLayerFilter.php`

Ridefinisce `apply(NovaRequest $request, Builder $query, mixed $value): Builder`:

1. `$layer = Layer::find($value)`; se non esiste → `$query` invariata (oggi `onLayer(null)`
   andrebbe in errore di tipo).
2. Se `! $layer->isAutoTrackMode()` → tracce associate.
3. Se il layer ha un filtro automatico → `parent::apply()` (`onLayer()`). «Filtro automatico» è la
   stessa condizione di `LayerService::hasValidAutoModeFilter()` (privata nel package):
   `properties['taxonomy_where']` non vuoto oppure almeno una `taxonomyActivities`.
4. Se il layer ha tracce associate (`$layer->ecTracks()->exists()`, senza caricare la relazione)
   → tracce associate.
5. Altrimenti → `parent::apply()`.

«Tracce associate» si esprime sulla query di Nova, così si combina in AND con gli altri filtri e
con la paginazione:

```php
$query->whereHas('layers', fn ($q) => $q->where('layers.id', $layer->id));
```

Docblock della classe aggiornato: `apply()` non è più quello del package; il commento spiega la
regola e perché i layer con filtro automatico restano su `onLayer()` (la tabella `layerables` per
loro è una copia aggiornata da un job in differita).

## Task 2 — Feature test

**File:** `tests/Feature/EcTrack/EcTrackFiltersTest.php` (si aggiunge ai casi di oc:8806, stessi
helper)

Casi nuovi:

1. Layer in automatico **senza** attività, con 2 tracce associate su 4 → solo le 2 associate
   (il bug di oc:8825).
2. Layer in automatico **con** attività e con associata una traccia che non ha l'attività →
   le tracce dell'attività, non l'associata (passo 3: `onLayer()`).
3. Layer in modalità **manuale** con attività e 1 traccia associata → solo l'associata (passo 2).
4. Layer in automatico senza attività né tracce associate → tutte le tracce (passo 5, invariato).
5. Layer senza attività con tracce associate + «Aree catastali» → le associate che passano per
   l'area.

La modalità manuale si imposta con `Layer::setTrackMode('manual')` o scrivendo
`configuration['track_mode']`, a seconda di cosa evita gli observer; i layer si creano con
`createQuietly` come nei test esistenti.

Prima di lanciarli, isolamento come da `.claude/rules/test.md` (`.env.testing` e
`forestas_testing` già presenti dal 26/10).

```bash
docker exec php-forestas vendor/bin/pest tests/Feature/EcTrack/EcTrackFiltersTest.php
```

## Task 3 — Pagina di conoscenza

**File:** `docs/knowledge/8806-filtro-ec-tracce.md`

- In «Come funziona oggi» il filtro dei layer non usa più solo `apply()` del package: si descrive
  la regola a quattro passi.
- Il limite «Cammini di Sardegna e Sentieri accessibili restituiscono tutte le tracce» passa in
  «Come ci siamo arrivati», con il motivo (oc:8825).
- In «Perché così»: correggere il filtro e non la configurazione dei layer; i layer con filtro
  automatico restano su `onLayer()` (oc:8825).

Mostrare alla dev il prima e il dopo della pagina.

## Task 4 — Verifiche finali

```bash
docker exec php-forestas vendor/bin/pest            # dopo il controllo di isolamento
docker exec php-forestas vendor/bin/phpstan analyse --debug   # in parallelo fallisce sulla cache
docker exec php-forestas vendor/bin/pint app/Nova/Filters/EcTrackLayerFilter.php tests/Feature/EcTrack/EcTrackFiltersTest.php
```

Verifica in sola lettura sui dati locali, applicando il filtro a una query: Sentieri 614,
Itinerari 165, Cammini di Sardegna 50, Sentieri accessibili 2; «Cammini di Sardegna» + un'area.

## Commit (solo su conferma della dev)

```
fix(oc:8825): il filtro dei layer delle EC Tracce usa le tracce associate al layer
```

Con `docs/features/8825-filtro-layer-lista-ec-tracce/` (plan, notes) e la pagina di conoscenza.
Poi push e PR #32 da draft a pronta, verso `develop`.
