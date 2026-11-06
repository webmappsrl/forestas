# Mettere in opera WordPress su dev

> Ticket: oc:8947. La procedura di riferimento è quella di UAT,
> [messa-in-opera-wordpress-uat.md](messa-in-opera-wordpress-uat.md): qui solo i valori di dev e
> i passi che cambiano. Cos'è e come funziona l'ambiente: README di
> [`wp-forestas`](https://github.com/webmappsrl/wp-forestas).

Il WordPress di dev serve per provare senza toccare UAT, che guarda il cliente: si può rompere,
fermare e azzerare. Gira sull'host di dev come parte dello shard, nei container
`wordpress-forestasdev` e `mariadb-forestasdev`, ed è pubblicato su
`https://wp.forestas.dev.maphub.it` dall'Apache dell'host. Non ha licenze di Impreza e WPML, e
quindi nemmeno la modalità manutenzione che su UAT nasconde il sito: chi apre l'indirizzo lo vede.

| | Valore |
|---|---|
| Host | `DEV-forestas` (Hetzner), lo stesso di `forestas.dev.maphub.it`, `ssh dev.forestas` |
| Shard | `/var/www/html/forestas`, `APP_NAME` che dà il suffisso `forestasdev` |
| Cartella dei comandi | i comandi di questa pagina si lanciano da `/var/www/html/forestas` |
| Porta del container | `127.0.0.1:8090` (`WP_PORT`) |
| Copia Drupal | quella di UAT, dal tunnel `tunnel-sardegnasentieri-dump` su `172.24.0.1:3307` ([copia-database-drupal.md](copia-database-drupal.md)) |
| Cron di `allinea` | crontab di `root`, alle 05:00 UTC (l'host è in UTC: 06:00 o 07:00 in Italia) |

## 1. DNS

Record `A` di `wp.forestas.dev.maphub.it` verso lo stesso IP di `forestas.dev.maphub.it`, nella zona
`maphub.it` sul pannello di Hosting Solutions (accanto a `forestas.dev` e `wp.forestas.uat`). Il
wildcard `*.dev` della zona punta a un altro server e non copre questo nome, perché `forestas.dev`
ha un record suo. Verifica: `dig +short wp.forestas.dev.maphub.it` restituisce lo stesso indirizzo
di `dig +short forestas.dev.maphub.it`.

## 2. Zip e `.env`

Gli zip commerciali (`impreza.zip` in `wp-forestas/docker/themes/`, i due di WPML in
`wp-forestas/docker/plugins/`) si copiano dal locale con `scp`: devono avere le versioni di
`wp-forestas/config/versioni.json`, altrimenti l'apply automatico si ferma.

`wp-forestas/.env` si crea da `wp-forestas/.env-example`, con `chmod 600`. Rispetto a UAT:

- `WP_URL=https://wp.forestas.dev.maphub.it`, `WP_PORT=8090`;
- `WPML_SITE_KEY`, `IMPREZA_LICENSE_SECRET`, `IMPREZA_MAINTENANCE_KEY` **vuote**: dev non si
  registra presso WPML e UpSolution (oc:8947);
- `DRUPAL_SYNC=on`, `DRUPAL_DB_HOST=172.24.0.1`, `DRUPAL_DB_PORT=3307`, utente e password della
  copia uguali a quelli che usa `php-forestasdev`.

Le password del database e dell'amministratore si generano sull'host (`openssl rand -hex 24`) e
restano solo nel `.env`.

## 3. Primo avvio

Come su UAT: `scripts/wordpress-up.sh`, poi `docker logs -f wordpress-forestasdev` fino a
«WordPress pronto». Nei log, senza chiavi, compare «IMPREZA_LICENSE_SECRET vuoto: licenza di
Impreza non gestita dallo script»: è atteso.

## 4. Virtual host e certificato

I file di riferimento sono in `wp-forestas/docker/dev/`. Sull'host di dev la cartella della
challenge non esiste di partenza: va creata. **Prima il solo virtual host HTTP, poi il certificato,
poi quello HTTPS**: abilitato senza certificato, quello HTTPS fa fallire `configtest`. Il
certificato si chiede con `certonly --webroot`, mai con `certbot --apache`, che scriverebbe un suo
`-le-ssl.conf` con lo stesso nome di quello versionato.

```bash
mkdir -p /var/www/letsencrypt
cp wp-forestas/docker/dev/wp.forestas.dev.maphub.it.conf /etc/apache2/sites-available/
a2ensite wp.forestas.dev.maphub.it
apache2ctl configtest && systemctl reload apache2

certbot certonly --webroot -w /var/www/letsencrypt -d wp.forestas.dev.maphub.it

cp wp-forestas/docker/dev/wp.forestas.dev.maphub.it-le-ssl.conf /etc/apache2/sites-available/
a2ensite wp.forestas.dev.maphub.it-le-ssl
apache2ctl configtest && systemctl reload apache2
```

Apache serve anche il backend di dev (`forestas.dev.maphub.it`): dopo ogni reload,
`curl -s -o /dev/null -w '%{http_code}' https://forestas.dev.maphub.it/` deve dare ancora `302`.

## 5. Contenuti

L'init applica `config/` da solo. Pagine, news, eventi e «da sapere» si importano come su UAT
(`wp-forestas/docs/howto/ricreare-wordpress-da-zero.md`, passi 3 e 4):

```bash
wp-forestas/bin/wordpress-config.sh pagine              # anteprima
wp-forestas/bin/wordpress-config.sh pagine --conferma
wp-forestas/bin/wordpress-config.sh applica             # i menu da ricollegare
wp-forestas/bin/wordpress-config.sh applica --conferma
wp-forestas/bin/wordpress-config.sh allinea             # deve chiudere con «STATO allineato»
```

Il primo giro scarica i media dal sito Drupal. Come su UAT, i giri a mano non vanno lanciati durante
il reload della copia di UAT (alle 10:00 UTC, circa un quarto d'ora): il controllo di integrità li
ferma senza scrivere. Due media della news 4606 risultano `MANCANTE` a ogni giro: danno 404 anche
sul sito Drupal pubblico.

## 6. Verifica

- `https://wp.forestas.dev.maphub.it` risponde con certificato valido e mostra header e footer di
  Sardegna Sentieri (child theme `forestas-child` attivo, `config/` applicata), senza pagina di
  manutenzione;
- `/news/`, `/da-sapere/` e `/en/news/` rispondono `200`; `/en/` rimanda alla home, come su UAT;
- `/wp-admin` accetta `WP_ADMIN_USER` con la password del `wp-forestas/.env`;
- `certbot renew --dry-run --cert-name wp.forestas.dev.maphub.it` riesce.

Il resto è come la verifica di UAT
([messa-in-opera-wordpress-uat.md](messa-in-opera-wordpress-uat.md), «5. Verifica»), tranne le
licenze, che su dev non ci sono.

## 7. Cron

Nel crontab di `root` dell'host va la riga per dev di `wp-forestas/cron/allinea.crontab`, alle
05:00 UTC. Di giorno su dev `pagine` e `applica` si lanciano a mano, e prendono un blocco: con un
giro a mano in corso, quello del cron esce con «un'altra sincronizzazione» e chiude non allineato.

## Aggiornamenti successivi

I file versionati arrivano sull'host solo con il puntatore di `wp-forestas` in `forestas`: un
commit in `wp-forestas` non basta, serve anche l'aggiornamento del puntatore su `develop` di
`forestas`. Sull'host non si fanno commit né `git pull` dentro il submodule. Dopo l'aggiornamento
dello shard, `scripts/wordpress-up.sh`, come su UAT.

Per azzerare: `scripts/wordpress-reset.sh --conferma`, poi il passo 5 (o si aspetta il giro del
cron) e la verifica. Su dev si può fare quando serve.
