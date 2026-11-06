# Accendere, caricare e raggiungere la copia del database Drupal

> Ticket: oc:8705, oc:8706. Cos'è la copia, come si interroga e com'è fatto lo schema:
> [docs/knowledge/copia-database-drupal.md](../knowledge/copia-database-drupal.md).

La copia **non c'è per forza**: esiste solo dove qualcuno ha acceso il container e ci ha caricato
un dump.

## Verificare che la copia ci sia

```bash
docker inspect -f '{{.State.Running}}' mysql-sardegnasentieri-dump      # → true
```

## Accenderla

```bash
docker compose -f sardegnasentieri-dump.compose.yml up -d
```

Va lanciato dalla root del repo, come i compose principali: così il container finisce sulla stessa
rete Docker di `php-forestas`, che lo raggiunge per nome. Un container nuovo ha il database vuoto e
non ha l'utente `readonly` finché non si carica un dump.

## Caricare o aggiornare la copia

1. Copiare il dump in `storage/drupal-dump/` (ignorata da git). La produzione lo genera ogni
   giorno con il nome `prod-sardegnasentieri-sardegnasentieriuyyrag83f9-AAAA-MM-GG.sql.gz`; vanno
   bene sia il `.sql.gz` sia il `.sql` decompresso, purché il nome contenga la data.
2. Lanciare lo script:

   ```bash
   scripts/drupal-dump-reload.sh
   ```

Lo script prende il dump più recente per data nel nome, cancella e ricrea il database
`sardegnasentieri`, lo carica, ricrea l'utente `readonly` con il solo `SELECT` e stampa file
caricato, numero di tabelle e data dell'ultima modifica dei nodi. Non tocca volumi né altri
container. Un dump intero richiede alcuni minuti.

Si ferma senza toccare nulla se la cartella non contiene un dump con la data nel nome, o se il
container è spento.

## Scaricare il dump da produzione

```bash
scripts/drupal-dump-download.sh
```

Lo può lanciare solo chi ha una chiave autorizzata su Acquia e l'host `prod.sardegnasentieri` nel
proprio `~/.ssh/config`: oggi il dev e UAT. Sul server esegue solo letture, `ls` su
`prod/backups/` e lo `scp` del file verso questa macchina.

Su Acquia i dump restano 3 giorni e nascono ogni giorno verso le 08:14 UTC. Lo script prende il
più recente, lo scarica in un file nascosto (`.<nome>.part`), ne verifica l'integrità con
`gzip -t` e solo allora gli dà il nome definitivo in `storage/drupal-dump/`. Poi tiene in cartella
solo i dump delle ultime 2 date e cancella i più vecchi; i file senza data nel nome non li tocca.

Uscita: **0** dump nuovo scaricato, **2** nessun dump nuovo (quello più recente c'è già), **1**
errore. Per scaricare e ricaricare in un colpo solo:

```bash
scripts/drupal-dump-sync.sh
```

che ricarica solo se il download ha portato un dump nuovo.

## La copia su UAT

Su UAT la copia si aggiorna da sola, con questa riga nel crontab di `root`:

```
0 10 * * * cd /var/www/html/forestas && scripts/drupal-dump-sync.sh >> storage/logs/drupal-dump-sync.log 2>&1
```

Il log, una manciata di righe per giorno con data e ora di inizio e fine:

```bash
ssh uat.forestas 'tail -n 30 /var/www/html/forestas/storage/logs/drupal-dump-sync.log'
```

- **La chiave** è `/root/.ssh/id_rsa_acquia_uat`, dedicata a UAT. Sulla console Acquia è la voce
  `id_rsa_acquia_to_forestas_uat`: per togliere l'accesso a UAT basta revocare quella.
- **Il firewall Hetzner di UAT** lascia uscire la porta 22 solo verso alcuni IP; per Acquia c'è una
  regola in uscita TCP 22 verso `18.201.110.246`. Se il download va in timeout, Acquia può aver
  cambiato IP: `getent hosts sardegnasentieriuyyrag83f9.ssh.devcloud.acquia-sites.com` dice quello
  nuovo, da mettere nella regola.
- **Il container** ha `restart: unless-stopped`: riparte da solo dopo un riavvio, resta spento se
  lo si ferma a mano.

## La copia su dev: non c'è, si legge quella di UAT

Su `dev.forestas` **la copia non esiste**: niente container, e il `.env` non aveva nemmeno le
variabili di connessione. L'import ci girava a vuoto da sempre — si fermava sulla sua guardia con
«Source unreachable or empty, import aborted before any change», senza cancellare niente.

Non se ne tiene una seconda: è un dump in sola lettura, uguale per tutti e rigenerato da un
processo solo, e pesa 15 GB. Dev legge quella di UAT attraverso un tunnel SSH, installato come
servizio di sistema:

```bash
ssh dev.forestas 'systemctl status tunnel-sardegnasentieri-dump'
```

L'unità è `/etc/systemd/system/tunnel-sardegnasentieri-dump.service`, con `Restart=always` e
`ServerAliveInterval=30`: se la connessione cade il tunnel si riapre da solo dopo dieci secondi, e
riparte al boot. Se proprio non risale, l'import si ferma sulla guardia e non cancella niente.

Due cose da sapere, perché non sono deducibili:

- **Il tunnel ascolta su `172.24.0.1:3307`, non su `127.0.0.1`.** A leggere la copia sono i
  container `php-forestasdev` e `wordpress-forestasdev` (oc:8947), che l'host lo raggiungono dal
  gateway della rete Docker `forestas_default`: in ascolto sul solo loopback non lo vedrebbero. Nel
  `.env` di dev ci sono `DB_SARDEGNASENTIERI_HOST=172.24.0.1` e `DB_SARDEGNASENTIERI_PORT=3307`, nel
  `wp-forestas/.env` di dev `DRUPAL_DB_HOST=172.24.0.1` e `DRUPAL_DB_PORT=3307`: sono gli unici due
  posti dove la regola «dentro Docker si usa `mysql-sardegnasentieri-dump:3306`» non vale, e se il
  gateway della rete cambia vanno aggiornati tutti e due, insieme all'unità del tunnel.
- **Il Cloud Firewall Hetzner di dev chiude la 22 in uscita**, verso qualunque destinazione —
  anche GitHub, ed è il motivo della riga `Host github.com / Port 443` nel suo `~/.ssh/config`.
  Il filtro non è sulla macchina: `ufw` è inattivo e `iptables` ha la catena OUTPUT vuota. Serve
  una regola in uscita TCP 22 verso l'IP di UAT, nella console Hetzner (instance `DEV-forestas`,
  `fsn1-dc14`). Senza quella, `dev → uat:22` va in timeout a sei secondi netti.

