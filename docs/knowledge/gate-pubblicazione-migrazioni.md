# Il gate `publish-missing-migrations` in CI

## Stato attuale

`.github/workflows/run-tests.yml` esegue
`php artisan wm-package:publish-missing-migrations --with=trail_registry --dry-run`: fallisce se
uno stub del package non ha corrispondenza nello schema del database.

Forestas è il **secondo** repo ad averlo, dopo maphub (che lo ha da oc:8218): non è la coda del
ticket del package, è il primo passo per farne uno standard. (oc:8492)

- `--with=trail_registry` non è strettamente necessario — il flag in `.env-deploy` basterebbe — ma
  rende leggibile cosa quello step controlla. `--with` può solo **aggiungere** domini alla
  verifica, mai toglierne.
- **Se un giorno il dominio Catasto va spento**, va tolto anche `--with=trail_registry`: continua a
  pretendere gli stub di un dominio disattivato, e lascia la pipeline rossa senza una via d'uscita
  evidente.
- Gli stub di un dominio opzionale **non** si pubblicano con `vendor:publish`: la scoperta delle
  migration di Spatie non è ricorsiva, serve
  `php artisan wm-package:publish-migration trail_registry/<stub>`. Il dettaglio è nel package,
  sezione «Migration» di `wm-package/docs/resources/TrailRegistry.md`.

## Cosa ha richiesto accenderlo

Due disallineamenti pregressi, **estranei al catasto**: `create_users_table` (colonne `balance`,
`fiscal_code`, `app_id`) e `zz_2026_07_27_000001_add_surname_to_users_table`. Erano un bug latente,
non solo igiene: `User::$fillable` del package dichiara `surname` e `app_id`, e la rotta
`POST /wallet/buy` (`wm-package/routes/api.php`) legge `users.balance` — colonne che nel database
non esistevano. (oc:8492)

Lo stub `create_users_table` fa `Schema::table`, non `Schema::create`: il nome inganna,
pubblicarlo su una tabella esistente è sicuro.
