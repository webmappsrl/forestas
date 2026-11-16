> Ticket: oc:8989
> Stato: approvata il 2026-11-16

# Percorribilità dei sentieri su Forestas — piano (shard)

> **Per chi esegue:** usa `superpowers:subagent-driven-development` o `superpowers:executing-plans`.
> **I commit scritti qui sono istruzioni, non azioni da eseguire** durante il lavoro.

**Goal:** sdoppiare lo stato unico ereditato da Drupal — la validazione resta la pratica catastale,
la percorribilità diventa il terreno — e mostrare il nuovo dato dove Forestas lavora.

**Architettura:** il package fornisce famiglia, termini, service, azione, renderer e card; lo shard
li monta, smista l'import sui due assi, sposta i sentieri già classificati e dichiara l'incongruenza.

**Stack:** Laravel 12, PHP 8.4 nel container, PostgreSQL + PostGIS, Nova 5.7.6, Elasticsearch 8.

**Spec:** [overview.md](overview.md).

## Vincoli globali

- **Questo piano parte solo dopo** che il lavoro nel package è merged e il puntatore del submodule
  è bumpato, nello stesso commit della prima modifica qui.
- Identificatori in inglese, commenti e documentazione in italiano.
- La famiglia e i suoi termini **non si dichiarano** nel comando delle tassonomie dello shard: li
  crea il package. Due elenchi che scrivono la stessa riga, divergendo, creerebbero due famiglie.
- Prima di lanciare la suite, verifica l'isolamento del database (`.claude/rules/test.md`).
- Comando dei test: `docker exec -it php-forestas vendor/bin/pest`.

## Dove guardare in review

1. Un sentiero smistato deve avere **due** termini: la percorribilità giusta e «Non verificato»
   sulla validazione — non uno solo (Task 12).
2. Il comando di migrazione, rilanciato due volte, non deve produrre eventi doppi (Task 13).
3. Un sentiero senza alcun termine della famiglia non deve far esplodere card e incongruenze
   (Task 14).
4. L'incongruenza va ricalcolata anche quando il dato cambia **fuori** dall'import (Task 14).
5. La dashboard deve aprirsi per un ruolo che non ha accesso al Catasto (Task 15).

---

### Task 11: il comando di migrazione dei sentieri già classificati — [R8](overview.md#r8)

> Viene **prima** della rimozione dei termini (Task 12): finché i due termini sono ancora
> dichiarati, i sentieri si spostano su una base stabile.

**File**
- Crea: `app/Console/Commands/MoveWalkabilityFromValidationCommand.php`
- Test: `tests/Feature/MoveWalkabilityFromValidationTest.php`

**Interfacce**
- Consuma: `WalkabilityService::set(...)` del package, con `TrailStatusEventSource::Migration`.

- [ ] **Passo 1 — i test che falliscono**

```php
it('sposta i due stati di terreno e scrive Non verificato sulla validazione', function () {
    // traccia con validation-status-walkable
    $this->artisan('sardegnasentieri:move-walkability');
    expect($track->term('walkability-status')->identifier)->toBe('walkability-status-walkable');
    expect($track->term('validation-status')->identifier)->toBe('validation-status-unverified');
});

it('dà percorribilità sconosciuta agli altri', function () {
    expect($track->term('walkability-status')->identifier)->toBe('walkability-status-unknown');
    expect($track->term('validation-status')->identifier)->toBe('validation-status-validated');
});

it('rilanciato una seconda volta non aggiunge eventi', function () {
    $this->artisan('sardegnasentieri:move-walkability');
    $this->artisan('sardegnasentieri:move-walkability');
    expect(TrailStatusEvent::where('ec_track_id', $track->id)->count())->toBe(1);
});

it('con --dry-run non scrive niente', function () {
    expect(TrailStatusEvent::count())->toBe(0);
});
```

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi il comando.** Lavora **per sentiero**, passando dal service: non rinomina
      le righe dei termini. Il docblock spiega perché — i termini nuovi esistono già, e
      `renameIdentifiers()` del comando delle tassonomie salta la rinomina quando trova entrambi
      gli identifier, lasciando i sentieri dov'erano senza nessun errore.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): comando che sposta i sentieri nella famiglia della percorribilità`

---

### Task 12: l'import smista sui due assi — [R2](overview.md#r2), [R3](overview.md#r3)

**File**
- Modifica: `app/Services/Import/SardegnaSentieriImportService.php` (`VALIDATION_STATUS_TERMS` e il
  punto che costruisce le classificazioni, righe ~1043 e ~1099-1106)
