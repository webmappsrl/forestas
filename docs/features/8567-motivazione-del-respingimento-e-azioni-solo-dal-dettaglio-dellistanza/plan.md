> Ticket: oc:8567

# Piano — Motivazione del respingimento, e azioni solo dal dettaglio dell'istanza

Il piano completo è nel package:
`wm-package/docs/features/8567-motivazione-del-respingimento-e-azioni-solo-dal-dettaglio-dellistanza/plan.md`.
Qui ci sono solo i task di forestas. Il commit parte **dopo il merge** della PR del package
(webmappsrl/wm-package#292).

> ⚠️ L'implementazione ha deviato dal task 10: la migration è già stata allineata, prima del
> merge del package, per provare la funzione in locale. Il dettaglio è nelle note del package,
> sezione «Task 10: migration di forestas modificata prima del merge del package».

- **Task 10**: rendere
  `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php`
  identico al nuovo stub del package, con la colonna `rejection_reason`.
- **Task 11**:

  > ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-11-bump-del-submodule-lasciato-al-reviewer)

  - aggiungere la colonna al DB locale con un `ALTER`, dopo il consenso della dev e mai con
    `migrate:rollback`;
  - aggiornare il puntatore del submodule;
  - provare le azioni a mano in Nova.

Commit suggerito, solo dopo l'approvazione della dev:
```
feat(oc:8567): migration di trail_applications con rejection_reason e bump wm-package
```

La PR di forestas va verso `develop`.
