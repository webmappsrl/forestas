> Ticket: oc:8168

# Allineare FORESTAS

## Cosa cambia

Il wm-package submodule (già aggiornato in locale a `a94a634f` = origin/main) viene allineato ufficialmente nel repo forestas. L'autoloader composer nel container viene ricostruito, le migration stubs mancanti vengono pubblicate e applicate al DB, e vengono aggiunti i Nova stub per le nuove risorse introdotte dal package (Tile, TaxonomyTheme).

## Perché

Il wm-package è passato dalla versione locale `0cff8d34` a `a94a634f` (57 commit), introducendo feature rilevanti (Tile, TaxonomyTheme, Editor role, BulkEditAction, LayerAnalytics, BboxField, fix import, ecc.). Il container è attualmente in errore (`Class "Wm\WmPackage\Nova\Cards\LayerAnalytics\CardServiceProvider" not found`) perché l'autoloader è stale. Le nuove migration stubs non sono state pubblicate in forestas e le relative tabelle non esistono nel DB.

## Requisiti

- [ ] `composer install` nel container per aggiornare l'autoloader con le nuove PSR-4 map (risolve errore di boot immediato)
- [ ] `php artisan vendor:publish --tag=wm-package-migrations` per pubblicare le stub mancanti: `create_tiles_table`, `create_app_tile_table`, `add_editor_role` (e taxonomy_themes/themeables se risultano mancanti)
- [ ] `php artisan migrate` per applicare le nuove migrazioni al DB (dev)
- [ ] Aggiornare il puntatore submodule in forestas da `0cff8d34` a `a94a634f`
- [ ] Aggiungere `app/Nova/Tile.php` (stub vuoto che estende `WmNovaTile`)
- [ ] Aggiungere `app/Nova/TaxonomyTheme.php` (stub vuoto che estende `WmNovaTaxonomyTheme`)
- [ ] Registrare `TilePolicy` in `AppServiceProvider::boot()`
- [ ] Verificare che il container si avvii senza errori dopo tutte le modifiche
- [ ] Registrare le nuove risorse Nova nel menu (`NovaServiceProvider`) — posizione indicata dall'utente

## Rischi

- **Migrazioni duplicate:** `taxonomy_themes`/`taxonomy_themeables` potrebbero risultare già nel migration log se in una versione precedente del package era attivo `runsMigrations`. Laravel skippa le migration già eseguite, ma va verificato prima di procedere.
- **Backfill `create_app_tile_table`:** la migrazione backfilla `apps.tiles` (colonna JSON) nella nuova tabella pivot. Se la colonna ha dati in formato non atteso, alcune app potrebbero perdere i tile — la migrazione usa `updateOrInsert` e skippa silenziosa entries non parsabili.
- **Ruolo Editor:** `add_editor_role` inserisce il ruolo `Editor` nel DB. Va verificato che non ci siano policy o logiche in forestas che assumano solo i ruoli esistenti (Administrator, Editor già presente?, Validator, Guest).

## Out of scope

- Personalizzazioni forestas-specific delle nuove risorse Nova (campi extra, label custom, ecc.)
- Aggiornamento ambiente UAT (da fare dopo validazione su dev)
- Test automatici per le nuove migrazioni

## Moduli toccati

- `wm-package` (puntatore submodule in git index e `.git/modules/`)
- `database/migrations/` (nuovi file da `vendor:publish`)
- `app/Nova/Tile.php` (nuovo)
- `app/Nova/TaxonomyTheme.php` (nuovo)
- `app/Providers/AppServiceProvider.php` (aggiunta `TilePolicy`)
- `app/Providers/NovaServiceProvider.php` (registrazione nuove risorse nel menu)
