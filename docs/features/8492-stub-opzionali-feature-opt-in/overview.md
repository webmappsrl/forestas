> Ticket: oc:8492

# Adozione del gate stub wm-package nella CI di forestas

Parte forestas del ticket oc:8492. Il meccanismo di opt-in vive in wm-package:
vedi `wm-package/docs/features/8492-stub-opzionali-feature-opt-in/overview.md`.

## Cosa cambia

La CI di forestas verifica che lo schema del database sia allineato agli stub di
migration di wm-package, come gia' fa maphub. Due righe in `run-tests.yml`, piu'
la configurazione che serve a far vedere il dominio `trail_registry` al gate e
alla suite di test.

## Perche'

Il gate `wm-package:publish-missing-migrations --dry-run` esiste dal ticket
oc:8218 ma vive in **un repo su 16**: maphub. Forestas ne e' privo. (Il conteggio
e' sui consumer reali, letti dal blocco `require` del loro `composer.json`.)

Questo rende inerte, proprio su forestas, la parte piu' importante del
meccanismo di opt-in: forestas e' il consumer che usera' il catasto, e quindi
l'unico che ha davvero bisogno di sapere se lo stub del dominio e' allineato al
suo schema. Senza gate, un disallineamento si scopre a runtime.

La ragione per farlo adesso e non "quando tocchera' a forestas" e' che un gate
attivo in un repo solo non e' uno standard, e' un'eccezione: senza un secondo
caso non diventa mai una pratica. Forestas diventa il precedente copiabile per
gli altri 14.

## Da cosa si parte — costo misurato

