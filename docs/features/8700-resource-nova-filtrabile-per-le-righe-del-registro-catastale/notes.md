> Ticket: oc:8700

# Notes — Righe del registro catastale in Nova

## Divergenze dal piano, task per task

### Task 3: Resource «Righe del registro», filtri e menu

- `defaultOrderings()` ha la firma reale di Nova, `(Builder $query)` senza tipo di ritorno: quella
  scritta nel piano non era compatibile.
- Nova legge il parametro `filters` come `[{<key del filtro>: <valore>}]`, non `{class, value}`:
  l'helper dei test `filtroNova()` segue il formato reale.
- Aggiunto `public static $with = ['trailRegistryCode'];`: senza, il `BelongsTo` del codice
  nell'index fa una query per riga.

### Task 4: submodule, documentazione e verifica finale

L'aggiornamento del puntatore del submodule è un'operazione git e resta al dev. La pagina
`docs/knowledge/registro-catastale.md` si aggiorna nella fase di chiusura del lavoro, mostrando al
dev il prima e il dopo.

## Bug trovati

## Decisioni

- **Modifica chiesta dal dev dopo il piano:** il link al codice agganciato è la prima colonna
  dell'index (Codice · Tab · Area · Settore · Numero) e compare anche nel detail, sopra la tabella
  del registro. Il piano lo prevedeva solo nell'index, dopo il Numero.
- Il titolo di una riga è il numero come sul foglio; per una riga con solo il link è
  «<tab> · riga <n>», perché un titolo vuoto lasciava vuote intestazione e breadcrumb del detail.
- Gli endpoint di ricerca di Nova restano senza controllo dei ruoli, per scelta del dev: vedi le
  note del package.

- Le Policy del package sono tre (una per modello) invece di una: vedi le note del package,
  `wm-package/docs/features/8700-resource-nova-filtrabile-per-le-righe-del-registro-catastale/notes.md`.

## Follow-up
