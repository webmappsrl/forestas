# Copia del database Drupal di Sardegna Sentieri

## Come funziona oggi

Forestas importa da Sardegna Sentieri (Drupal) attraverso le API pubbliche (`SardegnaSentieriClient`,
`https://www.sardegnasentieri.it/ss`). Accanto alle API c'è una **copia SQL del database Drupal**, in
un container MySQL 8 a parte, da cui si leggono anche i dati che le API non espongono. Drupal verrà
dismesso: l'ultima copia resterà la fonte del dato.

### Dove sta

La copia **non c'è per forza**: esiste solo dove qualcuno ha acceso il container
`mysql-sardegnasentieri-dump` e ci ha caricato un dump. Come accenderla, caricarla, scaricare il
dump da produzione, come si aggiorna da sola su UAT, come raggiungere quella di UAT e scaricare il
codice Drupal:
[docs/howto/copia-database-drupal.md](../howto/copia-database-drupal.md).

Il dump è un `mysqldump` di MySQL 5.7, senza `CREATE DATABASE` né `USE`. Lo carica solo
`scripts/drupal-dump-reload.sh`, che ricrea anche l'utente `readonly` con il solo `SELECT`.

Su UAT la copia si aggiorna da sola ogni giorno con il dump di produzione: orario, log e chiave
sono nella sezione «La copia su UAT» dell'howto.

### Come si interroga

```bash
docker exec -e MYSQL_PWD=readonly mysql-sardegnasentieri-dump \
  mysql --default-character-set=utf8mb4 -ureadonly sardegnasentieri -e "SELECT ..."
```

**Senza `--default-character-set=utf8mb4` le lettere accentate escono corrotte**
(`accessibilit�`).

Da Laravel: `DB::connection('sardegnasentieri')`, che raggiunge il container per nome sulla rete
Docker (`mysql-sardegnasentieri-dump:3306`). L'utente `readonly` può solo leggere: un `INSERT` o un
`CREATE TABLE` vengono rifiutati.

Lo schema qui sotto è ricavato dalla copia e dal codice Drupal in produzione (tag
`2024-04-10-bis`, cartella `config/sync/`); gli endpoint `/ss/...` sono nel modulo custom
`api_webmapp`.

**Dati personali**: `users*`, `user__*`, `webform_submission*` e gli alias `/user/<uid>` in
`path_alias` contengono dati di persone. Non vanno importati, né riportati in documentazione o
ticket.

### Lo schema

**Le tabelle di un nodo**

| Tabella | Cosa contiene | Chiave |
|---|---|---|
| `node` | una riga per nodo: `nid`, `vid` (revisione di default), `type`, `langcode` originale | `nid` |
| `node_field_data` | una riga per **traduzione** della revisione di default: `nid, vid, type, langcode, status, uid, title, created, changed, default_langcode` | `nid`, `langcode` |
| `node__<campo>` | valore corrente del campo: `bundle, deleted, entity_id, revision_id, langcode, delta, <campo>_<colonna>` | `entity_id`, `deleted`, `delta`, `langcode` |
| `node_revision*` | tutte le revisioni | `vid` |

Colonne del valore secondo il tipo di campo:

| Tipo | Colonne |
|---|---|
| `string`, `integer`, `boolean`, `list_string` | `<campo>_value` |
| `text_long` | `<campo>_value`, `<campo>_format` (HTML) |
| `text_with_summary` (`body`) | `body_value`, `body_summary`, `body_format` |
| `entity_reference` | `<campo>_target_id` (nid, tid o mid) |
| `image` (legacy) | `<campo>_target_id` (fid), `_alt`, `_title`, `_width`, `_height` |
| `file` (legacy) | `<campo>_target_id` (fid), `_display`, `_description` |
| `geofield` | `<campo>_value` (testo), `_geo_type`, `_lat`, `_lon`, bbox |
| `datetime` (solo data) | `<campo>_value` stringa `Y-m-d` |
| `timestamp` | `<campo>_value` intero unix |
| `hour_minutes_seconds` | `<campo>_value` intero in **secondi** |
| `link` | `<campo>_uri`, `_title` |

**Come si ricava il valore corrente di un campo**

1. Si parte da `node_field_data` filtrando `type`, `status = 1` e la lingua.
2. Join su `node__<campo>` con `entity_id = nid AND deleted = 0`, e in più:
   - campo **traducibile** → `AND langcode = node_field_data.langcode`;
   - campo **non traducibile** → nessun filtro sulla lingua: c'è una sola riga, nella lingua
     originale del nodo (`default_langcode = 1`).
3. **Mai join su `revision_id = node.vid`**: quando un valore non cambia, `revision_id` resta quello
   vecchio e il join perde la riga.