- Modifica: `app/Console/Commands/ConfigureTaxonomyTermsCommand.php` (i due termini escono
  dall'elenco di `validation-status`)
- Test: `tests/Feature/Import/WalkabilityImportTest.php`

- [ ] **Passo 1 — i test che falliscono**

```php
it('manda il tid del terreno nella percorribilità e segna la validazione come non verificata', function () {
    // nodo Drupal con stato_di_validazione = 4870 (Non percorribile)
    expect($track->term('walkability-status')->identifier)->toBe('walkability-status-not-walkable');
    expect($track->term('validation-status')->identifier)->toBe('validation-status-unverified');
});

it('scrive un evento con provenienza import', function () {
    expect($event->source)->toBe(TrailStatusEventSource::Import);
    expect($event->user_id)->toBeNull();
});

it('non scrive un evento se lo stato non cambia', function () {
    // secondo giro con lo stesso nodo
    expect(TrailStatusEvent::where('ec_track_id', $track->id)->count())->toBe(1);
});

it('lascia a validation-status i cinque stati di pratica', function () {
    expect($family->children()->pluck('identifier')->all())->toHaveCount(5);
});
```

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — cambia il mapping:** i tid `4870` e `4187` puntano agli identifier della
      percorribilità; gli altri cinque restano. Dove oggi il service scrive la famiglia della
      validazione solo se Drupal dichiara uno stato, per i due tid smistati scrive **esplicitamente**
      `validation-status-unverified`: senza, quei sentieri resterebbero senza validazione ed
      uscirebbero da ogni filtro.

- [ ] **Passo 4 — fai passare la percorribilità dal service.** L'import calcola già la differenza
      fra i termini che ha e quelli che vuole: per la famiglia della percorribilità non chiama
      `detach`/`attach`, chiama il service con `TrailStatusEventSource::Import`. Senza questo,
      [R3](overview.md#r3) del package è falso dal primo giro.

- [ ] **Passo 5 — togli i due termini** dall'elenco di `validation-status` nel comando delle
      tassonomie, **senza** aggiungere la famiglia della percorribilità: quella la crea il package.

- [ ] **Passo 6 — lancia i test**: passano.

- [ ] **Passo 7 — commit:** `feat(oc:8989): l'import smista validazione e percorribilità`

---

### Task 13: l'incongruenza `missing_walkability` — [R6](overview.md#r6)

**File**
- Crea: `app/Services/TrailRegistry/InconsistencyTypes/MissingWalkability.php`
- Modifica: `app/Providers/AppServiceProvider.php` (registrazione, come per i tipi di anomalia del
  registro catastale)
- Test: `tests/Feature/MissingWalkabilityTest.php`

**Interfacce**
- Consuma: `TrailRegistryInconsistencyTypes` del package (Task 8).

- [ ] **Passo 1 — i test che falliscono:** un sentiero validato con percorribilità sconosciuta
      produce l'incongruenza; uno in revisione con percorribilità sconosciuta no; appena la
      percorribilità viene inserita a mano l'incongruenza sparisce **senza aspettare l'import**; un
      sentiero senza alcun termine della famiglia non fa esplodere il calcolo.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi il tipo e registralo.** Il ricalcolo si aggancia dove già si ricalcolano
      le anomalie a fine import, **e** alla scrittura del service: a differenza delle anomalie,
      questa dipende anche da un dato che l'import non tocca.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): incongruenza dei sentieri validati senza percorribilità`

---

### Task 14: la dashboard — [R7](overview.md#r7)

**File**
- Modifica: `app/Nova/Dashboards/Main.php`
- Test: `tests/Feature/Nova/MainDashboardTest.php`

- [ ] **Passo 1 — i test che falliscono:** la dashboard contiene le due card del Catasto al posto
      di `Help`; si apre senza errori per `Administrator`, `Editor`, `Validator` e `Contributor`.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — monta le card** del package nell'array di `cards()`. Nessun `canSee` per ruolo:
      sono numeri d'insieme, e in Nova entrano solo i ruoli che li possono vedere.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): dashboard del Catasto al posto della pagina di benvenuto`

---

### Task 15: il filtro — [R4](overview.md#r4)

> **Bloccato finché oc:8988 non è chiuso.** È l'unico task che dipende da un ticket esterno: se
> slitta, tutto il resto è consegnabile e la ricerca continua a filtrare sulla validazione.

**File**
- Modifica: `app/Nova/EcTrack.php` (registrazione del filtro generico sulla famiglia)
- Test: `tests/Feature/Nova/WalkabilityFilterTest.php`

- [ ] **Passo 1 — il test che fallisce:** filtrando per `walkability-status-not-walkable` la index
      mostra solo i sentieri con quel termine.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — registra il filtro generico di oc:8988** con l'identifier della famiglia. Nessuna
      classe di filtro nuova.

- [ ] **Passo 4 — lancia la suite intera** e `vendor/bin/phpstan analyse`.

- [ ] **Passo 5 — commit:** `feat(oc:8989): filtro della percorribilità nella lista delle tracce`

---

## Prima di chiudere

- Ricontare i sentieri per stato sul database vero: i numeri dell'overview vengono da Geohub al
  19/10 e servono solo come ordine di grandezza.
- Verificare dove vivono le fasce del filtro «Percorribilità» della ricerca pubblica e chi le
  cambia sui due ambienti: non è coperto da questo lavoro ed è nei rischi.
