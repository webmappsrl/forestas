> Ticket: oc:8988

# Piano — Filtro Nova generico per termine di tassonomia (forestas)

Fonte dei requisiti: [overview.md](overview.md). Dipende dal piano del package
(`wm-package/docs/features/8988-filtro-nova-generico-per-termine-di-tassonomia/plan.md`): i Task
2-6 del package devono esistere sul branch del submodule prima del Task 2 qui.

**Commit:** istruzioni per la dev, da eseguire solo su sua richiesta. Scope `oc:8988`. Una sola PR
(forestas#65); il puntatore del submodule va in un commit suo, nella stessa PR.

**Test:** prima di lanciare la suite verificare l'isolamento come da `.claude/rules/test.md`
(`.env.testing` su `forestas_testing`, database esistente). Se non è verificabile, non si lancia.

---

## Task 1 — `statuses()` sul modello

File:
- `app/Models/EcTrack.php`
- `app/Nova/EcTrack.php` (`validationStatusField()`, riga 274)
- `tests/Feature/Import/ValidationStatusEventsTest.php` (riga 64)

Passi:
1. Spostare `EcTrackValidationStatusFilter::statuses()` (`app/Nova/Filters/EcTrackValidationStatusFilter.php:44`)
   sul modello come `public static function validationStatusTerms(): Collection`, invariato nel
   comportamento (solo i termini dichiarati dal comando).
2. `validationStatusField()` (riga 276) e `ValidationStatusEventsTest.php:64` chiamano il metodo
   nuovo.
3. Lanciare `ValidationStatusEventsTest`.

## Task 2 — Menu dei filtri della Resource EcTrack

File: `app/Nova/EcTrack.php` (`filters()`, righe 360-385)

Passi:
1. Nel ciclo su `parent::filters()`: `FeaturesByLayerFilter`, `FeaturesIncludeByIds` e
   `FeaturesExcludeByIds` si saltano tutti con `continue`, senza sostituti.
2. Restituire, dopo i filtri del package rimasti:
   ```php
   new TaxonomyTermFilter(EcTrackModel::VALIDATION_STATUS_TAXONOMY),
   ...$walkability,   // solo se il dominio Catasto è acceso, vedi sotto
   new TaxonomyTermFilter('route-type', multiple: true),
   new TaxonomyTermFilter('inclusivity-disabilities', multiple: true),
   new TaxonomyTermFilter('inclusivity-difficulty', multiple: true),
   new EcTrackCadastralAreaFilter,
   ```
   con
   ```php
   $walkability = config('wm-package.features.trail_registry.enabled')
       ? [new TaxonomyTermFilter(config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status'))]
       : [];
   ```
3. Togliere gli `use` di `EcTrackLayerFilter`, `EcTrackRuoloFilter`, `EcTrackValidationStatusFilter`.
4. Verificare che le relazioni `MorphToMany` della Resource Ente (`app/Nova/Ente.php:124-132`)
   mostrino lo stesso menu: ereditano `filters()` della Resource EcTrack, quindi non serve codice.

## Task 3 — Aree catastali su `ChoiceFilter`

File:
- `app/Nova/Filters/EcTrackCadastralAreaFilter.php`
- `composer.json`, `composer.lock`

Passi:
1. `extends ChoiceFilter` invece di `MultiselectFilter`; nel costruttore `->multiple()` e
   `->searchPlaceholder(__('Select cadastral area'))`.
2. `options()` diventa `choiceOptions()`: stessa query e stesso ordinamento, formato
   `[['value' => $area->id, 'label' => $area->name], …]` (la conversione in stringa la fa
   `ChoiceFilter`).
3. `apply()` diventa `applyChoice()` con lo stesso corpo (cast a int, scarto dei `<= 0`, OR su
   `properties->taxonomy_where`).
4. Nessuna `key()`: resta il nome della classe.
5. `composer remove outl1ne/nova-multiselect-filter` nel container; `grep -rn "NovaMultiselectFilter"`
   su `app/` e `tests/` deve restituire zero righe.

## Task 4 — Eliminare i filtri vecchi

File eliminati:
- `app/Nova/Filters/EcTrackLayerFilter.php`
- `app/Nova/Filters/EcTrackRuoloFilter.php`
- `app/Nova/Filters/EcTrackValidationStatusFilter.php`

Passi:
1. `grep -rn` dei tre nomi su `app/`, `tests/`, `docs/knowledge/`: oltre a quanto toccato nei task,
   devono restare solo le pagine di conoscenza del Task 7.
2. Chiavi di traduzione in `lang/it.json` e `lang/en.json`: per ogni chiave usata solo dai tre file
   (per esempio `"Role"`, `"Layer"`, `"Validation status"`) cercare altri usi in `app/`,
   `resources/` e nel package prima di toglierla. `"Validation status"` e `"Layer"` sono
   probabilmente usate altrove: si tolgono solo se il grep non trova nulla.

## Task 5 — Test

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--titoli-dei-filtri-in-inglese)

