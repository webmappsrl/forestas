> Ticket: oc:8706

# Sync della copia del database Drupal fra produzione, UAT e locale

## Cosa cambia

Questo giro copre il pezzo **produzione → UAT**: su UAT la copia del database Drupal di Sardegna
Sentieri si aggiorna da sola ogni giorno con il dump di produzione.

- Un nuovo script, `scripts/drupal-dump-download.sh`, si collega in sola lettura al server Acquia
  di produzione, prende il dump più recente di `~/prod/backups/` e lo copia in
  `storage/drupal-dump/`. Gira ovunque ci sia una chiave autorizzata su Acquia: oggi il Mac del
  dev, dopo questo ticket anche UAT.
- Su UAT il container `mysql-sardegnasentieri-dump` (oc:8705) viene acceso, e un cron lancia ogni
  giorno, dopo che Acquia ha prodotto il dump, prima il download e poi la ricarica già esistente
  (`scripts/drupal-dump-reload.sh`).

Su Acquia di produzione i dump stanno in `~/prod/backups/`: ne restano 3, uno al giorno, generati
verso le 08:14 ora del server, di circa 1 GB compresso ciascuno
(`prod-sardegnasentieri-sardegnasentieriuyyrag83f9-AAAA-MM-GG.sql.gz`).

## Perché

Fino a oggi la copia esisteva solo in locale e si aggiornava a mano. Gli import futuri leggeranno
dalla copia SQL al posto delle API (oc:8705), e questo ha senso solo se la copia è aggiornata ogni
giorno: le API sono in tempo reale, una copia ferma no. UAT è il posto dove la copia deve vivere,
perché sta accanto a Forestas e da lì la raggiungeranno sia gli import sia il locale.

## Requisiti

- [ ] `scripts/drupal-dump-download.sh`, bash portabile (macOS bash 3.2 e Ubuntu), con
      `set -euo pipefail`:
  - [ ] si collega all'host SSH `prod.sardegnasentieri` (sovrascrivibile con una variabile
        d'ambiente); quale chiave usare lo decide il `~/.ssh/config` della macchina
  - [ ] elenca `~/prod/backups/` e sceglie il dump più recente in base alla data nel nome
  - [ ] se `storage/drupal-dump/` contiene già un dump con quella data, non scarica nulla e lo
        dice
  - [ ] esce con tre codici distinti: **0** dump nuovo scaricato, **2** nessun dump nuovo (caso
        normale, non un errore), **1** errore
  - [ ] scarica in un file temporaneo, verifica l'integrità con `gzip -t`, e solo allora gli dà
        il nome definitivo: un download interrotto non lascia mai un `.sql.gz` che la ricarica
        potrebbe prendere
  - [ ] dopo un download riuscito tiene in `storage/drupal-dump/` solo gli ultimi 2 dump per data
        nel nome (`.sql` o `.sql.gz`), e cancella i più vecchi
  - [ ] sul server Acquia esegue solo comandi di lettura: l'unico comando remoto è `ls` su
        `~/prod/backups/`, più lo `scp` dal server alla macchina locale. Nessun `rm`, `mv` o altra
        scrittura remota; la pulizia degli ultimi 2 dump riguarda solo la cartella locale.
        Verificato in review con un `grep` sui comandi `ssh`/`scp` dello script
  - [ ] si ferma con un messaggio chiaro se la connessione fallisce o se sul server non c'è nessun
        dump
- [ ] Download e ricarica restano due script separati: il cron lancia la ricarica solo se il
      download esce con 0
- [ ] Messa in opera su UAT:
  - [x] chiave dedicata `/root/.ssh/id_rsa_acquia_uat` (RSA 4096, senza passphrase) creata su UAT
        e autorizzata su Acquia (fingerprint MD5 `2f:66:32:e9:4a:84:a7:f6:cd:71:24:f3:6c:44:1e:73`)
  - [x] porta 22 in uscita verso `18.201.110.246` (Acquia) aperta nel firewall Hetzner di UAT;
        verificato con `ls ~/prod/backups` da UAT
  - [ ] voce `Host prod.sardegnasentieri` nel `~/.ssh/config` di `root` su UAT, con la chiave
        dedicata
  - [ ] cartella `storage/drupal-dump/` creata nel repo di UAT
  - [ ] container `mysql-sardegnasentieri-dump` acceso dalla root del repo, sulla stessa rete
        Docker di `php-forestasuat` (`forestas_default`), con la porta solo su `127.0.0.1:3307`
  - [ ] primo download e prima ricarica lanciati a mano, e verifica che `php-forestasuat` legga la
        copia (`select 1` sulla connessione `sardegnasentieri`)
  - [ ] riga nel crontab di `root` alle **10:00 UTC** (Acquia e UAT sono entrambi in UTC, il dump
        nasce verso le 08:14): download, e ricarica solo se il download esce con 0
  - [ ] output di download e ricarica in un log dedicato, `storage/logs/drupal-dump-sync.log`, con
        data e ora all'inizio di ogni giro
  - [ ] la riga del cron si prova lanciandola così com'è, con l'ambiente minimo di cron
