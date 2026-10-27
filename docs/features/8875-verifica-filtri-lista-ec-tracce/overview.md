> Ticket: oc:8875

# Verifica di tutti i filtri della lista EC Tracce e rimozione del filtro App

## Cosa cambia

- Il menu filtri della lista EC Tracce in Nova non mostra più il filtro **App**: forestas ha una
  sola app, e il filtro restituirebbe sempre tutte le tracce. Il campo App resta com'è in lista,
  dettaglio e form; cambia solo che in forestas non è più `filterable`. Il package non si tocca.
- Il menu non mostra più nemmeno **Include per id** ed **Exclude per id**
  (`FeaturesIncludeByIds` / `FeaturesExcludeByIds` del package). Erano stati aggiunti a marzo 2025
  per il campo che associa le tracce ai layer, che allora chiamava la lista Nova passando gli id
  via codice (`b7385b57`, `6e9d7f31`, `30d38474`). A settembre 2025 quel campo è stato riscritto
  (`ee8a5551`) e oggi usa rotte sue (`/nova-vendor/layer-features/…`): nessuno usa più questi due
  filtri. Per un operatore non sono utilizzabili: `options()` è vuoto e il valore atteso è una
  lista di id, mentre il componente è una tendina a scelta singola. In forestas si tolgono in
  `filters()`, come già si fa per il filtro Layer del package.
- I titoli dei filtri passano da `__()` con chiave inglese, con la voce in `lang/en.json` e
  `lang/it.json`: **Layer** (oggi `'Features By Layer Filter'`, in inglese anche per l'utente
  italiano) diventa «Layer», **Ruolo** resta «Ruolo», **Stato validazione** resta «Stato
  validazione». Per Stato validazione il titolo del filtro è l'etichetta del campo, quindi la
  traduzione vale anche per colonna, dettaglio e form; in italiano il testo non cambia.
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
- [ ] I filtri Include per id ed Exclude per id non compaiono nel menu della lista EC Tracce;
      `wm-package` non è modificato
- [ ] Titoli tradotti via `__()` con chiave inglese, presenti in `lang/en.json` e `lang/it.json`:
      `Layer` → «Layer», `Role` → «Ruolo», `Validation status` → «Stato validazione» (etichetta del
      campo, quindi anche colonna, dettaglio e form)
- [ ] Verifica nel browser di ogni filtro del menu (Layer, Aree catastali, Ruolo, Stato
      validazione): per ogni valore, conteggio in Nova confrontato con una
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

## Esito della verifica nel browser (27/10, Nova locale)

**I filtri funzionano.** Ogni conteggio di Nova coincide con la query scritta dal significato del
filtro, tranne una traccia nelle aree catastali, che però è un problema di dati.

| Filtro | Valore | Cosa conta la query | Nova | DB |
|---|---|---|---|---|
| Layer | Sentieri / Itinerari / Cammini / Sentieri accessibili | tracce associate al layer (`layerables`) | 614 / 165 / 50 / 2 | 614 / 165 / 50 / 2 ✅ |
| Aree catastali | F / B / T / C / D / E | tracce la cui geometria tocca l'area | 24 / 130 / 162 / 97 / 193 / 32 | uguali ✅ |
| Aree catastali | **G (Nord-EST)** | idem | **166** | **167** ⚠️ |
| Ruolo | complesso forestale / gestore / manutentore / rilevatore / operatore | tracce con almeno un ente con quel ruolo | 100 / 305 / 302 / 397 / 202 | uguali ✅ (190 senza enti) |
| Stato validazione | pre-accatastamento / revisione / percorribile / non percorribile / non verificato | valore della colonna | 278 / 234 / 225 / 17 / 3 | uguali ✅ (22 vuoti) |
| Stato validazione | validato / certificato | idem | 0 / 0 | 0 / 0 ✅ |
| App | Sardegna Sentieri | tutte le tracce (una sola app) | 779 | 779: non serve |
| **Combinazione** | Sentieri + Area B + gestore + percorribile | tutte e quattro le condizioni | **10** | **10** ✅ |

Per Sentieri e Itinerari il conto torna anche con le attività «sentiero» (614) e «itinerario»
(165). I totali di Nova sono letti con la stessa richiesta della lista (`/nova-api/ec-tracks/count`
con i filtri codificati); la combinazione è verificata anche a schermo («1-10 of 10»).

Considerazioni:

- **Area G, 166 contro 167: anomalia di dati, non del filtro.** Manca la traccia 730 «Iscuvudè -
  Santu Jorzi - Attalai (G 606)», tutta dentro l'area G. Il filtro legge le aree salvate in
  `properties.taxonomy_where`: per 778 tracce su 779 ci sono le aree catastali (chiave numerica,
  `source: geohub_conf_32`); la 730 ha solo tre aree amministrative nel formato di osmfeatures
  (`{"R40425": {"it": "Illorai", "_admin_level": 8}, …}`). Quel formato lo scrive
  `SyncModelTaxonomyWhereJob` del package solo quando il calcolo locale non trova nessuna area
  (`wm-package/src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php:38-62`): il 23/09 per questa
  traccia il calcolo locale ha restituito zero, anche se le aree esistevano già. Il motivo non è
  ricostruibile dal DB locale. `wm:resync-taxonomy-where --only-legacy` non la sistemerebbe, perché
  la traccia ha `_admin_level`; va controllato se su UAT e in produzione ci sono casi uguali.
