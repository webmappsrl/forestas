# Catasto Sentieri — i 37 sentieri rimasti senza codice

> Riferito ai dati dell'ambiente di collaudo al 10/09/2026.
> Ticket oc:8489 — identificazione automatica del sentiero, codice REI.
>
> Le domande sul funzionamento a regime sono in un documento a parte,
> «Catasto Sentieri — domande per Forestas».

## Dove siamo

La piattaforma legge il codice di ogni sentiero, verifica che il settore scritto nel codice sia
davvero quello in cui il sentiero passa, e tiene il tutto in un registro consultabile dal
backoffice, in **Catasto → Registro dei codici**.

| | |
|---|---|
| Sentieri con un codice | **578** |
| Sentieri che dovrebbero averlo e ne sono rimasti senza | **37** |
| Sentieri fuori da ogni settore | 0 |
| Codici illeggibili | 0 |

**Ogni riga della schermata Anomalie è un sentiero senza codice.** Non è un elenco di
imperfezioni da sistemare con comodo: è la lista di ciò che manca. Un sentiero che compare lì non
ha un numero — nemmeno provvisorio, nemmeno parziale — e continuerà a non averlo finché il dato
all'origine non viene sistemato. Le 37 righe sono quindi 37 sentieri scoperti, ed è il motivo per
cui questa lista va svuotata prima di andare in esercizio.

Il registro, all'opposto, contiene solo codici su cui non pende alcun dubbio: se un sentiero è lì
dentro, il suo numero è definitivo.

I 37 non sono un errore della piattaforma, ma i casi in cui **il dato di partenza non consente di
decidere** — e ciascuno porta accanto il motivo.

### Gli altri tracciati senza codice

Nell'archivio ci sono altri tracciati privi di numero, e stanno bene così: sono in gran parte gli
**itinerari**, che un codice REI non lo prevedono. Non compaiono fra le anomalie proprio perché
non è previsto che ne abbiano uno: le 37 righe riguardano solo chi il codice dovrebbe averlo.

## Come si correggono

**Le correzioni si fanno su Drupal, non sulla nuova piattaforma.**

Ogni riga della schermata Anomalie porta due collegamenti: il nome del sentiero apre la sua
scheda nella nuova piattaforma, l'icona accanto apre quella su Drupal. Si corregge lì.

Ogni notte la piattaforma si riallinea a Drupal: rilegge tutto da capo, riassegna i codici e
riscrive la lista. **Una riga sistemata alla fonte sparisce da sola il giorno dopo**, e se nel
frattempo il codice è diventato assegnabile il sentiero lo riceve senza che nessuno debba
intervenire.

Non serve quindi comunicarci le correzioni: si vedono da sé.

### Cosa fare, secondo il tipo di anomalia

La colonna **Tipo** dice quale dei cinque casi è, e ciascuno si chiude in un modo diverso.

| Tipo | Cosa dice | Come si risolve su Drupal |
|---|---|---|
| **Codice già assegnato** | quel numero ce l'ha già un altro sentiero, che il dettaglio nomina | Si decide chi lo tiene. Se le due schede sono lo stesso sentiero, si elimina quella di troppo; se sono sentieri diversi, si cambia il codice a uno dei due |
| **Geometria duplicata** | due o più schede hanno la stessa identica traccia | Si elimina la scheda di troppo. Se invece i sentieri sono davvero due, si carica il GPX giusto su quella che ha il tracciato sbagliato |
| **Settore discordante** | la cifra del settore nel codice non è quella del settore attraversato | Si corregge il codice nel campo dedicato, mettendo la cifra del settore giusta |
| **Codice illeggibile** | dal campo del codice non si ricava un numero valido | Si riscrive il codice in una forma leggibile. Il dettaglio propone il primo numero libero del settore, ma è solo un'indicazione: il numero giusto è quello sulla segnaletica in campo |
| **Fuori da ogni settore** | la traccia non ricade in alcun settore CAI | Si verifica il tracciato: di norma è il GPX a essere sbagliato o fuori posto |

