# Mettere in opera WordPress su UAT

> Ticket: oc:8711, oc:8717. Cos'è e come funziona l'ambiente: README di
> [`wp-forestas`](https://github.com/webmappsrl/wp-forestas).

Questa pagina copre ciò che riguarda l'host e lo shard (DNS, `.env`, Apache, certificato). Zip
commerciali, chiavi di licenza e configurazione del sito sono di `wp-forestas`: li descrive il
[README di `wp-forestas`, «Messa in opera su UAT»](https://github.com/webmappsrl/wp-forestas#messa-in-opera-su-uat).

WordPress gira sull'host di UAT come parte dello shard, nei container `wordpress-forestasuat` e
`mariadb-forestasuat`, ed è pubblicato su `https://wp.forestas.uat.maphub.it` dall'Apache
dell'host. Questi passi si fanno **una volta sola**, a mano, dal team.

## 1. DNS

Record `A` di `wp.forestas.uat.maphub.it` verso lo stesso IP di `forestas.uat.maphub.it`.
Verifica: `dig +short wp.forestas.uat.maphub.it` restituisce l'IP dell'host.

## 2. Codice e configurazione

> Finché `wp-forestas/.env` non esiste, `wordpress-forestasuat` e `mariadb-forestasuat` si
> fermano subito con un messaggio nei log e Docker li riavvia a intervalli crescenti: il resto
> dello shard funziona normalmente. MariaDB, senza password, non inizializza il suo volume, quindi
> creare il `.env` dopo il primo aggiornamento non lascia nulla da sistemare.

Sull'host, in `/var/www/html/forestas`, dopo l'aggiornamento abituale (che fa già
`git submodule update --init --recursive`):

```bash
ls wp-forestas/compose.yml                       # il submodule c'è
cp wp-forestas/.env-example wp-forestas/.env     # poi compila:
#   WP_PORT=8090        (verifica che sia libera: ss -ltn | grep :8090 non deve stampare nulla)
#   WP_URL=https://wp.forestas.uat.maphub.it
#   password del database e dell'amministratore: valori robusti, solo in questo file
```

Prima del primo avvio copia gli zip commerciali e aggiungi le chiavi di licenza al
`wp-forestas/.env`, come dal [README di `wp-forestas`, «Messa in opera su UAT»](https://github.com/webmappsrl/wp-forestas#messa-in-opera-su-uat).

## 3. Primo avvio

```bash
scripts/wordpress-up.sh
docker logs -f wordpress-forestasuat     # attendi «WordPress pronto»
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: wp.forestas.uat.maphub.it' -H 'X-Forwarded-Proto: https' http://127.0.0.1:8090/   # → 200
```

## 4. Virtual host e certificato

I file di riferimento sono in `wp-forestas/docker/uat/`.

```bash
sudo cp wp-forestas/docker/uat/wp.forestas.uat.maphub.it.conf /etc/apache2/sites-available/
sudo a2ensite wp.forestas.uat.maphub.it
sudo apache2ctl configtest && sudo systemctl reload apache2

sudo certbot certonly --webroot -w /var/www/letsencrypt -d wp.forestas.uat.maphub.it

sudo cp wp-forestas/docker/uat/wp.forestas.uat.maphub.it-le-ssl.conf /etc/apache2/sites-available/
sudo a2ensite wp.forestas.uat.maphub.it-le-ssl
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Se `WP_PORT` non è `8090`, cambia la porta di `ProxyPass` nel file HTTPS.

## 5. Verifica

- `https://wp.forestas.uat.maphub.it` risponde con certificato valido e mostra il sito.
- `https://wp.forestas.uat.maphub.it/wp-admin` accetta l'utente amministratore del `.env`.
- Aspetto → Temi mostra `forestas-child` attivo, con Impreza come tema padre; con le chiavi nel
  `.env` la licenza di Impreza e la registrazione di WPML si riattivano da sole.

## Aggiornamenti successivi

Nella procedura abituale di aggiornamento di UAT, **dopo** `git submodule update` e i passi nel
container, lancia sull'host:

```bash
scripts/wordpress-up.sh
```

Senza questo passo i container di WordPress restano quelli vecchi, e un errore nel compose incluso
emergerebbe solo al primo riavvio della macchina, bloccando l'intero shard.

Se il nuovo puntatore porta una configurazione del sito diversa (`wp-forestas/config/`), su un sito
esistente non si applica da sola: `wp-forestas/bin/wordpress-config.sh apply` mostra le differenze,
`apply --conferma` le applica dopo un backup. Dettagli nel [README di `wp-forestas`, «Messa in opera su UAT»](https://github.com/webmappsrl/wp-forestas#messa-in-opera-su-uat).

## Azzerare WordPress

```bash
scripts/wordpress-reset.sh --conferma
```

Cancella database e file di WordPress e lo ricrea da zero; non tocca gli altri volumi. Il sito
ricreato ha già temi, plugin, licenze e configurazione, a patto che sull'host ci siano gli zip
commerciali e le chiavi nel `wp-forestas/.env` (messa in opera nel README di `wp-forestas`): li
rimette `wp-forestas` dagli zip, dal `.env` e da `config/`. Si perdono contenuti e uploads. Oggi si lancia solo a mano: il ciclo
giornaliero su UAT non è ancora attivo. **In produzione non va usato**.
