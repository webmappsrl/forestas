# Messa in sicurezza PHP2Shell su forestas

## Come funziona oggi

La sequenza (fotografa → raccogli evidenze → patcha), le trappole del rebuild e i criteri per i
limiti sono nella procedura condivisa: `wm-package/docs/howto/messa-in-sicurezza-php-shard.md`.
Qui solo ciò che è specifico di forestas:

- **Il Dockerfile sorgente dell'immagine `wm-phpfpm:8.4` di questo shard è
  `docker/configs/phpfpm/Dockerfile`**, buildato dai compose del repo (verificato con `php -m`
  sul container UAT: profilo estensioni corrispondente — niente redis/imagick/apcu, coerente con
  `REDIS_CLIENT=predis` e `CACHE_STORE=database`). Anche wm-package ha un Dockerfile PHP (base
  mobile `php:8.4-fpm`), ma serve allo sviluppo del package: non è la fonte dell'immagine di
  forestas, non bumparlo pensando di aggiornare lo shard.
- Versioni e limiti correnti si leggono nei file, non qui: `docker/configs/phpfpm/Dockerfile`
  (pin, da aggiornare con un commit a ogni CVE del motore) e `www.conf` (pool FPM). Alzare un
  limite di upload è una riga di `www.conf`.
- I limiti del pool valgono solo dove gira FPM (compose base e develop): `php artisan serve` di
  `local.compose.yml` legge `php.ini`, non il pool.

## Perché così

- **Limiti ridotti dai 1024M storici** (oc:8833): misura sugli storage di locale e UAT, massimo
  reale 16,7MB; flussi previsti immagini e GPX.
- **`session.upload_progress` spenta** (oc:8833): oltre ai motivi nel howto del package, su
  forestas è verificato che nessuna dipendenza la usa (grep su `vendor/`).

## Come ci siamo arrivati

- **`LimitRequestBody` finito sul vhost dell'host** (oc:8833, scartata): l'Apache 2.4.41 degli
  host non lo applica alle richieste proxate — un limite che non limita. Contenimento spostato
  sul pool FPM.
- **Copia versionata del vhost nel repo** (oc:8833, scartata): il repo è pubblico, avrebbe
  pubblicato la mappa interna del server (porte, proxy verso le console).
