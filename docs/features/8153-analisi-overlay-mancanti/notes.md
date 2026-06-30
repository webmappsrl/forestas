> Ticket: oc:8153 — Analisi overlay mancanti (figlio di oc:7605)

# Notes — Analisi overlay mancanti

## Deviazioni dal piano

Nessuna deviazione rilevante. L'analisi ha seguito il flusso previsto.

## Bug trovati

Nessun bug nel codice. Il problema è esclusivamente di configurazione dati.

## Decisioni

- **Geohub come riferimento:** il DB locale non ha dati significativi (ambiente di sviluppo non popolato). Il confronto è stato fatto via API pubblica di Geohub (`/api/v2/app/webmapp/32/config.json`) e API dev/UAT (`/api/app/webapp/1/config`).
- **App Geohub identificata:** l'app "Sardegna Sentieri" in Geohub è id=32 (non id=1 — Geohub e Forestas hanno namespace di ID separati).
- **Pattern dev confermato come corretto:** la FC "Aree Catastali" in dev (id=1) ha il GeoJSON su MinIO locale, non punta direttamente a Geohub. Questo pattern va replicato per tutte le FC mancanti.
- **Meccanismo di upload GeoJSON:** `mode: upload` in Nova non ha un campo File nel form — `file_path` è readonly. Il GeoJSON va caricato su MinIO tramite seeder con `StorageService::storeFeatureCollection()` o upload manuale + aggiornamento di `file_path` via tinker/SQL. `mode: external` non è raccomandato perché crea dipendenza da Geohub lato client al momento del fetch del GeoJSON (non lato server in `AppConfigService`).

## Follow-up

- **oc:7605** — implementazione: creare FC mancanti e aggiornare `config_overlays` in dev e UAT
- **Decisione pendente:** seeder idempotente vs configurazione manuale via Nova. **Raccomandazione: seeder** (vedi `overview.md` → sezione Raccomandazione). Da decidere prima di iniziare oc:7605.
- **Tech debt:** nessun meccanismo di sync automatico tra GeoJSON Geohub e copie locali su MinIO. Da valutare in un ciclo futuro se i dati geografici cambiano frequentemente.
