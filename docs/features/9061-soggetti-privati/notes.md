> Ticket: oc:9061
> Overview approvata il 2026-11-26 · impronta `725d301611cb46db2ccbaeb5515f9e9a100bd9c1`

# Notes — Soggetti privati (shard)

## Modifiche ai requisiti

### 2026-11-26 — la pagina non promette più la posizione nella lista come criterio di distinzione
- **Requisito:** [R3](overview.md#r3)
- **Effetto:** modificato
- **Decide:** agente (challenge adversariale), confermato dal dev
- **Perché:** `TrailApplication::mergeApiData()` fa `array_merge`, quindi una patch che rimanda un
  ruolo ne sostituisce la lista intera: la posizione non è stabile fra una patch e l'altra, e
  prometterla nella pagina sarebbe una garanzia che il canale non offre.
- **Scartato:** scrivere «si distinguono per posizione», che era la forma approvata.
- **Testo nuovo:**
  > La pagina dice che i dati identificativi di un soggetto privato restano sul SUS e si
  > recuperano attraverso il riferimento della pratica, nominandolo con il nome che ha nel canale
  > (`reference`) e con quello che l'operatore vede in Nova («External reference»). Dice anche che
  > i privati di un ruolo arrivano senza nulla che li distingua fra loro, perché non sia scambiato
  > per una perdita di dati: la posizione nella lista non è un identificativo, perché una patch che
  > rimanda il ruolo ne sostituisce la lista intera.

## Divergenze dal piano, task per task

Nessuna finora.

## Bug trovati

### 2026-11-26 — la pagina si contraddice già oggi in un punto
`config/scramble.php:511-512` dice «mandarla con dentro un soggetto senza `name` no». Dopo questo
ticket direbbe il contrario della regola, ed è il motivo per cui i punti della pagina da toccare
sono sei e non tre. Trovato dalla challenge e verificato aprendo il file.

## Decisioni

### 2026-11-26 — la pagina nomina esplicitamente il segnaposto rifiutato
Alessio Piccioli in call il 23/11/2026 aveva chiesto l'opposto — «però il placeholder ce lo dovete
mettere» — e la pagina Scramble è il canale con cui gli si risponde. Tacere avrebbe fatto scoprire
il rifiuto come un `422` inatteso.
**Scartato:** scrivere solo la regola, senza nominare il segnaposto.

## Follow-up

Nessuno finora.
