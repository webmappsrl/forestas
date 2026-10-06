> Ticket: oc:8711

# Container Docker custom con lo stack completo per WordPress e installazione iniziale — parte `forestas`

L'ambiente WordPress vive nel repo `wp-forestas` (overview principale:
`wp-forestas/docs/features/8711-container-docker-custom-con-lo-stack-completo-per-wordpress-e-installazione-iniziale/overview.md`).
Qui c'è solo ciò che cambia nello shard.

## Cosa cambia

WordPress diventa parte dello shard `forestas`:

- `wp-forestas` entra come submodule in `forestas/wp-forestas/`, come già `wm-package`;
- tutti i file compose da cui si avvia lo shard — `local.compose.yml` (locale), `develop.compose.yml`
  (UAT) e `compose.yml` (futura produzione) — includono i servizi di `wp-forestas/compose.yml`: un
  solo `docker compose up` avvia shard e WordPress, sulla stessa rete;
- uno script sull'host aggiorna i soli container di WordPress, deducendo da Docker con quali file
  compose gira lo shard;
- uno script sull'host azzera WordPress e lo ricrea da zero, toccando solo i suoi volumi;
- su UAT WordPress è pubblicato su `https://wp.forestas.uat.maphub.it`, dietro l'Apache dell'host
  che già serve `forestas.uat.maphub.it`.

## Perché

La macchina che tiene su lo shard deve tenere su anche il WordPress che sostituisce la parte
editoriale del Drupal di Sardegna Sentieri. Su UAT il sito va reso visibile per essere visionato.

L'aggiornamento di UAT oggi non lancia mai `docker compose`: `scripts/deploy_dev.sh` gira dentro
il container PHP e fa solo submodule, `composer install`, `migrate` e pulizia della cache. Senza un
passo dedicato i container di WordPress non si aggiornerebbero mai, e un errore nel compose incluso
resterebbe nascosto fino al primo riavvio della macchina, bloccando allora l'intero shard.

## Requisiti

- [ ] `.gitmodules` con il submodule `wp-forestas` → `https://github.com/webmappsrl/wp-forestas.git`
- [ ] `local.compose.yml`, `develop.compose.yml` e `compose.yml` includono `wp-forestas/compose.yml`
- [ ] Il `.env` di `forestas` non riceve variabili di WordPress: stanno nel `.env` di `wp-forestas`
- [ ] `scripts/wordpress-up.sh`, da lanciare sull'host: legge `APP_NAME` dal `.env`, ricava i file
      compose dello shard dalla label `com.docker.compose.project.config_files` del container
      `php-${APP_NAME}`, lancia `config --quiet` e poi `up -d --build wordpress mariadb` con quei
      file. Se il container dello shard non è avviato si ferma con un messaggio chiaro. Nessuna
      variabile nuova: funziona uguale in locale, su UAT e in futura produzione
- [ ] `scripts/wordpress-reset.sh`, da lanciare sull'host: ferma `wordpress` e `mariadb`, cancella i
      loro due volumi e solo quelli, poi rilancia `scripts/wordpress-up.sh`. Non va usato in
      produzione, dove i dati di WordPress sono permanenti
- [ ] La procedura di aggiornamento di UAT aggiunge, dopo `git submodule update`, il lancio di
      `scripts/wordpress-up.sh` sull'host
- [ ] Procedura in `docs/howto/` per la prima messa in opera su UAT: record DNS di
      `wp.forestas.uat.maphub.it`, virtual host sull'Apache dell'host, certificato con `certbot`,
      `.env` di `wp-forestas`, zip di Impreza, primo avvio con `scripts/wordpress-up.sh`
- [ ] Gli stack esistenti non cambiano comportamento: i container dello shard mantengono nomi,
      porte e volumi

## Rischi

- **Un errore nell'`include` blocca l'avvio dell'intero shard**, su UAT compreso: se
  `wp-forestas/compose.yml` non è valido o il submodule non è inizializzato, `docker compose up` di
  `forestas` fallisce. Mitigazione: `scripts/wordpress-up.sh` valida il compose (`config --quiet`)
  a ogni aggiornamento, così l'errore emerge subito e non al primo riavvio; la procedura di
  aggiornamento di UAT inizializza già i submodule.
- **Su UAT si scrive solo seguendo la procedura del team.** DNS, virtual host, certificato e `.env`
  di UAT li applica il team a mano, seguendo la procedura in `docs/howto/`.

## Out of scope

- Produzione: oggi `forestas` ha solo UAT, e il workflow `prod-deploy.yml` non porta da nessuna
  parte. La procedura di produzione, quando esisterà, userà lo stesso `scripts/wordpress-up.sh`.
- Ciclo giornaliero di cancellazione e ricreazione di WordPress e import dei contenuti: ticket
  successivo, che userà `scripts/wordpress-reset.sh`.
- Modifiche alle API dello shard per il plugin `wp-geohub`.

## Moduli toccati

Repo `forestas`:

- `.gitmodules`, `wp-forestas/` (puntatore del submodule)
- `local.compose.yml`, `develop.compose.yml`, `compose.yml` — `include` dei servizi WordPress
- `scripts/wordpress-up.sh` — aggiornamento dei soli container WordPress
- `scripts/wordpress-reset.sh` — azzeramento e ricreazione di WordPress
- `docs/howto/messa-in-opera-wordpress-uat.md` — procedura per UAT
- `CLAUDE.md` — riga nella tabella delle procedure
