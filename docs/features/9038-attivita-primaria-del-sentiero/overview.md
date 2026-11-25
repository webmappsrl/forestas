> Ticket: oc:9038
> Stato: chiusa il 2026-11-24 · approvata il 2026-11-24

# Attività primaria del sentiero — la parte di Forestas

Il lavoro vive nel package: la descrizione completa è in
[wm-package/docs/features/9038-attivita-primaria-del-sentiero/overview.md](../../../wm-package/docs/features/9038-attivita-primaria-del-sentiero/overview.md).
Qui resta solo ciò che è di questo shard.

## Cosa cambia

Niente nel codice di forestas: cambia la **pagina delle API del canale SUS**, la cui prosa vive qui
e non nel package.

## Perché

`config/scramble.php` di questo shard contiene per esteso le sezioni dei campi dell'istanza —
compresa «Campo 36 — `activities`, le attività proposte», la spiegazione di `difficulty` come
oggetto attività→livello, la tabella delle scale per attività e gli esempi `curl`. Gli attributi
`#[BodyParameter]` sui controller stanno invece nel package: la pagina si compone dalle due parti.
Un campo nuovo nel payload che non venga descritto qui resta invisibile a Engineering, che
implementa la maschera del SUS leggendo quella pagina.

## Punto di partenza

In `config/scramble.php`: la sezione del campo 36 (riga 256), la spiegazione di `difficulty`
(riga 360), la tabella delle scale (riga 367) e gli esempi `-F activities='["hiking","mtb"]'` e
`-F difficulty='{"hiking":"EE"}'` (righe 308-309).

Scramble è configurato sulle sole rotte del canale SUS in `app/Providers/AppServiceProvider.php`, e
l'elenco dei valori ammessi dei campi a scelta chiusa viene in parte da qui e in parte iniettato da
`ApplicationSchema::valuesDescription()`.

## Requisiti

- <a id="r1"></a>**R1** — La pagina delle API descrive il campo dell'attività primaria proposta, con
  un esempio, i valori che accetta e la regola che lo lega alle attività dichiarate: una proposta
  che nomini un'attività non dichiarata viene rifiutata.
  - Verifica: [tests/Feature/Sus/SusApiDocumentationTest.php](../../../tests/Feature/Sus/SusApiDocumentationTest.php) › `spiega la attivita primaria: obbligatoria e fra quelle dichiarate in activities`

Gli altri requisiti del lavoro sono nel package: vedi
[la sua overview](../../../wm-package/docs/features/9038-attivita-primaria-del-sentiero/overview.md#requisiti).

## Rischi

**La pagina si compone da due repo.** Aggiornare solo gli attributi `#[BodyParameter]` del package
lascia la prosa di questo shard a descrivere la forma vecchia, e viceversa: la contraddizione non la
segnala nessun test.

## Out of scope

**La conferma delle scale di difficoltà di mountain bike e ippovia.** Scrivendo la documentazione è
emerso che la pagina **elenca già** quei livelli — `TC`, `MC`, `BC`, `OC`, `EC` per la mountain bike
ed `easy`, `medium`, `hard` per l'ippovia — e che coincidono con i termini a database. Quello che
manca non è l'elenco, ma la sua conferma nel nuovo regolamento, che Alessio Saba aveva promesso e non
ha inviato. Finché non arriva, i valori in pagina restano quelli, non confermati.

## Moduli toccati

- `config/scramble.php` — la sezione dell'attività primaria sotto il campo 36, e quella sulla
  distinzione fra `submitted_data` e `approved_data` con la tabella delle sei scale di difficoltà
- `resources/views/vendor/scramble/docs.blade.php` — il payload del pulsante «Prova una domanda
  completa», che senza il campo nuovo avrebbe risposto `422` a ogni prova
- `app/Http/Controllers/Api/Sus/SusTestDecisionController.php` — il canale di prova passa le scelte
  all'approvazione, altrimenti consegnava una decisione sempre vuota
- `database/migrations/2026_11_24_162631_zz_2026_11_24_000001_install_primary_activity_taxonomy.php`
  — pubblicata dal comando del package
- `tests/Feature/Sus/SusApiDocumentationTest.php` (nuovo) e `tests/Feature/Sus/SusTestDecisionTest.php`
