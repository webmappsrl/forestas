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

## Raggiungere la copia di UAT dal locale

La porta del container è pubblicata solo sul loopback del server, quindi ci si arriva con un
tunnel SSH:

```bash
ssh -N -L 3308:127.0.0.1:3307 uat.forestas
# poi: host 127.0.0.1, porta 3308, utente readonly, database sardegnasentieri
```

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
