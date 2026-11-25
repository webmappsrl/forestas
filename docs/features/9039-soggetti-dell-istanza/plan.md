> Ticket: oc:9039
> Stato: seguito senza divergenze sui task; dopo la review il lavoro si è allargato al rifiuto delle chiavi e dei valori non concordati ([R8](../../../wm-package/docs/features/9039-soggetti-dell-istanza/overview.md#r8) del package), ai tematismi, ai limiti di lunghezza e allo schema OpenAPI, che il piano non prevedeva — le voci sono in notes.md

# Soggetti dell'istanza come liste di persone — piano (forestas)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** portare la pagina delle API del canale SUS alla forma nuova dei soggetti, e togliere dai
test dello shard i campi che spariscono.

**Architecture:** la pagina si compone da due repo — gli attributi `#[BodyParameter]` stanno nel
package, la prosa e gli esempi stanno qui. Questo piano copre solo la parte di qui, e va eseguito
**dopo** il Task 5 del piano del package, altrimenti la pagina descrive due forme diverse.

**Tech Stack:** Laravel 12, Scramble, Pest.

**Spec:** [overview.md](overview.md) — requisiti [R1](overview.md#r1) e [R2](overview.md#r2).
Il lavoro vero è nel package:
[wm-package/docs/features/9039-soggetti-dell-istanza/plan.md](../../../wm-package/docs/features/9039-soggetti-dell-istanza/plan.md).

## Global Constraints

- **Repo: `forestas`.** Ogni file di questo piano sta sotto
  `/Users/bongiu/Documents/geobox2/forestas`.
- **Nella pagina delle API non va mai scritto un numero di ticket.** Il SUS non sa cosa siano i
  nostri `oc:`: le motivazioni lì si scrivono per esteso.
- **Nessun commit durante l'esecuzione.**
- **I test dello shard girano su `forestas_testing`.** Prima di lanciare la suite verifica che
  `.env.testing` esista e punti lì, e che il database esista; se l'isolamento non è verificabile,
  fermati e chiedi — il DB reale contiene dati importati da processi lunghi.
- **Comando dei test:**
  `docker exec -i php-forestas bash -c "cd /var/www/html/forestas && vendor/bin/pest --filter='<nome>'"`

## Review Focus

1. **L'esempio `curl` e il modulo precompilato sono due copie dello stesso payload**: aggiornarne
   uno solo lascia il bottone di prova a mandare una chiamata che il canale rifiuta. → Task 1,
   pinnato da un test che confronta i due elenchi di campi.
2. **`keeping_subjects` è facoltativo**, quindi fuori da `requiredFieldNames()`: il test della
   documentazione, che oggi controlla solo i campi obbligatori, non si accorgerebbe se restasse
   fuori dalla pagina. → Task 1.
3. **Un campo vecchio rimasto in una tabella della pagina** descrive a Engineering un contratto che
   non esiste più. → Task 1.

---

### Task 1: La pagina delle API

**Files:**
- Modify: `config/scramble.php` — le tabelle dei valori dei campi 47 (riga 279), 49 e 53, la
  tabella «Se… allora sono obbligatori» (da riga 345), l'esempio `curl` (da riga 380)
- Modify: `resources/views/vendor/scramble/docs.blade.php` — la costante `SAMPLE` (da riga 147)
- Test: `tests/Feature/Sus/SusApiDocumentationTest.php`

**Interfaces:**
- Consumes: i quattro campi e le loro regole, dichiarati nel package (Task 1 di quel piano), e gli
  attributi `#[BodyParameter]` (Task 5 di quel piano).

Realizza [R1](overview.md#r1) e [R2](overview.md#r2).

- [ ] **Step 1: Scrivi i test che falliscono** in `SusApiDocumentationTest.php`

```php
it('spiega le quattro liste di soggetti, custode compreso', function () {
    $page = $this->get(route('scramble.docs.ui'))->getContent();

    expect($page)
        ->toContain('managing_subjects', 'surveying_subjects', 'maintaining_subjects', 'keeping_subjects')
        ->toContain('organization', 'person');
});

it('non nomina più i campi delle categorie e dei comuni', function () {
    $page = $this->get(route('scramble.docs.ui'))->getContent();

    foreach ([
        'soggetto_gestore', 'soggetto_rilevatore', 'soggetto_manutentore', 'soggetto_custode',
        'proposed_managing_municipalities', 'municipality_email',
    ] as $campo) {
        expect($page)->not->toContain($campo);
    }
});

it('tiene l esempio curl e il modulo di prova sugli stessi campi', function () {
    // I due elenchi sono copie dello stesso payload: se divergono, il bottone
    // di prova manda una chiamata che il canale rifiuta.
    $page = $this->get(route('scramble.docs.ui'))->getContent();

    foreach (['managing_subjects', 'surveying_subjects', 'maintaining_subjects'] as $campo) {
        expect(substr_count($page, $campo))->toBeGreaterThanOrEqual(2);
    }
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Comando: `… vendor/bin/pest --filter='SusApiDocumentationTest'`

- [ ] **Step 3: Sostituisci le tre tabelle dei valori** in `config/scramble.php`

Via le `### Campo 47 — soggetto_gestore`, `### Campo 49 — soggetto_rilevatore`,
`### Campo 53 — soggetto_manutentore` con i loro elenchi di identificativi. Al loro posto una
sezione sola sui soggetti, che spieghi: i quattro campi con il numero del modulo da cui vengono
(47, 49, 53, 58), la forma dell'oggetto `{name, email, subject}`, i due valori di `subject`, la
regola della e-mail (obbligatoria e valida per un `organization`, facoltativa per una `person`),
quanti soggetti servono per ruolo e che la posizione citata in un errore **parte da zero**.

Il motivo del cambiamento va scritto per esteso, citando la fonte — Forestas ha chiesto i soggetti
veri e non più la categoria — senza alcun `oc:`.

- [ ] **Step 4: Aggiorna la tabella dei campi condizionati**

Le quattro righe di oggi riguardano campi che non esistono più. Resta il vincolo fra
`primary_activity` e `activities`; si aggiunge la riga della e-mail condizionata a `subject`.

- [ ] **Step 5: Aggiorna l'esempio `curl`**

Le righe `-F soggetto_gestore=…`, `-F soggetto_rilevatore=…`, `-F soggetto_manutentore=…`,
`-F soggetto_custode_presente=false`, `-F proposed_managing_municipalities=…`,
`-F municipality_email=…` diventano le tre liste obbligatorie come stringa JSON, sul modello della
riga di `activities`.

- [ ] **Step 6: Aggiorna la costante `SAMPLE`** in `docs.blade.php`

Gli stessi campi del `curl`, nella sintassi dell'oggetto JavaScript già usata lì.

- [ ] **Step 7: Lancia i test e verifica che passino**

- [ ] **Step 8: Guarda la pagina**

Apri la pagina delle API e premi il bottone di prova: la chiamata deve rispondere `201`. È
l'unica verifica che prova davvero che i due esempi siano allineati.

- [ ] **Step 9: Commit** *(istruzione testuale, non eseguire)*

```bash
git add config/scramble.php resources/views/vendor/scramble/docs.blade.php tests/Feature/Sus/SusApiDocumentationTest.php
git commit -m "feat(oc:9039): la pagina delle API descrive le liste di soggetti"
```

---

### Task 2: Il test dei log del canale

**Files:**
- Test: `tests/Feature/Sus/SusRequestLogTest.php` — righe 67, 71, 72

- [ ] **Step 1: Sostituisci `municipality_email` con un campo che resta**

Il test manda un valore riservato su un campo e verifica che il log registri il **nome** del campo
in errore e non il valore. Il campo sparisce: serve un campo che resti e che dia `422` allo stesso
modo. `managing_subjects` va bene — un valore scalare al posto della lista produce un errore sul
campo stesso. Tieni intatto ciò che il test prova: il nome nel log, il valore mai.

- [ ] **Step 2: Lancia il test**

Comando: `… vendor/bin/pest --filter='SusRequestLogTest'`
Atteso: PASS.

- [ ] **Step 3: Lancia la suite del canale SUS**

Comando: `… vendor/bin/pest --filter='Sus'`
Atteso: PASS.

- [ ] **Step 4: Commit** *(istruzione testuale)*

```bash
git add tests/Feature/Sus/SusRequestLogTest.php
git commit -m "feat(oc:9039): il test dei log del canale non usa più un campo sparito"
```
