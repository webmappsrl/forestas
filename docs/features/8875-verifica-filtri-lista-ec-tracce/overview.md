> Ticket: oc:8875

# Verifica di tutti i filtri della lista EC Tracce e rimozione del filtro App

## Cosa cambia

- Il menu filtri della lista EC Tracce in Nova non mostra più il filtro **App**: forestas ha una
  sola app, e il filtro restituirebbe sempre tutte le tracce. Il campo App resta com'è in lista,
  dettaglio e form; cambia solo che in forestas non è più `filterable`. Il package non si tocca.
- I titoli dei filtri **Layer** (oggi `'Features By Layer Filter'`, in inglese anche per l'utente
  italiano) e **Ruolo** passano da `__()` con chiave inglese, con la voce in `lang/en.json` e
  `lang/it.json`. «Stato validazione» resta com'è: il suo titolo è l'etichetta del campo (domanda
  aperta sotto).
- Ogni filtro che compare nel menu è verificato nel browser sul Nova locale, confrontando il
  conteggio della lista con una query sul DB, e coperto da test automatici dove oggi non lo è.
- Ne esce un elenco dei filtri che non funzionano o non servono, con il comportamento attuale e una
  proposta, da discutere con Giuseppe prima di toccarli.

## Perché

Richiesto da Giuseppe allo scrum del 27/10, in coda alla correzione di oc:8825: «controlla tutti i
filtri che funzionino… se ce n'è uno che non funziona… li discutiamo», e «il filtro per app è
inutile averlo su Forestas… piuttosto lo togliamo». Va chiuso prima dell'aggiornamento di
produzione e della risposta al cliente (oc:8827).

Il filtro Layer era rotto e nessun test se n'era accorto (oc:8825): oggi Ruolo, Stato validazione,
App e Include/Exclude non hanno nessun test.

## Requisiti

- [ ] Il filtro App non compare nel menu filtri della lista EC Tracce di forestas; colonna e campo
      App restano invariati; `wm-package` non è modificato
- [ ] Titoli dei filtri Layer e Ruolo tradotti via `__()` con chiave inglese, presenti in
      `lang/en.json` e `lang/it.json`; il testo italiano definitivo è una domanda aperta (sotto)
- [ ] Verifica nel browser di ogni filtro del menu (Layer, Aree catastali, Ruolo, Stato
      validazione, Include/Exclude per id): per ogni valore, conteggio in Nova confrontato con una
      `SELECT` sul DB locale
- [ ] Ogni query di riscontro parte dal **significato** che l'operatore dà al filtro, non dal
      codice di `apply()` (Layer = tracce che l'app mostra in quel layer; Ruolo = tracce con un ente
      con quel ruolo; Stato = valore della colonna; Aree = tracce dentro quell'area), con accanto la
      frase che dice cosa conta. Un conteggio che non torna finisce nell'elenco per Giuseppe
- [ ] Per ogni filtro, una riga anche per le tracce che nessun valore raggiunge (in locale 22 senza
      stato di validazione, 190 su 779 senza enti), così la somma dei conteggi torna al totale
- [ ] Verifica di una combinazione che usa insieme Layer + Aree catastali + Ruolo + Stato
      validazione, con lo stesso riscontro sul DB
- [ ] Prove in `notes.md`: tabella con filtro, valore, conteggio Nova, conteggio query, frase e
      query usata; uno screenshot per filtro (e per la combinazione) nella cartella del cantiere
- [ ] Test automatici per Ruolo, Stato validazione, assenza del filtro App e titoli tradotti, in
      `tests/Feature/EcTrack/EcTrackFiltersTest.php`; i test sul testo italiano impostano la lingua
      (`app()->setLocale('it')`), perché la CI gira con `APP_LOCALE=en`
- [ ] Elenco dei filtri che non funzionano o non servono, con comportamento attuale e proposta,
      pronto per Giuseppe; nessuno di questi viene corretto o tolto in questo ticket

## Domande aperte (da risolvere con Giuseppe)

- **Testo italiano dei titoli dei filtri**: che dicitura per Layer (oggi «Features By Layer
  Filter») e Ruolo.
- **Etichetta «Stato validazione»**: il titolo del filtro è il nome del campo
  (`Fields/Filters/Filter.php:35` di Nova), quindi tradurlo cambia anche colonna, dettaglio e form.
  Oggi è una stringa italiana fissa: la si passa da `__()`?
- **Filtro App sulle altre liste**: lo stesso campo filtrabile arriva anche a EC POI, UGC POI e UGC
  Tracce. Proposta della dev: toglierlo da tutte le liste di forestas, che ha una sola app. Serve la
  conferma di Giuseppe.
- **Colonna App nella lista**: con una sola app mostra lo stesso valore su tutte le righe; la si
  toglie?
- **Include/Exclude per id**: hanno `options()` vuote; se la verifica conferma che compaiono nel
  menu come select senza scelte, cosa farne.
- **Tracce senza valore**: nessun filtro trova le tracce senza stato di validazione o senza enti.
  Serve un'opzione «Senza stato» / «Senza ente»?
- Gli eventuali filtri che la verifica trova rotti.

## Rischi

- **Base del branch**: oc:8875 parte da `feature/oc-8825-…` (commit `ae14162`), perché la verifica
  va fatta sui filtri come andranno in produzione. La PR di oc:8875 dipende dalla PR #32 di oc:8825
  e va unita dopo; finché #32 non è in `develop`, il diff della PR di oc:8875 mostra anche i suoi
  commit.
- **Rimozione del filtro App fatta nel posto sbagliato**: toglierla in `filters()` o modificare il
  package la toglierebbe a tutti gli shard. Si interviene sul campo nei `fields()` di forestas,
  cercandolo per attribute (`app`): se il package lo rinomina o lo sposta, il filtro ricompare
  senza errori. Lo protegge il test sull'assenza del filtro, che controlla la chiave
  (`BelongsTo:app`) e non il nome.
- **Verifica su dati locali**: i conteggi valgono per il DB locale; per i layer automatici
  `layerables` si aggiorna in differita, e su UAT il reset notturno può cambiare il quadro (vedi
  oc:8825). Le prove dicono che il filtro fa ciò che deve, non che i dati di UAT siano gli stessi.
- **Include/Exclude**: il package li registra commentandoli come necessari al campo dei layer, ma
  quel campo usa una rotta propria (`/nova-vendor/layer-features/…`), verificato nella review di
  oc:8825. Toccarli resta comunque una modifica al comportamento del package: si discute con
  Giuseppe, non si fa qui.

## Out of scope

- Correzione o rimozione di filtri diversi da App: si discutono prima con Giuseppe.
- Rimozione del filtro App dalle liste diverse da EC Tracce (domanda aperta).
- Etichetta del campo «Stato validazione» (domanda aperta).
- Modifiche a `wm-package`.
- Colonna App nella lista (domanda aperta).

## Moduli toccati

Tutto in forestas:

- `app/Nova/EcTrack.php` — campo App non filterable
- `app/Nova/Filters/EcTrackLayerFilter.php` — titolo in `__()`
- `app/Nova/Filters/EcTrackRuoloFilter.php` — titolo in `__()`
- `lang/en.json`, `lang/it.json` — chiavi dei titoli
- `tests/Feature/EcTrack/EcTrackFiltersTest.php` — test nuovi e aggiornamento del test sul titolo
  del Layer
- `docs/features/8875-verifica-filtri-lista-ec-tracce/` — overview, piano, note con le prove,
  screenshot
