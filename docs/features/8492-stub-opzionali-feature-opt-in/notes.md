> Ticket: oc:8492

# Notes — Adozione del gate stub wm-package

## Divergenze dal piano, task per task

Ogni voce e' richiamata da una riga nel `plan.md` del task corrispondente.

### Task 1: disallineamento create users table

Gli stub disallineati si sono rivelati **due**, non uno: un aggiornamento del
submodule durante il ciclo ha portato anche
`zz_2026_07_27_000001_add_surname_to_users_table`. Ed e' caduta la premessa
delle tre opzioni descritte nel piano: lo stub `create_users_table` fa
`Schema::table`, non `Schema::create`, quindi pubblicarlo su una tabella
esistente era sicuro e non serviva alcuna decisione.

### Task 2: dichiarare l'adesione

I file da toccare sono **quattro** e non tre: la code review ha rilevato che
serve anche `.env.testing`, letto dai comandi artisan lanciati con
`--env=testing`, dove `phpunit.xml` non arriva. I posti in cui tenere allineata
la variabile sono sei, non cinque.

## Deviazioni dal piano

- **I disallineamenti erano due, non uno.** Il piano ne prevedeva uno
  (`create_users_table`). Durante il ciclo il developer ha aggiornato il
  submodule, portando un nuovo stub obbligatorio
  (`zz_2026_07_27_000001_add_surname_to_users_table`) e con esso un secondo
  disallineamento. Lezione operativa: **l'elenco va rimisurato al momento di
  intervenire**, non letto dal piano.

- **La decisione prevista dal piano non e' servita.** Il Task 1 elencava tre
  opzioni per `create_users_table`, fra cui "scrivere una migration a mano
  perche' non si puo' pubblicare uno stub `create` su una tabella esistente".
  Falso: lo stub fa `Schema::table`, non `Schema::create` — il nome inganna.
  Pubblicarlo era sicuro, e le altre due opzioni sono cadute.

## Bug trovati

- **Bug latente preesistente, chiuso da questo ciclo.** `User::$fillable` del
  package dichiara `surname` e `app_id`, e `WalletController::buy()` legge
  `users.balance` sulla rotta `POST /wallet/buy`, registrata anche in forestas
  (`wm-package/routes/api.php:35`). Nessuna delle tre colonne esisteva nel
  database: la rotta avrebbe risposto con un errore SQL, e un
  mass-assignment di `surname` sarebbe fallito. Non e' un problema introdotto
  qui — accendere il gate lo ha soltanto reso visibile, che e' esattamente il
  motivo per cui il gate serve.

## Decisioni

- **`fiscal_code` entra pur essendo inutilizzata in forestas.** E' citata solo in
  `GeohubImportService`, che questo progetto non usa. Accettata: e' nullable e
  costa una colonna vuota, meno del tenere lo schema disallineato dal modello
  che il package distribuisce.

- **Nessuna verifica automatica dell'interruttore al deploy.** Valutata e
  scartata dal developer: il rischio che `WM_TRAIL_REGISTRY_ENABLED` sparisca dal
  `.env` di un server e' reale ma poco probabile, e non giustifica un passo in
  piu' in `deploy_prod.sh`. Rischio accettato, documentato in overview e
  `CLAUDE.md`.

- **Il rollback non e' coperto.** Il developer ha dichiarato che non e' una
  preoccupazione per questo ticket: nessuna mitigazione scritta, e le note su
  ordine di spegnimento e `--with` come contratto sono state tolte
  dall'overview invece che ammorbidite.

## Correzioni dopo la code review formale

- **`.env.testing` era un sesto posto non censito.** Overview e `CLAUDE.md`
  elencavano cinque punti in cui tenere allineata
  `WM_TRAIL_REGISTRY_ENABLED`; ne mancava uno versionato. `phpunit.xml` copre
  `php artisan test`, ma non i comandi artisan lanciati con `--env=testing`
  (migrate, tinker, il gate stesso), che leggono `.env.testing`. Aggiunta li' e
  corretto il conteggio ovunque.

- **Via d'uscita da `--with`, documentata.** L'opzione e' indipendente
  dall'interruttore: se un giorno il dominio venisse spento, la CI continuerebbe
  a pretenderne gli stub e resterebbe rossa. Scritto in `CLAUDE.md` e nella
  guida del package che va tolta anche dallo step di CI.

## Follow-up

- **L'adozione del gate negli altri 14 consumer.** Forestas e' il secondo repo su
  16 ad averlo. Ogni repo ha la sua bonifica da fare — qui sono bastati due
  stub, altrove potrebbe essere peggio — quindi ognuno merita un ticket suo.

- **Verificare l'esito reale in CI.** Il `--dry-run` locale gira su un dump di
  produzione; la CI costruisce lo schema da zero da `php artisan migrate` e puo'
  dare un esito diverso. La prima PR e' l'unica verifica che conta.

- **`WM_TRAIL_REGISTRY_ENABLED` sui server.** Va aggiunta a mano nel `.env` di
  UAT e produzione: non e' coperta da nessun file di questo repo.
