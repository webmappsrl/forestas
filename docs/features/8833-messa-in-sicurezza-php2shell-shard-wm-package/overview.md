> Ticket: oc:8833

# Messa in sicurezza PHP2Shell (CVE-2026-109618/109619) sugli shard wm-package

## Cosa cambia

Forestas chiude le due CVE PHP2Shell aggiornando PHP alla versione corretta e, nello stesso
giro, riduce in modo permanente la superficie d'attacco della configurazione PHP. Forestas è il
caso d'uso di riferimento: la procedura risultante viene documentata in `wm-package` e
replicata dal team sugli altri shard.

In ordine di esecuzione:

0. **Snapshot Hetzner dell'host UAT** (fatto dal dev, avviato il 22/10): congela l'evidenza
   forense prima di ogni intervento ed è il punto di ritorno operativo se l'aggiornamento
   rompesse qualcosa. Non è uno stato «fidato» a cui tornare in caso di compromissione
   accertata: contiene anche l'eventuale compromissione.
1. **Raccolta evidenze su UAT, prima di ogni rebuild** (sola lettura): log Apache dell'host,
   processi e file recenti nei container PHP **e WordPress**. Il rebuild azzera il filesystem
   dei container: l'ordine evidenze → rebuild è vincolante, e va scritto così anche nella
   procedura per gli altri shard.
2. **Aggiornamento immagine PHP**: `docker/configs/phpfpm/Dockerfile` passa da
   `FROM php:8.4.3-fpm` a `FROM php:8.4.27-fpm` (immagine ufficiale disponibile, verificato su
   Docker Hub il 22/10). Prima del rebuild su UAT: `docker tag` dell'immagine corrente come
   `wm-phpfpm:8.4-pre-8833` (l'immagine in esercizio non è riproducibile: provenienza
   anteriore al repo). Il Dockerfile di forestas è la fonte confermata per questo shard: il
   profilo estensioni del container UAT corrisponde al suo (verificato con `php -m`: niente
   redis/imagick/apcu, che infatti non servono — `REDIS_CLIENT=predis`, `CACHE_STORE=database`).
3. **Pulizia permanente della configurazione FPM** (`docker/configs/phpfpm/www.conf`):
   - `php_admin_flag[session.upload_progress.enabled] = off` — feature non usata (verificato:
     nessun uso in `vendor/`), vettore di una delle due CVE;
   - `php_admin_value[upload_max_filesize] = 32M` e `php_admin_value[post_max_size] = 64M`
     (oggi entrambi 1024M): i flussi di upload sono immagini e GPX via API e media Nova,
     decine di MB al massimo. Riscontro nel piano: verifica del file più grosso oggi in
     storage/MinIO prima di applicare; se smentisce i valori, si ridecide col dev;
   - `memory_limit` resta 1024M: la mitigazione d'emergenza `-1` non si applica, a patch
     disponibile è solo dannosa.
4. **Rotazione precauzionale dei segreti economici da ruotare**, a prescindere dall'esito
   della verifica: token del client SUS (procedura esistente, `JWTAuth::invalidate`) e
   password DB. **Mai `JWT_SECRET`** (invaliderebbe i token di tutti gli utenti): resta fermo
   salvo evidenza di compromissione.
5. **Verifica di compromissione su UAT** (sola lettura, sulle evidenze raccolte al punto 1):
   pattern dell'exploit pubblico nei log (multipart anomali, OOM ripetuti), file e processi
   inattesi nei container PHP e WordPress. Esito scritto — pulito o con segnalazioni — nelle
   note del ticket. Un esito «pulito» non è una prova (i log sono alterabili da chi ha RCE):
   per questo la rotazione del punto 4 non dipende dall'esito.
6. **Procedura di replica per gli altri shard** in `wm-package`, che deve rendere espliciti:
   l'ordine snapshot → evidenze → patch; quale Dockerfile è la fonte dell'immagine in ogni
   shard (qui: quello di forestas; il Dockerfile mobile `php:8.4-fpm` di wm-package e il tag
   `wm-phpfpm:8.2.15-fpm` del suo compose sono altra cosa e vanno annotati); la trappola del
   rebuild con tag mobile senza `--pull` (no-op silenzioso); la verifica sempre con `php -v`
   nel container, mai con l'esito della build.

La parte WordPress (pin `php:8.3.36-apache`, `--pull` in `scripts/wordpress-up.sh`) è
descritta nell'overview di `wp-forestas`.

## Perché