Oggi sul collaudo compaiono solo i primi tre tipi; gli ultimi due sono elencati perché possono
presentarsi in futuro.

**Due cose da sapere prima di mettere mano alle schede:**

- **Il codice va scritto nel campo dedicato, non nel nome.** La piattaforma lo legge anche dal
  nome, quando è fra parentesi in fondo, ma è un ripiego: finché sta solo lì, ogni riformulazione
  del titolo rischia di portarselo via. Se correggendo un'anomalia vi accorgete che il campo è
  vuoto, è il momento buono per riempirlo.
- **Correggere una scheda può scoprirne un'altra.** Liberando un numero, il sentiero che lo
  rivendicava lo ottiene; ma se al posto giusto c'è già qualcun altro, comparirà una nuova riga
  al posto di quella risolta. È normale: la lista si accorcia nel giro di qualche passaggio, non
  necessariamente tutto in una notte.

## Da dove la piattaforma legge il codice

Prima dal **campo dedicato** della scheda. Se quel campo è vuoto, prova a leggerlo dal **nome**,
quando è scritto fra parentesi in fondo — la forma `(G 106)`, `(D 180 A)` che ricorre
nell'archivio.

La distinzione conta, perché una correzione nel nome e una nel campo non sono la stessa cosa:
nelle tabelle che seguono la colonna **Codice** dice sempre da dove viene, e dove è preso dal
nome è segnalato esplicitamente. Il campo resta comunque il posto giusto in cui scriverlo: finché
sta solo nel nome, ogni riformulazione del titolo rischia di portarselo via.

Su 578 codici assegnati, **555 vengono dal campo e 23 sono stati letti dal nome**.

---

## 1. Ventidue sentieri hanno la traccia identica a un altro

Undici coppie di schede con lo **stesso identico tracciato**, al centimetro. Finché non si sa
quale delle due è quella buona, **nessuna delle due riceve un codice**: assegnarlo a caso
significherebbe dare un numero a una scheda che forse non dovrebbe esistere.

### 1a. Stesso codice scritto in due forme diverse — 6 coppie

Entrambe le schede hanno il codice **nel campo dedicato**, ma scritto diversamente: una con la
lettera dell'area, l'altra senza. È lo stesso numero, quindi con ogni probabilità lo stesso
sentiero inserito due volte.

| | Scheda A | Codice A | Scheda B | Codice B |
|---|---|---|---|---|
| 1 | #468 San Costantino: Sedilo - Guado Pedra Lada | `G-611` | #486 stesso nome + «G-611» | `611` |
| 2 | #470 Fontana Putzola | `G-611A` | #488 stesso nome + «G-611A» | `611A` |
| 3 | #472 Frontigheddu | `G-611B` | #489 stesso nome + «G-611B» | `611B` |
| 4 | #474 Borta Melone | `T-511C` | #497 «Borta melone T-511C» | `511C` |
| 5 | #466 Villasalto - Belvedere | `C-401A` | #477 Villasalto - Belvedere Su Pardu | `401A` |
| 6 | #52 Su Mudregu: Guado Pedra Lada… (T 611) | `611` | #471 stesso nome senza codice | `T-611` |

### 1b. Codice identico in entrambe — 2 coppie

| | Scheda A | Scheda B | Codice (in entrambe) |
|---|---|---|---|
| 7 | #552 Murrelis - Sa Pedra Carpida | #589 stesso nome | `Z-NU-G-643-A` |
| 8 | #529 Gutturu Melsi - Su Barracconi | #591 Da Gutturu Melfi a Si Barracconi | `327` |

### 1c. Codici realmente diversi, traccia identica — 2 coppie

Qui non è una questione di forma: i due codici indicano sentieri diversi, ma il tracciato è lo
stesso. Delle due, una ha il GPX sbagliato.

| | Scheda A | Codice A | Scheda B | Codice B |
|---|---|---|---|---|
| 9 | #152 Funtana de sos fangos - Nuraghe S'Ulivera | `Z-NU-G-624` | #154 S'Utturu de S'Iscala Ezza | `Z-NU-G-624C` |
| 10 | #624 Su Caminu 'e Carru - S'Ena 'e Talisi | **dal nome:** `G 102` | #639 Centro Servizi Crastazza - Nodu Battista | **dal nome:** `G 202` |

