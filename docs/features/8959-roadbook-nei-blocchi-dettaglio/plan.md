> Ticket: oc:8959

# Roadbook nei Blocchi Dettaglio — piano

**Goal:** l'import scrive il roadbook di Drupal in `properties.config_detail` (un blocco `info`, una
riga per tappa) e `forestas.roadbook` sparisce.

**Architettura:** la fonte legge i paragrafi propri di ogni lingua (`roadbook_stages`), accanto al
`roadbook` accumulato di oggi che resta solo per `compare-source`; `App\Support\RoadbookBlock` costruisce
il blocco e lo mette in `config_detail` al posto del blocco del roadbook precedente (riconosciuto dai
titoli «Tappa N»), lasciando gli altri; l'import lo chiama dopo l'`array_merge` delle properties.

**Stack:** Laravel 12, Pest su `forestas_testing`.

**Spec:** `docs/features/8959-roadbook-nei-blocchi-dettaglio/overview.md`

## Global Constraints

- Nessuna modifica a `wm-package/`; forma dei blocchi: `{box_type: "info", items: [{title, content}]}`
  (`ConfigDetailResolver::INFO_BOX_TYPE`).
- Titoli: it «Tappa N», en «Stage N», fr «Étape N», es «Etapa N», de «Etappe N».
- Identificatori in inglese, commenti e documentazione in italiano; niente commit durante l'esecuzione.

## Review Focus

- Traccia senza roadbook su Drupal che ne aveva uno: il blocco del roadbook sparisce, gli altri restano.
- Lingua senza testi propri (sentiero 1266): nessun titolo né contenuto in quella lingua.
- Lingue con numero di paragrafi diverso: tante tappe quante nella lingua che ne ha di più.
- `config_detail` con blocchi aggiunti in Nova: restano, nello stesso ordine.
- `config_detail` salvato in forma non valida (non array): l'import non si rompe.

---

### Task 1: paragrafi propri per lingua alla fonte

**Files:** Modify `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php`,
`app/Dto/Api/ApiTrackResponse.php`. Test: `tests/Feature/Import/` (test della fonte sui testi tradotti,
quello esistente su `accumulatedTexts`).

**Produces:** chiave `roadbook_stages` della fonte e `ApiTrackResponse::$roadbook_stages`
(`array<string, list<string>>|null`, lingua → paragrafi propri, stesse chiavi di lingua di
`languages()`).

- [ ] Test che falliscono: con italiano 2 paragrafi e inglese 2 propri, `roadbook_stages` = it 2, en 2
      (e `roadbook` resta accumulato: en 4); con l'inglese senza testi propri, `roadbook_stages` non ha
      `en`.
- [ ] Implementare `ownTexts(array $record, string $field): ?array` accanto ad `accumulatedTexts()`.
- [ ] Commit: `feat(oc:8959): paragrafi del roadbook per lingua dalla copia di Drupal`

### Task 2: blocco del roadbook

**Files:** Create `app/Support/RoadbookBlock.php`. Test: `tests/Unit/` o `tests/Feature/Import/RoadbookBlockTest.php`.

**Produces:** `RoadbookBlock::apply(mixed $configDetail, ?array $stages): array` (il nuovo
`config_detail`).

- [ ] Test che falliscono:
  - stages it 2 / en 2 → un blocco `info` con 2 righe, titoli «Tappa 1»/«Stage 1», contenuti per lingua;
  - it 3 / en 1 → 3 righe, la 2 e la 3 solo in italiano;
  - un blocco manuale già presente resta, il blocco del roadbook va in testa;
  - un blocco del roadbook già presente (righe «Tappa 1», «Tappa 2») si sostituisce nella stessa
    posizione;
  - stages vuoti o `null` → il blocco del roadbook si toglie, gli altri restano;
  - `config_detail` non array → trattato come vuoto.
- [ ] Implementare; il riconoscimento usa il titolo italiano `/^Tappa \d+$/` di tutte le righe.
- [ ] Commit: `feat(oc:8959): il roadbook come blocco info dei Blocchi Dettaglio`

### Task 3: import, dettaglio e Nova

**Files:** Modify `app/Services/Import/SardegnaSentieriImportService.php`,
`app/Dto/Import/ForestasTrackData.php`, `app/Models/EcTrack.php`, `app/Nova/EcTrack.php`,
`app/Support/ForestasTrackDetail.php`. Test: test dell'import delle tracce, `TrackDetailForestasTest`.

- [ ] Test che falliscono:
  - l'import di una traccia con roadbook scrive `config_detail` con il blocco e non scrive
    `forestas.roadbook`;
  - un secondo import con un roadbook cambiato sostituisce il blocco e tiene un blocco manuale;
  - il dettaglio della traccia non ha `forestas.roadbook` e, nel file (`EcTrackResource`), ha
    `config_detail`.
- [ ] Implementare: `ForestasTrackData` senza `roadbook`; nell'import, dopo l'`array_merge`,
      `config_detail` = `RoadbookBlock::apply($existingProperties['config_detail'] ?? null, $response->roadbook_stages)`;
      `roadbook` tolto dalle chiavi traducibili di `EcTrack`, dal campo Nova e da `ForestasTrackDetail`.
- [ ] Run: suite completa e PHPStan.
- [ ] Commit: `feat(oc:8959): l'import scrive il roadbook nei Blocchi Dettaglio`

### Task 4: verifica e documentazione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#deviazioni-dal-piano)

- [ ] Prova sul DB locale: reimport di alcune tracce (es. B 401, 1266) e controllo del file e di Nova.
- [ ] Documentazione: pagina del dettaglio della traccia, `docs/knowledge/copia-database-drupal.md`
      (lettura per lingua del roadbook), `notes.md` del pezzo; da fare al deploy: `--force` dove non c'è
      il reset.
- [ ] Commit: `docs(oc:8959): roadbook nei Blocchi Dettaglio`