4. Campi multipli: ordinare per `delta`.

La traducibilità che conta è quella dell'istanza (`field.field.node.<bundle>.<campo>.yml` →
`translatable`), non quella dello storage, che è `true` quasi ovunque.

Lingue: `it` (default, URL senza prefisso) ed `en` (prefisso `/en`). Alcuni nodi sono nati in
inglese e alcuni sono `und`: per la lingua originale usare `default_langcode = 1`, non
`langcode = 'it'`. `status` è **per traduzione**. È attivo il workflow `editorial`
(content_moderation): `node_field_data` contiene la revisione di default, quella pubblicata.

**sentiero**

| Campo | Tipo → destinazione | Multiplo | Traducibile |
|---|---|---|---|
| `title`, `body` | | | sì |
| `field_codice`, `field_codice_cai` | string | no | no |
| `field_lunghezza` (metri), `field_dislivello_totale`, `_positivo`, `_negativo`, `field_quota_minima`, `_massima` | integer | no | no |
| `field_tempo_di_percorrenza` | secondi | no | no |
| `field_data` | data `Y-m-d` | no | no |
| `field_data_rilievo` | timestamp | no | no |
| `field_stato_di_validazione` | termine `stato_di_validazione` | no | no |
| `field_tipologia_sentiero` | termine `tipologia_sentieri` | sì | no |
| `field_frubilita` | termine `categorie_fruibilita_sentieri` | max 3 | no |
| `field_avvertenze_e_pericoli` | termine `tipologia_di_avvertenze` | sì | no |
| `field_zona_geografica` | termine `zona_geografica` | sì | no |
| `field_partenza`, `field_arrivo` | nodo `poi` | no | no |
| `field_poi_correlati` | nodo `poi` | sì | no |
| `field_sentieri_collegati` | nodo `sentiero` | sì | **sì** |
| `field_itinerari_correlati` | nodo `itinerario` | sì | no |
| `field_soggetto_gestore`, `_manutentore`, `_rilevatore`, `field_complesso_forestale` | nodo `ente_istituzione_societa` | no | no |
| `field_riferimento_operatori_guid` | nodo `ente_istituzione_societa` | sì | no |
| `field_immagine_principale_media`, `field_galleria_media` | media `image` | no / sì | sì |
| `field_gpx_media`, `field_allegati_media` | media `document` | no / sì | sì |
| `field_video_media` | media `remote_video` | sì | sì |
| `field_files_privati` | media `files_privati` (file privati) | sì | no |
| `field_info_utili`, `field_roadbook` | text_long (HTML) | sì | sì |
| `field_percorso`, `field_percorso_per_mappetta` | geofield (GeoJSON) | sì / no | no |
| `field_svg` | URL dell'immagine dell'altimetria | no | no |
| `field_contiene_carte`, `field_iframe_mappa`, `field_ordine_di_visualizzazione` | | no | |

**poi**

| Campo | Tipo → destinazione | Multiplo | Traducibile |
|---|---|---|---|
| `title`, `body` | | | sì |
| `field_codice` | string | no | **sì** |
| `field_posizione` | geofield Point (WKT) | no | no |
| `field_localita` | string | no | no |
| `field_come_arrivare` | text_long (HTML) | no | sì |
| `field_collegamenti` | link | sì | no |
| `field_tipologia_poi` | termine `tipologia_poi` | sì | no |
| `field_servizi` | termine `servizi` | sì | no |
| `field_zona_geografica` | termine `zona_geografica` | sì | no |
| `field_poi_correlati` | nodo `poi` | sì | no |
| `field_sentieri_collegati` | nodo `sentiero` | sì | no |
| `field_itinerari_correlati` | nodo `itinerario` | sì | no |
| `field_riferimento_operatori_guid` | nodo `ente_istituzione_societa` | sì | no |
| `field_immagine_principale_media`, `field_galleria_media`, `field_allegati_media`, `field_video_media` | media | | sì |

**itinerario** — gli stessi campi tecnici del sentiero (lunghezza, dislivelli, tempo, stato di
validazione, fruibilità, partenza/arrivo, media, info utili, roadbook), senza codice, quote,
soggetti ed `field_percorso`; in più:

| Campo | Tipo → destinazione | Multiplo | Traducibile |
|---|---|---|---|
| `field_sentieri_collegati` | nodo `sentiero`, in ordine di `delta`: **sono i sentieri dell'itinerario** | sì | **sì** |
| `field_sentiero` | nodo `sentiero` (usato da pochi itinerari) | sì | no |
| `field_punti_di_interesse` | nodo `poi` | sì | no |
| `field_tipologia_itinerario` | termine `tipologia_itinerari` | sì | no |
| `field_tag` | termine `tags` | sì | no |
| `field_zona_geografica`, `field_riferimento_operatori_guid` | | sì | **sì** |

