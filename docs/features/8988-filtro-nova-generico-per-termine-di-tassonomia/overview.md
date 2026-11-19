> Ticket: oc:8988

# Filtro Nova generico per termine di tassonomia — parte Forestas

## Cosa cambia

Il menu dei filtri dell'index della Resource EcTrack (`App\Nova\EcTrack::filters()`) sostituisce i
filtri per layer, ruolo e stato di validazione con il `TaxonomyTermFilter` del package, richiamato
una volta per tassonomia. Il filtro delle aree catastali passa dal componente
`outl1ne/nova-multiselect-filter` al filtro di base del package (`ChoiceFilter`), lo stesso selettore
del campo `Choice`:

| Filtro | Prima | Dopo |
|---|---|---|
| Layer | `EcTrackLayerFilter` | tolto, insieme a `FeaturesByLayerFilter` del package |
| Ruolo | `EcTrackRuoloFilter` | tolto |
| Stato validazione | `EcTrackValidationStatusFilter` (identifier cablato) | `TaxonomyTermFilter('validation-status')`, single |
| Percorribilità | — | `TaxonomyTermFilter(<walkability_taxonomy>)`, single, solo con il dominio Catasto acceso |
| Tipo percorso | — | `TaxonomyTermFilter('route-type', multiple: true)` |
| Disabilità accolte | — | `TaxonomyTermFilter('inclusivity-disabilities', multiple: true)` |
| Difficoltà di accessibilità | — | `TaxonomyTermFilter('inclusivity-difficulty', multiple: true)` |
| Aree catastali | `EcTrackCadastralAreaFilter` su `MultiselectFilter` | `EcTrackCadastralAreaFilter` su `ChoiceFilter`, stessa query |

## Perché

