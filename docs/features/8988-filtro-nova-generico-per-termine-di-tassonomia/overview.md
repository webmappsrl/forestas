> Ticket: oc:8988

# Filtro Nova generico per termine di tassonomia — parte Forestas

## Cosa cambia

Il menu dei filtri dell'index della Resource EcTrack (`App\Nova\EcTrack::filters()`) sostituisce i
filtri per layer, ruolo e stato di validazione con il `TaxonomyTermFilter` del package, richiamato
una volta per tassonomia:

| Filtro | Prima | Dopo |
|---|---|---|
| Layer | `EcTrackLayerFilter` | tolto, insieme a `FeaturesByLayerFilter` del package |
| Ruolo | `EcTrackRuoloFilter` | tolto |
| Stato validazione | `EcTrackValidationStatusFilter` (identifier cablato) | `TaxonomyTermFilter('validation-status')`, single |
| Tipo percorso | — | `TaxonomyTermFilter('route-type', multiple: true)` |
| Disabilità accolte | — | `TaxonomyTermFilter('inclusivity-disabilities', multiple: true)` |
| Difficoltà di accessibilità | — | `TaxonomyTermFilter('inclusivity-difficulty', multiple: true)` |
| Aree catastali | `EcTrackCadastralAreaFilter` | invariato |

## Perché

Il filtro per layer è un meccanismo indiretto: i layer si costruiscono dalle tassonomie, e filtrare
per layer ha già prodotto risultati sbagliati e sovrapposizioni (oc:8827). Il cliente vuole che
l'elenco corrisponda a ciò che è scritto sul contenuto, con le stesse icone della compilazione.
Allo scrum del 16/11 Giuseppe: «non voglio più mettere un filtro per layer. Ok, c'abbiamo ora le
tassonomie, usiamo quelle»; il «Ruolo» si elimina del tutto perché al cliente non serve (oc:8827,
risposta del 02/11: «Continuo a non capire l'utilità del filtro "Ruoli"»); lo stato di validazione
resta, rifatto con il nuovo approccio; le aree catastali restano com'erano,
perché leggono dalla tabella delle zone e non da `taxonomy_terms`. oc:8827 resta il ticket del
cliente: serve per rispondere e per la release.

## Domande aperte per il reviewer

- **Aree catastali sul componente outline**: il ticket dice «l'Area catastale resta com'è», e così
  è in questa overview (stessa query, stesso componente `outl1ne/nova-multiselect-filter`). Alla
  call delle 10:25 però Giuseppe, sul componente outline usato nei filtri: «smettiamo di usarlo,
  cioè là dove c'è da fare una select si usa il choice field». Va migrato anche il filtro delle
  aree al selettore di `Choice` (stessa query sulle zone, via la dipendenza da Forestas)? Se sì,
  serve nel package un filtro di base per opzioni che non vengono da `taxonomy_terms`; si può fare
  in questo ticket o in uno a parte.

## Requisiti

- [ ] `filters()` restituisce, oltre ai filtri del package non toccati: Stato validazione (single),
      Tipo percorso (multiple), Disabilità accolte (multiple), Difficoltà di accessibilità
      (multiple), Aree catastali (invariato)
- [ ] Con i filtri tassonomici si ricostruisce il contenuto di ognuno dei quattro layer di
      tipologia (`docs/knowledge/8806-filtro-ec-tracce.md`): Sentieri, Itinerari e Cammini con
      Tipo percorso; Sentieri Accessibili con Difficoltà di accessibilità = AT, AE, AEE
- [ ] Filtri diversi si combinano in AND; i valori dello stesso filtro in OR
- [ ] Il filtro per layer non compare più: né `EcTrackLayerFilter` né `FeaturesByLayerFilter` del
      package
- [ ] `EcTrackRuoloFilter`, `EcTrackLayerFilter`, `EcTrackValidationStatusFilter` eliminati dalla
      codebase, con i loro test e le chiavi di traduzione rimaste orfane
- [ ] Prima di eliminare `EcTrackValidationStatusFilter`, il suo `statuses()` si sposta sul modello
      `App\Models\EcTrack` (accanto a `VALIDATION_STATUS_TAXONOMY`, per esempio
      `validationStatusTerms()`): lo usa `validationStatusField()` (`app/Nova/EcTrack.php:236`),
      il campo «Stato validazione» di index, detail e form, che resta. Senza lo spostamento la
      Resource EcTrack va in «Class not found»
- [ ] Stesso menu anche nelle relazioni `MorphToMany` verso EcTrack della detail view della
      Resource Ente (`ecTracksGestore` e le altre)
- [ ] `tests/Feature/EcTrack/EcTrackFiltersTest.php` aggiornato: elenco esatto dei filtri, AND/OR
      (anche con le aree catastali), ricostruzione dei quattro layer, titoli
- [ ] Prova a mano in locale: `sardegnasentieri:configure-taxonomy-terms` prima con `--dry-run`,
      poi senza `--fresh`; termini assegnati a una decina di tracce dalla edit Nova (che prova anche
      il form con il selettore estratto); casi: due termini dello stesso filtro, termini di due
      filtri, traccia senza termini, togliere l'ultima chip (il contatore dei filtri attivi torna a
      zero), «Reset filters» (le chip spariscono)