**news**

| Campo | Tipo → destinazione | Multiplo | Traducibile |
|---|---|---|---|
| `title`, `body` | | | sì |
| `field_data` | data `Y-m-d`: data editoriale, distinta da `created` | no | no |
| `field_date` | timestamp | no | no |
| `field_top_image_media` | media `image` | no | sì |
| `field_galleria_media` | media `image` | sì | no |
| `field_file_media` | media `document` | sì | sì |
| `field_tipologia` / `field_tag_news` | termine `tipologia_news` / `tag_news` | no / sì | no |
| `field_references` | nodo (la config ammette solo enti, i dati puntano anche a sentieri e itinerari) | sì | no |
| `field_in_evidenza` | boolean | no | no |
| `field_descrizione_testata_pagina` | string_long | no | sì |
| `field_collegamenti` | link | sì | no |

**ente_istituzione_societa**: `field_tipo_ente` → `tipo_ente_istituzione_societa`, `field_servizi`
→ `servizi`, `field_geolocalizzazione` (geofield), `field_contatti` (text_long), `field_pagina_web`
(link), `field_collegamenti`, `field_immagine_principale` (image legacy) e
`field_immagine_principale_media`, `field_galleria_media`.

Altri bundle: `da_sapere`, `evento`, `carta`, `page`, `banner`, `slide_homepage`.

**Tassonomie**

`taxonomy_term_field_data` (`tid, vid, langcode, status, name, description__value, weight,
default_langcode`), gerarchia in `taxonomy_term__parent` (`parent_target_id = 0` è la radice),
campi in `taxonomy_term__<campo>`. Il nome tradotto si prende con `langcode = 'en'` se esiste,
altrimenti si ricade sull'italiano: pochi vocabolari sono tradotti.

| Vocabolario | Campi propri | Puntato da |
|---|---|---|
| `stato_di_validazione` | `field_classe_colore_stato`, `field_valore_numerico` | sentiero, itinerario |
| `tipologia_sentieri` | `field_geohub_identifier`, icone | sentiero |
| `categorie_fruibilita_sentieri` | gerarchico | sentiero, itinerario |
| `tipologia_di_avvertenze` | `field_classe_id_icona` | sentiero |
| `zona_geografica` | `field_body`, `field_slug` | sentiero, poi, itinerario, carta |
| `tipologia_poi` | `field_geohub_identifier`, icone, marker; gerarchico | poi |
| `servizi` | `field_geohub_identifier`, icona | poi, ente |
| `tipologia_itinerari` | `field_geohub_identifier`, icone | itinerario |
| `tags`, `tipologia_news`, `tag_news`, `tipologia_da_sapere`, `tipo_ente_istituzione_societa` | | itinerario e da_sapere, news, da_sapere, ente |

**File e media**

Catena da un campo `*_media` del nodo al file:

`node__field_X_media.<campo>_target_id` → `media_field_data.mid` → `media__field_media_image`
(immagini) o `media__field_media_document` (documenti, GPX) `._target_id` → `file_managed.fid` →
`uri`.

- `public://<percorso>` → `https://www.sardegnasentieri.it/sites/default/files/<percorso>`, con il
  percorso codificato per URL segmento per segmento: molti nomi hanno spazi e parentesi.
- `private://` (media `files_privati`, campo `field_media_file`) non ha un URL pubblico.
- Alt e title stanno in `media__field_media_image._alt/_title`; autore e credits in
  `media__field_autore` e `media__field_credits`. **I media non sono tradotti**.
- Video remoti: `media__field_media_oembed_video._value` è l'URL YouTube.

**Geometrie**

- poi, `node__field_posizione`: `_value` è **WKT** (`POINT (lon lat)`, spaziatura variabile);
  `field_posizione_lat` e `_lon` sono già pronti.
- sentiero, `node__field_percorso`: `_value` è **GeoJSON** (FeatureCollection), non WKT, e c'è solo
  su una parte dei sentieri.
- **La traccia vera è il GPX**: `field_gpx_media` → media `document` → file `.gpx`. Il contenuto
  sta sul disco di Drupal, **non nel database**: dalla copia si ricava solo l'URL.
- ente, `node__field_geolocalizzazione`: geofield con `_lat`/`_lon`.

**Path alias**

