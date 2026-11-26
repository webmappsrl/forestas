> Ticket: oc:9061
> Stato: seguito senza divergenze

# Soggetti privati: la pagina delle API — piano (shard)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** la pagina delle API dice, in tutti i punti in cui parla dei soggetti, che per una
persona fisica nome ed e-mail non si mandano, che un segnaposto riceve lo stesso rifiuto, e dove
si recupera il dato vero.

**Architecture:** la prosa sta tutta in `config/scramble.php`, il modulo di prova del bottone in
`docs.blade.php`. I due esempi del payload — quello `curl` nella prosa e quello del modulo —
devono restare identici byte per byte: lo asserisce un test già esistente, che li valida anche
contro le regole vere dello schema.

**Tech Stack:** Laravel 12, Scramble, Pest.

**Spec:** [overview.md](overview.md)

## Global Constraints

- La pagina scrive gli accenti come apostrofo (`e'`, `piu'`): è la convenzione del file.
- Niente numeri di ticket nel testo della pagina: è rivolta a chi integra.
- Nessun commit durante l'esecuzione.
- Questo piano si esegue **dopo** quello del package: finché le regole vecchie sono in vigore, il
  test che valida gli esempi contro le regole rifiuterebbe la forma nuova.

## Review Focus

- L'esempio `curl` e il modulo di prova che divergono di un carattere: il test li confronta come
  stringhe, e un apostrofo o una virgola diversi lo fanno fallire con un messaggio che parla di
  divergenza, non di sintassi. Coperto dal test esistente, Task 2.
- Il payload dell'esempio che resta valido per lo schema ma non dimostra più niente: un privato
  senza nome è il caso nuovo, e se l'esempio tornasse a un ente il test passerebbe lo stesso.
  Task 2.
- Un punto della pagina che resta indietro: sono sei, e solo due sono sotto test. Task 1 chiude
  con la rilettura della sezione intera.
- La frase di `:511-512` dimenticata: è l'unica che dopo questo ticket direbbe il contrario della
  regola, e nessun test la guarda. Task 1, primo passo.

---

### Task 1: La prosa della pagina, in tutti e sei i punti

Realizza [R1](overview.md#r1), [R2](overview.md#r2), [R3](overview.md#r3).

**Files:**
- Modify: `config/scramble.php` — `:421`, `:438-441`, `:466`, `:478-481`, `:509-512`, `:568`

**Interfaces:**
- Consumes: le regole del package (`prohibited_if` sui quattro ruoli) e le descrizioni dei
  `#[BodyParameter]`.
- Produces: la prosa che il Task 2 cita nel modulo di prova.

- [ ] **Step 1: La frase che oggi direbbe il contrario (`:511-512`)**

Dice: «Non dire niente di `managing_subjects` e' lecito — si consegnera' dopo —, mandarla con
dentro un soggetto senza `name` no.» Riscrivila: una lista mandata a metà resta un errore, ma il
soggetto incompleto è l'**ente** senza nome o senza e-mail, non il privato, che senza quei campi è
nella sua forma giusta.

- [ ] **Step 2: La tabella «Se… allora sono obbligatori» (`:509`)**

Ha oggi una sola riga, l'e-mail dell'ente. Diventano due righe: per un `organization` nome ed
e-mail; per un `person` nessuno dei due, e anzi vietati — la riga del divieto sta nella tabella
dei `422` del passo 4.

- [ ] **Step 3: Il paragrafo condizionato (`:438-441`) e la riga della tabella delle chiavi (`:421`)**

Il paragrafo si intitola «La `email` dipende da `subject`» e parla di un campo solo: diventa un
paragrafo su nome ed e-mail insieme. Deve dire tre cose e stare nella misura del paragrafo sul
codice fiscale che gli sta sotto: per un ente servono entrambi; per una persona fisica non si
mandano e se arrivano la risposta è `422`, **anche se il valore è un segnaposto convenzionale**;
i dati del privato restano sul SUS e si recuperano con il riferimento della pratica — il
`reference` che il SUS manda alla creazione, che l'operatore vede sulla domanda come «External
reference». Aggiungi che i privati di un ruolo arrivano senza nulla che li distingua fra loro, e
che la posizione nella lista non è un identificativo perché una patch che rimanda il ruolo ne
sostituisce la lista intera.

La riga `:421` della tabella delle chiavi descrive `name` come «la denominazione del soggetto: il
nome dell'ente, o il nominativo della persona»: la seconda metà non vale più.

- [ ] **Step 4: La tabella dei `422` (`:478-481`)**

Elenca cosa il canale rifiuta. Aggiungi la riga del dato personale di un privato, nella forma
delle altre: cosa arriva, cosa risponde.

- [ ] **Step 5: I due esempi (`:466` e `:568`)**

Entrambi mandano `[{"name":"Mario Rossi","subject":"person"}]` come rilevatore. Diventano
`[{"subject":"person"}]` in tutti e due. Il `curl` di `:568` deve restare identico, carattere per
carattere, a quello che il Task 2 scrive nel modulo di prova.

- [ ] **Step 6: Rileggi la sezione dei soggetti per intero**

Non le righe cambiate: la sezione. Cerca ogni occorrenza di `name`, `nominativo`, `Mario Rossi` e
`person` in `config/scramble.php` e verifica che nessuna dica più che il nome di un privato si
manda.

Run: `grep -n "Mario Rossi\|nominativo\|senza \`name\`" config/scramble.php`
Expected: nessuna occorrenza che riguardi un soggetto `person`.

---

### Task 2: Il modulo di prova

Realizza [R4](overview.md#r4).

**Files:**
- Modify: `resources/views/vendor/scramble/docs.blade.php:158`
- Test: `tests/Feature/Sus/SusApiDocumentationTest.php` (esistente, nessun test nuovo)

**Interfaces:**
- Consumes: l'esempio `curl` del Task 1, `:568`.
- Produces: niente.

- [ ] **Step 1: Lancia il test che già copre questo caso e verifica che fallisca**

`pubblica due esempi identici, e che il canale accetterebbe` estrae i payload dal `curl` della
prosa e dal modulo di prova, li confronta fra loro e li valida con le regole vere dello schema.
Dopo il Task 1 i due divergono.

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas && vendor/bin/pest tests/Feature/Sus/SusApiDocumentationTest.php --filter='due esempi identici'"`
Expected: FAIL — «l'esempio curl e il modulo di prova divergono su surveying_subjects».

- [ ] **Step 2: Allinea il modulo di prova**

Riga 158: `surveying_subjects: '[{"subject":"person"}]',`. Gli altri due ruoli mandano enti e non
cambiano.

- [ ] **Step 3: Lancia il test e verifica che passi**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas && vendor/bin/pest tests/Feature/Sus/SusApiDocumentationTest.php"`
Expected: PASS — tutti i test della pagina, non solo quello degli esempi.

- [ ] **Step 4: Apri la pagina e premi il bottone**

La verifica che nessun test fa: la pagina delle API nel browser, la sezione dei soggetti letta
dall'inizio alla fine, e il bottone «Prova la chiamata» premuto su una domanda aperta.
Expected: la chiamata risponde `200`, e la domanda risulta con un rilevatore `{"subject":"person"}`.
