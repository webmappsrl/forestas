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

Nel package le ultime due sono vuote di default. Senza di esse la lista delle anomalie perde i
collegamenti alla fonte, cioè il modo con cui il gestore raggiunge la scheda da correggere. (oc:8492)

`WM_TRAIL_REGISTRY_ENABLED` va tenuta allineata in **sei** posti, di cui uno fuori dal repository:
`.env`, `.env-deploy`, `.env-example`, `phpunit.xml`, `.env.testing-example` (da cui nasce
`.env.testing`, letto dai comandi artisan lanciati con `--env=testing`, dove `phpunit.xml` non
arriva) e il `.env` del server. Solo i primi cinque si vedono in un diff. (oc:8492)

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