## La porta è sempre la 3307

Uno script che legge la copia dall'host si collega **sempre** a `127.0.0.1:3307`, utente
`readonly`, database `sardegnasentieri`, ovunque giri. Così lo script scritto e provato in locale
gira identico su UAT, senza cambiare configurazione.

| Dove gira lo script | Cosa c'è sulla `127.0.0.1:3307` |
|---|---|
| UAT | la copia di UAT |
| Mac, container locale acceso | la copia locale |
| Mac, container locale spento e tunnel aperto | la copia di UAT, aggiornata ogni giorno |
| dev | niente: la copia di UAT sta sulla `172.24.0.1:3307`, vedi sopra |

Il codice Laravel non passa dall'host: dentro Docker usa `mysql-sardegnasentieri-dump:3306`, in
locale come su UAT.

## Lavorare dal locale sulla copia di UAT

Si spegne il container locale, che libera la 3307, e si apre il tunnel sulla stessa porta:

```bash
docker stop mysql-sardegnasentieri-dump
ssh -f -N -o ExitOnForwardFailure=yes -L 3307:127.0.0.1:3307 uat.forestas
```

Da qui la 3307 del Mac è la copia di UAT. Il container locale ha `restart: unless-stopped`: spento
a mano, non riparte da solo occupando la porta. `ExitOnForwardFailure` fa fallire il tunnel con un
errore se la 3307 è ancora occupata, invece di partire senza inoltrare nulla.

Per tornare alla copia locale:

```bash
pkill -f "3307:127.0.0.1:3307"
docker start mysql-sardegnasentieri-dump
```

Anche Laravel, dentro `php-forestas`, può leggere la copia di UAT: nel `.env` locale

```bash
DB_SARDEGNASENTIERI_HOST=host.docker.internal
DB_SARDEGNASENTIERI_PORT=3307
```

Su Mac `host.docker.internal` è l'host; su UAT e in produzione le due variabili restano vuote e
Laravel raggiunge la copia per nome sulla rete Docker.

Il tunnel passa dall'SSH di UAT: serve la propria chiave autorizzata su `uat.forestas` e il proprio
IP fra quelli ammessi in entrata dal firewall Hetzner. Da internet la copia resta irraggiungibile.

Con PHP 7.4 PDO non segnala gli errori se non glielo si chiede: negli script va sempre
`PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, altrimenti una query rifiutata sembra riuscita.

## Confrontare l'import con le API

> ⚠️ Superato (oc:8827): il confronto serviva a provare in locale il passaggio dalle API alla copia
> prima della produzione (oc:8900) e non va più fatto dopo ogni modifica alle query. Il comando
> resta, per un controllo occasionale; sui campi che le API non esponevano (per esempio
> `field_tipologia_itinerario`) segnala differenze attese.

Con la copia raggiungibile da Laravel:

```bash
docker exec php-forestas php artisan sardegnasentieri:compare-source
docker exec php-forestas php artisan sardegnasentieri:compare-source --only=tracks --ids=164,3636
```

Esce con 0 se non ci sono differenze sui campi che l'import usa. Le risposte delle API restano in
`storage/app/sardegnasentieri-compare-cache/`: `--refresh-api` le riscarica. I nodi modificati dopo
il dump vengono saltati e contati.

## Se l'import resta fermo dopo una ricarica

Durante la ricarica lo script tiene `storage/drupal-dump/reload.lock`, e l'import salta il giro
scrivendo «Copia del DB Drupal in ricarica dal …» nel log `import`. Se il lock ha più di due ore il
comando esce in errore con «Copia del DB Drupal bloccata dal … (ricarica fallita?)». Se la ricarica
fallisce il lock resta, apposta: prima si guarda perché è fallita (log del sync), poi si rilancia
`scripts/drupal-dump-reload.sh`, che lo toglie a ricarica riuscita. Cancellarlo a mano con la copia
a metà fa leggere all'import un database incompleto.

## Scaricare il codice Drupal di riferimento

Il codice in produzione è il tag `2024-04-10-bis` del repository Acquia. Si clona fuori dal repo,
solo per leggerlo:

```bash
GIT_SSH_COMMAND="ssh -o IdentitiesOnly=yes -i ~/.ssh/id_rsa_acquia" \
  git clone sardegnasentieri@svn-6182.devcloud.hosting.acquia.com:sardegnasentieri.git drupal-code
git -C drupal-code checkout 2024-04-10-bis
```

Le cartelle utili sono `config/sync/` (bundle, campi, vocabolari: `field.storage.*.yml`,
`field.field.node.<bundle>.<campo>.yml`) e
`docroot/modules/custom/api_webmapp/src/Plugin/rest/resource/` (gli endpoint `/ss/...`).
