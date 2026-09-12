# Forestas — CLAUDE.md

Backend Laravel per FoReSTAS (Sardegna). Stack: Laravel 12, PHP 8.4 nel container (`composer.json`
richiede `^8.2`), PostgreSQL + PostGIS, Nova 5.7.6, Elasticsearch 8, Redis, Horizon.

## Regole che precedono tutte le altre

**Non eseguire mai `git commit` senza istruzione esplicita dell'utente.** Implementa, testa, poi
fermati: è l'utente che controlla il codice e committa. Vale anche per i subagent, che vanno
istruiti esplicitamente a non committare.

**Il DB reale contiene dati importati da processi lunghi: distruggerli è inaccettabile.** Prima di
lanciare la suite va verificato l'isolamento; se non è verificabile, non si lancia e si chiede
consenso. Le condizioni da controllare sono in [.claude/rules/test.md](.claude/rules/test.md), e
valgono anche per i subagent.

**Non ruotare mai `JWT_SECRET` per revocare un token.** Sembra la soluzione ovvia, ma invalida i
token di *tutti* gli utenti della piattaforma, non solo del client SUS. Un singolo token si revoca
con `JWTAuth::invalidate()` (snippet qui sotto).

## Comandi

```bash
composer format                                  # formattazione codice
composer dev                                     # ambiente locale (serve + horizon + pail + vite)
vendor/bin/pest                                  # test — leggi prima .claude/rules/test.md
vendor/bin/pest --filter=<nome-test>
vendor/bin/phpstan analyse                       # livello 5, baseline in phpstan-baseline.neon

# i container sono <servizio>-forestas: il suffisso viene da APP_NAME nel .env
docker exec -it php-forestas bash
docker exec -it php-forestas php artisan <comando>

php artisan vendor:publish --tag=wm-package-migrations   # migrazioni del package

# Revocare il token del client SUS — NON ruotare JWT_SECRET
docker exec -it php-forestas php artisan tinker --execute="
\Tymon\JWTAuth\Facades\JWTAuth::setToken('<token-da-revocare>')->invalidate(true);
"
```

## Regole del repo

- **Documentazione, commenti e messaggi di commit in italiano.** I termini tecnici restano in
  inglese.
- **Le Resource Nova del progetto estendono quelle del package**, non le duplicano.
- **Quando modifichi il `wm-package`, ricorda che è condiviso fra progetti**: è un submodule
  (`wm-package/`), ha un repo e un `CLAUDE.md` propri. Un fatto che vale per chiunque monti il
  package si documenta lì, non qui.
- **Forestas importa da Sardegna Sentieri, non da GeoHub**: il flusso GeoHub del package non è
  usato qui — vedi le trappole sull'import.

## Trappole

Regole path-scoped, si caricano quando tocchi i file corrispondenti:

| Soggetto | Dove |
|---|---|
| Import da Sardegna Sentieri, `--reset`, troncamento notturno | [.claude/rules/import-sardegna-sentieri.md](.claude/rules/import-sardegna-sentieri.md) |
| Nova: gate, menu, trait, policy | [.claude/rules/nova.md](.claude/rules/nova.md) |
| Test e isolamento del database | [.claude/rules/test.md](.claude/rules/test.md) |
| Deploy, configurazione congelata, CI, licenza Nova | [.claude/rules/deploy-e-configurazione.md](.claude/rules/deploy-e-configurazione.md) |

Le trappole del dominio Catasto Sentieri stanno nel package: sezione «Trappole» di
`wm-package/docs/resources/TrailRegistry.md`.

## Conoscenza

| Argomento | Cosa copre | Pagina |
|---|---|---|
| Catasto Sentieri su Forestas | import come sorgente del codice, valori di configurazione di questo shard, cifre di go-live | [docs/knowledge/catasto-sentieri.md](docs/knowledge/catasto-sentieri.md) |
| Gate di pubblicazione migrazioni | `publish-missing-migrations` in CI, stub dei domini opzionali | [docs/knowledge/gate-pubblicazione-migrazioni.md](docs/knowledge/gate-pubblicazione-migrazioni.md) |
| Il client SUS | cosa può raggiungere, creazione e rotazione, revoca dei token | [docs/knowledge/client-sus.md](docs/knowledge/client-sus.md) |
| Identifier di `TaxonomyWhere` | da dove deriva, dove vivono i suoi test | [docs/knowledge/import-taxonomy-where.md](docs/knowledge/import-taxonomy-where.md) |

Il dominio del Catasto Sentieri — tabelle, stati, service, comando, interfaccia — è documentato nel
package: `wm-package/docs/resources/TrailRegistry.md`. Le pagine qui coprono solo la
customizzazione di Forestas.

## Procedure

| Cosa devi fare | Procedura |
|---|---|
| Installare il progetto da zero | [docs/howto/setup-progetto.md](docs/howto/setup-progetto.md) |
| Creare o ruotare il client SUS | [docs/howto/creazione-client-sus.md](docs/howto/creazione-client-sus.md) |

## Ruoli

`Administrator` (accesso completo a Nova, gestione utenti e app), `Editor` (contenuti), `Validator`
(validazione UGC), `Contributor`, `Guest` (sola lettura, niente Nova), `Sus` (client programmatico,
niente Nova). Il sistema usa `spatie/laravel-permission` tramite il package.
