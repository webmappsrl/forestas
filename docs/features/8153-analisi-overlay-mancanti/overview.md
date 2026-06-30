> Ticket: oc:8153 — Analisi overlay mancanti (figlio di oc:7605)

# Analisi overlay mancanti in dev e UAT

## Cosa cambia

Questo documento è il risultato dell'analisi tecnica richiesta da oc:8153. Non introduce modifiche al codice — documenta lo stato attuale, cosa manca e come va risolto nell'implementazione (oc:7605).

## Come funzionano gli overlay in Forestas

Gli overlay della mappa sono gestiti tramite modelli `FeatureCollection` (tabella `feature_collections`) collegati all'App via il campo JSONB `config_overlays` su `apps`.

**Struttura `config_overlays`:**
```json
{
  "OVERLAYS": [
    { "box_type": "title", "label": {"it": "Dettagli Mappa"} },
    { "box_type": "feature_collection", "feature_collection": 1, "name": "Aree Catastali" }
  ]
}
```

**Ciclo di vita di un overlay:**
1. Si crea una `FeatureCollection` via Nova (nome, label IT/EN, app, stile, mode)
2. Il GeoJSON viene reso disponibile tramite uno di questi meccanismi:
   - `mode: external` → `getUrl()` restituisce `external_url` direttamente (il client fa fetch da quell'URL a runtime)
   - `mode: generated` → l'observer dispatcha `GenerateFeatureCollectionJob` che genera il GeoJSON dai layer e lo salva su MinIO (`file_path`)
   - `mode: upload` → **non ha logica automatica in Nova** (nessun campo File nel form, `file_path` è readonly). Il file va caricato su MinIO manualmente o via seeder usando `StorageService`, e `file_path` va impostato direttamente sul record.
3. Si aggiunge la FC al campo Flexible `config_overlays` dell'App in Nova (dopo la creazione, per usare l'ID reale)
4. Al salvataggio di `config_overlays`, `UpdateAppConfigJob` rigenera il JSON di config → `MAP.controls.overlays`

Il metodo `AppConfigService::config()` legge `config_overlays`, risolve gli ID delle FC abilitate (`enabled = true`) e costruisce l'array `MAP.controls.overlays` servito all'app mobile/webapp.

**Pattern usato in dev (da replicare):** la FC id=1 "Aree Catastali" ha il GeoJSON fisicamente salvato su MinIO (non punta direttamente a Geohub). Questo è il pattern corretto da seguire. Il meccanismo concreto per replicarlo è via seeder con `StorageService::storeFeatureCollection()` oppure upload diretto su MinIO + aggiornamento manuale di `file_path` via tinker/SQL.

## Stato attuale per ambiente

| Ambiente | Overlay presenti | Note |
|---|---|---|
| **Geohub** (app 32) | Titolo + 4 FC | Fonte di riferimento |
| **Dev** | Titolo + 1 FC | Solo "Aree Catastali" (FC id=1) |
| **UAT** | Nessuno | `config_overlays` vuoto |

## Overlay in Geohub (ordine da rispettare)

| # | Label IT | Label EN | Fill (`fill_color`) | Stroke (`stroke_color`) | SW | GeoJSON source (URL completo) |
|---|---|---|---|---|---|---|
| title | Dettagli Mappa | Map Details | — | — | — | — |
| 1 | Aree geografiche | Geographical areas | `rgba(166,28,0,0.4)` | `rgba(166,28,0,1)` | 2 | `https://geohub.webmapp.it/api/export/taxonomy/geojson/32/AreeGeograficheConLayers.geojson` |
| 2 | Aree forestali | Forest areas | `rgba(106,168,79,0.5)` | `rgba(106,168,79,1)` | 2 | `https://geohub.webmapp.it/api/export/taxonomy/geojson/32/aree-forestali.geojson` |
| 3 | Aree catastali | Cadastral sectors | `rgba(17,85,204,0.5)` | `rgba(17,85,204,1)` | 2 | `https://geohub.webmapp.it/api/export/taxonomy/geojson/32/AreeCatastaliconLayers.geojson` |
| 4 | Aree montane | Mountain areas | `rgba(120,63,4,0.5)` | `rgba(120,63,4,1)` | 2 | `https://geohub.webmapp.it/api/export/taxonomy/geojson/32/aree_montane_2%20%281%29.geojson` (nome file: `aree_montane_2 (1).geojson`) |
| 5 | Aree no caccia | Hunting areas | `rgba(153,0,255,0.5)` | `rgba(153,0,255,1)` | 2 | `https://geohub.webmapp.it/api/export/taxonomy/geojson/32/aree-no-caccia-2026.geojson` |

> **Nota colori:** `fill_color` e `stroke_color` accettano sia valori hex (`#RRGGBB`) che rgba. `hexToRgba()` in `AppConfigService` passa i valori rgba as-is al frontend. I valori in tabella sono già nel formato corretto da inserire nel DB.

Gli SVG delle icone sono recuperabili dalla risposta API di Geohub: `GET https://geohub.webmapp.it/api/v2/app/webmapp/32/config.json` → `MAP.controls.overlays[*].icon`.

## Cosa manca per ambiente

**Dev** — mancano 4 FeatureCollections:
- Aree geografiche
- Aree forestali
- Aree montane
- Aree no caccia

E `config_overlays` va aggiornato per includerle nell'ordine corretto (attualmente contiene solo il titolo + Aree Catastali).

