> Ticket: oc:8711

# Notes — Container Docker custom con lo stack completo per WordPress e installazione iniziale (parte `forestas`)

## Divergenze dal piano, task per task

### Task 1 submodule

`git submodule add -b <branch feature>` aveva scritto in `.gitmodules` la riga
`branch = feature/oc-8711-…`: tolta, come per `wm-package` il puntatore vale per commit. Su
indicazione del dev, prima del puntatore il branch feature di `wp-forestas` è stato chiuso con uno
squash su `develop` e `main` (`b648d38`); il submodule punta a quel commit. Il branch feature resta
sul remoto finché un amministratore non imposta `main` come branch predefinito di `wp-forestas`
(con permesso `WRITE` non si può, e GitHub non cancella il branch predefinito).

## Bug trovati

- **MariaDB inizializzata senza password** quando lo shard parte prima che esista
  `wp-forestas/.env`: trovato al Task 2 (passo 3, poi passo 4 con `Access denied`), corretto in
  `wp-forestas` (vedi le sue note). Il volume locale `mariadb-forestas` rimasto senza password è
  stato cancellato.

## Decisioni

- In locale, durante le prove, WordPress dello shard è stato pubblicato su `WP_PORT=8091` perché
  la 8090 era occupata dall'istanza di prova di `wp-forestas`.
- `wordpress-up.sh` con lo shard locale stampa un avviso di Compose sul container orfano
  `mysql-sardegnasentieri-dump` (stesso progetto compose, file diverso): innocuo, ed è il motivo per
  cui lo script non usa mai `--remove-orphans`.

## Follow-up

- Un amministratore di GitHub deve impostare `main` come branch predefinito di `wp-forestas` e
  cancellare il branch feature.
- Prima messa in opera su UAT seguendo `docs/howto/messa-in-opera-wordpress-uat.md` (DNS, `.env`,
  virtual host, certificato): la fa il team.
