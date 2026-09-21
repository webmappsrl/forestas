> Ticket: oc:8607

# Il reset dell'import lascia il Catasto senza anomalie

## Cosa cambia

Oggi `sardegnasentieri:import --reset` cancella i sentieri e, con loro, tutte le anomalie del
Catasto; a ricalcolarle è un altro comando, invocato da una callback dello scheduler
(`routes/console.php:34-41`) che parte **quando l'import non ha ancora scritto nulla**.

Dopo questo intervento la ricalcolo è agganciata alla fine effettiva dell'importazione —
cioè al completamento dei job, non al ritorno del comando — e appartiene al `--reset` stesso,
che arrivi dallo scheduler o da una shell. Se non è possibile o fallisce, la cosa viene
dichiarata invece di passare per un'esecuzione riuscita.

Il reset inoltre azzera anche le domande di iscrizione, che oggi sopravvivono al troncamento
lasciando domande senza i codici che avevano riservato.

## Perché

Le anomalie (`trail_registry_anomalies`) sono legate agli `EcTrack` da una foreign key con
`cascadeOnDelete`: il troncamento se le porta via per costruzione. L'unico comando che le
riscrive è `wm-package:trail-registry-normalize`, e oggi vive fuori dal comando che ha
cancellato — in un `->then()` dello scheduler.

Il difetto non è che quella callback abbia sbagliato: è che l'obbligo di ricalcolare sta in un
posto diverso da chi distrugge. Se salta, il risultato è una tabella vuota e nessun errore, e
una lista di anomalie vuota è indistinguibile da un catasto senza anomalie. Su UAT questo stato
è quello attuale: 767 `ec_tracks`, zero anomalie.

**E la callback probabilmente non salta affatto: arriva troppo presto.** L'import non importa,
accoda: `ImportSardegnaSentieriTrackJob::dispatch()` (righe 144 e 226) e `return self::SUCCESS`
(riga 104). Quando il comando termina, i tracciati li sta ancora scrivendo Horizon — il log di
UAT lo conferma, `--reset` chiude in 18 secondi, il tempo di accodare 767 job, non di
importarli. Il normalize parte quindi su un archivio appena troncato, calcola zero anomalie e
esce con successo.

Per questo l'aggancio non può essere la fine del comando: sarebbe lo stesso istante di adesso.

## Requisiti

- [ ] La rigenerazione delle anomalie parte **quando i job di import sono finiti**, non quando
      il comando ritorna: l'archivio su cui si calcola è completo
- [ ] Dopo un `--reset`, le anomalie risultano ricalcolate senza dipendere da
      `routes/console.php`, sia da scheduler sia da lancio a mano
- [ ] Se la rigenerazione non parte o fallisce, la cosa è visibile: non si conclude in silenzio
      come se fosse andata a buon fine
- [ ] Un import senza `--reset` non innesca la rigenerazione: non avendo troncato nulla, non ha
      nulla da ricalcolare
- [ ] `--reset` cancella anche le domande di iscrizione (`trail_applications`) e i loro eventi,
      che oggi sopravvivono al troncamento restando orfane dei codici che avevano riservato
- [ ] Il `->then()` in `routes/console.php` viene rimosso

## Rischi

- **Un archivio parziale produce anomalie sbagliate, non semplicemente poche.** Le anomalie sono
  relazioni fra tracciati (geometrie duplicate, posizioni contese): se un job di import è
  fallito e il normalize gira lo stesso, il risultato non è incompleto, è errato — e ha
  l'aspetto di un risultato valido. *Mitigazione:* la condizione di partenza deve tenere conto
  dei job falliti, non solo della coda vuota.
- **`--reset` combinato con `--only=pois`** tronca comunque `ec_tracks` senza reimportare i
  tracciati: la rigenerazione scriverebbe «catasto vuoto» come stato legittimo. *Mitigazione:*
  definire il comportamento per questa combinazione in fase di piano.
- **Esecuzioni sovrapposte.** Nulla impedisce che la rigenerazione automatica si sovrapponga a
  un normalize lanciato a mano, o a un server non ancora aggiornato che ha ancora il `->then()`.
  *Mitigazione:* prevedere un lock, e mettere in conto l'ordine fra deploy del codice e ricarica
  dello scheduler.
- **Un fallimento rumoroso è un cambiamento per chi guarda l'import.** Rendere visibile un
  errore che oggi passa inosservato è lo scopo dell'intervento, ma qualunque controllo che oggi
  considera «riuscito» quell'import comincerà a segnalare. *Mitigazione:* è un effetto voluto, va
  solo detto a chi presidia UAT.
- **Il rollback del codice non annulla ciò che è stato scritto.** Il normalize scrive codici,
  eventi e anomalie: tornare al commit precedente non li toglie. *Mitigazione:* il backup che
  `--reset` fa prima di troncare (riga 447) resta l'unica rete, e vale verificare che comprenda
  le tabelle del registro.

## Out of scope

- **Rendere osservabile lo scheduler.** L'output del job schedulato va in `/dev/null`, il che
  ha reso impossibile stabilire se il normalize partisse. Va affrontato, ma è un intervento di
  configurazione sul server e non un cambio di comportamento del codice: resta come follow-up.
- **La conferma sperimentale su UAT.** Che la callback parta troppo presto è dedotto dal codice
  e coerente con i 18 secondi nel log, ma non è stato riprodotto: su UAT si guarda soltanto. La
  riproduzione avviene in locale.
- **Il comportamento del normalize.** Non si tocca `TrailRegistryNormalizeCommand` né nulla nel
  package: viene solo invocato da un punto diverso.
- **Un ripristino retroattivo** delle anomalie mancanti su UAT: le ricalcolerà il primo reset
  dopo il rilascio.

## Moduli toccati

Tutto nel repo principale `forestas`; il submodule `wm-package` non viene modificato.

| File | Repo | Cosa |
|---|---|---|
| `app/Console/Commands/ImportSardegnaSentieriCommand.php` | forestas | aggancia la rigenerazione alla fine dei job quando `--reset` è attivo; `resetData()` azzera anche `trail_applications` |
| `routes/console.php` | forestas | rimozione del `->then()` |
| `app/Jobs/ImportSardegnaSentieri*Job.php` | forestas | eventuale raggruppamento in batch, se è la strada scelta per sapere quando l'import è finito |
| `tests/` | forestas | copertura del nuovo comportamento |
