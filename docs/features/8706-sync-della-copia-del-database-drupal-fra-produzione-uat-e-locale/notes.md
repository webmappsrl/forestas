> Ticket: oc:8706

# Notes — Sync della copia del database Drupal fra produzione, UAT e locale

## Deviazioni dal piano

Nessuna deviazione nei Task 1-5: gli script sono quelli del piano, le verifiche hanno dato l'esito
atteso. Il Task 5 è stato eseguito il 05/10/2026 dopo il rilascio su `main` (`09fbd1b`): voce
`Host prod.sardegnasentieri` nel `~/.ssh/config` di root (copia in `config.bak-oc8706`), cartella
`storage/drupal-dump/`, container acceso su `127.0.0.1:3307` e rete `forestas_default`, primo giro
dalle 13:29 alle 13:41 UTC (download circa 1 minuto, ricarica circa 12, 972 tabelle),
`php-forestasuat` legge la copia, riga del cron alle 10:00 UTC (crontab precedente in
`/root/crontab.bak-oc8706`), riga provata con l'ambiente minimo di cron.

## Bug trovati

- **Da UAT la porta 22 in uscita era bloccata**, verso Acquia e verso qualunque server (timeout su
  `18.201.110.246:22` e `github.com:22`; `ufw` inattivo, `iptables` OUTPUT `ACCEPT`). La causa è il
  firewall Hetzner di UAT, che in uscita lascia la 22 solo verso tre IP. Il dev ha aggiunto
  `18.201.110.246` alla regola in uscita; da UAT `ls ~/prod/backups` su Acquia ora funziona.

## Decisioni

- **Chiave dedicata a UAT** `/root/.ssh/id_rsa_acquia_uat` (RSA 4096, senza passphrase perché la
  usa il cron), creata su UAT su richiesta del dev e autorizzata da lui sulla console Acquia
  (`id_rsa_acquia_to_forestas_uat`, MD5 `2f:66:32:e9:4a:84:a7:f6:cd:71:24:f3:6c:44:1e:73`). La host
  key di Acquia è stata aggiunta ai `known_hosts` di root su UAT alla prima connessione.
- **Uno script in più rispetto all'overview**, `scripts/drupal-dump-sync.sh`: tiene semplice la riga
  del cron (niente `%` da scappare né logica sui codici di uscita in crontab) e si prova anche in
  locale. Aggiunto ai «Moduli toccati» prima dell'approvazione del piano.
- **Stima** impostata dal dev a 2 ore, al posto di quella dell'agente (2,9 ore).
- **Log dedicato** `storage/logs/drupal-dump-sync.log`: il dev ha corretto l'assunzione che i log di
  UAT non vengano letti; il log è letto da lui e dalle sessioni di lavoro.

- **Righe duplicate tolte dal crontab di UAT**, su richiesta del dev: il backup del database delle
  19:50 e il download `wm:download-db-backup --latest --s3` delle 20:00 duplicavano lo scheduler del
  package (`wm-package/src/Providers/ScheduleServiceProvider.php`, ramo `production`: backup alle
  20:00, download alle 20:10). Su UAT `--s3` non cambia nulla, perché `AWS_DUMPS_ENDPOINT` non è
  definito e la regione è già `eu-central-1`. Crontab precedente in
  `/root/crontab.bak-oc8706-duplicati`. Da allora il backup lo fa solo lo scheduler, con le
  notifiche.
- **Prova dal locale sulla copia di UAT**: tunnel SSH e script PHP con PDO, le ultime 10 news con
  titolo, data, tipologia, URL, immagine e testo; scrittura rifiutata (1142). Con PHP 7.4 PDO non
  segnala gli errori senza `ERRMODE_EXCEPTION`: la prima prova di scrittura sembrava riuscita.
- **Porta unica 3307**, regola del dev: gli script dall'host usano sempre `127.0.0.1:3307`; per
  lavorare su UAT dal locale si spegne il container locale e si apre il tunnel sulla 3307 (con
  `ExitOnForwardFailure`). Tolta dal compose la variabile `DOCKER_DRUPAL_DUMP_PORT`.

## Follow-up

- **Controllare il log di UAT il 06/10/2026 dopo le 10:00 UTC**: primo giro automatico con il dump
  del 06/10.
- **Resto di oc:8706**: scaricamento UAT → locale, verifica del tunnel, password su UAT.
