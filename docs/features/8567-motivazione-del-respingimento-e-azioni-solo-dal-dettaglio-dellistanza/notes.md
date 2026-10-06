> Ticket: oc:8567

# Notes — Motivazione del respingimento, e azioni solo dal dettaglio dell'istanza

Il lavoro vive nel package: deviazioni, verifiche, decisioni e follow-up sono in
`wm-package/docs/features/8567-motivazione-del-respingimento-e-azioni-solo-dal-dettaglio-dellistanza/notes.md`.
Qui ci sono solo i fatti di forestas.

## Divergenze dal piano, task per task

### Task 10: migration allineata prima del merge

La migration è stata allineata prima del merge del package, per provare la funzione in locale.
Dettaglio nelle note del package.

### Task 11: bump del submodule lasciato al reviewer

Il commit di forestas non contiene il puntatore del submodule. Dopo il merge della PR
webmappsrl/wm-package#292, il reviewer aggiorna il puntatore sul commit di `develop` del package e
poi fa il merge della PR di forestas. I link alle due PR sono nella description del ticket.

## Decisioni
- La migration pubblicata è identica allo stub del package, byte per byte (`cmp`).

## Follow-up
- Su UAT, prima del deploy, il team deve lanciare l'`ALTER` di `rejection_reason`: comando e query
  di verifica sono nelle note del package. Il gate `publish-missing-migrations --dry-run` non lo
  segnala.