**UAT** — mancano tutti gli overlay. Vanno create **4 FeatureCollections** (Aree geografiche, forestali, catastali, montane, no caccia — il titolo "Dettagli Mappa" è un `box_type: title`, non una FC) e configurato `config_overlays` con il box titolo + le 4 FC.

## Requisiti per l'implementazione (oc:7605)

- [ ] **Prerequisito:** verificare che il bucket `wmfe` esista e sia accessibile in UAT prima di iniziare (es. `aws s3 ls s3://wmfe --endpoint-url=<MINIO_URL>` o verifica via console MinIO)
- [ ] **Prerequisito:** esportare il valore attuale di `config_overlays` (backup manuale) prima di modificarlo in ciascun ambiente
- [ ] Creare le 4 FeatureCollections mancanti in dev con `enabled = true`, GeoJSON scaricato da Geohub e salvato su MinIO tramite seeder (`StorageService::storeFeatureCollection()`) o upload manuale + aggiornamento `file_path` via tinker
- [ ] Aggiornare `config_overlays` dell'App in dev: aggiungere il box titolo + le 4 nuove FC usando gli **ID effettivi** assegnati dal DB di dev (non copiare ID da altri ambienti)
- [ ] Creare **4 FeatureCollections** in UAT (Aree geografiche, forestali, catastali, montane, no caccia) con `enabled = true` — il box titolo si configura in `config_overlays` senza creare una FC
- [ ] Aggiornare `config_overlays` dell'App in UAT con box titolo + 4 FC, usando gli **ID effettivi** assegnati dal DB di UAT
- [ ] Copiare gli SVG delle icone dalla risposta `GET /api/v2/app/webmapp/32/config.json` di Geohub → campo `icon` di ciascuna FC
- [ ] Verificare che il JSON `MAP.controls.overlays` risultante abbia la stessa struttura, ordine e stili di Geohub (gli URL punteranno a MinIO locale — non a Geohub — è il comportamento atteso)

## Rischi

- **ID divergenti tra ambienti:** `config_overlays` contiene ID assoluti delle FC. Un ID sbagliato non produce errore — l'overlay scompare silenziosamente. Mitigazione: configurare `config_overlays` sempre *dopo* aver creato le FC, usando gli ID reali del DB target.
- **URL con anno nel nome file:** `aree-no-caccia-2026.geojson` contiene l'anno e potrebbe cambiare o sparire nel 2027. Scaricare e salvare su MinIO al momento dell'import.
- **Aggiornamento futuro dei GeoJSON:** se i dati su Geohub vengono aggiornati, le copie locali su MinIO diventano stantie. Non esiste oggi un meccanismo automatico di sync — tech debt consapevole.
- **MinIO non configurato in UAT:** se il bucket `wmfe` non esiste, le FC vengono create nel DB ma il GeoJSON non è servibile — errore silenzioso visibile solo sulla mappa.
- **Corruzione del JSON `config_overlays`:** un edit manuale malformato in Nova svuota gli overlay senza fallback. Backup manuale obbligatorio prima di ogni modifica.

## Out of scope

- Non si modifica la logica del codice (nessuna modifica a `AppConfigService`, `FeatureCollection` model, Nova)
- Non si automatizza la sincronizzazione periodica con Geohub
- Non si tocca l'ambiente di produzione (non esiste)

## Moduli toccati (in fase di implementazione)

| Modulo | Dove | Tipo di modifica |
|---|---|---|
| `feature_collections` table | Dev DB, UAT DB | Nuovi record (dati) |
| `apps.config_overlays` (id=1) | Dev DB, UAT DB | Aggiornamento JSON |
| MinIO `wmfe` bucket | Dev, UAT | Upload GeoJSON files |

## Raccomandazione: seeder idempotente vs configurazione manuale

**Raccomandazione: usare un seeder idempotente** (`database/seeders/OverlaysSeeder.php`).

Motivazioni tecniche:
- **Causa radice:** UAT è vuoto probabilmente perché non esiste un meccanismo riproducibile — il seeder risolve la causa, non solo il sintomo
- **Rollback:** `php artisan db:seed --class=OverlaysSeeder` è idempotente e reversibile; la configurazione manuale via Nova non lo è
- **Produzione futura:** quando esiste un ambiente di produzione, il seeder lo popola senza procedura manuale ad-hoc
- **Auditabilità:** il seeder è nel repository e versionato — la configurazione manuale non lascia traccia

La configurazione manuale via Nova rimane valida come approccio veloce one-shot, ma introduce tech debt che si paga all'arrivo della produzione.

### Stima ore per approccio manuale

Volume: 9 FeatureCollections totali (4 in dev, 5 in UAT) × 2 ambienti distinti.

| Fase | Tempo |
|---|---|
| Preparazione (backup `config_overlays`, fetch SVG icone da Geohub, verifica MinIO UAT) | ~30 min |
| Dev: 4 FC (download GeoJSON + MinIO + Nova + tinker) + aggiornamento `config_overlays` | ~1.5h |
| UAT: 5 FC (download GeoJSON + MinIO + Nova + tinker) + aggiornamento `config_overlays` | ~2h |
| Verifica finale (API check su entrambi gli ambienti) | ~20 min |
| Buffer (MinIO non configurato in UAT, ID sbagliati, problemi accesso) | ~30 min |

**Totale stimato approccio manuale: 4–5 ore**

Il seeder richiede ~2h di sviluppo in più rispetto al manuale, ma gira in <5 min su qualsiasi ambiente futuro — ripaga già alla prima esecuzione in produzione.