- **Include/Exclude per id** nel menu compaiono solo come titolo, senza nessun controllo: non si
  possono usare. Via codice funzionano (include `[1, 2]` → 2, exclude → 777). Conferma la decisione
  di toglierli.
- **La colonna «Stato validazione» mostra il valore tecnico** (`in_pre_accatastamento`,
  `percorribile`) invece dell'etichetta dell'elenco degli stati: al campo `Select` manca
  `->displayUsingLabels()`. Non riguarda il filtro, ma l'utente lo vede.
- **La chiave del filtro App è `resource:apps:app`**, non `BelongsTo:app`: il test sull'assenza va
  scritto su quella.

## Decisioni (27/10, dev)

Le domande aperte della prima versione sono state chiuse dalla dev:

- **Titoli**: «Layer» per il filtro dei layer (scartate «Tipologia» e «Tipologia di percorso»),
  «Ruolo» invariato, «Stato validazione» passato da `__()`.
- **Filtro App**: tolto solo dalla lista EC Tracce, perché è quella che si sta verificando. EC POI,
  UGC POI e UGC Tracce: follow-up, con le loro verifiche.
- **Colonna App nella lista**: resta; follow-up.
- **Tracce senza valore** (senza stato, senza enti): nessuna opzione nuova; follow-up, solo se
  serve a chi lavora sui dati.
- **Include/Exclude per id**: tolti dalla lista EC Tracce in forestas, perché nessuno li usa più e
  un operatore non può usarli (motivo in «Cosa cambia»). La pulizia nel package è un follow-up.

La verifica nel browser ha chiuso anche l'ultima domanda: nessun filtro è rotto (sezione sopra).

## Follow-up

- Forestas ha una sola app: filtro App su EC POI, UGC POI e UGC Tracce, e colonna App nelle liste.
- Opzione «Senza stato» / «Senza ente» nei filtri, se serve.
- Traccia 730 senza aree catastali in `taxonomy_where`: controllare UAT e produzione, ricalcolare
  le tracce nella stessa situazione, e capire nel package perché il calcolo locale di
  `SyncModelTaxonomyWhereJob` ha restituito zero (ticket sul package).
- Colonna «Stato validazione» con il valore tecnico invece dell'etichetta (`->displayUsingLabels()`).
- wm-package: togliere `FeaturesIncludeByIds` / `FeaturesExcludeByIds` da `src/Nova/EcTrack.php`
  e il commento «Needed filters for the custom field on layers», non più vero. Il package è
  condiviso: va verificato che nessuno shard li usi.

## Rischi

- **Rimozione del filtro App fatta nel posto sbagliato**: toglierla in `filters()` o modificare il
  package la toglierebbe a tutti gli shard. Si interviene sul campo nei `fields()` di forestas,
  cercandolo per attribute (`app`): se il package lo rinomina o lo sposta, il filtro ricompare
  senza errori. Lo protegge il test sull'assenza del filtro, che controlla la chiave
  (`resource:apps:app`) e non il nome.
- **Verifica su dati locali**: i conteggi valgono per il DB locale; per i layer automatici
  `layerables` si aggiorna in differita, e su UAT il reset notturno può cambiare il quadro (vedi
  oc:8825). Le prove dicono che il filtro fa ciò che deve, non che i dati di UAT siano gli stessi.
- **Include/Exclude tolti in forestas**: si scartano per classe in `filters()`, come il filtro
  Layer del package. Se il package li rinomina o ne cambia la chiave, ricompaiono senza errori: lo
  protegge il test sull'assenza, che controlla le chiavi (`features_include_ids_…`,
  `features_exclude_ids_…`).

## Out of scope

- Correzione o rimozione di filtri diversi da App: si discutono prima con Giuseppe.
- Rimozione del filtro App dalle liste diverse da EC Tracce (follow-up).
- Opzioni «Senza stato» / «Senza ente» (follow-up).
- Modifiche a `wm-package`, compresa la pulizia di Include/Exclude (follow-up).
- Colonna App nella lista (follow-up).

## Moduli toccati

Tutto in forestas:

- `app/Nova/EcTrack.php` — campo App non filterable, etichetta di Stato validazione in `__()`,
  Include/Exclude scartati in `filters()`
- `app/Nova/Filters/EcTrackLayerFilter.php` — titolo in `__()`
- `app/Nova/Filters/EcTrackRuoloFilter.php` — titolo in `__()`
- `lang/en.json`, `lang/it.json` — chiavi dei titoli
- `tests/Feature/EcTrack/EcTrackFiltersTest.php` — test nuovi e aggiornamento del test sul titolo
  del Layer
- `docs/features/8875-verifica-filtri-lista-ec-tracce/` — overview, piano, note con le prove,
  screenshot
