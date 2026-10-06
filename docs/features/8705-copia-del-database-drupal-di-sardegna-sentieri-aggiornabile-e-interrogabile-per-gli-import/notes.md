> Ticket: oc:8705

# Notes — Copia del database Drupal di Sardegna Sentieri aggiornabile e interrogabile per gli import

## Divergenze dal piano, task per task

### Task 2: verifica degli accenti

La verifica del passo 6 cercava un titolo con `LIKE '%à%'`. Le tabelle della copia sono in
`utf8mb4_0900_ai_ci`, che confronta ignorando gli accenti: la query trovava anche titoli con la
sola `a` («Su Fundu Mannu…»), quindi la verifica passava senza provare nulla. Rifatta con
`COLLATE utf8mb4_bin`: «Janna Sa Chessa, SP 67 - Usinavà, Janna Renosa (G 242)» esce identico da
shell e da Laravel. La trappola è stata aggiunta alla pagina di conoscenza.

## Bug trovati

- **Le API restituiscono anche i nodi non pubblicati**, negli elenchi oltre che nei dettagli. Lo
  studio dello schema aveva concluso dal codice (`accessCheck(TRUE)`) che gli elenchi li
  escludessero; la prova sul sito il 05/10/2026 dice il contrario: `/ss/list-tracks/` ha 761 nodi,
  tutti quelli della copia, compresi i sentieri 545, 560 e 561 (`status = 0`, moderazione
  `draft`, pagina pubblica 403). La pagina di conoscenza è stata corretta. Conseguenza fuori da
  questo ticket: vedi Follow-up.

## Decisioni

- **Copia ricaricata a mano prima del piano**, su richiesta del dev, per studiare lo schema sul
  dump del 05/10/2026: `DROP`/`CREATE` del database e caricamento da `~/Downloads` con
  `SET SESSION sql_log_bin=0`. Il dump `.sql` è stato poi spostato in `storage/drupal-dump/`. La
  copia del 21/04/2026 non è recuperabile: il suo dump non esisteva più.
- **Codice Drupal di riferimento**: clone del repository Acquia al tag `2024-04-10-bis` (quello
  indicato in produzione), fuori dal repo, solo in lettura; la chiave dell'host Acquia è stata
  accettata con `StrictHostKeyChecking=accept-new` su autorizzazione del dev.
- **Stima** impostata dal dev a 2 ore, al posto di quella dell'agente (4,1 ore).
- **La prova della ricarica con lo script** ha messo in cartella sia il `.sql` sia il `.sql.gz`
  con la stessa data: lo script ha caricato il `.sql`, uno solo, come previsto. Il `.gz` è stato
  poi tolto.
- **Procedure spostate in `docs/howto/copia-database-drupal.md`** dopo il controllo di forma:
  accendere, caricare, raggiungere la copia di UAT e clonare il codice Drupal sono «come si fa»;
  nella pagina di conoscenza resta il fatto che la copia va accesa a mano, con il rimando. Riga
  aggiunta nella tabella «Procedure» del `CLAUDE.md`. Tolte dalla regola path-scoped le due
  trappole che ripetevano la pagina.

## Follow-up

- **Ticket generico di sync** della copia (produzione → UAT, UAT → locale): il dev ha chiesto di
  crearlo al termine di questo ticket.
- **L'import via API porta in Forestas anche i sentieri in bozza**: nel Forestas locale ci sono i
  nid 545, 560 e 561, non pubblicati su Drupal. Da verificare in produzione e da decidere se
  escluderli; con le API non si può (lo stato non è esposto), con la copia SQL sì.
- **Rete Docker implicita**: né `sardegnasentieri-dump.compose.yml` né i compose principali
  dichiarano la rete. PHP raggiunge la copia per nome perché entrambi finiscono su
  `<cartella>_default` se lanciati dalla stessa cartella. Il ticket di sync, che porterà la copia
  su UAT, deve verificarlo lì.