Il filtro per layer è un meccanismo indiretto: i layer si costruiscono dalle tassonomie, e filtrare
per layer ha già prodotto risultati sbagliati e sovrapposizioni (oc:8827). Il cliente vuole che
l'index corrisponda a ciò che è scritto sul contenuto, con le stesse icone della compilazione.
Allo scrum del 16/11 Giuseppe: «non voglio più mettere un filtro per layer. Ok, c'abbiamo ora le
tassonomie, usiamo quelle»; il «Ruolo» si elimina del tutto perché al cliente non serve (oc:8827,
risposta del 02/11: «Continuo a non capire l'utilità del filtro "Ruoli"»); lo stato di validazione
resta, rifatto con il nuovo approccio.

Il cliente in oc:8827 (28/10) ha chiesto due filtri separati, «Validazione catastale» e
«Percorribilità». Con oc:8989 la percorribilità è una famiglia a sé, `walkability-status`
(`wm-package/src/TrailRegistry/Services/WalkabilityService.php:58`), e la «Validazione catastale» è
lo «Stato validazione»: le due richieste diventano due righe di `filters()`. Allo scrum del 17/11
Giuseppe: la percorribilità «lo mettiamo», e «tutti e due i filtri mettili single choice […] poi se
c'è da cambiare lo cambiamo». La percorribilità è del dominio Catasto del package, che «si accende
solo se ha il flag del dominio catasto acceso», e i suoi identifier «sono completamente
customizzabili»: il filtro segue la stessa config.

Le aree catastali leggono dalla tabella delle zone e non da `taxonomy_terms`, quindi non possono
usare `TaxonomyTermFilter`. Alla call del 16/11 delle 10:25 Giuseppe, sul componente outline usato
nei filtri: «smettiamo di usarlo, cioè là dove c'è da fare una select si usa il choice field». Per
questo il package offre un filtro di base, `ChoiceFilter`, con le opzioni fornite da chi lo estende,
e le aree catastali lo usano (review del 17/11).

oc:8827 resta il ticket del cliente: serve per rispondere e per la release.

## Requisiti

- [ ] `filters()` restituisce, oltre ai filtri del package non toccati: Stato validazione (single),
      Percorribilità (single), Tipo percorso (multiple), Disabilità accolte (multiple), Difficoltà
      di accessibilità (multiple), Aree catastali
- [ ] Filtro «Percorribilità»:
  - [ ] l'identifier della tassonomia si legge da
        `config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status')`,
        come in tutto il dominio (`WalkabilityService.php:58` e le altre letture), mai scritto fisso:
        uno shard che cambia la config cambia anche il filtro
  - [ ] il filtro c'è solo se `wm-package.features.trail_registry.enabled` è vero: l'import scrive
        la percorribilità solo a dominio acceso
        (`app/Services/Import/SardegnaSentieriImportService.php:1171`), e a dominio spento il filtro
        non avrebbe opzioni. `WM_TRAIL_REGISTRY_ENABLED` vale `true` in `.env`, `.env-example` e
        `.env-deploy`; il valore reale su dev e UAT va controllato sui server prima della prova
- [ ] Filtro «Stato validazione» sull'identifier di `App\Models\EcTrack::VALIDATION_STATUS_TAXONOMY`,
      non su una stringa ripetuta
- [ ] `EcTrackCadastralAreaFilter` oggi nasce dentro il ramo che sostituisce `FeaturesByLayerFilter`
      (`app/Nova/EcTrack.php:367-371`): togliendo quel ramo, il filtro delle aree va aggiunto a parte
- [ ] Il filtro «Stato validazione» propone **tutti** i termini della famiglia `validation-status`,
      compresi quelli ritirati da oc:8989 (`validation-status-walkable`,
      `validation-status-not-walkable`, `ConfigureTaxonomyTermsCommand::RETIRED_TERMS`) finché
      esistono: servono a trovare le tracce che li hanno ancora. Il campo «Stato validazione» del
      form continua invece a proporre solo i termini dichiarati (`statuses()`). La differenza è
      voluta: non va riportato il taglio dei ritirati nel filtro
- [ ] Con i filtri tassonomici si ricostruisce il contenuto di ognuno dei quattro layer di
      tipologia (`docs/knowledge/8806-filtro-ec-tracce.md`): Sentieri, Itinerari e Cammini con
      Tipo percorso; Sentieri Accessibili con Difficoltà di accessibilità = AT, AE, AEE
- [ ] Filtri diversi si combinano in AND; i valori dello stesso filtro in OR
- [ ] Aree catastali su `ChoiceFilter` del package, multiple:
  - [ ] `EcTrackCadastralAreaFilter` estende `ChoiceFilter` invece di `MultiselectFilter`;
        `apply()` invariato (array di id, OR su `properties->taxonomy_where`)
  - [ ] `options()` nel formato del `Choice`, `{value: id, label: nome}`, nello stesso ordine di
        oggi (nome, ordinamento naturale); gli id restano interi, li converte in stringa
        `ChoiceFilter`, e `apply()` fa già il cast a int
  - [ ] il campo di ricerca ha lo stesso segnaposto degli altri filtri del menu; la chiave
        «Select cadastral area» del vecchio filtro si toglie da `lang/it.json` e `lang/en.json`
  - [ ] `key()` resta il nome della classe e il valore resta un array di id: i link salvati con le
        aree continuano a filtrare
- [ ] `outl1ne/nova-multiselect-filter` tolto da `composer.json` (e `composer.lock`) di forestas: dopo
      la migrazione delle aree non ha più usi
- [ ] Il filtro per layer non compare più: né `EcTrackLayerFilter` né `FeaturesByLayerFilter` del
      package
- [ ] `EcTrackRuoloFilter`, `EcTrackLayerFilter`, `EcTrackValidationStatusFilter` eliminati dalla
      codebase, con i loro test e le chiavi di traduzione rimaste orfane
- [ ] Prima di eliminare `EcTrackValidationStatusFilter`, il suo `statuses()` si sposta sul modello
      `App\Models\EcTrack` (accanto a `VALIDATION_STATUS_TAXONOMY`, per esempio
      `validationStatusTerms()`). Lo usano:
  - [ ] `validationStatusField()` (`app/Nova/EcTrack.php:274`, la chiamata a `statuses()` è alla
        riga 276), il campo «Stato validazione» di index, detail e form, che resta. Senza lo
        spostamento la Resource EcTrack va in «Class not found»
  - [ ] `tests/Feature/Import/ValidationStatusEventsTest.php:64`, che verifica che i termini
        ritirati non siano proposti: va aggiornato al nuovo metodo, non eliminato
- [ ] Stesso menu anche nelle relazioni `MorphToMany` verso EcTrack della detail view della
      Resource Ente (`ecTracksGestore` e le altre, `app/Nova/Ente.php:124-132`), con un test sul
      menu dei filtri in `ecTracksGestore`, dove la query è già in join con `enteables`
- [ ] `tests/Feature/EcTrack/EcTrackFiltersTest.php` aggiornato: elenco esatto dei filtri, AND/OR
      (anche con le aree catastali), ricostruzione dei quattro layer, titoli. Per le aree:
  - [ ] la definizione del filtro (test a riga 138, l'attesa del componente
        `nova-multiselect-filter` è a riga 144) passa al componente del `ChoiceFilter`
  - [ ] i test sulla query delle aree (righe 156-247) restano invariati, tranne quello a riga 190
        (punto sotto)
  - [ ] i due test che combinano le aree con il filtro dei layer (`LAYER_FILTER_KEY`, righe 190 e
        303) si rifanno con un filtro tassonomico, perché il filtro dei layer sparisce
- [ ] Prova a mano in locale: `sardegnasentieri:configure-taxonomy-terms` prima con `--dry-run`,
      poi senza `--fresh`; termini assegnati a una decina di tracce dalla edit Nova (che prova anche
      il form con il selettore estratto); casi: due termini dello stesso filtro, termini di due
      filtri, traccia senza termini, aree catastali insieme a un filtro tassonomico, togliere
      l'ultima chip (il contatore dei filtri attivi torna a zero), «Reset filters» (le chip
      spariscono)
- [ ] Pagina di conoscenza `docs/knowledge/8806-filtro-ec-tracce.md` riscritta: il layer esce da
      «Come funziona oggi» e passa in «Come ci siamo arrivati» con il motivo; le aree catastali
      sul selettore di `Choice`
