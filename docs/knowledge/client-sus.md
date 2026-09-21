# Il client SUS

Branch `/api/v1/sus/*` autenticato JWT per l'integrazione con lo Sportello Unico Sentieri. Solo
scaffolding: nessuna logica di business. (oc:8333)

## Stato attuale

### Cosa può raggiungere

**Solo `api/v1/sus/*`.** `RestrictSusClient` (`app/Http/Middleware/RestrictSusClient.php`, appeso
al gruppo `api` in `bootstrap/app.php`) è una whitelist per prefisso, non una blacklist: le route
che wm-package aggiungerà al gruppo `auth:api` restano escluse per costruzione. Login e refresh
vivono **dentro** quel prefisso (`api/v1/sus/auth/login`, `api/v1/sus/auth/refresh`), quindi il
client non ha mai bisogno di uscirne; le route di autenticazione del package rispondono 403.

Verificato con `php artisan route:list --path=sus` (2026-09-12): login, refresh, ping e la
documentazione su `docs/api/sus`.

### Creazione e rotazione si fanno da Nova

Non da comandi artisan. Il resource utente espone `Password::make()` e `RoleBooleanGroup` per i
ruoli (`wm-package/src/Nova/AbstractUserResource.php`), entrambi in sola lettura per chi non passa
`RolesAndPermissionsService::allowsUser()` (allowlist di email). Un `sus:create-client` /
`sus:rotate-client` aggiungerebbe un secondo modo di fare la stessa cosa, con la password esposta
nello scrollback del terminale.

La procedura passo-passo è in [docs/howto/creazione-client-sus.md](../howto/creazione-client-sus.md).

### Revocare un token

La blacklist JWT è attiva (`config/jwt.php` → `blacklist_enabled`, default `true`), quindi un
singolo token si invalida con `JWTAuth::invalidate()` — unica operazione senza interfaccia Nova,
snippet fra i comandi del `CLAUDE.md`.

Cambiare la password del client **non** invalida il token già emesso: sono due azioni distinte e in
caso di compromissione servono entrambe — la revoca via blacklist taglia l'accesso in corso, il
cambio password da Nova impedisce di ottenerne uno nuovo.

**Non ruotare `JWT_SECRET` per revocare un token**: è la regola in cima al `CLAUDE.md`, non una
sfumatura.

## Come ci siamo arrivati

- **«Nessun utente mobile ha token con `exp`, perché `JWT_TTL` non è impostato»** (oc:8333) — non
  regge: `.env` e `.env-example` impostano `JWT_TTL=60`, e `.env-example` annota che con
  `tymon/jwt-auth` 2.3.0 un valore nullo non produce token senza scadenza. Il motivo per non
  ruotare `JWT_SECRET` resta valido (invalida i token di tutti), ma non passa più da lì.
- **«Il client SUS può usare `POST /api/auth/login` e `POST /api/auth/refresh` del package»**
  (oc:8333) — non regge: la whitelist del middleware contiene il solo prefisso `api/v1/sus/*`, e
  gli endpoint di autenticazione del branch stanno dentro quel prefisso.
