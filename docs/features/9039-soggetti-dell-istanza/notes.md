> Ticket: oc:9039
> Overview approvata il 2026-11-25 · impronta `c9a0e16fe4203784f0d79ed1bb0c75f5ab233a1b`

# Notes — Soggetti dell'istanza, la parte di Forestas

Le decisioni che riguardano il dominio stanno nelle note del package:
[wm-package/docs/features/9039-soggetti-dell-istanza/notes.md](../../../wm-package/docs/features/9039-soggetti-dell-istanza/notes.md).
Qui solo ciò che è di questo shard.

## Modifiche ai requisiti

Nessuna, per ora.

## Divergenze dal piano, task per task

Nessuna, per ora.

## Bug trovati

Nessuno, per ora.

## Decisioni

### 2026-11-25 — i numeri del modulo FORES_02 restano sui campi nuovi
La pagina delle API apre ogni campo con il numero del campo nel modulo — «Campo 47 del format —
*Soggetto gestore*». I quattro campi nuovi se li portano dietro (47, 49, 53, 58), dicendo che la
forma è cambiata, perché l'istruttoria in Forestas si fa con il modulo in mano.
- **Scartato:** dichiararli «non hanno un numero nel format», come oc:9038 ha fatto per
  `primary_activity`. Quel campo nel modulo non esiste; questi quattro sì, in forma diversa.

### 2026-11-25 — la sezione dei soggetti sta dopo l'attività primaria, non in mezzo alle scelte chiuse
Scritta la prima volta subito dopo la tabella del campo 38, `## I soggetti del sentiero` si prendeva
come sottosezioni `### Campo 36 — activities` e `### L'attività primaria`, che appartengono a
«I valori ammessi dei campi a scelta chiusa». Chi integra cercava `activities` sotto i valori a
scelta chiusa e lo trovava sotto i soggetti. Il blocco è stato spostato dopo l'attività primaria,
senza toccarne il testo. Rilevato da entrambi i revisori.

### 2026-11-25 — il test fra i due esempi ora valida i payload, non i nomi
Il test che doveva impedire all'esempio `curl` e al modulo del bottone di prova di divergere
confrontava solo i **nomi** dei tre campi: un `subject` scritto male nel modulo lo avrebbe lasciato
verde, e il bottone avrebbe mandato una chiamata rifiutata — il rischio dichiarato in
[overview.md](overview.md#rischi). Ora estrae i due payload, verifica che siano identici e li valida
con `ApplicationSchema::rules()`. Sensibilità provata alterando il modulo: il test cade.

### 2026-11-25 — il documento OpenAPI pubblica di nuovo la forma vera dei campi strutturati
Trovato in review: nel documento pubblicato `managing_subjects` usciva con
`items: {"type":"string"}`, cioè una lista di stringhe. Chi genera un client dalla pagina otteneva
il tipo sbagliato. Lo schema giusto Scramble lo ricava dalle regole della Form Request, ma
`AppServiceProvider` appiattiva l'`allOf` tenendo **solo** l'oggetto costruito dagli attributi
`#[BodyParameter]` e buttando via quello delle regole. Ora i due si fondono: tipi, `enum` e `items`
dalle regole, descrizione ed esempio dagli attributi.
Il difetto è precedente a questo ticket — valeva per `activities` e per ogni campo strutturato — ma
con i soggetti diventa visibile, perché è il primo campo i cui elementi sono oggetti.
- **Scartato:** tenere l'appiattimento e riscrivere a mano la forma dell'oggetto nella descrizione.
  Sarebbe stata una terza copia da tenere allineata, e il documento avrebbe continuato a dichiarare
  un tipo falso.
- **Attenzione:** l'appiattimento non si può togliere del tutto. Stoplight Elements, il renderer
  della pagina, non raccoglie i campi di un `multipart/form-data` il cui schema è un `allOf`: il
  bottone di prova partirebbe con il corpo vuoto. Un test lo verifica.

## Follow-up

- **La metà delle descrizioni di questa pagina vive nel package.** Verificato il 25/11/2026
  generando il documento OpenAPI: le descrizioni scritte nei `#[BodyParameter]` del package ci
  finiscono dentro, perché `app/Providers/AppServiceProvider.php:192` filtra le rotte
  `api/v1/sus/` e Scramble legge i controller, che sono del package. Il dettaglio e la decisione
  di correggerlo più avanti sono nelle note del package.
