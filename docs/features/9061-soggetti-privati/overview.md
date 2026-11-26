> Ticket: oc:9061
> Stato: approvata il 2026-11-26

# Soggetti privati: la pagina delle API dice cosa non si manda e dove si recupera

## Cosa cambia

La pagina delle API del canale SUS smette di descrivere nome ed e-mail dei soggetti come dati da
mandare sempre. Per un soggetto dichiarato **persona fisica** (`subject: person`) dice che non si
mandano, che se arrivano la risposta è `422`, e che **un segnaposto testuale non è una via
d'uscita**: anche «campo oscurato per motivi di privacy» riceve lo stesso rifiuto.

Dice inoltre **dove si recupera il dato vero**: sul SUS, attraverso il riferimento della pratica
— il `reference` che il SUS manda alla creazione e che Forestas vede in Nova come «External
reference».

Il modulo di prova del bottone «Prova la chiamata» manda un rilevatore privato senza nome né
e-mail, così chi prova vede la forma giusta invece di una che ora sarebbe rifiutata.

La regola vera — la validazione che produce il `422` — sta nel package:
`wm-package/docs/features/9061-soggetti-privati/overview.md`.

## Perché

La pagina Scramble è il modo con cui parliamo a chi integra: su questo canale non si comunica a
parte. Questo ticket è l'unico punto in cui una richiesta fatta in call viene rifiutata — Alessio
Piccioli, 23/11/2026: «magari in quei tre campi lì uno può anche mettere il placeholder "campo
oscurato per motivi di privacy", nel nome e nell'email», e più avanti «però il placeholder ce lo
dovete mettere» — e se la pagina tace è ragionevole che il segnaposto venga provato, scoprendo il
rifiuto come un `422` inatteso.

L'altra metà è il recupero: tolto il nome, Forestas ha comunque bisogno di sapere chi sia quel
privato. Il documento dei 34 quesiti, alla riga 24, lo scrive nello stato del quesito: «Per i
soggetti privato il recupero delle eventuali informazioni da aggiungere in fase post "approved"
devono essere recuperate dal SUS usando il codice identificativo SUS presente». Quel codice è il
`reference`, e la pagina deve dirlo.

## Punto di partenza

Letto sul codice il 26/11/2026, sul branch `feature/sus-2026-seconda-revisione`.

**La prosa della pagina sta in `config/scramble.php`.** Una tabella descrive le tre chiavi
dell'oggetto soggetto (`:419-424`), dove `name` è «la denominazione del soggetto: il nome
dell'ente, o il nominativo della persona»; un'altra elenca i quattro ruoli con quanti soggetti
servono (`:426-432`); un paragrafo intitolato «La `email` dipende da `subject`» spiega la regola
condizionata (`:438-441`), e subito sotto c'è il paragrafo «Il codice fiscale non viene più
richiesto», che è il precedente più vicino: un dato personale tolto dallo scambio, spiegato in
due righe.

La pagina scrive gli accenti come apostrofo (`e'`, `piu'`): è la convenzione già in uso nel file,
e la seguo.

**Il modulo di prova sta in `resources/views/vendor/scramble/docs.blade.php`.** Alla riga 158
precompila `surveying_subjects: '[{"name":"Mario Rossi","subject":"person"}]'` — un privato con
il nome, che dopo questo ticket riceverebbe `422`. Gli altri due ruoli (`:157`, `:159`) mandano
enti con nome ed e-mail e restano validi.

**Il `reference` è obbligatorio su questo shard.** `WM_TRAIL_REGISTRY_REQUIRE_REFERENCE=true` nel
`.env` (`:147`) accende la regola `required` di `StoreApplicationRequest` (`:55-61`): ogni
domanda nata dal canale ha il suo riferimento, quindi il recupero sul SUS ha sempre una chiave.
È una colonna `unique` della tabella, mostrata in Nova come «External reference»
(`wm-package/src/TrailRegistry/Nova/TrailApplication.php:162`).

## Requisiti

### <a id="r1"></a>**R1** — La pagina descrive la regola nuova per nome ed e-mail

La pagina dice che per un soggetto `person` nome ed e-mail non si mandano, che per un
`organization` restano obbligatori, e che un privato che li porta valorizzati riceve `422` con
ruolo e posizione del soggetto. La riga della tabella che descrive `name` non lo presenta più
come un dato sempre dovuto.

