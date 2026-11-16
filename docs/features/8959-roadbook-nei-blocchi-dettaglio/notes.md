> Ticket: oc:8959

# Notes — roadbook nei Blocchi Dettaglio

## Deviazioni dal piano

Task 4: la prova non ha usato una copia locale di Drupal (in locale il container non c'è) ma quella di
UAT, attraverso il tunnel sulla 3307 (`docs/howto/copia-database-drupal.md`), con i job finti. Tracce
reimportate e controllate anche nel file su AWS: B 401 (nid 1043, 10 tappe it/en), CMSB tappa 1 (nid
1266, 2 tappe solo in italiano: l'inglese non ha testi propri), E 118 (nid 164, 5 tappe it/en, prima
10 paragrafi inglesi accumulati), CMSB tappa 18 (nid 1289, «Lasciata Nuxis…», l'esempio dello
screenshot di sardegnasentieri.it). In tutte `forestas.roadbook` non c'è più.

## Decisioni (col dev, 2026-11-12)

- Un blocco `info` con una riga per tappa (non un blocco per tappa), titolo «Tappa N» come su
  sardegnasentieri.it: i titoli non sono nei dati di Drupal, li generava il tema.
- Specchio di Drupal: l'import riscrive il blocco del roadbook, gli altri blocchi restano.
- `roadbook` tolto del tutto: DB, dettaglio, Nova, chiavi traducibili.
- Lingue lette alla fonte per lingua (`roadbook_stages`), non sottratte dalla lista accumulata
  (challenge): la sottrazione svuotava o spostava le tappe (sentiero 1266, inglese senza testi propri).
  Titoli solo nelle lingue in cui la tappa ha un testo.
- Nello stesso branch del dettaglio del POI: un solo merge in più per oc:8959.

## Da fare al deploy

- Dev e UAT: il blocco arriva al reset notturno dopo il deploy; fino ad allora il dettaglio non ha né
  `forestas.roadbook` né `config_detail` (nessun frontend legge ancora nessuno dei due).
- Dove non c'è il reset: `sardegnasentieri:import --force` una volta, perché l'import orario rilegge
  solo i nodi cambiati su Drupal.

## Follow-up

- Avvisare Rubens: il roadbook è in `config_detail`, `forestas.roadbook` non c'è più.
- Un rollback del codice non toglie i blocchi già scritti.
