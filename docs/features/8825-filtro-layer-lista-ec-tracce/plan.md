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

---

# Seconda parte (27/10) — «Aree catastali» come multiselect

Riferimento: sezione «Seconda parte» di [overview.md](overview.md), approvata dalla dev il 27/10.
Stesso repo (**forestas**), stesso branch, stessa PR #32. Il wm-package non si modifica.

I task 5 e 6 non dipendono dal pacchetto e si possono fare subito. **Il task 7 parte solo dopo la
conferma di Alessandro Peci** sull'uso di `outl1ne/nova-multiselect-filter` (domanda aperta
nell'overview). Se dice di no, ci si ferma e si torna dalla dev: la strada alternativa è la
multiselect nativa di Nova, con un piano diverso.

## Task 5 — Test delle aree catastali nel formato nuovo (prima del codice)

**File:** `tests/Feature/EcTrack/EcTrackFiltersTest.php`

- `spuntate()` restituisce la lista degli id (`[$areaB->id, $areaG->id]`) invece di
  `[id => true]`; il nome diventa `scelte()`. I test che la usano non cambiano altro: stessi casi,
  stessi risultati attesi.
- «nessuna casella spuntata non restringe nulla» → «nessuna area scelta non restringe nulla», con
  valore `[]`.
- «il filtro aree catastali elenca le aree ordinate per nome»: le asserzioni sulle opzioni si
  adattano al JSON che il filtro del pacchetto manda a Nova (formato da leggere nel sorgente del
  pacchetto al task 7), sempre in ordine di nome; in più si controlla il `component` del filtro.

A questo punto i test delle aree falliscono: il filtro è ancora un `BooleanFilter`.

## Task 6 — Valore nel formato vecchio

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-il-formato-vecchio-non-viene-ignorato)

Nessun task di codice: `apply()` legge solo la lista di id. Un link salvato su dev o UAT con il
formato `{"12": true}` non va gestito (oc:8806 non è in produzione; rischio dichiarato ipotetico
nella challenge).

## Task 7 — Installazione del pacchetto (dopo la conferma di Alessandro)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-installazione-prima-della-conferma-e-lock-riallineato)

```bash
docker exec php-forestas composer require outl1ne/nova-multiselect-filter:^5.0 --no-update
docker exec php-forestas composer update outl1ne/nova-multiselect-filter
```

Controlli, prima di andare avanti:

- `git diff composer.lock`: cambia solo il pacchetto nuovo (più eventuali sue dipendenze);
  `laravel/nova` resta 5.7.6. Se si muove altro → stop e `git checkout composer.json composer.lock`.
- Leggere in `vendor/outl1ne/nova-multiselect-filter/src/` la classe `MultiselectFilter`: formato
  di `options()`, cosa finisce nel JSON per Nova, nome del componente, testi del componente e
  lingue di cui il pacchetto ha già la traduzione.

## Task 8 — `EcTrackCadastralAreaFilter` estende `MultiselectFilter`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-8-costruttore-con-il-segnaposto)

**File:** `app/Nova/Filters/EcTrackCadastralAreaFilter.php`

- `extends MultiselectFilter` al posto di `BooleanFilter`.
- `options()`: stesse aree (`properties->source` = `SOURCE_KEY`), stesso ordinamento naturale per
  nome, formato `id => nome`.
- `apply()`: il valore è una lista di id; la normalizzazione diventa
  `collect(is_array($value) ? $value : [])->map(fn ($id) => (string) (int) $id)->filter(...)->unique()`,
  scartando gli id non positivi. Lista vuota → query invariata. La query resta quella di oggi.
- `name()` invariato (`__('Cadastral areas')`), chiave invariata (la classe).
- Docblock di `apply()` aggiornato con il formato nuovo.

**Testi del componente:** se il pacchetto non ha l'italiano, o se i suoi testi non passano da
`lang/`, si aggiungono le traduzioni italiana e inglese (via `vendor:publish --tag=translations` o
`lang/it.json` / `lang/en.json`, secondo come il pacchetto le legge, visto al task 7).

Ora i test del task 5 passano.

## Task 9 — Pagina di conoscenza

**File:** `docs/knowledge/8806-filtro-ec-tracce.md`

- «Come funziona oggi»: il filtro delle aree è una multiselect (`outl1ne/nova-multiselect-filter`),
  il valore è una lista di id.
- «Perché così»: multiselect invece delle caselle, richiesta di Giuseppe Bonfanti allo scrum del
  27/10; pacchetto invece della multiselect nativa (Cmd/Ctrl+clic, nessuna ricerca) (oc:8825).
- «Come ci siamo arrivati»: le caselle (`BooleanFilter`) di oc:8806, superate, con il motivo.

Mostrare alla dev il prima e il dopo della pagina.

## Task 10 — Verifiche

```bash
# isolamento (regola in .claude/rules/test.md): .env.testing → forestas_testing, DB esistente
docker exec php-forestas grep '^DB_DATABASE' .env.testing
docker exec postgres-forestas psql -U forestas -lqt | cut -d'|' -f1 | grep -w forestas_testing
docker exec php-forestas vendor/bin/pest --filter=EcTrackFiltersTest
docker exec php-forestas vendor/bin/pest
docker exec php-forestas vendor/bin/phpstan analyse --debug
docker exec php-forestas vendor/bin/pint app/Nova/Filters/EcTrackCadastralAreaFilter.php tests/Feature/EcTrack/EcTrackFiltersTest.php
```

Prova a mano in Nova locale (http://localhost:8000, lista EC Tracce):

- la tendina «Aree Catastali» si apre, cerca per nome, mostra le scelte come etichette;
- una area, due aree (OR), nessuna area (tutte le tracce);
- «Cammini di Sardegna» + un'area: stesso risultato di ieri («Cammini di Sardegna» + Area B = 1);
- il reset dei filtri svuota la tendina; la pagina non va in errore in console.

Poi la dev mostra la multiselect in locale ad Alessandro Peci, prima di passare il ticket in
`testing`.

## Commit (solo su conferma della dev)

```
feat(oc:8825): il filtro delle aree catastali delle EC Tracce diventa una multiselect
```

Con `composer.json`, `composer.lock`, il filtro, i test, eventuali traduzioni, overview/plan/notes
e la pagina di conoscenza. Poi push sulla PR #32.