**Verifica:** _da compilare alla chiusura._

### <a id="r2"></a>**R2** — La pagina dice che il segnaposto testuale è rifiutato

La pagina nomina esplicitamente il caso del valore convenzionale al posto del dato vero e dice
che riceve lo stesso `422`, senza numeri di ticket nel testo.

*Fonte:* decisione del dev del 26/11/2026, contro la richiesta di Alessio Piccioli in call il
23/11/2026.
**Verifica:** _da compilare alla chiusura._

### <a id="r3"></a>**R3** — La pagina dice dove si recupera il dato del privato

La pagina dice che i dati identificativi di un soggetto privato restano sul SUS e si recuperano
attraverso il riferimento della pratica, nominandolo con il nome che ha nel canale (`reference`)
e con quello che l'operatore vede in Nova («External reference»). Dice anche che i privati di un
ruolo arrivano senza nulla che li distingua fra loro, perché non sia scambiato per una perdita di
dati: la posizione nella lista non è un identificativo, perché una patch che rimanda il ruolo ne
sostituisce la lista intera.

*Fonte:* documento dei 34 quesiti, riga 24, stato del quesito — «devono essere recuperate dal SUS
usando il codice identificativo SUS presente».
**Verifica:** _da compilare alla chiusura._

### <a id="r4"></a>**R4** — Il modulo di prova manda un privato senza dati personali

Il bottone «Prova la chiamata» precompila il rilevatore privato come `[{"subject":"person"}]`, e
la chiamata che ne esce è accettata.

**Verifica:** _da compilare alla chiusura._

## Rischi

**La pagina parla dei soggetti in sei punti e può restare a metà.** Dentro `config/scramble.php`
la regola compare nella tabella delle chiavi (`:421`), nel paragrafo condizionato (`:438-441`),
nella tabella dei `422` (`:478-481`), nella tabella «Se… allora sono obbligatori» con la frase
che la chiude (`:509-512`), nell'esempio JSON della sezione (`:466`) e nell'esempio `curl`
completo (`:568`); fuori di lì ci sono il modulo di prova e le descrizioni dei campi, che stanno
nel package. Un aggiornamento parziale lascia la pagina che si contraddice da sé, ed è già così
oggi in un punto: `:511-512` dice «mandarla con dentro un soggetto senza `name` no». Mitigazione:
tutti e sei i punti sono nei moduli toccati, la verifica si fa rileggendo la sezione intera e non
le righe cambiate, e `SusApiDocumentationTest` valida contro le regole vere i payload estratti
dall'esempio `curl` — quindi quello non può restare indietro in silenzio.

**Il paragrafo cresce.** «La `email` dipende da `subject`» diventa un paragrafo su due campi più
il caso del segnaposto più il recupero sul SUS. Se diventa illeggibile, la pagina perde proprio
sul punto che deve comunicare meglio. Mitigazione: si taglia come il paragrafo sul codice
fiscale, che dice la stessa classe di cosa in due righe.

## Out of scope

**La validazione**, che sta nel package e ha la sua overview.

**Un servizio che restituisca i dati dei privati**: escluso in call, Forestas li prende dal SUS a
mano con il riferimento della pratica.

**Il `reference`**, che esiste già, è obbligatorio su questo shard e si vede in Nova.

## Moduli toccati

Previsti:

- `config/scramble.php`, sei punti:
  - `:421` — la riga della tabella che descrive `name`
  - `:438-441` — il paragrafo «La `email` dipende da `subject`», che diventa su due campi e porta
    la nota su segnaposto e recupero dal SUS
  - `:466` — l'esempio JSON della sezione dei soggetti, dove il rilevatore privato perde il nome
  - `:478-481` — la tabella «Cosa arriva / Cosa risponde», che guadagna la riga del dato personale
  - `:509-512` — la tabella «Se… allora sono obbligatori» e la frase «mandarla con dentro un
    soggetto senza `name` no», che oggi direbbe il contrario della regola nuova
  - `:568` — l'esempio `curl` completo, stessa correzione dell'esempio JSON
- `resources/views/vendor/scramble/docs.blade.php:158` — il rilevatore privato del modulo di prova

La validazione e la documentazione dei campi del canale stanno nel package:
`wm-package/docs/features/9061-soggetti-privati/overview.md`.