- [ ] Pagina di conoscenza `docs/knowledge/8806-filtro-ec-tracce.md` riscritta: il layer esce da
      «Come funziona oggi» e passa in «Come ci siamo arrivati» con il motivo
- [ ] Puntatore del submodule `wm-package` aggiornato in un commit suo, nella stessa PR

## Rischi

- **Link salvati con il vecchio filtro**: Nova abbina i filtri dell'URL per `key()` e scarta in
  silenzio quelli sconosciuti (`vendor/laravel/nova/src/Query/FilterDecoder.php:40-44`). Smettono
  di filtrare i link con Layer, Ruolo e Stato validazione (la chiave nuova deriva da
  `validation-status`): un preferito «tracce da validare» apre l'index con tutte le tracce. Si
  accetta, e la risposta al cliente su oc:8827 dice che i filtri salvati vanno reimpostati.
- **Rollback su due repo**: `filters()` dipende da `TaxonomyTermFilter` del package; riportare
  indietro solo il puntatore manda Forestas in «Class not found». Procedura: revert di Forestas,
  poi del puntatore (ordine inverso al rilascio).
- **Nessun dato in locale**: `taxonomy_terms` è vuota (0 righe, 779 `ec_tracks`); senza la prova a
  mano descritta nei requisiti il componente non si vedrebbe mai con opzioni reali.
- **Tracce senza termini**: l'import assegna già `route-type` e `inclusivity-disabilities`
  (`SardegnaSentieriImportService.php:1041`, `:969`), e il reset notturno toglie solo i
  collegamenti in `taxonomy_termables`, non la tassonomia (`ImportSardegnaSentieriCommand.php`,
  «`taxonomy_terms` invece non si tocca»). Restano senza termini le tracce che Drupal non
  classifica: un filtro attivo le esclude, ed è il comportamento atteso.

## Out of scope

- Altre tassonomie nel menu (tipologia itinerario, difficoltà, avvertenze): si aggiungono con una
  riga ciascuna quando il cliente le chiede.
- Aree catastali: restano sul loro componente (`outl1ne/nova-multiselect-filter`) e sulla loro
  tabella, salvo diversa risposta alla domanda aperta.
- Filtri separati «Validazione catastale» e «Percorribilità» (chiesti dal cliente in oc:8827 il
  28/10): oggi Drupal dà un valore solo e la famiglia `validation-status` è una; quando Forestas
  fornirà la percorribilità separata (oc:8783, oc:8932, vedi
  `docs/knowledge/classificazioni-e-inclusivita.md`) sarà una famiglia a sé, e il suo filtro una
  riga in `filters()`.
- Risposta al cliente su oc:8827: si scrive a lavoro finito.

## Moduli toccati

Repo `forestas`:

- `app/Nova/EcTrack.php` (`filters()`, `validationStatusField()`)
- `app/Models/EcTrack.php` (riceve `statuses()` dal filtro eliminato)
- `app/Nova/Filters/EcTrackLayerFilter.php` (eliminato)
- `app/Nova/Filters/EcTrackRuoloFilter.php` (eliminato)
- `app/Nova/Filters/EcTrackValidationStatusFilter.php` (eliminato)
- `lang/it.json`, `lang/en.json`
- `tests/Feature/EcTrack/EcTrackFiltersTest.php`
- `docs/knowledge/8806-filtro-ec-tracce.md`
- puntatore `wm-package`

Repo `wm-package`: vedi `wm-package/docs/features/8988-filtro-nova-generico-per-termine-di-tassonomia/overview.md`.
