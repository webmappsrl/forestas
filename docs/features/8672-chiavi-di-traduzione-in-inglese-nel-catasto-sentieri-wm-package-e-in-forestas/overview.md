> Ticket: oc:8672

# Chiavi di traduzione in inglese nel Catasto Sentieri (wm-package) e in forestas

Questa overview copre la parte di forestas. La parte del package è in
`wm-package/docs/features/8672-chiavi-di-traduzione-in-inglese-nel-catasto-sentieri-wm-package-e-in-forestas/overview.md`.

## Cosa cambia

Le chiavi di `__()` scritte in italiano in `app/` passano all'inglese. Il testo che l'operatore
legge in italiano resta quello di oggi, perché si sposta nella voce di `lang/it.json`. Si
aggiunge un test che segnala una chiave letterale di `app/` senza traduzione, contando le
traduzioni di forestas e del package unite, come fa Laravel.

## Perché

La convenzione Webmapp è la chiave in inglese. Le chiavi italiane di forestas sono nate con il
Catasto e con il client SUS, quando la regola traduzioni di `wm-plan` chiedeva il testo base
nella lingua di default del repo (`APP_LOCALE=it`). Oggi 26 chiavi letterali di `app/` non hanno
nessuna voce e compaiono in italiano con qualunque lingua.

## Requisiti

- [ ] Nessuna chiave di `__()` in italiano in `app/`. Sono 40 chiavi distinte (il ticket ne
  stimava 34), in: `Enums/TipoEnte.php`, `Http/Controllers/Api/Sus/SusAuthController.php`,
  `Nova/Actions/CreateSusClient.php`, `Nova/Actions/ImportTaxonomyWhereFromLayersAction.php`,
  `Nova/Filters/TrailAnomalySourceFilter.php`, `Nova/RegistroTabRenderer.php`,
  `Nova/TrailRegistryAnomaly.php`, `Providers/NovaServiceProvider.php`,
  `Services/RegistroCatastale/AnomalyTypes/`. L'elenco completo è nella «Tabella delle chiavi» in
  fondo
- [ ] Testo della chiave inglese: stesse regole del package, compreso il controllo collisioni
  (nessuna chiave nuova già presente nei JSON di forestas o del package con un testo italiano
  diverso). Il controllo è stato fatto sulla tabella in fondo: nessuna collisione. Per le chiavi
  in comune col package si usa esattamente la chiave inglese scelta nel package: `Catasto`
  diventa `Trail registry`, `Sentiero` diventa `Trail`. Le chiavi italiane che hanno già una voce
  inglese in `lang/en.json` prendono quel valore come chiave, salvo dove il valore dice
  «Catasto»: lì si allinea a «Trail registry» (`Codice del catasto` → `Trail registry code`,
  «…in Catasto › Anomalies…» → «…in Trail registry › Anomalies…», e i testi di
  `NumeroDiverso.php:18` e `Nova/TrailRegistryAnomaly.php:63`)
- [ ] Per ogni chiave nuova: voce in `lang/it.json` con il testo italiano di oggi e in
  `lang/en.json`, salvo che la voce esista già nel package con lo stesso testo: in quel caso non
  si ricopia
- [ ] La voce della chiave italiana sostituita viene tolta dai JSON, non lasciata accanto
- [ ] Con `APP_LOCALE=it` l'operatore vede esattamente i testi italiani di oggi. Unica eccezione
  accettata: la chiave generica `Row` (vedi Rischi), oltre a quelle del package
- [ ] I messaggi di errore dell'API SUS (`SusAuthController`) restano identici con
  `APP_LOCALE=it`
- [ ] I test esistenti che usano le chiavi italiane passano con le chiavi nuove: da una ricerca
  non esaustiva, `tests/Feature/RegistroCatastale/RegistroAnomalyDetailRowsTest.php` e
  `tests/Feature/TrailRegistry/RegistroTabTest.php`