File: `tests/Feature/EcTrack/EcTrackFiltersTest.php`

Passi:
1. Il `beforeEach` lancia già `sardegnasentieri:configure-taxonomy-terms` (riga 34): i
   termini esistono nei test.
2. Menu (oggi «il menu dei filtri ha solo Layer, Aree catastali, Ruolo e Stato validazione»): elenco
   esatto delle chiavi — chiave di `TaxonomyTermFilter` per `validation-status`, `walkability-status`,
   `route-type`, `inclusivity-disabilities`, `inclusivity-difficulty`, più
   `EcTrackCadastralAreaFilter::class`. Un caso con `trail_registry.enabled` a false: niente
   percorribilità.
3. Definizione del filtro aree (test a riga 138): componente `choice-filter`, opzioni con `value` in
   stringa, ordine per nome, placeholder.
4. Test delle aree che usano `LAYER_FILTER_KEY` (righe 190 e 303): rifatti con il filtro
   `route-type`. I test dei layer (righe 118-133, 249-300) si tolgono insieme al filtro; i layer come
   contenuto restano coperti dai loro test.
5. Nuovi:
   - Stato validazione: una traccia con `validation-status-validated` filtrata;
   - Percorribilità: una traccia con un termine di `walkability-status` filtrata;
   - Tipo percorso multiple: Sentieri + Cammini in OR;
   - tassonomico AND aree catastali;
   - ricostruzione dei quattro layer di tipologia (Sentieri, Itinerari, Cammini con `route-type`;
     Sentieri Accessibili con `inclusivity-difficulty` = AT, AE, AEE);
   - link salvato delle aree con id interi `[12, 13]`: filtra, e `currentValue` è in stringa;
   - titoli tradotti in it e en;
   - menu dei filtri nella relazione `ecTracksGestore` della Resource Ente.
6. Togliere i test di Ruolo e Layer e i loro helper rimasti senza usi (`LAYER_FILTER_KEY`, …).

## Task 6 — Puntatore del submodule

Dopo il merge della PR del package (wm-package#335), aggiornare il puntatore di `wm-package` al
commit mergiato.

Commit (solo il puntatore): `chore(oc:8988): puntatore di wm-package con ChoiceFilter e TaxonomyTermFilter`

## Task 7 — Documentazione

File:
- `docs/knowledge/8806-filtro-ec-tracce.md`: il filtro per layer esce da «Come funziona oggi» e
  passa in «Come ci siamo arrivati» con il motivo (risultati sbagliati e sovrapposizioni, oc:8827);
  «Come funziona oggi» descrive il menu nuovo, la percorribilità legata al dominio Catasto e le aree
  sul selettore di `Choice`
- `docs/knowledge/classificazioni-e-inclusivita.md` (riga 165): `EcTrackValidationStatusFilter`
  sostituito da `TaxonomyTermFilter` e da `EcTrack::validationStatusTerms()`

## Task 8 — Prova a mano

In locale, insieme al Task 8 del piano del package:
1. `sardegnasentieri:configure-taxonomy-terms --dry-run`, poi senza `--fresh`.
2. Termini assegnati a una decina di tracce dalla edit Nova.
3. Casi: due termini dello stesso filtro, termini di due filtri, traccia senza termini, aree
   catastali insieme a un filtro tassonomico, ultima chip tolta (contatore a zero), «Reset filters».
4. Detail view di un Ente, relazione «Track gestiti»: stesso menu.

## Chiusura

- `vendor/bin/phpstan analyse` e `composer format` (controllare `git status`).
- `notes.md` con le deviazioni, se ce ne sono.

Commit di lavoro (prima del puntatore):
`feat(oc:8988): filtri di EcTrack sui termini delle tassonomie`
