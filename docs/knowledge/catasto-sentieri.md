# Catasto Sentieri su Forestas

**Fonte di verità per il dominio:** [`wm-package/docs/resources/TrailRegistry.md`](../../wm-package/docs/resources/TrailRegistry.md)
— le tre tabelle, gli stati, la provenienza, il service, il comando di normalizzazione e
l'interfaccia Nova valgono per chiunque monti il dominio e si documentano lì.

Questa pagina copre **solo ciò che è di Forestas**: da dove arrivano i dati, quali valori di
configurazione usa questo shard, cosa è stato deciso di non fare.

## Stato attuale

### L'import è la sorgente del codice

`SardegnaSentieriImportService` (`app/Services/Import/SardegnaSentieriImportService.php`) scrive
il codice storico del sentiero in `properties['ref']`, e il catasto lo legge da lì.

Il service **non è parametrizzato** sul nome di quella chiave, ed è una scelta: è custom di questo
progetto, in un altro shard non esiste, ed è proprio lui a decidere come si chiama la chiave. La
parametrizzazione serve a chi la legge senza saperla in anticipo — il comando del package, dove
infatti c'è. **Se quella chiave cambiasse nome va aggiornata `WM_TRAIL_LEGACY_CODE_PROPERTY`**,
altrimenti il comando cerca la chiave vecchia e non trova più nulla. (oc:8489)

### I valori di questo shard

| Chiave | Valore su Forestas | Perché |
|---|---|---|
| `WM_TRAIL_REGISTRY_ENABLED` | `true` | adesione al dominio opzionale |
| `WM_TRAIL_SOURCE_URL_PROPERTY` | `forestas.url` | dove l'import scrive l'indirizzo della scheda su Drupal |
| `WM_TRAIL_SOURCE_LABEL` | `Drupal` | il nome che compare nel collegamento |
| `REGISTRO_CATASTALE_URL` | URL del foglio Google (`config('forestas.registro_catastale.url')`) | sorgente del mirror del registro catastale — [docs/knowledge/registro-catastale.md](registro-catastale.md) (oc:8539) |

Nel package le ultime due sono vuote di default. Senza di esse la lista delle anomalie perde i
collegamenti alla fonte, cioè il modo con cui il gestore raggiunge la scheda da correggere. (oc:8492)

`WM_TRAIL_REGISTRY_ENABLED` va tenuta allineata in **sei** posti, di cui uno fuori dal repository:
`.env`, `.env-deploy`, `.env-example`, `phpunit.xml`, `.env.testing-example` (da cui nasce
`.env.testing`, letto dai comandi artisan lanciati con `--env=testing`, dove `phpunit.xml` non
arriva) e il `.env` del server. Solo i primi cinque si vedono in un diff. (oc:8492)

### I tematismi concordati con Forestas

Il campo 45 del modulo FORES_02 («Tematismo») viaggia sul canale come `themes`, una lista
facoltativa di identifier. L'elenco è chiuso: un valore fuori da questi dieci fa rispondere `422`.

| Identifier | Etichetta |
|---|---|
| `history-archaeology` | Storia e archeologia |
| `mining-geology` | Miniere e geologia |
| `transhumance` | Transumanza e pastorizia |
| `spirituality` | Fede e spiritualità |
| `woods-forests` | Boschi e foreste |
| `flora-fauna` | Flora e fauna |
| `waters` | Acque: fiumi, sorgenti e cascate |
| `sea-coast` | Mare e coste |
| `villages` | Borghi e centri storici |
| `rural-landscape` | Paesaggio agrario e tradizioni rurali |

Proposti da Webmapp e **validati da Alessio Saba**, riportato da Alessio Piccioli nella call del
23/11/2026: «ha validato questa lista e basta». Il formato era stato fissato il 19/11: al Catasto
serve l'array degli identifier, le etichette del portale non si trasmettono — Moreno Santucci:
«se li trasmettiamo, ignorate comunque i label nostri, prendete solo l'identificativo».

Vivono nella costante `THEMES` di `ApplicationSchema` (package) e nella tabella della pagina delle
API (`config/scramble.php`). **L'Excel del format li descrive ancora come testo libero, «Max 600»**:
quella riga è precedente alla decisione sugli identifier e non è stata riscritta. (oc:9039)

### Il canale rifiuta ciò che non è concordato

Dal 25/11/2026 una chiamata che porta una chiave non dichiarata dallo schema — o un valore fuori
dagli elenchi — riceve `422` con un errore che la nomina, invece di essere accettata in silenzio.
Vale anche per i tredici campi della forma precedente dei soggetti. Il meccanismo e il perché sono
nel package: [wm-package/docs/knowledge/operazioni-del-fascicolo-e-canale-dell-ente.md](../../wm-package/docs/knowledge/operazioni-del-fascicolo-e-canale-dell-ente.md).

**Ne segue che un campo nuovo va dichiarato da noi prima che il SUS lo mandi.** Finché non è nello
schema, inviarlo fa fallire la creazione: è il prezzo scelto per non perdere più un refuso dentro
`api_data`. (oc:9039)

### Condizione di go-live

```sql
select count(*) from trail_registry_anomalies where type = 'codice_gia_assegnato';
```

Rilevazione locale (2026-09-12): 9 `codice_gia_assegnato`, 18 `geometria_duplicata`,
7 `settore_discordante`; 588 righe in `trail_registry_codes`. La join di controllo fra
`trail_registry_codes` e `trail_registry_anomalies` torna 0, come il dominio prescrive. (oc:8489)

### Le tracce senza tipo non si toccano

Sono 6 (locale, 2026-09-12) e hanno tutte un `ref`; tutte e 6 hanno già un codice nel registro. Da
quando il catasto guarda il codice e non il tipo, il tipo mancante non nasconde più niente. Il
rischio residuo — un tracciato senza tipo non compare nella Resource `Sentieri` — è stato accettato
dal dev. (oc:8489)

### Nessuna Resource `Sentiero`

Sarebbe un doppione del Registro dei codici, che mostra già codice, denominazione e collegamento al
sentiero. (oc:8489)

## Come ci siamo arrivati

- **«Zero righe in conflitto nel registro» come condizione di go-live** (oc:8489) — caduta: lo
  stato «in conflitto» non esiste più nel dominio, un sentiero in conflitto non entra affatto nel
  registro. Sostituita dal conteggio delle anomalie `codice_gia_assegnato`.
- **Una Resource `Sentiero` astratta**, che selezionava i tracciati con un codice assegnato invece
  di filtrare per tassonomia (oc:8489) — scritta e poi rimossa: stesso doppione dell'altra.
- **«Le istanze sono zero, il troncamento notturno non morde»** (oc:8489) — non regge più: al
  2026-09-12 `trail_applications` ha 2 righe in locale. Il rischio descritto in
  [.claude/rules/import-sardegna-sentieri.md](../../.claude/rules/import-sardegna-sentieri.md) è
  quindi attuale, non futuro.