Le CVE-2026-109618/109619 («PHP2Shell») sono RCE non autenticate nel motore PHP (CVSS 9.2),
pubbliche dal 21/10/2026 con exploit disponibile e sfruttamento osservato in rete prima del
rilascio della patch. La falla è nel motore, non nel codice applicativo: ogni shard è esposto a
prescindere dal proprio codice. L'UAT di forestas è vulnerabile oggi (PHP 8.4.15 nel container,
verificato il 22/10) ed era raggiungibile nella finestra di sfruttamento: per questo evidenze e
rotazione precauzionale fanno parte del lavoro, non solo il bump. Hetzner (mail 22/10) conferma
che su server dedicati l'installazione degli update è responsabilità nostra.

## Requisiti

- [ ] Snapshot Hetzner dell'host UAT esistente prima di ogni intervento (fatto dal dev)
- [ ] Evidenze raccolte da UAT (host + container PHP e WordPress) prima di ogni rebuild
- [ ] Immagine corrente taggata `wm-phpfpm:8.4-pre-8833` su UAT prima del rebuild
- [ ] `wm-phpfpm` ricostruita con PHP ≥ 8.4.27; `php -v` lo conferma in tutti i container PHP
      di forestas (locale e UAT)
- [ ] `session.upload_progress.enabled = off` effettivo nel pool FPM (verifica `php -i` da FPM)
- [ ] `upload_max_filesize = 32M` / `post_max_size = 64M` nel pool (salvo riscontro storage
      contrario, da ridecidere col dev); un upload rappresentativo del flusso reale va a buon
      fine
- [ ] Token client SUS ruotato e password DB UAT cambiata (rotazione precauzionale)
- [ ] Suite Pest verde nel container aggiornato (nel rispetto di `.claude/rules/test.md`)
- [ ] Verifica di compromissione eseguita sulle evidenze, esito scritto nelle note del ticket
- [ ] Procedura di replica per gli altri shard scritta in `wm-package` (contenuti al punto 6)
- [ ] Nessuna stringa visibile all'utente: lavoro infrastrutturale, i18n non toccata

## Rischi

Dalla challenge, con le decisioni prese:

- **Falso «pulito» nella verifica di compromissione** (log alterabili, evidenza distrutta dal
  rebuild): mitigato con snapshot + evidenze prima del rebuild, WordPress incluso, rotazione
  precauzionale non condizionata all'esito. Un'eventuale compromissione accertata è incident
  response: si scala al team, fuori ticket.
- **Rebuild non riproducibile / rollback assente**: mitigato con lo snapshot Hetzner e il tag
  `wm-phpfpm:8.4-pre-8833`. Il rollback logico (tornare a PHP vulnerabile) resta comunque
  inaccettabile: se 8.4.27 rompe qualcosa, la via è avanti (fix), non indietro.
- **Limiti upload troppo bassi rompono un flusso legittimo**: mitigato derivando i valori dai
  flussi dichiarati (immagini e GPX), dal riscontro su storage e dal test di upload nel done.
  Alzare il limite resta una riga in `www.conf`, documentata nella procedura.
- **UAT resta vulnerabile finché il team non applica**: il lavoro nel repo non chiude la
  finestra da solo; i criteri di done includono la verifica su UAT proprio per questo.

## Out of scope

- L'applicazione delle stesse modifiche sugli **altri shard**: la fa il team con la procedura
  in `wm-package` (questo ticket produce la procedura, non i deploy altrui).
- **Vhost Apache dell'host UAT**: niente copia nel repo (il repo è pubblico: pubblicherebbe la
  mappa interna del server) e niente `LimitRequestBody` (sull'Apache 2.4.41 dell'host non si
  applica alle richieste proxate: sarebbe un limite che non limita). Il contenimento è ai
  limiti FPM.
- **Aggiornamento dell'OS dell'host** (Ubuntu 20.04 senza Ubuntu Pro, fuori supporto):
  tracciato in **oc:8835**, con censimento degli host degli altri shard.
- **Unificazione dei Dockerfile** forestas/wm-package in un'immagine base condivisa: debito
  reale, annotato nella procedura, intervento strutturale da ticket a sé.
- CORS aperto e proxy verso Kibana/MinIO console nei vhost UAT: annotati, non si toccano qui.
- `JWT_SECRET`: non si ruota (regola di progetto), salvo evidenza di compromissione.
- Gestione di un'eventuale compromissione accertata (incident response): si segnala e si scala.

## Moduli toccati

| Repo | File |
|---|---|
| `forestas` | `docker/configs/phpfpm/Dockerfile` (pin 8.4.27), `docker/configs/phpfpm/www.conf` (upload_progress off, limiti upload) |
| `wp-forestas` | vedi il suo `docs/features/<slug>/overview.md` (pin 8.3.36, `--pull`) |
| `forestas` (script) | `scripts/wordpress-up.sh` (`--pull` nella build) |
| `wm-package` | solo documentazione: pagina con la procedura di replica per gli shard |