Nella coppia 10 nessuna delle due schede ha il campo del codice compilato: entrambi i numeri sono
stati letti dal nome. E sono due sentieri distinti, in due settori diversi (1 e 2), con la stessa
traccia.

### 1d. Una scheda senza codice — 1 coppia

| | Scheda A | Scheda B |
|---|---|---|
| 11 | #467 Sa grutta 'e Scusi — **nessun codice, né nel campo né nel nome** | #333 Su Pardu - Sa gruta 'e Scusi (C-401B) — `Z-SU-C-401B` |

**Le domande:**

1. Nei gruppi **1a** e **1b** (otto coppie) si tratta della stessa scheda inserita due volte:
   quale si elimina? Nel gruppo 1a le due si distinguono solo per come è scritto il codice — la
   forma con la lettera dell'area (`G-611`) o quella senza (`611`)?
2. Nel gruppo **1c** i codici sono diversi davvero: è il tracciato di una delle due a essere
   sbagliato, cioè è stato caricato il GPX dell'altra?
3. Nel caso **11**, la scheda #467 è un doppione da eliminare oppure un sentiero a sé a cui manca
   il codice?

## 2. Otto sentieri hanno un codice che appartiene già a un altro

Due schede rivendicano lo stesso numero. La piattaforma lo lascia a chi lo ha ottenuto per primo
e segnala l'altra.

| Codice conteso | Chi lo tiene | Suo codice | Chi resta senza | Suo codice |
|---|---|---|---|---|
| ZNUC505A | #462 Sa Brecca, raccordo C-521 e C-505 | `C-505A` | #619 Genna 'e meri - Funtana sa canna | `C-505A` |
| ZNUG101 | #57 Chiesa de La Solitudine - Redentore | `101` | #81 Da La Solitudine al Redentore | `G-101` |
| ZNUG106 | #426 Corru 'e mandra - Badde Viola | `G-106` | #629 Nuscalè - SP 45 - Janna 'e Ferulargiu | **dal nome:** `G 106` |
| ZNUT114 | #461 Montarbu – Flumini de Tula | `T-114` | #613 Montarbu – Flumini de Tula | `Z-NU-T-114` |
| ZORT513 | #473 Nolau | `T-513` | #491 Nolau | `513` |
| ZORT513A | #291 Monte Cresia | `T-513A` | #493 Monte Cresia T-513A | `513A` |
| ZSUD322 | #203 Tinni' - Scoveri | `Z-SU-D-322` | #522 Da eliporto (bivio 324) a Case Marganai | `D 322` |
| **ZSUT110** | **#459 Seui - Seulo** | **`ex T-110`** | **#623 Sorgente Su Scurzu - Seui** | **`T-110`** |

I casi non sono tutti uguali:

- in **cinque** (ZNUG101, ZNUT114, ZORT513, ZORT513A, ZNUG106) le due schede sembrano descrivere
  **lo stesso percorso**, con il nome riformulato e il codice scritto in due forme;
- in **ZNUC505A** i due nomi sono di luoghi diversi, ma il codice è scritto identico in entrambe;
- in **ZSUD322** sono due sentieri distinti che si contendono davvero il numero;
- **ZSUT110 è un caso a sé, e va guardato per primo** (sotto).

### Il caso `ex T-110`: il sentiero dismesso ha preso il numero di quello attivo

La scheda #459 ha nel campo del codice il valore **`ex T-110`**. La piattaforma non sa che «ex»
significhi dismesso: legge il numero, glielo assegna, e di conseguenza **#623 — che ha `T-110`,
il codice vero — è rimasto senza numero**.

È l'unico caso in cui il risultato prodotto è chiaramente sbagliato, e si sistema in due modi
diversi a seconda della risposta:

- se «ex» è una convenzione **occasionale**, basta liberare quel campo sulla scheda #459;
- se è una convenzione **usata sistematicamente** per i tracciati dismessi, ce lo dite e la
  piattaforma impara a riconoscerla, ignorando quei codici invece di assegnarli.

**Le domande:**

4. Dove le due schede sono lo stesso percorso (i cinque casi), quale si tiene?
5. Dove sono sentieri diversi (ZNUC505A, ZSUD322), a chi resta il numero e a chi se ne assegna
   uno nuovo?
6. **`ex` è una convenzione vostra per i sentieri dismessi?** Se sì, quanti sentieri la usano?

## 3. Sette codici da correggere: il settore scritto non è quello attraversato

Il codice REI contiene la cifra del settore. In sette casi quella cifra **non coincide** con il
settore in cui la traccia effettivamente passa.

I perimetri dei settori sono quelli ufficiali CAI, quindi non sono in discussione: dove i due
dati divergono, **è il codice a essere sbagliato**. Questi sette sentieri restano senza numero
finché il codice non viene corretto alla fonte.

Sei delle sette posizioni corrette sono libere: basta correggere il codice e il sentiero riceve
il numero al primo allineamento.

| Codice attuale | Codice corretto | Sentiero | Posizione |
|---|---|---|---|
| `611C` | **ZORT511C** | #490 Nuraghe Busurtei G-611C | libera |
| `Z-SS-E-207` | **ZSSE307** | #95 Padria - SP11 (E 207) | libera |
| `Z-SS-G-506-B` | **ZSSG606B** | #710 Baduladu Foresta Burgos - Nuraghe sa Costa (G 602 B) | libera |
| `Z-SS-G-506-D` | **ZSSG606D** | #712 Sa Pala e Sa Trae - Convento di Monte Rasu (G 602 D) | libera |
| `Z-SS-G-506-E` | **ZSSG606E** | #711 Sos Nibberos - Monte Rasu (G 602 E) | libera |
| `Z-SU-D-132` | **ZSUD332** | #689 Da Gutturu Abis a Scoveri (D 132) | libera |
| `Z-SU-D-131` | ZSUD331 | #72 Sorgente s'Ega Bogas - Cuccurdoni Mannu (D 131) | **occupata** |

**Il settimo caso non si chiude da solo.** Correggendo `Z-SU-D-131` in `Z-SU-D-331`, il sentiero
#72 andrebbe a occupare una posizione che è già di **#758 Miniera Arenas - Tramogge Pubusinu**,
il cui codice è appunto `Z-SU-D-331`. Sono due sentieri diversi: uno dei due dovrà prendere un
numero differente.

In tutti e sette il codice è nel **campo dedicato**, non nel nome. Nella scheda di ciascuna
anomalia la mappa mostra entrambi i settori, quello dichiarato dal codice e quello attraversato:
si vede a colpo d'occhio quanto è ampio lo scarto.

Nei tre casi `Z-SS-G-506-*` la correzione è confermata da un secondo indizio: **il nome del
sentiero dice già `G 602`**, cioè settore 6, come la traccia. È il solo codice a dire 5.

**La domanda:**

7. Confermate la correzione dei sei codici la cui posizione è libera? Numero e variante restano
   quelli, cambia solo la cifra del settore.
8. Per il settimo (#72 contro #758, entrambi su `D-331`): a quale dei due resta il numero, e
   quale ne riceve uno nuovo?

## Una nota sui numeri

Ai 37 sentieri senza codice **non ne assegniamo uno d'ufficio**, ed è una scelta.

In circa metà dei casi le due schede in gioco sono lo **stesso sentiero** entrato due volte:
dargli un numero nuovo significherebbe creare un codice per un sentiero che non dovrebbe
esistere, e quel numero finirebbe sulla segnaletica. Prima si sciolgono i doppioni, poi si vede
quanti sentieri restano davvero scoperti — presumibilmente pochi.

Per lo stesso motivo, dove il codice è illeggibile o assente non ne inventiamo uno: **il numero
giusto è quello già presente sulla segnaletica in campo**, e lo conosce solo chi il sentiero lo
gestisce.