`wm-package:publish-missing-migrations --dry-run` eseguito sul container
`php-forestas` (DB locale, che e' un dump di produzione):

```
[dry-run] 1 stub non allineati al database:
  - create_users_table
      manca colonna users.balance
      manca colonna users.fiscal_code
      manca colonna users.app_id
```

**Due stub disallineati.** `create_users_table` (tre colonne) e
`zz_2026_07_27_000001_add_surname_to_users_table`, arrivato con un aggiornamento
del submodule durante questo stesso ciclo — segno che l'elenco non e' stabile e
va rimisurato al momento di intervenire. Non e' una bonifica, ma non e'
nemmeno meccanico: e' proprio il caso che `wm-package/CLAUDE.md` segnala come
insidioso nella sezione oc:8218 — «`publish-migration <stub>` non si ferma al
suffisso se contenuto diverso (es. `create_users_table`)». Forestas ha una sua
`create_users_table` con lo stesso suffisso ma contenuto diverso.

Due avvertenze su questa misura:

- **E' il DB locale, non la CI.** In CI il database nasce vuoto e viene
  costruito da `php artisan migrate` sulle migration committate di forestas: il
  risultato puo' differire. La misura vera la da' il gate acceso su una PR.
- **La correzione richiede una decisione.** Non si puo' pubblicare lo stub
  `create_users_table` su un DB dove `users` esiste gia'. Va capito se
  `balance`, `fiscal_code` e `app_id` servano a forestas o se il caso vada
  gestito altrimenti.

Questo va risolto **prima** di accendere il gate, altrimenti la prima PR nasce
rossa.

## Requisiti

- [ ] `create_users_table`: risolto il disallineamento delle tre colonne
      (`balance`, `fiscal_code`, `app_id`), con la scelta motivata in `notes.md`
- [ ] Step del gate in `.github/workflows/run-tests.yml`, nella stessa posizione
      di maphub — fra `Migrate` e `Clear the config cache` — con lo stesso nome
      di step, piu' `--with=trail_registry`
- [ ] `WM_TRAIL_REGISTRY_ENABLED=true` in `.env-deploy` (il file che la CI copia
      in `.env` alla riga 33): senza, `migrate` e il gate girano con la feature
      spenta
- [ ] `WM_TRAIL_REGISTRY_ENABLED` in `phpunit.xml`, accanto a `DB_DATABASE` e
      alle altre: `php artisan test` forza `APP_ENV=testing`, quindi Laravel
      carica `.env.testing` e **non** vede `.env-deploy`
- [ ] `WM_TRAIL_REGISTRY_ENABLED` documentato in `.env-example`
- [ ] Riga in `CLAUDE.md`, sezione "Feature disponibili"
- [ ] La pipeline resta verde sulla PR

## Lo step, verbatim da maphub

I due workflow sono identici riga per riga in quel tratto (`Generate key` →
`Migrate` → `Clear the config cache` → `Optimize` → `Laravel Tests`). Maphub
inserisce due righe fra `Migrate` e `Clear the config cache`
(`maphub/.github/workflows/run-tests.yml:50-51`):

```yaml
      - name: Check wm-package stub vs schema DB
        run: php artisan wm-package:publish-missing-migrations --with=trail_registry --dry-run
```

Unica differenza rispetto a maphub: `--with=trail_registry`. L'opzione non e'
necessaria (il flag in `.env-deploy` basta a far includere il dominio) ma rende
lo step autodocumentante — chi legge il workflow sa cosa quel controllo copre
senza aprire `.env-deploy`. E' ridondanza voluta e innocua: `--with` puo' solo
aggiungere domini alla verifica, mai toglierne.

**Perche' non rompe la pipeline prima di oc:8489.** Al merge di questo ticket il
dominio `trail_registry` e' dichiarato in `config/wm-package.php` ma non ha
ancora nessuno stub: il primo arriva con oc:8489. Il comando valida `--with`
contro le **chiavi dichiarate in config**, non contro l'esistenza della
sottocartella (requisito esplicito dell'overview di wm-package): un dominio
dichiarato e privo di stub e' legittimo e produce zero verifiche aggiuntive. Se
la validazione fosse sull'esistenza della cartella, questo step farebbe fallire
ogni PR di forestas fino al merge di oc:8489.

## Perche' il flag serve in due file diversi

| File | Chi lo legge | Serve a |
|---|---|---|
| `.env-deploy` | il workflow, via `cp .env-deploy .env` (riga 33) | far girare `migrate` e il gate con la feature accesa |
| `phpunit.xml` | `php artisan test` | far vedere alla suite route, modelli e Nova resource del dominio |
| `.env.testing` | i comandi artisan lanciati con `--env=testing` | far vedere il dominio anche fuori dalla suite, dove `phpunit.xml` non arriva |

Il secondo non e' opzionale nemmeno adesso che il catasto non esiste: dal
momento in cui oc:8489 aggiungera' i suoi test, senza quella riga girerebbero
con la feature spenta e fallirebbero in modo poco leggibile.

`phpunit.xml` e' preferito a `.env.testing` perche' e' il file che dichiara le
condizioni della suite — quello che si guarda quando un test si comporta in modo
inatteso — e ha la precedenza sui file `.env`, quindi il valore e' garantito
indipendentemente dall'ambiente locale di chi lancia i test.

## Rischi

*(Completata dopo la challenge adversariale.)*

- **Il gate puo' nascere rosso per motivi diversi da quello misurato.** Il
  `--dry-run` locale gira su un dump di produzione; la CI costruisce lo schema
  da zero. Possono emergere disallineamenti che in locale non si vedono.
- **Il fix di `create_users_table` non e' un `publish` e via.** Contiene una
  decisione su tre colonne (`balance`, `fiscal_code`, `app_id`) che sembrano
  appartenere ad altri prodotti.
- **Il gate blocca la pipeline.** Nessun `continue-on-error`, come in maphub: da
  qui in avanti un disallineamento ferma le PR di forestas. E' l'effetto voluto,
  ma cambia il regime di lavoro del repo.
- **Rischio accettato, senza mitigazione: l'interruttore che sparisce in
  produzione.** `scripts/deploy_prod.sh` esegue `php artisan optimize` (riga 12),
  che congela la configurazione. Se il `.env` del server perde
  `WM_TRAIL_REGISTRY_ENABLED` — server ricostruito, ambiente nuovo allineato
  male — route e Nova resource del catasto spariscono mentre la tabella resta
  piena di dati veri, e il SUS riceve 404. Una verifica automatica al deploy e'
  stata valutata e scartata: poco probabile, non vale un passo in piu'. Se il
  catasto smette di rispondere senza motivo apparente, **la causa va cercata
  qui per prima**.
- **La chiave vive anche fuori dal repository.** Cinque dei sei posti in cui va
  ricordata sono versionati (`.env-deploy`, `.env-example`, `phpunit.xml`,
  `.env.testing`, piu' il `.env` locale che versionato non e'); il sesto e' il
  `.env` del server, mantenuto a mano, dove una svista non si vede in nessun
  diff.
- **`vendor:publish` non pubblica gli stub dei domini opzionali** (la scoperta
  delle migration in `spatie/laravel-package-tools` non e' ricorsiva): quando
  arrivera' lo stub del catasto con oc:8489, andra' pubblicato con
  `publish-migration trail_registry/<stub>`, non con il passo 3 del setup
  documentato in `CLAUDE.md`.

## Out of scope

- Il meccanismo di opt-in in se': vive in wm-package
- L'adozione del gate negli altri 14 repo che ne sono privi
- Le feature del catasto (oc:8489, oc:8490, oc:8491)

## Moduli toccati

| File | Cosa |
|---|---|
| `.github/workflows/run-tests.yml` | Step del gate fra `Migrate` e `Clear the config cache` |
| `.env-deploy` | `WM_TRAIL_REGISTRY_ENABLED=true` |
| `.env-example` | Stessa variabile, documentata |
| `phpunit.xml` | `<env name="WM_TRAIL_REGISTRY_ENABLED" value="true"/>` |
| `.env.testing` | Stessa variabile: e' il file che leggono i comandi artisan lanciati con `--env=testing`, dove `phpunit.xml` non arriva |
| `database/migrations/` | Risoluzione del disallineamento `create_users_table` |
| `CLAUDE.md` | Riga in "Feature disponibili" |