`path_alias (id, langcode, path, alias, status)`, `path = '/node/<nid>'`. Pattern: sentiero
`/sentiero/<titolo>`, itinerario `/itinerario/<titolo>`, poi `/da-vedere/<titolo>`, news
`/news/<titolo>`. URL: italiano `https://www.sardegnasentieri.it` + alias, inglese
`https://www.sardegnasentieri.it/en` + alias (lo slug inglese è quello italiano). Possono esserci
alias doppi (prendere `MAX(id)`) o mancanti (ripiegare su `/node/<nid>`).

**Relazioni fra nodi**

Tutti `entity_reference`: `<campo>_target_id` è il `nid` di destinazione. Un riferimento può
puntare a un nodo cancellato: il join va fatto su `node_field_data` (o `node`) e il riferimento
senza corrispondenza va scartato.

### Corrispondenza con le API usate dall'import

Per ogni chiamata di `app/Http/Clients/SardegnaSentieriClient.php`, da dove arriva nella copia ogni
campo che Forestas legge davvero (DTO in `app/Dto/Api/`, service
`app/Services/Import/SardegnaSentieriImportService.php`). «Nodo» sta per `node_field_data`.
Gli endpoint `/ss/*` sono nel modulo Drupal `api_webmapp`.

**`/ss/list-tracks/` e `/ss/listpoi/`** — elenco `{nid: changed}`

| Campo | Copia | Note |
|---|---|---|
| chiave | nodo `.nid` con `type IN ('sentiero','itinerario')` / `type = 'poi'` | **include anche i nodi non pubblicati** (bozze in `draft`, `status = 0`): verificato il 05/10/2026, l'elenco ha tutti i sentieri e itinerari della copia, compresi quelli non pubblicati, mentre la pagina pubblica del nodo risponde 403 |
| valore | nodo `.changed` | l'API lo restituisce già formattato come data |

**`/ss/track/{id}`** — `ApiTrackResponse` (serve sia sentieri sia itinerari)

| Campo del DTO | Copia | Note |
|---|---|---|
| `id`, `type` | nodo `.nid`, `.type` | |
| `created_at`, `updated_at` | nodo `.created`, `.changed` | unix in UTC; l'API li restituisce come data nell'ora italiana (`changed` 10:00:01 UTC di novembre → `11:00:01`) |
| `name` | nodo `.title`, righe `it` ed `en` | |
| `description`, `excerpt` | `node__body.body_value`, `.body_summary`, per lingua | HTML |
| `lunghezza`, `dislivello_totale`, `durata` | `node__field_lunghezza`, `node__field_dislivello_totale`, `node__field_tempo_di_percorrenza` | `durata` in secondi; letti dal DTO ma non usati (oc:8641) |
| `ele_min`, `ele_max` | `node__field_quota_minima`, `node__field_quota_massima` | non usati (oc:8641) |
| `codice`, `codice_cai` | `node__field_codice`, `node__field_codice_cai` | `codice_cai` diventa il `ref` |
| `data_rilievo` | `node__field_data_rilievo` | timestamp UTC di una mezzanotte italiana; l'API lo restituisce nell'ora italiana (`2008-01-02 23:00:00` UTC → `2008-01-03 00:00:00`) |
| `allegati` | `field_allegati_media` → media `document` → file | l'API restituisce gli URL |
| `video` | `field_video_media` → `media__field_media_oembed_video._value` | |
| `gpx` | `field_gpx_media` → media `document` → file | solo l'URL: il contenuto è su disco |
| `immaginePrincipale` | `field_immagine_principale_media` → media `image` → file, più `media__field_autore` e `media__field_credits` | l'API costruisce `{url, autore, credits}` |
| `galleriaImmagini` | `field_galleria_media`, stessa catena | |
| `info_utili`, `roadbook` | `node__field_info_utili`, `node__field_roadbook`, per lingua | HTML, multipli |
| `partenza`, `arrivo`, `poi_correlati` | `node__field_partenza`, `node__field_arrivo`, `node__field_poi_correlati` | nid dei poi |
| `complesso_forestale` | `node__field_complesso_forestale` | nid dell'ente |
| `ente_istituzione_societa` | `node__field_soggetto_gestore`, `_rilevatore`, `_manutentore`, `node__field_riferimento_operatori_guid` | nid degli enti |
| `taxonomies.tipologia_sentieri` | `node__field_tipologia_sentiero` | tid |
| `taxonomies.tipologia_di_avvertenze` | `node__field_avvertenze_e_pericoli` | tid |
| `taxonomies.categorie_fruibilita_sentieri` | `node__field_frubilita` | tid; letto ma non usato dal service |
| `taxonomies.stato_di_validazione` | `node__field_stato_di_validazione` | tid, mappato sull'enum `StatoValidazione` |
| `taxonomies.zona_geografica` | `node__field_zona_geografica` | tid |
| `url` | `path_alias.alias` del nodo | l'API dà l'URL canonico assoluto |
| `geometryFallback` | `node__field_percorso._value`, solo `delta = 0` | GeoJSON; usato solo se il GPX manca |

