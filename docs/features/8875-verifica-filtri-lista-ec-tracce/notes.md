> Ticket: oc:8875

# Notes — Verifica dei filtri della lista EC Tracce e rimozione del filtro App

## Divergenze dal piano, task per task

### Task 1 — chiave del filtro App

Il piano (prima versione) controllava `BelongsTo:app`: la verifica nel browser ha mostrato che la
chiave è `resource:apps:app`, e il test usa quella. Corretto anche nell'overview prima
dell'approvazione del piano.

### Task 5 — `composer format` sull'intero repo

`composer format` non formatta solo i file toccati: ha riscritto una cinquantina di file non
legati al ticket (migrazioni, config, model, test) e il contenuto del submodule `wp-forestas`.
Prima del format il working tree aveva solo i file del ticket: gli altri sono stati ripristinati
con `git checkout`, `wp-forestas` con `git -C wp-forestas checkout -- .`. In quel momento nel diff
restavano solo i sei file di codice, test e traduzioni del ticket; la pagina di conoscenza è stata
scritta dopo (task 8).

## Bug trovati

Nessuno nei filtri. Due cose emerse dalla verifica, fuori scope (vedi «Follow-up»):

- la traccia 730 senza aree catastali nel suo `taxonomy_where` (anomalia di dati);
- la colonna «Stato validazione» mostra il valore tecnico invece dell'etichetta.

## Decisioni

- Le domande aperte della prima overview le ha chiuse la dev il 27/10 (sezione «Decisioni»
  dell'overview), non Giuseppe come previsto all'inizio.
- I totali di Nova per valore sono letti con la stessa richiesta della lista
  (`/nova-api/ec-tracks/count` con i filtri codificati in base64); la combinazione è verificata
  anche a schermo («1-10 of 10»).
- `plan.md`, non ancora committato, è sparito dalla cartella dopo il rebase su `develop`: è stato
  riscritto dalla conversazione con le decisioni del 27/10.

## Verifiche

Nova locale (`localhost:8000`), DB locale in sola lettura, 27/10. Tabella dei risultati
nell'overview, sezione «Esito della verifica nel browser». Query usate, scritte dal significato del
filtro:

```sql
-- Layer: tracce associate al layer
select l.layer_id, count(distinct t.id) from layerables l join ec_tracks t on t.id = l.layerable_id
where l.layerable_type = 'App\Models\EcTrack' and l.layer_id in (8, 9, 10, 11) group by 1;

-- Layer, controllo: tracce con l'attività della tipologia
select a.identifier, count(distinct ta.taxonomy_activityable_id) from taxonomy_activityables ta
join taxonomy_activities a on a.id = ta.taxonomy_activity_id
join ec_tracks t on t.id = ta.taxonomy_activityable_id
where ta.taxonomy_activityable_type like '%EcTrack'
  and a.identifier in ('sardegnasentieritypesentiero', 'sardegnasentieritypeitinerario') group by 1;

-- Aree catastali: tracce la cui geometria tocca l'area
select w.id, w.name, count(t.id) from taxonomy_wheres w join ec_tracks t on ST_Intersects(t.geometry, w.geometry)
where w.id in (79, 80, 81, 82, 83, 84, 85) group by 1, 2;

-- Ruolo: tracce con almeno un ente in quel ruolo, e tracce senza enti
select e.ruolo, count(distinct t.id) from enteables e join ec_tracks t on t.id = e.enteable_id
where e.enteable_type = 'App\Models\EcTrack' group by 1;
select count(*) from ec_tracks t where not exists (
  select 1 from enteables e where e.enteable_id = t.id and e.enteable_type = 'App\Models\EcTrack');

-- Stato validazione: valore della colonna
select coalesce(stato_validazione, '(vuoto)'), count(*) from ec_tracks group by 1;

-- Combinazione: Sentieri + Area B + gestore + percorribile
select count(*) from ec_tracks t where t.stato_validazione = 'percorribile'
  and exists (select 1 from layerables l where l.layer_id = 11 and l.layerable_type = 'App\Models\EcTrack' and l.layerable_id = t.id)
  and exists (select 1 from taxonomy_wheres w where w.id = 80 and ST_Intersects(t.geometry, w.geometry))
  and exists (select 1 from enteables e where e.enteable_type = 'App\Models\EcTrack' and e.enteable_id = t.id and e.ruolo = 'gestore');

-- Tracce che toccano un'area catastale non presente nel loro taxonomy_where (risultato: 1, la 730)
select count(distinct t.id) from ec_tracks t join taxonomy_wheres w on w.id between 79 and 85 and ST_Intersects(t.geometry, w.geometry)
where not (coalesce(t.properties->'taxonomy_where', '{}'::jsonb) ? (w.id::text));
```

Dopo le modifiche: menu con Layer, Aree catastali, Ruolo, Stato validazione; conteggi invariati
(779 totali, Sentieri accessibili 2, Area G 166, gestore 305, percorribile 225, combinazione 10).

Test: `EcTrackFiltersTest` 20 passati (5 nuovi più il dataset dei titoli), suite completa 257
passati. PHPStan: nessun errore sui file toccati (restano i 5 preesistenti in
`tests/Feature/Sus/`).

## Follow-up

- Forestas ha una sola app: filtro App su EC POI, UGC POI e UGC Tracce, e colonna App nelle liste.
- Opzione «Senza stato» / «Senza ente» nei filtri, se serve.
- Traccia 730 senza aree catastali in `taxonomy_where`: controllare UAT e produzione, ricalcolare
  le tracce nella stessa situazione, capire nel package perché il calcolo locale di
  `SyncModelTaxonomyWhereJob` ha restituito zero.
- Colonna «Stato validazione» con il valore tecnico invece dell'etichetta
  (`->displayUsingLabels()`).
- wm-package: togliere `FeaturesIncludeByIds` / `FeaturesExcludeByIds` da `src/Nova/EcTrack.php` e
  il commento «Needed filters for the custom field on layers»; verificare che nessuno shard li usi.
