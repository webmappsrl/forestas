# Deploy automatico su dev e su UAT

Dev e UAT si aggiornano da soli con GitHub Actions:

| Ambiente | Server | Parte con |
|---|---|---|
| dev | `dev.forestas`, branch `develop` | ogni push su `develop` (`deploy-dev.yml`) |
| UAT | `uat.forestas`, branch `main` | ogni push su `main` (`deploy-uat.yml`) |

Nessuno dei due chiede un'approvazione: e' una scelta. Se servisse, si aggiunge
all'environment su GitHub (Settings, Environments, Required reviewers) senza toccare i workflow.

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
  un database esistente quelle colonne vanno aggiunte a mano, prima del deploy: per UAT, prima
  del merge su `main`, perche' il deploy parte da solo.
- **I rilasci di WordPress con passi propri**, come `applica --conferma` e `pagine --conferma` di
  `wp-forestas/bin/wordpress-config.sh`: vedi [messa-in-opera-wordpress-uat.md](messa-in-opera-wordpress-uat.md).
- **Le variabili nuove nei `.env`** dei server.
- **Il primo deploy delle versioni del JSON della traccia (oc:8997)**, su ogni server:
  **prima del deploy**, scrivere nel `.env` del server `WMFE_TRACK_VERSIONS=legacy,v1`. Il deploy
  esegue `php artisan optimize`, che congela la configurazione: una variabile aggiunta dopo non
  viene letta, e senza di essa lo shard continua a scrivere il solo file senza versione, senza
  nessun segnale. I file della `v1` nascono poi da soli al primo reset notturno; per vederli
  subito si lancia una volta l'Action Nova «Update Tracks on AWS» sulla lista delle EC Tracce.
  Se su un server il reset notturno venisse spento (`SARDEGNASENTIERI_DAILY_RESET`), quell'Action
  diventerebbe obbligatoria dopo ogni deploy che introduce una versione nuova.
- **Il primo deploy dell'API degli enti (oc:8965)**, su ogni server, in quest'ordine:
  1. **prima del deploy**, scrivere nel `.env` del server `WP_API_TOKEN` con un valore generato
     da `openssl rand -hex 32`, e passarlo a chi configura il sito WordPress. Il deploy esegue
     `php artisan optimize`, che congela la configurazione: una variabile aggiunta dopo non viene
     letta, e senza token le rotte `api/v1/entes` rispondono 403;
  2. **subito dopo il deploy, prima del prossimo import** (orario sul dev, alle 06:00 su UAT),
     creare l'indice della ricerca degli enti, altrimenti `api/v1/entes/search` risponde 503:
     `docker exec php-forestas php artisan scout:import "App\Models\Ente"`.
     Se un import o un salvataggio da Nova arriva prima, Elasticsearch crea da solo un indice vero
     chiamato `entes`, senza il mapping degli enti, e `scout:import` fallisce perché non può creare
     l'alias con lo stesso nome. Il rimedio è cancellarlo e rilanciare:
     `docker exec php-forestas php artisan scout:delete-index entes`, poi di nuovo `scout:import`;
  3. generare le miniature delle immagini degli enti già importate, che prima di oc:8965 non
     avevano conversioni:
     `docker exec php-forestas php artisan media-library:regenerate "App\Models\Ente" --only-missing --force`
     (`--force` perché il comando chiede conferma in produzione, e UAT gira come `production`);
  4. riscrivere le descrizioni con la loro formattazione, che l'import prima toglieva:
     `docker exec php-forestas php artisan sardegnasentieri:import --only=enti --force`. Su UAT
     il reset notturno riscrive comunque gli enti ogni notte, ma il passo evita di aspettarlo.

## Configurazione una tantum

La fa un admin del repo su GitHub, Settings, Environments, creando `dev` e `uat`. Per ciascuno:

- **secret** `SSH_HOST` (l'indirizzo del server), `SSH_USER` (`root`, lo stesso utente con cui
  si fa il deploy a mano) e `SSH_KEY` (la chiave privata di deploy);
- **variabili** `APP_NAME` (`forestasdev`, `forestasuat`) e `APP_URL`
  (`https://forestas.dev.maphub.it`, `https://forestas.uat.maphub.it`);

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
