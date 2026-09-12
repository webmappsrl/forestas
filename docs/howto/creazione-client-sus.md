# Creare il client SUS

Si fa **da Nova**, non da comandi artisan: il perché è in
[docs/knowledge/client-sus.md](../knowledge/client-sus.md). I passi sono del dev, che ha accesso a
Nova e alle credenziali.

1. **(dev)** Nova → Users → Create User
2. **(dev)** Nome: `SUS Client`. Email: un indirizzo su dominio **non instradabile** (es.
   `sus@catasto.invalid`) — Nova espone il reset password pubblico, e un reset innescato per errore
   cambierebbe la password del client
3. **(dev)** Password: generata lunga e casuale
4. **(dev)** Roles: selezionare **solo** `Sus`. Mai `Administrator`: porterebbe il permesso
   `access-nova`, cioè l'accesso al backoffice, a un fornitore esterno
5. **(dev)** Consegnare a Engineering su canali separati: l'URL della documentazione
   (`/docs/api/sus`) e le credenziali. Mai nello stesso messaggio
6. **(dev)** Ripetere su ogni ambiente (UAT per il collaudo, produzione al go-live) con credenziali
   distinte

I campi Password e Roles del resource utente sono in sola lettura per chi non passa
`RolesAndPermissionsService::allowsUser()` (allowlist di email): un account fuori dall'allowlist non
vede nemmeno i campi da compilare.
