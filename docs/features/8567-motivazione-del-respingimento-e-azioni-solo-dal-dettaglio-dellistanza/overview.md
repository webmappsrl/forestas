> Ticket: oc:8567

# Motivazione del respingimento, e azioni solo dal dettaglio dell'istanza

Il lavoro vive nel package. L'overview completa, con requisiti, rischi e risposte del reviewer, è in
`wm-package/docs/features/8567-motivazione-del-respingimento-e-azioni-solo-dal-dettaglio-dellistanza/overview.md`.

## Cosa cambia in forestas

- La migration pubblicata
  `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php`
  diventa identica al nuovo stub del package, con la colonna `rejection_reason`. Il gate
  `publish-missing-migrations --dry-run` in CI resta verde.
- Il puntatore del submodule `wm-package` viene aggiornato dopo il merge del package, come chiede
  la regola del package: prima si fa il merge del package, poi il consumer aggiorna il puntatore.

## Rischi

- **Il DB locale e UAT hanno già la tabella.** La migration è registrata come eseguita nel batch 9,
  e lo stub esce subito se la tabella esiste. La colonna va aggiunta una volta con l'`ALTER` qui
  sotto; su UAT lo lancia il team prima del deploy:
  ```sql
  ALTER TABLE trail_applications ADD COLUMN rejection_reason text NULL;
  ```
  **Non usare `migrate:rollback`**: annulla tutto il batch 9 (10 migration, fra cui
  `add_identifier_to_taxonomy_wheres`) e distrugge dati importati.

## Moduli toccati

- `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php`
- `wm-package` (puntatore del submodule)