**`/ss/poi/{id}`** — `ApiPoiResponse`

| Campo del DTO | Copia | Note |
|---|---|---|
| `id`, `updated_at` | nodo `.nid`, `.changed` | |
| `name`, `description` | nodo `.title`, `node__body`, per lingua | |
| `codice` | `node__field_codice` | traducibile: l'API dà quello della traduzione caricata |
| `addr_locality` | `node__field_localita` | |
| `come_arrivare` | `node__field_come_arrivare`, per lingua | HTML |
| `collegamenti` | `node__field_collegamenti._uri`, `._title` | l'API li appiattisce in stringa |
| `coordinates` | `node__field_posizione._lon`, `._lat` | l'API li ricava dal WKT di `_value` |
| `immaginePrincipale`, `galleria`, `allegati`, `video` | catene media come per il sentiero | |
| `poi_correlati` | `node__field_poi_correlati` | |
| `ente_istituzione_societa` | `node__field_riferimento_operatori_guid` | |
| `taxonomies.tipologia_poi`, `.servizi`, `.zona_geografica` | `node__field_tipologia_poi`, `node__field_servizi`, `node__field_zona_geografica` | tid |
| `url` | `path_alias.alias` | |

**`/ss/tassonomia/{vocabolario}`** — usata per `tipologia_poi`, `servizi`, `tipologia_sentieri`,
`tipologia_di_avvertenze`

| Campo letto | Copia | Note |
|---|---|---|
| chiave | `taxonomy_term_field_data.tid` con `vid = '<vocabolario>'` | |
| `name` | `taxonomy_term_field_data.name`, righe `it` ed `en` | |
| `geohub_identifier` | `taxonomy_term__field_geohub_identifier` | se c'è, diventa l'identifier in Forestas |
| `description` | `taxonomy_term_field_data.description__value` | l'API **non la emette**: in Forestas arriva sempre vuota |

**`/node/{id}`** (REST generico di Drupal) — usato per gli enti

