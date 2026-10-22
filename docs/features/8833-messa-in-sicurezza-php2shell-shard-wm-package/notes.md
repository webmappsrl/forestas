> Ticket: oc:8833

# Notes — Messa in sicurezza PHP2Shell

## Verifica di compromissione (22/10/2026, su evidenze raccolte prima di ogni rebuild)

**Esito: nessuna evidenza di compromissione.**

- Evidenze: log Apache dell'host UAT (copertura 08–22/10), processi, cron, file recenti e log dei
  container PHP, Horizon e WordPress. Conservate fuori dal repo (il repo è pubblico), sul Mac del
  dev, più snapshot Hetzner del disco fatto prima di ogni intervento.
- Container PHP e WordPress: solo processi attesi (php-fpm / apache2), cron standard, nessun file
  anomalo nelle directory scrivibili.
- Gli oltre 16.000 errori 500 del 20–22/10 sono tutti sull'endpoint del filtro layer di EC Tracce:
  è il bug già segnalato dal cliente il 21/10 (polling del browser degli editor), non un attacco.
- Un probabile tentativo PHP2Shell il 21/10 ~23:48 UTC: 3 POST a `/` da un bot (user-agent
  fasthttp, IP cloud), uno troncato a metà body (errore proxy_fcgi «reading input brigade», che
  è la forma dell'exploit). Risposte 405/400, nessuna attività successiva, nessun segno di
  successo.
- Rumore di fondo: scanner su vecchie CVE PHP-CGI, probe di path traversal, tutte respinte.
- Lacune: il log FPM interno al container risulta vuoto/assente; i log Apache non coprono prima
  dell'8/10. Per questo (e perché i log sono alterabili da chi ha RCE) l'esito pulito non annulla
  la rotazione precauzionale, solo la rimanda (vedi Decisioni).

## Deviazioni dal piano

- **Task 3 (riscontro limiti):** confermato in locale (file più grosso: 16,7MB, PNG in MinIO —
  sotto 32M). La misura su UAT, lanciata in parallelo, non è determinante: i valori 32M/64M sono
  stati confermati anche dal dev (flussi previsti: immagini e GPX).
- **Task 7 (test funzionale limiti):** rimandato all'applicazione su UAT. Motivo: in locale
  servirebbe `develop.compose.yml` (nginx+FPM), che confligge con l'ambiente `local.compose`
  attivo; i valori del pool sono già verificati con `php-fpm -tt` e la priorità del dev è
  patchare i ~20 server. Il test oversize/legittimo va fatto su UAT dopo l'applicazione.
- **Ordine di esecuzione dichiarato dal dev:** locale → develop → UAT.

## Decisioni

- **Stima saltata** su indicazione del dev (wm-estimate aveva prodotto 1,1h misurate + 2,65h
  stimate; nessuna scrittura di `estimated_hours`).
- **Rotazione segreti (Task 8) rimandata a dopo il patching dei server**, su priorità del dev
  («prima risolvere, poi analizzare»). Resta da fare: token client SUS + password DB UAT. Mai
  JWT_SECRET.
- **Niente vhost di forestas nel repo** (repo pubblico) e **niente LimitRequestBody**: l'Apache
  2.4.41 dell'host non lo applica alle richieste proxate. Contenimento ai limiti FPM. Tema host →
  oc:8835.
- **Ticket oc:8835** creato per gli host con OS fuori supporto (UAT forestas: Ubuntu 20.04.6
  senza Ubuntu Pro, verificato il 22/10).
- **Tag Orchestrator `php2shell` (id 706)** creato con problema e strategia condivisa; agganciati
  oc:8833 (da me) e oc:8835 (dal dev).
- **Snapshot Hetzner** fatto dal dev prima di ogni intervento (22/10).
- **Branch:** `feature/oc-8833-messa-in-sicurezza-php2shell-shard-wm-package` nei tre repo.
  In wp-forestas e wm-package il branch parte dal puntatore del submodule, non da
  origin/develop (wp: 2504e61 vs 7c190aa; wm-package: 0f2cf63 vs 77d79ee): alla PR può servire
  un rebase.
- Il dato «php2shell» e la strategia provengono dalle mail NetSeven (21/10) e Hetzner (22/10) e
  dagli advisory pubblici; versioni target verificate su Docker Hub il 22/10.

## Bug trovati

- PHPStan dentro il container fallisce coi worker paralleli («Unable to create file … nette.configurator», race sulla cache nel bind mount macOS): in modalità seriale (`--debug`) passa con exit 0. Non riguarda la CI, che gira su runner Linux.
- Nessun bug applicativo nuovo. Confermata la mole di 500 del filtro layer EC Tracce (ticket
  cliente già aperto il 21/10, fuori da questo lavoro).

## Follow-up

- Applicazione su UAT (team, con la procedura in wm-package) + test funzionale dei limiti (Task 7)
  + `php -v` nei container UAT.
- Rotazione token SUS e password DB UAT (Task 8, rimandata — vedi Decisioni).
- Replica sugli altri shard (~20 server): un ticket per shard agganciato al tag `php2shell`.
- oc:8835: censimento OS degli host e piano di aggiornamento.
- Debito: unificazione dei Dockerfile `wm-phpfpm` (forestas / wm-package / tag 8.2.15 del package).
