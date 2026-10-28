> Ticket: oc:8875

# Piano — Verifica dei filtri della lista EC Tracce e rimozione del filtro App

Riferimento: [overview.md](overview.md) (commit `277eae0`), con le decisioni della dev del 27/10 e
l'esito della verifica nel browser. Repo **forestas**, branch
`feature/oc-8875-verifica-filtri-lista-ec-tracce`, PR #33 verso `develop`. Il wm-package non si
modifica. Nessun commit senza conferma esplicita della dev.

La verifica nel browser è già fatta (sezione «Esito» dell'overview): il piano copre il codice, i
test e le prove da conservare.

## Task 1 — Test (prima del codice)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1--chiave-del-filtro-app)

**File:** `tests/Feature/EcTrack/EcTrackFiltersTest.php`

Nuovi test, con gli helper già presenti (`amministratore()`, `traccia()`, `idFiltrati()`,
`definizioneFiltro()`):

- **Filtri tolti dal menu**: in `GET /nova-api/ec-tracks/filters` nessun filtro ha `class`
  `resource:apps:app`, né una chiave che inizia con `features_include_ids_` o
  `features_exclude_ids_`; restano `features_by_layer_ecTracks`,
  `App\Nova\Filters\EcTrackCadastralAreaFilter`, `App\Nova\Filters\EcTrackRuoloFilter` e
  `Select:stato_validazione`. Si controllano le chiavi, non i nomi.
- **Ruolo**: una traccia con un ente `gestore`, una con un ente `rilevatore`, una senza enti; il
  filtro `App\Nova\Filters\EcTrackRuoloFilter` = `gestore` restituisce solo la prima. Le righe di
  `enteables` si scrivono come l'import (`SardegnaSentieriImportService.php:906-911`):
  `enteable_type` = `App\Models\EcTrack::class`. Serve un ente: si crea con il suo model o factory,
  da leggere all'inizio del task.
- **Stato validazione**: tre tracce con `percorribile`, `in_revisione_validazione` e `null`; il
  filtro `Select:stato_validazione` = `percorribile` restituisce solo la prima.
- **Titoli tradotti**: con `app()->setLocale('it')` e poi `'en'`, il `name` dei filtri in
  `/nova-api/ec-tracks/filters` è: Layer «Layer» / «Layer», Ruolo «Ruolo» / «Role», Stato
  validazione «Stato validazione» / «Validation status». La lingua va impostata nel test: la CI
  gira con `APP_LOCALE=en`.
- **Test esistente da aggiornare**: «il filtro dei layer elenca solo le tipologie e conserva
  etichetta e chiave» (riga 112) controlla `'Features By Layer Filter'`: diventa il titolo tradotto,
  la chiave `features_by_layer_ecTracks` resta.

A questo punto falliscono i test sui filtri tolti, quelli sui titoli e quello aggiornato; Ruolo e
Stato validazione passano già (fotografano il comportamento attuale, verificato nel browser).

## Task 2 — Filtro App non filtrabile

**File:** `app/Nova/EcTrack.php`

In `fields()`, sui campi di `parent::fields()` già filtrati in `$regularFields`, il `BelongsTo`
con `attribute === 'app'` riceve `->withoutFilterable()` (trait `Filterable` di Nova,
`vendor/laravel/nova/src/Fields/Filterable.php:45`). Commento di una riga con il motivo (una sola
app, oc:8875). Colonna, dettaglio e form non cambiano.

## Task 3 — Include/Exclude per id fuori dal menu

**File:** `app/Nova/EcTrack.php`

In `filters()`, nel ciclo sui filtri del package che già sostituisce `FeaturesByLayerFilter`, si
scartano le istanze di `FeaturesIncludeByIds` e `FeaturesExcludeByIds`. Il commento esistente si
estende con il motivo: nessuno li usa più dalla riscrittura del campo dei layer (`ee8a5551`), e un
operatore non può usarli.

## Task 4 — Titoli

**File:** `app/Nova/Filters/EcTrackLayerFilter.php`, `app/Nova/Filters/EcTrackRuoloFilter.php`,
`app/Nova/EcTrack.php`, `lang/en.json`, `lang/it.json`

- `EcTrackLayerFilter::name()` → `__('Layer')`; docblock aggiornato (non è più l'etichetta del
  package).
- `EcTrackRuoloFilter::name()` → `__('Role')`.
- `Select::make(__('Validation status'), 'stato_validazione')`: cambia anche colonna, dettaglio e
  form, in italiano il testo resta uguale.
- Voci: `Layer` → «Layer» / «Layer», `Role` → «Role» / «Ruolo», `Validation status` →
  «Validation status» / «Stato validazione». Prima di aggiungerle, controllare se `Layer` o `Role`
  esistono già in `lang/*.json` o nel package con un'altra traduzione.

Ora i test del task 1 passano.

## Task 5 — Verifiche automatiche

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--composer-format-sullintero-repo)

Prima dei test, isolamento del DB secondo `.claude/rules/test.md`: `.env.testing` punta a
`forestas_testing` e il database esiste. Se non è verificabile, ci si ferma e si chiede alla dev.

```bash
docker exec php-forestas vendor/bin/pest tests/Feature/EcTrack/EcTrackFiltersTest.php
docker exec php-forestas vendor/bin/pest
docker exec php-forestas vendor/bin/phpstan analyse --memory-limit=1G
docker exec php-forestas composer format
```

## Task 6 — Controllo nel browser dopo le modifiche

Nova locale, lista EC Tracce: il menu ha quattro filtri (Layer, Aree catastali, Ruolo, Stato
validazione) con i titoli nuovi; App, Include e Exclude non ci sono; colonna e campo App invariati.

## Task 7 — Prove e note

**File:** `docs/features/8875-verifica-filtri-lista-ec-tracce/notes.md`

- `notes.md`: le query usate per la tabella dell'overview, le deviazioni dal piano, i follow-up.

## Task 8 — Pagina di conoscenza

**File:** `docs/knowledge/8806-filtro-ec-tracce.md`

«Come funziona oggi»: sulla lista EC Tracce non ci sono il filtro App né Include/Exclude, e
perché; titoli tradotti. «Perché così»: le due rimozioni con il ticket. Niente di nuovo nell'indice
del `CLAUDE.md`: la riga c'è già.

## Commit (solo su conferma della dev)

```
fix(oc:8875): lista EC Tracce senza filtri App e Include/Exclude, titoli dei filtri tradotti
```

Stessa PR #33, verso `develop`.