- [ ] Test nuovo sulle chiavi di `app/`:
  - ogni chiave convertita (quelle della «Tabella delle chiavi») ha una voce in `it.json` e
    `en.json`, cercata nell'unione di `wm-package/resources/lang/` e `lang/` (vince forestas, come
    in `FileLoader::loadJsonPaths`);
  - le altre chiavi letterali senza voce, già inglesi (`Admin`, `Files`, `Icons`, `Vocabulary`,
    `Info`, `Forestas`, `DEM`, `Email`, `Password`), sono elencate nell'esito, non verificate:
    oggi l'operatore le legge in inglese e il ticket non le tocca;
  - per le chiavi convertite, il valore italiano risultante è identico al testo di oggi,
    confrontato con un elenco «chiave inglese → testo italiano» scritto nel test. L'elenco si
    ricava dal codice di oggi, prima di modificarlo, cioè dalla «Tabella delle chiavi»: mai da
    `it.json` già modificato, altrimenti il test confronta il file con sé stesso. È l'unica
    garanzia sul testo italiano: i test esistenti confrontano `__('chiave')` con `__('chiave')`
    e girano in CI con `en`;
  - estrazione con il tokenizer di PHP (`token_get_all`): `CreateSusClient.php:43-46` e `:62-65`
    hanno `__(` su più righe, `SusAuthController.php:70` ha apici con escape;
  - le chiamate con chiave dinamica sono elencate nell'esito, non verificate
- [ ] Test nuovo sul menu: con lingua inglese il menu di Nova ha una sola sezione del Catasto
  (protegge dallo sdoppiamento se package e forestas usano chiavi diverse)
- [ ] Puntatore del submodule aggiornato al commit del merge di oc:8672 nel package, con
  `git -C wm-package fetch` e `git -C wm-package checkout <commit>`, mai scrivendo l'hash a mano
  (con oc:8567 un hash scritto a mano e inesistente ha richiesto la correzione `cb16427`). Prima
  di aprire la PR:
  - `git -C wm-package merge-base --is-ancestor <commit-del-merge-di-oc:8672> HEAD` risponde sì;
  - `git -C wm-package branch -r --contains HEAD` mostra `origin/develop`

## Rischi

- **Una chiave dimenticata in `it.json` mostra l'inglese all'operatore senza nessun errore.**
  Mitigazione: il test nuovo.
- **Oggi le chiavi italiane mostrano l'italiano con qualunque `APP_LOCALE`; dopo il ticket la
  lingua dipende davvero da `APP_LOCALE`.** Verificato: UAT, che è anche la produzione (è l'host
  `HOSTPROD` su cui `prod-deploy.yml` fa il deploy di `main`), ha `lang="it"` nella pagina di login
  di Nova (`Nova::resolveUserLocale()`). `config/app.php:91` ha come default `en`, e la CI gira con
  `en` (`.env-deploy:49`, copiato da `run-tests.yml:33`). Se forestas avrà un giorno una
  produzione separata, il suo `.env` deve avere `APP_LOCALE=it`.
- **La sezione di menu «Catasto» si trova per etichetta tradotta.** Il package cerca la
  `MenuSection` creata in `Providers/NovaServiceProvider.php:56` confrontando il nome con
  `__('Catasto')` (`WmPackageServiceProvider.php:335`, chiamato alle righe 807 e 844). Se forestas e package usano chiavi diverse, con lingua
  inglese la sezione si sdoppia. Mitigazione: la chiave diventa `Trail registry` nella stessa PR
  in cui si aggiorna il submodule, e il test sul menu lo verifica.
- **Le traduzioni del package valgono anche in forestas, e quelle di forestas anche sulle
  schermate del package.** La chiave nuova `Row` → «Riga» (`AnomalyTypes/RegistroDetailRows.php:24`)
  è già usata senza voce italiana nel report di `wm-package/src/Nova/Actions/UploadPoiFile.php:233`:
  in forestas quell'intestazione passa da «Row» a «Riga». Lo stesso fanno le chiavi `Details`,
  `Detail`, `Status` aggiunte dal package (vedi la sua overview); `Source` → «Provenienza» in
  forestas c'è già oggi (`lang/it.json`). Per esempio la tab «Details» di
  `app/Nova/EcTrack.php:44` e `app/Nova/EcPoi.php:60` diventa «Dettagli». È voluto: l'obiettivo
  sono traduzioni coerenti.
