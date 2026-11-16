> Ticket: oc:8959

# Roadbook nei Blocchi Dettaglio

Quarto pezzo di oc:8959, nello stesso branch del dettaglio del POI
(`feature/oc-8959-poi-nel-dettaglio`): oc:8959 entra in `develop` con un solo merge in più.

## Cosa cambia

L'import scrive il roadbook di Drupal (`field_roadbook`) nei Blocchi Dettaglio della traccia
(`properties.config_detail`, campo Nova «Detail Blocks» del package) invece che in
`properties.forestas.roadbook`: un blocco `info` con una riga per tappa, titolo «Tappa N» e il
paragrafo di Drupal come contenuto, come lo mostra sardegnasentieri.it.

```json
"config_detail": [
  {"box_type": "info", "items": [
    {"title": {"it": "Tappa 1", "en": "Stage 1"}, "content": {"it": "<p>Lasciata Nuxis, dopo circa <strong>3,5 km</strong>…</p>", "en": "<p>…</p>"}},
    {"title": {"it": "Tappa 2", "en": "Stage 2"}, "content": {"it": "<p>Ritornati sulla strada principale…</p>", "en": "<p>…</p>"}}
  ]}
]
```

`roadbook` sparisce del tutto: l'import non lo scrive più in `forestas`, il dettaglio della traccia
non lo porta, il campo Nova in sola lettura si toglie.

## Perché

Scrum del 2026-11-10: il roadbook «va tolto da Forestas» e diventa un blocco del dettaglio, popolato
dall'import (l'attributo diventa deprecato). Il WordPress (Rubens) aspetta i blocchi per la sezione
delle tappe.

## Requisiti

- [ ] L'import (traccia importata o aggiornata, reset compreso) scrive in `properties.config_detail`
      un blocco `info` con una riga per tappa: `title` = «Tappa N» (it), «Stage N» (en), «Étape N»
      (fr), «Etapa N» (es), «Etappe N» (de); `content` = il paragrafo HTML di quella lingua.
- [ ] Ogni lingua ha i **suoi** paragrafi, letti dal record di Drupal per lingua senza accumularli
      (challenge, decisione del dev): la lista salvata oggi ripete in ogni lingua i paragrafi delle
      precedenti, e una lingua senza testi propri riporta solo quelli (sentiero 1266: `en` uguale a
      `it`), quindi sottrarre dalla lista sposterebbe o svuoterebbe le tappe. Il numero di tappe è il
      massimo fra le lingue; titolo e contenuto di una tappa escono solo nelle lingue in cui ha un testo.
      `info_utili` e `come_arrivare` restano come sono (`accumulatedTexts()`).
- [ ] Specchio di Drupal (decisione del dev): l'import riscrive il blocco del roadbook a ogni giro in cui
      rilegge la traccia; gli altri blocchi di `config_detail` restano. Il blocco del roadbook è quello
      le cui righe si chiamano tutte «Tappa N» (in italiano); se non c'è, va in testa. Una traccia senza
      roadbook su Drupal perde il blocco del roadbook, non gli altri.
- [ ] `properties.forestas.roadbook` non si scrive più; il dettaglio della traccia non ha più
      `forestas.roadbook`; il campo Nova «Roadbook» sparisce; `roadbook` esce dalle chiavi traducibili
      del modello.
- [ ] `config_detail` esce nel file della traccia su AWS (verificare che `removeInvalidProperties()`
      non lo tolga).

## Rischi

- **Il blocco si riconosce dai titoli**: il package, al salvataggio in Nova, riscrive ogni blocco solo
  con `box_type` e `items`, quindi un segno messo dall'import si perderebbe. Se la redazione rinomina
  una tappa in Nova, al giro dopo l'import non riconosce più il blocco e ne aggiunge uno nuovo in testa:
  finché Drupal è vivo il roadbook si corregge su Drupal.
- **Dati già salvati**: `forestas.roadbook` resta nelle tracce finché l'import non le riscrive (dev e
  UAT: al reset notturno). Nessuna migration.
- **Nessun frontend legge ancora `config_detail`** (né wm-core né il WordPress): va avvisato Rubens.
  Il tema del WordPress non legge nemmeno `roadbook` (verificato), quindi toglierlo non rompe nulla.
- **Finestra dopo il deploy**: il dettaglio perde `forestas.roadbook` subito, `config_detail` arriva
  quando l'import riscrive la traccia (dev e UAT: reset della mattina dopo). Dove il reset non c'è,
  serve `sardegnasentieri:import --force` una volta dopo il deploy: l'import orario rilegge solo i
  nodi cambiati su Drupal.
- **Il reset notturno cancella anche i blocchi aggiunti in Nova** (`TRUNCATE ec_tracks`), come tutto
  il resto delle tracce, finché esiste.
- **Un blocco creato a mano con righe «Tappa N»** sarebbe preso per il roadbook e riscritto.
- **Rollback**: riportare il codice non toglie i blocchi già scritti; servirebbe uno script.

## Out of scope

- Blocchi per i POI (`come_arrivare` resta in `forestas.directions`).
- Altri tipi di blocco oltre `info`.

## Moduli toccati

forestas:
- `app/Services/Import/SardegnaSentieriImportService.php` (scrittura del blocco, niente più
  `forestas.roadbook`), con una classe di supporto per costruire il blocco.
- `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` e DTO dell'import, se serve separare
  le lingue alla fonte.
- `app/Models/EcTrack.php` (chiavi traducibili), `app/Nova/EcTrack.php` (campo Roadbook),
  `app/Support/ForestasTrackDetail.php`.
- Test dell'import e del dettaglio; documentazione (`docs/knowledge/dettaglio-della-traccia-dati-forestas.md`,
  `notes.md` del pezzo).