| Campo letto | Copia |
|---|---|
| `title` | nodo `.title` |
| `body` | `node__body.body_value` (Forestas toglie l'HTML) |
| `field_contatti` | `node__field_contatti.field_contatti_value` |
| `field_pagina_web` | `node__field_pagina_web.field_pagina_web_uri` |
| `field_tipo_ente` | `node__field_tipo_ente.field_tipo_ente_target_id` |
| `field_immagine_principale` (url) | `node__field_immagine_principale` (image legacy) → `file_managed.uri` |
| `field_geolocalizzazione` (lat, lon) | `node__field_geolocalizzazione._lat`, `._lon` |

**`/taxonomy/term/{id}`** — usato quando il tipo di ente non è riconosciuto: `name` →
`taxonomy_term_field_data.name`.

**URL del GPX** — non ha un equivalente nel database: dalla copia si ricava l'URL
(`field_gpx_media` → `file_managed.uri`), il contenuto si scarica via HTTP.

### Cosa le API non espongono

- **sentiero**: `status` delle traduzioni, `field_dislivello_positivo`, `_negativo`, `field_data`,
  `field_sentieri_collegati`, `field_itinerari_correlati`, `field_svg`, `field_contiene_carte`,
  `field_ordine_di_visualizzazione`, `field_files_privati`, alt e title delle immagini, le
  traduzioni inglesi di galleria, GPX e allegati, gli alias per lingua.
- **poi**: `status`, `field_sentieri_collegati`, `field_itinerari_correlati`,
  `field_tipologia_generale`, il `field_codice` inglese, la struttura dei link.
- **itinerario**: tutti i campi propri — `field_sentieri_collegati`, `field_sentiero`,
  `field_punti_di_interesse`, `field_tipologia_itinerario`, `field_tag`.
- **tassonomie**: `description`, `weight`, `field_classe_colore_stato`, `field_valore_numerico`,
  `field_body`, `field_slug`.
- **bundle interi**: `news`, `da_sapere`, `evento`, `carta`, `page`, `banner`, `slide_homepage`.

### Query di riferimento

Si eseguono con il comando di shell sopra.

**News pubblicate in italiano, con data, testo, immagine e alias**

```sql
SELECT n.nid, n.title, d.field_data_value AS data_news, b.body_value,
       f.uri AS immagine_uri,
       COALESCE(pa.alias, CONCAT('/node/', n.nid)) AS alias
FROM node_field_data n
LEFT JOIN node__field_data d ON d.entity_id = n.nid AND d.deleted = 0 AND d.delta = 0
LEFT JOIN node__body b ON b.entity_id = n.nid AND b.langcode = n.langcode AND b.deleted = 0
LEFT JOIN node__field_top_image_media tim ON tim.entity_id = n.nid AND tim.langcode = n.langcode
     AND tim.deleted = 0 AND tim.delta = 0
LEFT JOIN media__field_media_image mi ON mi.entity_id = tim.field_top_image_media_target_id AND mi.deleted = 0
LEFT JOIN file_managed f ON f.fid = mi.field_media_image_target_id
LEFT JOIN (SELECT path, langcode, MAX(id) AS id FROM path_alias WHERE status = 1 GROUP BY path, langcode) pmax
     ON pmax.path = CONCAT('/node/', n.nid) AND pmax.langcode = n.langcode
LEFT JOIN path_alias pa ON pa.id = pmax.id
WHERE n.type = 'news' AND n.status = 1 AND n.langcode = 'it'
ORDER BY d.field_data_value DESC;
```

**Sentiero con codice, lunghezza, dislivello, stato di validazione e zona**

```sql
SELECT n.nid, n.title, TRIM(c.field_codice_value) AS codice, cai.field_codice_cai_value AS codice_cai,
       l.field_lunghezza_value AS lunghezza, dt.field_dislivello_totale_value AS dislivello,
       SEC_TO_TIME(tp.field_tempo_di_percorrenza_value) AS durata, sv.name AS stato_validazione,
       (SELECT GROUP_CONCAT(z.name ORDER BY zg.delta SEPARATOR ' | ')
          FROM node__field_zona_geografica zg
          JOIN taxonomy_term_field_data z ON z.tid = zg.field_zona_geografica_target_id AND z.langcode = 'it'
         WHERE zg.entity_id = n.nid AND zg.deleted = 0) AS zona
FROM node_field_data n
LEFT JOIN node__field_codice c ON c.entity_id = n.nid AND c.deleted = 0
LEFT JOIN node__field_codice_cai cai ON cai.entity_id = n.nid AND cai.deleted = 0
LEFT JOIN node__field_lunghezza l ON l.entity_id = n.nid AND l.deleted = 0
LEFT JOIN node__field_dislivello_totale dt ON dt.entity_id = n.nid AND dt.deleted = 0
LEFT JOIN node__field_tempo_di_percorrenza tp ON tp.entity_id = n.nid AND tp.deleted = 0
LEFT JOIN node__field_stato_di_validazione s ON s.entity_id = n.nid AND s.deleted = 0
LEFT JOIN taxonomy_term_field_data sv ON sv.tid = s.field_stato_di_validazione_target_id AND sv.langcode = 'it'
WHERE n.type = 'sentiero' AND n.status = 1 AND n.default_langcode = 1;
```

**POI con coordinate e tipologia**

```sql
SELECT n.nid, n.title, p.field_posizione_lat AS lat, p.field_posizione_lon AS lon,
       (SELECT GROUP_CONCAT(t.name ORDER BY tp.delta SEPARATOR ' | ')
          FROM node__field_tipologia_poi tp
          JOIN taxonomy_term_field_data t ON t.tid = tp.field_tipologia_poi_target_id AND t.langcode = 'it'
         WHERE tp.entity_id = n.nid AND tp.deleted = 0) AS tipologia
FROM node_field_data n
JOIN node__field_posizione p ON p.entity_id = n.nid AND p.deleted = 0
WHERE n.type = 'poi' AND n.status = 1 AND n.langcode = 'it';
```

**Itinerario con i suoi sentieri, in ordine**

```sql
SELECT i.nid, i.title AS itinerario, sc.delta AS ordine, s.nid AS sentiero_nid, s.title AS sentiero
FROM node_field_data i
JOIN node__field_sentieri_collegati sc ON sc.entity_id = i.nid AND sc.langcode = i.langcode AND sc.deleted = 0
JOIN node_field_data s ON s.nid = sc.field_sentieri_collegati_target_id AND s.default_langcode = 1
WHERE i.type = 'itinerario' AND i.status = 1 AND i.langcode = 'it'
ORDER BY i.nid, sc.delta;
```

**Immagine principale di un sentiero → URL**

```sql
SELECT n.nid, n.title, f.uri,
       CONCAT('https://www.sardegnasentieri.it/sites/default/files/', SUBSTRING(f.uri, 10)) AS url,
       mi.field_media_image_alt AS alt, ma.field_autore_value AS autore, mc.field_credits_value AS credits
FROM node_field_data n
JOIN node__field_immagine_principale_media im ON im.entity_id = n.nid AND im.langcode = n.langcode
     AND im.deleted = 0 AND im.delta = 0
JOIN media_field_data m ON m.mid = im.field_immagine_principale_media_target_id AND m.default_langcode = 1
JOIN media__field_media_image mi ON mi.entity_id = m.mid AND mi.deleted = 0
JOIN file_managed f ON f.fid = mi.field_media_image_target_id
LEFT JOIN media__field_autore ma ON ma.entity_id = m.mid AND ma.deleted = 0
LEFT JOIN media__field_credits mc ON mc.entity_id = m.mid AND mc.deleted = 0
WHERE n.type = 'sentiero' AND n.langcode = 'it';
```

`SUBSTRING(f.uri, 10)` toglie `public://`; nel codice il percorso va codificato per URL.

**GPX di un sentiero → URI del file**

```sql
SELECT n.nid, n.title, f.uri
FROM node_field_data n
JOIN node__field_gpx_media g ON g.entity_id = n.nid AND g.langcode = n.langcode AND g.deleted = 0
JOIN media__field_media_document d ON d.entity_id = g.field_gpx_media_target_id AND d.deleted = 0
JOIN file_managed f ON f.fid = d.field_media_document_target_id
WHERE n.type = 'sentiero' AND n.status = 1 AND n.langcode = 'it';
```

**Italiano e inglese affiancati**

```sql
SELECT it.nid, it.title AS titolo_it, en.title AS titolo_en
FROM node_field_data it
JOIN node_field_data en ON en.nid = it.nid AND en.langcode = 'en'
WHERE it.type = 'sentiero' AND it.langcode = 'it';
```

**Riferimenti verso nodi cancellati**

```sql
SELECT f.bundle, COUNT(*) AS righe, SUM(t.nid IS NULL) AS orfani
FROM node__field_sentieri_collegati f
LEFT JOIN node t ON t.nid = f.field_sentieri_collegati_target_id
GROUP BY f.bundle;
```

**Termini di un vocabolario con nome inglese e geohub_identifier**

```sql
SELECT t.tid, t.name AS nome_it, en.name AS nome_en, g.field_geohub_identifier_value AS geohub_identifier,
       p.parent_target_id AS genitore
FROM taxonomy_term_field_data t
LEFT JOIN taxonomy_term_field_data en ON en.tid = t.tid AND en.langcode = 'en'
LEFT JOIN taxonomy_term__field_geohub_identifier g ON g.entity_id = t.tid AND g.deleted = 0
LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
WHERE t.vid = 'tipologia_poi' AND t.langcode = 'it';
```

### Trappole dello schema

- **Un campo non traducibile ha una sola riga, nella lingua originale**: filtrare `langcode = 'it'`
  perde i nodi nati in inglese; per la lingua originale usare `default_langcode = 1`.
- **Mai join su `revision_id`**: il valore corrente si prende su `entity_id` e `deleted = 0`.
- **`status` è per traduzione** e le API non lo espongono: sia gli elenchi `/ss/list-tracks/` e
  `/ss/listpoi/` sia i dettagli restituiscono anche i nodi non pubblicati. Dalla copia si possono
  escludere con `status = 1`.
- **Riferimenti verso nodi cancellati** in `sentieri_collegati`, `itinerari_correlati`,
  `field_sentiero` e `poi_correlati`: il join va fatto sul nodo di destinazione.
- **Liste di riferimenti diverse fra `it` ed `en`**: `field_sentieri_collegati` e l'immagine
  principale sono traducibili e su alcuni nodi divergono.
- **`target_bundles` non è affidabile**: `news.field_references` punta anche a sentieri e
  itinerari, `field_complesso_forestale` non lo dichiara.
- **Il testo è HTML** (`body`, `info_utili`, `roadbook`, `come_arrivare`) con entità come
  `&nbsp;`; togliendo i tag le parole possono restare attaccate.
- **Tre formati di data**: unix (`created`, `changed`, `field_data_rilievo`, `field_date`),
  stringa `Y-m-d` (`field_data`, date degli eventi), e `field_data_rilievo` contiene mezzanotti
  dell'ora italiana salvate in UTC.
- **`changed` non segnala una modifica umana**: viene aggiornato in blocco, spesso alle `10:00:01`.
  Per un import incrementale fa riscaricare molto più del necessario.
- **La geometria dei sentieri è il GPX, che non sta nel database**; `field_percorso` è GeoJSON e
  copre solo una parte dei sentieri. `field_posizione` dei poi è WKT con spaziatura variabile.
- **Campi immagine e file legacy** (`field_immagine_principale`, `field_gpx`, `field_allegati`,
  `field_galleria`, `news.field_top_image`) accanto ai `*_media`: le API leggono i `*_media`,
  tranne l'ente letto da `/node/{id}`, che usa `field_immagine_principale` legacy.
- **Nomi di file con spazi e caratteri speciali**: l'URL va codificato. I file `private://` non
  hanno URL pubblico.
- **Codici sporchi**: `field_codice` con spazi in testa o in coda, codici duplicati fra sentieri
  diversi, `field_codice` diverso da `field_codice_cai`.
- **Alias**: slug inglese uguale all'italiano, qualche alias doppio, qualche nodo senza alias.
- **Revisioni**: le tabelle `node_revision__*` hanno milioni di righe; per lo stato corrente bastano
  `node_field_data` e `node__*`.
- **I confronti ignorano accenti e maiuscole**: le tabelle sono in `utf8mb4_0900_ai_ci`, quindi
  `title LIKE '%à%'` trova anche le `a`. Per un confronto esatto: `COLLATE utf8mb4_bin`.
- **`deleted = 1`** oggi non compare, ma il filtro va tenuto: è la regola di Drupal.

## Perché così

- **Copia SQL accanto alle API** (oc:8705): le API non espongono tutti i dati (news, campi propri
  degli itinerari, relazioni) e spariranno con la dismissione di Drupal.
- **Import attuali ancora sulle API** (oc:8705): finché il sync non aggiorna la copia ogni giorno,
  le API sono più fresche della copia. Si passa all'SQL un import alla volta, con il confronto
  campo per campo nel ticket che lo sposta. Un argomento in più per passare all'SQL: le API non
  espongono lo stato pubblicato e restituiscono anche le bozze, quindi un import via API non può
  escluderle; dalla copia basta `status = 1` (oc:8705).
- **Caricamento solo dallo script** (oc:8705): una strada sola, che crea anche `readonly` con il
  solo `SELECT`; la copia è una fonte da leggere, e una query sbagliata non deve poterla
  modificare.
- **Porta sul loopback, password nel compose** (oc:8705): la porta legata a `127.0.0.1` tiene la
  copia fuori dalla rete anche su Ubuntu, dove Docker passa sopra a `ufw`. La password non protegge
  nulla in più: la raggiunge solo chi ha già accesso al server o ai container di Forestas.
- **Rete Docker e non `host.docker.internal`** (oc:8705): su Linux `host.docker.internal` non
  esiste.
- **Binlog spento** (oc:8705): in una copia di sola lettura il binlog occupa solo disco, circa
  quanto i dati a ogni ricarica.
- **SQL diretto e non un'API generata (GraphQL, Directus, Hasura)** (oc:8705): lo schema Drupal non
  dichiara chiavi esterne, quindi un'API generata darebbe tabelle scollegate da riunire a mano.
- **Script shell e non comando artisan** (oc:8705): artisan gira in `php-forestas`, che non ha
  accesso a Docker né il client `mysql`.

- **Download e ricarica separati, ricarica solo con un dump nuovo** (oc:8706): ognuno si prova e
  si rilancia da solo, e nei giorni senza dump nuovo la copia non resta vuota per una ricarica
  inutile.
- **File temporaneo nascosto e `gzip -t` prima del nome definitivo** (oc:8706): un download
  interrotto non lascia mai un `.sql.gz` che la ricarica prenderebbe per buono.
- **Ultimi 2 dump in cartella** (oc:8706): se il nuovo è rotto si torna al giorno prima senza
  riscaricare; più di 2 occupano solo disco, perché su Acquia ne restano comunque 3.
- **Chiave dedicata a UAT** (oc:8706): una chiave autorizzata su Acquia apre una shell completa
  sull'ambiente, quindi la «sola lettura» la garantisce lo script, non la chiave; la chiave a parte
  si revoca senza toccare quella del dev.
- **Porta unica 3307 per gli script dall'host** (oc:8706): uno script provato in locale su una porta
  diversa da quella di UAT fallirebbe una volta portato su UAT; con la porta fissa, per lavorare su
  UAT dal locale si spegne il container locale e si apre il tunnel sulla 3307.
- **Log dedicato** (oc:8706): l'esito del giro giornaliero sta in un file solo suo, letto dal dev e
  dalle sessioni di lavoro, invece di perdersi fra i log di Laravel.

## Come ci siamo arrivati

- **Dump montato su `/docker-entrypoint-initdb.d` con `MYSQL_USER`** (commit `f0af59d`, superata in
  oc:8705): caricava tutti i dump della cartella a ogni volume nuovo e dava `ALL PRIVILEGES` a
  `readonly`; lo script per limitarlo usava comandi PostgreSQL e non era montato.