- **Puntatore del submodule.** oc:8567 è già entrato in `develop` in tutti e due i repo
  (wm-package#292, forestas#17, puntatore su `bdd9c059`). La PR di forestas di questo ticket
  aggiorna il puntatore a un `develop` del package che contiene oc:8672: se lo portasse su un
  commit precedente a `bdd9c059`, toglierebbe a forestas il lavoro di oc:8567 senza nessun
  conflitto git. Se lo portasse su un hash inesistente, il deploy si fermerebbe su
  `git submodule update`; su un commit precedente al merge di oc:8672, la sezione del menu si
  sdoppierebbe anche in italiano, perché la voce `Trail registry` sta nel package. Mitigazione:
  le verifiche nei requisiti.
- **Le sovrascritture di forestas vanno conservate.** `Where` e `Wheres` hanno in `lang/it.json`
  «Zona geografica» e «Zone geografiche», diversi dal package: non si toccano.
- **I messaggi di `SusAuthController` sono la risposta dell'API al client SUS.** Con
  `APP_LOCALE=it` il testo non cambia; `SusAuthTest` non confronta il testo dei messaggi.

## Out of scope

- Le stringhe italiane passate senza `__()` (per esempio in `Nova/Ente.php`, `Nova/EcTrack.php`)
- Le 6 voci copiate dal package con lo stesso testo (`Activities`, `Activity`, `Tools`, `Link`,
  `Yes`, `No`)
- La revisione dei testi italiani: cambia solo la chiave

## Moduli toccati

Tutti in `forestas`:

- i file di `app/` elencati nei requisiti
- `lang/it.json`, `lang/en.json`
- i test esistenti che usano le chiavi italiane, in `tests/`
- due test nuovi in `tests/`: chiavi e traduzioni, sezione del Catasto nel menu
- il puntatore del submodule `wm-package`

Il branch parte da `origin/develop`, che contiene già oc:8567. Si fa il merge dopo quello
del package.

## Tabella delle chiavi

40 chiavi distinte, estratte con `token_get_all` su `develop` del 06/10. «Dove» è il percorso
sotto `app/`.

| Chiave italiana | Chiave inglese | Dove |
|---|---|---|
| Complesso forestale | Forest complex | `Enums/TipoEnte.php:40` |
| Ente partner | Partner body | `Enums/TipoEnte.php:41` |
| Altre Pubbliche Istituzioni | Other public institutions | `Enums/TipoEnte.php:42` |
| Privato/associazione | Private/association | `Enums/TipoEnte.php:43` |
| Comune | Municipality | `Enums/TipoEnte.php:44` |
| Credenziali non valide. | Invalid credentials. | `Http/Controllers/Api/Sus/SusAuthController.php:59` |
| Questo endpoint e' riservato al client SUS. | This endpoint is reserved for the SUS client. | `Http/Controllers/Api/Sus/SusAuthController.php:70` |
| Impossibile rinnovare il token: rieseguire il login. | Unable to refresh the token: log in again. | `Http/Controllers/Api/Sus/SusAuthController.php:94` |
| Crea client SUS | Create SUS client | `Nova/Actions/CreateSusClient.php:33` |
| Esiste gia' un utente con l'email :email. Per cambiare la password usa il campo Password sulla sua scheda. | A user with the email :email already exists. To change the password use the Password field on their record. | `Nova/Actions/CreateSusClient.php:45` |
| Client SUS :email creato con il ruolo Sus. | SUS client :email created with the Sus role. | `Nova/Actions/CreateSusClient.php:65` |
| Nome | Name | `Nova/Actions/CreateSusClient.php:79` |
| Suggerita automaticamente: copiala prima di confermare, oppure sostituiscila. | Suggested automatically: copy it before confirming, or replace it. | `Nova/Actions/CreateSusClient.php:88` |
| Import TaxonomyWhere (Aree Catastali) | Import TaxonomyWhere (Cadastral areas) | `Nova/Actions/ImportTaxonomyWhereFromLayersAction.php:24` |
| Documentazione API SUS | SUS API documentation | `Providers/NovaServiceProvider.php:65` |
| Sentiero | Trail | `Nova/TrailRegistryAnomaly.php:48` |
| Catasto | Trail registry | `Providers/NovaServiceProvider.php:56`, `Nova/Filters/TrailAnomalySourceFilter.php:34` |
| Registro | Registry | `Nova/Filters/TrailAnomalySourceFilter.php:35` |
| Foglio | Sheet | `Nova/RegistroTabRenderer.php:85`, `Services/RegistroCatastale/AnomalyTypes/RegistroDetailRows.php:23` |
| Riga | Row | `Services/RegistroCatastale/AnomalyTypes/RegistroDetailRows.php:24` |
| Righe | Rows | `Services/RegistroCatastale/AnomalyTypes/RigheMultiple.php:33` |
| Colonna | Column | `Services/RegistroCatastale/AnomalyTypes/ValoreNonSanitizzabile.php:28` |
| Valore | Value | `Services/RegistroCatastale/AnomalyTypes/ValoreNonSanitizzabile.php:29` |
| Importato il | Imported on | `Nova/RegistroTabRenderer.php:89` |
| Codici candidati | Candidate codes | `Services/RegistroCatastale/AnomalyTypes/RipiegoAmbiguo.php:29` |
| Codice del catasto | Trail registry code | `Services/RegistroCatastale/AnomalyTypes/NumeroDiverso.php:27` |
| Codice del foglio | Sheet code | `Services/RegistroCatastale/AnomalyTypes/NumeroDiverso.php:28` |
| Il registro non ha una riga per questo codice. | The registry has no row for this code. | `Nova/RegistroTabRenderer.php:51` |
| Questa riga non è agganciata a nessun codice. | This row is not linked to any code. | `Nova/RegistroTabRenderer.php:70` |
| Se è finita in anomalia la trovi in Catasto › Anomalie, filtro provenienza Registro. | If it ended up as an anomaly, you will find it in Trail registry › Anomalies, source filter Registry. | `Nova/RegistroTabRenderer.php:71` |
| La riga del registro per questo codice è finita in anomalia: | The registry row for this code has become an anomaly: | `Nova/RegistroTabRenderer.php:128` |
| vedi l'anomalia | view the anomaly | `Nova/RegistroTabRenderer.php:129` |
| Le anomalie del registro | Anomalies from the registry | `Nova/TrailRegistryAnomaly.php:62` |
| Link del registro senza traccia corrispondente | Registry link with no matching track | `Services/RegistroCatastale/AnomalyTypes/LinkOrfano.php:18` |
| Numero del registro diverso da quello del catasto | Registry number different from the trail registry | `Services/RegistroCatastale/AnomalyTypes/NumeroDiverso.php:18` |
| Più righe del registro per lo stesso codice | Multiple registry rows for the same code | `Services/RegistroCatastale/AnomalyTypes/RigheMultiple.php:19` |
| Più codici candidati per lo stesso numero del registro | Multiple candidate codes for the same registry number | `Services/RegistroCatastale/AnomalyTypes/RipiegoAmbiguo.php:19` |
| Traccia del registro senza codice attivo | Registry track without an active code | `Services/RegistroCatastale/AnomalyTypes/TracciaSenzaCodice.php:18` |
| Valore del registro non interpretabile | Unreadable registry value | `Services/RegistroCatastale/AnomalyTypes/ValoreNonSanitizzabile.php:19` |
| Sono le righe del foglio Google del registro catastale che non si sono agganciate in modo pulito a un codice del Catasto: un numero diverso da quello sul foglio, un link che non porta a nessuna traccia, una traccia senza codice attivo, un numero che il ripiego non sa attribuire a un solo codice, più righe del foglio per lo stesso codice, o valori di lunghezza o tempi che non si riescono a interpretare. Anche queste si correggono alla fonte — il foglio o la scheda del sentiero — non da qui: il prossimo giro dell'import le fa sparire da sole quando il dato torna coerente. | These are rows from the registro catastale Google Sheet that did not cleanly attach to a trail registry code: a number different from the sheet, a link that leads to no track, a track without an active code, a number the fallback cannot attribute to a single code, several sheet rows for the same code, or length or time values that cannot be interpreted. These are also corrected at the source — the sheet or the trail record — not here: the next import run makes them disappear on their own once the data is consistent again. | `Nova/TrailRegistryAnomaly.php:63` |