- [ ] Puntatore del submodule `wm-package` aggiornato in un commit suo, nella stessa PR

## Rischi

- **Link salvati con il vecchio filtro**: Nova abbina i filtri dell'URL per `key()` e scarta in
  silenzio quelli sconosciuti (`vendor/laravel/nova/src/Filters/FilterDecoder.php:39-49`). Smettono
  di filtrare i link con Layer, Ruolo e Stato validazione (la chiave nuova deriva da
  `validation-status`): un preferito «tracce da validare» apre l'index con tutte le tracce. Si
  accetta, e la risposta al cliente su oc:8827 dice che i filtri salvati vanno reimpostati. I link
  con le aree catastali invece continuano a filtrare, perché `key()` e formato del valore non
  cambiano, e le chip compaiono anche con gli id interi che `MultiselectFilter` metteva nei link
  (`vendor/outl1ne/nova-multiselect-filter/src/MultiselectFilter.php:127`), perché il componente
  del filtro (`FilterField.vue` del package) li converte in stringa.
- **Termini ritirati nello «Stato validazione»**: `validation-status-walkable` e `-not-walkable`
  («Percorribile», «Non percorribile», gli stessi nomi di due termini della percorribilità)
  restano in `taxonomy_terms` finché un evento dello storico li nomina
  (`ConfigureTaxonomyTermsCommand.php:316-327`), quindi possono comparire fra le opzioni del filtro.
  Nessuna traccia li ha più: l'import non li scrive (`SardegnaSentieriImportService.php:113-119`) e
  `sardegnasentieri:move-walkability` sposta i legami vecchi. Sceglierli dà zero tracce, nessun
  dato cambia. Si accetta, senza etichette dedicate: non è un bug.
- **Rollback su due repo**: `filters()` e `EcTrackCadastralAreaFilter` dipendono da
  `TaxonomyTermFilter` e `ChoiceFilter` del package; riportare indietro solo il puntatore manda
  Forestas in «Class not found». E togliendo `outl1ne/nova-multiselect-filter` un revert del solo
  codice delle aree non basta: serve anche il `composer.json`. Procedura: revert di Forestas
  (codice e dipendenza), poi del puntatore (ordine inverso al rilascio).
- **Nessun dato nel DB locale**: in locale `taxonomy_terms` e `taxonomy_termables` hanno 0 righe
  (779 `ec_tracks`, conteggio del 17/11). La review del 17/11 riporta 70 termini e 772 legami su
  `route-type`, contati su un altro database. In locale, senza la prova a mano descritta nei
  requisiti, il componente non si vedrebbe mai con opzioni reali.
- **Tracce senza termini**: l'import assegna già `route-type` e `inclusivity-disabilities`
  (`SardegnaSentieriImportService.php:1041`, `:969`), e il reset notturno toglie solo i
  collegamenti in `taxonomy_termables`, non la tassonomia (`ImportSardegnaSentieriCommand.php`,
  «`taxonomy_terms` invece non si tocca»). Restano senza termini le tracce che Drupal non
  classifica: un filtro attivo le esclude, ed è il comportamento atteso.

## Out of scope

- Altre tassonomie nel menu (tipologia itinerario, difficoltà, avvertenze): si aggiungono con una
  riga ciascuna quando il cliente le chiede.
- Filtro per ruolo sulla Resource Ente: allo scrum del 17/11 Giuseppe ha detto che il filtro «Ruolo»
  «forse è più utile farlo in enti», ma «per ora toglilo». Qui si toglie e basta.
- `outl1ne/nova-multiselect-field`: è nel `composer.json` di forestas e del package, ed è usato dal
  package (`wm-package/src/Nova/App.php`, sette `Multiselect::make`). Toglierlo riguarda tutti gli
  shard, non questo ticket.
- Risposta al cliente su oc:8827: si scrive a lavoro finito.

## Moduli toccati

Repo `forestas`:

- `app/Nova/EcTrack.php` (`filters()`, `validationStatusField()`)
- `app/Models/EcTrack.php` (riceve `statuses()` dal filtro eliminato)
- `app/Nova/Filters/EcTrackCadastralAreaFilter.php` (estende `ChoiceFilter`)
- `app/Nova/Filters/EcTrackLayerFilter.php` (eliminato)
- `app/Nova/Filters/EcTrackRuoloFilter.php` (eliminato)
- `app/Nova/Filters/EcTrackValidationStatusFilter.php` (eliminato)
- `composer.json`, `composer.lock` (via `outl1ne/nova-multiselect-filter`)
- `lang/it.json`, `lang/en.json`
- `tests/Feature/EcTrack/EcTrackFiltersTest.php`
- `tests/Feature/Import/ValidationStatusEventsTest.php`
- `docs/knowledge/8806-filtro-ec-tracce.md`
- `docs/knowledge/classificazioni-e-inclusivita.md` (riga 165 cita `EcTrackValidationStatusFilter`)
- puntatore `wm-package`

Repo `wm-package`: vedi `wm-package/docs/features/8988-filtro-nova-generico-per-termine-di-tassonomia/overview.md`.