- [ ] `sardegnasentieri-dump.compose.yml` con `restart: unless-stopped`: la copia riparte dopo un
      riavvio di UAT, ma resta spenta se fermata a mano
- [ ] `docs/howto/copia-database-drupal.md` aggiornato: come si scarica il dump da produzione, chi
      può farlo (solo chi ha una chiave autorizzata su Acquia), la riga del cron di UAT, dove sta
      e come si legge il log, cosa fare se l'IP di Acquia cambia (regola del firewall Hetzner)
- [ ] Pagina di conoscenza `docs/knowledge/copia-database-drupal.md` aggiornata con il perché
      delle scelte (due script, ultimi 2 dump, file temporaneo)

## Rischi

- **Ricarica inutile nei giorni senza dump nuovo** — con un semplice `download && reload`, un
  download che non trova nulla di nuovo farebbe ricaricare lo stesso dump, lasciando la copia
  vuota per alcuni minuti. Mitigato con il codice di uscita 2 e la ricarica solo dopo lo 0; cron
  alle 10:00 UTC, quasi due ore dopo la generazione su Acquia.
- **Copia spenta dopo un riavvio** — il compose della copia non aveva `restart`, mentre i
  container di UAT hanno `restart: always`. Mitigato con `restart: unless-stopped`.
- **Copia ferma senza che nessuno se ne accorga** — mitigato con un log dedicato,
  `storage/logs/drupal-dump-sync.log`, letto dal dev e dalle sessioni di lavoro.
- **La chiave su UAT apre una shell completa su Acquia** — Acquia non permette chiavi limitate
  alla lettura. Mitigato con una chiave dedicata a UAT, revocabile da sola senza toccare quella
  del dev.
- **L'IP di Acquia può cambiare** — la porta 22 in uscita è aperta solo verso `18.201.110.246`. Se
  Acquia sposta il sito, il download va in timeout e il log lo mostra: si aggiorna la regola del
  firewall Hetzner.

Considerati ipotetici e non mitigati: `gzip -t` che passa su un dump interrotto a metà; Acquia che
cambia percorso o nome dei file; un backup on-demand con la stessa data; la copia rimossa da un
`--remove-orphans`; due lanci contemporanei; la pulizia che cancella file con date future; file
temporanei rimasti dopo un'interruzione; la ricarica non atomica (oggi nessuno legge dalla copia);
un danno massivo su Drupal che, dopo 2 giorni di pulizia su UAT e 3 su Acquia, non si recupera
più.

## Out of scope

- Scaricamento della copia da UAT al locale
- Verifica del tunnel SSH dal locale verso la copia di UAT
- Password della copia su UAT diverse da quelle nel compose
- Spostare gli import attuali dalle API all'SQL
- L'import via API che porta in Forestas anche i sentieri in bozza

## Moduli toccati

Tutto nel repo principale `forestas`, nessuna modifica a `wm-package`.

| File o risorsa | Azione |
|---|---|
| `scripts/drupal-dump-download.sh` | nuovo |
| `scripts/drupal-dump-sync.sh` | nuovo: il giro giornaliero chiamato dal cron (intestazione nel log, download, ricarica solo con lo 0) |
| `sardegnasentieri-dump.compose.yml` | modifica: `restart: unless-stopped` |
| `docs/howto/copia-database-drupal.md` | modifica |
| `docs/knowledge/copia-database-drupal.md` | modifica |
| UAT: `~/.ssh/config` e chiave di `root`, `storage/drupal-dump/`, container `mysql-sardegnasentieri-dump`, crontab di `root` | configurazione sul server, non versionata |
| Firewall Hetzner di UAT | regola in uscita TCP 22 verso `18.201.110.246` (fatta dal dev) |
