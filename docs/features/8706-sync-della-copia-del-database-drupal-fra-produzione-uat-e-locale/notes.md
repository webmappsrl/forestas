> Ticket: oc:8706

# Notes — Sync della copia del database Drupal fra produzione, UAT e locale

## Deviazioni dal piano

Nessuna deviazione nei Task 1-4: gli script sono quelli del piano, le verifiche hanno dato l'esito
atteso. Il Task 5 (messa in opera su UAT) si esegue dopo il rilascio su `main`.

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

## Follow-up

- **Task 5 del piano**, dopo il rilascio: `~/.ssh/config` di root, cartella dei dump, accensione
  del container, primo giro, riga del cron, prova con l'ambiente minimo di cron, controllo del log
  il giorno dopo.
- **Resto di oc:8706**: scaricamento UAT → locale, verifica del tunnel, password su UAT.
