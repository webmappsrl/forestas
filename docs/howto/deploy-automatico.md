# Deploy automatico su dev e sul collaudo

Dev e collaudo si aggiornano da soli con GitHub Actions:

| Ambiente | Server | Parte con | Approvazione |
|---|---|---|---|
| dev | `dev.forestas`, branch `develop` | ogni push su `develop` (`deploy-dev.yml`) | no |
| collaudo | `uat.forestas`, branch `main` | ogni push su `main` (`deploy-uat.yml`) | si', da chi e' indicato nell'environment `uat` |

Prima del deploy girano i test (`run-tests.yml`): se falliscono il deploy non parte. Tutti e due
si lanciano anche a mano, da GitHub, Actions, il workflow e «Run workflow».

## Cosa fa

Il workflow riutilizzabile `deploy.yml` si collega al server via SSH e fa, sull'host:

1. `git pull --ff-only` del branch e `git submodule update --init wm-package`;
2. `scripts/deploy.sh` nel container `php-<APP_NAME>`: dipendenze, `migrate --force`, cache,
   riavvio di Horizon, con il sito in manutenzione solo durante quei passi;
3. `scripts/wordpress-up.sh`, solo se il puntatore di `wp-forestas` e' cambiato;
4. uno smoke test: `/up` e `/docs/api/sus` devono rispondere.

Alla fine, riuscito o no, l'esito arriva nel canale Slack (`notify-slack.yml`, come su maphub):
test, deploy, commit, autore e link alla run. Si spegne con la variabile di repo
`SLACK_NOTIFICATIONS_ENABLED=false`.

## Cosa non fa, e va fatto a mano

- **Le colonne scritte nelle migration di creazione gia' eseguite.** La regola del repo e' «si
  modifica la create della tabella»: `migrate` non riesegue una migration gia' eseguita, quindi su
  un database esistente quelle colonne vanno aggiunte a mano, prima del deploy. Sul collaudo il
  momento giusto e' prima di approvare.
- **I rilasci di WordPress con passi propri**, come `applica --conferma` e `pagine --conferma` di
  `wp-forestas/bin/wordpress-config.sh`: vedi [messa-in-opera-wordpress-uat.md](messa-in-opera-wordpress-uat.md).
- **Le variabili nuove nei `.env`** dei server.

## Configurazione una tantum

La fa un admin del repo su GitHub, Settings, Environments, creando `dev` e `uat`. Per ciascuno:

- **secret** `SSH_HOST` (l'indirizzo del server), `SSH_USER` (`root`, lo stesso utente con cui
  si fa il deploy a mano) e `SSH_KEY` (la chiave privata di deploy);
- **variabili** `APP_NAME` (`forestasdev`, `forestasuat`) e `APP_URL`
  (`https://forestas.dev.maphub.it`, `https://forestas.uat.maphub.it`);
- solo per `uat`, **Required reviewers**: chi approva il deploy sul collaudo.

In piu', a livello di repo (Settings, Secrets and variables, Actions), il secret
`SLACK_WEBHOOK_URL`: il webhook dell'app Slack che notifica i deploy, lo stesso di maphub se il
canale e' lo stesso.

La chiave di deploy e' una coppia generata apposta, senza passphrase, una per ambiente: la
pubblica va in `/root/.ssh/authorized_keys` del server, la privata nel secret `SSH_KEY`. Non si
riusa una chiave personale.

## Tornare indietro

Un deploy sbagliato si annulla con un revert del commit sul branch: il push del revert fa partire
un nuovo deploy. Una migration gia' eseguita non si annulla da sola: serve `migrate:rollback` a
mano nel container, dopo aver controllato cosa toglie.
