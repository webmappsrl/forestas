> Ticket: oc:8641

# L'import da Sardegna Sentieri non scrive più lunghezza, dislivello e tempi di percorrenza

## Cosa cambia

L'import da Sardegna Sentieri (Drupal) smette di scrivere in `properties.manual_data` dell'EcTrack.
Oggi `TrackPropertiesData::fromApiResponse()` copia dall'API `lunghezza`, `dislivello_totale`,
`durata` (usata sia per andata sia per ritorno), `ele_min` ed `ele_max` nei campi `distance`,
`ascent`, `duration_forward`, `duration_backward`, `ele_min`, `ele_max`, e con
`ManualTrackData::merge()` fa vincere il valore dell'API su quello già presente.

Dopo la modifica il DTO passa `manual_data: null`: `EcTrackPropertiesData::toArray()` omette la
chiave e l'`array_merge` di `SardegnaSentieriImportService::importTrackFromResponse()` lascia
intatto il `manual_data` esistente del sentiero. `manual_data` diventa un campo dei soli operatori
(Nova), e sui sentieri restano due fonti: il calcolo automatico (DEM/OSM) e, in un lavoro
successivo, il valore del registro storico (Excel).

## Perché

Nella call tecnica Sardegna Sentieri del 14/09/2026 il cliente ha chiarito che quei valori su Drupal
non sono misurati: erano stati popolati con il vecchio calcolo di Webmapp, a volte sbagliato
(Saba: «i dati erano stati popolati su Drupal usando la vostra libreria»). Si è deciso di ignorarli
(Bonfanti: «ignoriamo i valori presi da Drupal»; Saba: «Sì, possiamo anche fare»; Piccioli:
«Ignoriamo i valori da drupal»).

Siccome `manual_data` ha la precedenza su OSM e DEM sia in Nova (`HasDemClassification`) sia nei
tile PBF (`COALESCE` in `PBFGeneratorService`), finché l'import li scrive il calcolo DEM non si vede
su nessun sentiero. In più il merge attuale sovrascrive ogni notte le correzioni degli operatori.

## Requisiti

- [ ] `TrackPropertiesData::fromApiResponse()` non valorizza più `manual_data`: `toArray()` non
      contiene la chiave `manual_data`, qualunque cosa arrivi dall'API
- [ ] Il parametro `$existingManualData` di `fromApiResponse()` viene rimosso, e con lui la sua
      preparazione in `SardegnaSentieriImportService::importTrackFromResponse()`
- [ ] Un `manual_data` già presente su un EcTrack (es. valori inseriti da un operatore in Nova)
      resta identico dopo un import incrementale dello stesso sentiero
- [ ] Un EcTrack creato dall'import non ha `manual_data`
- [ ] Test unitario su `TrackPropertiesData` (senza DB) in `tests/Unit/Dto/`
- [ ] Test sul service in `tests/Feature/Import/SardegnaSentieriImportServiceTest.php`, lanciato
      solo dopo aver verificato l'isolamento del DB come da `.claude/rules/test.md`

## Rischi

- **I valori già importati restano sui sentieri esistenti**, e continuano a prevalere sul DEM.
  Mitigazione: nessun comando di pulizia. Su sviluppo e UAT (`SARDEGNASENTIERI_DAILY_RESET=true`)
  il reset delle 06:00 tronca `ec_tracks` e li ricrea senza quei valori; la produzione non esiste
  ancora e il suo primo import partirà con il codice nuovo. Restano solo nei DB locali con
  l'interruttore spento, sacrificabili.
- **Dopo l'import i sentieri non hanno né `manual_data` né `dem_data`**: l'import salva con
  `saveQuietly()` e non accoda la catena DEM (nel DB locale solo 2 sentieri su 769 hanno
  `dem_data`). È voluto e resta così: calcolare il DEM a ogni import, con il reset notturno,
  caricherebbe il server per niente. Lato client i valori arrivano con la chiamata API del
  dettaglio del sentiero (indicazione del dev in fase di challenge).
- **I test esistenti affermano il comportamento opposto**: in
  `tests/Feature/Import/SardegnaSentieriImportServiceTest.php` (righe ~313 e ~350) verificano che
  vinca il valore dell'API. Vanno riscritti, non solo integrati.
- **In locale la verifica a occhio non mostra differenze**: con l'interruttore spento l'import è
  incrementale e i `manual_data` già presenti restano. Per vedere l'effetto serve un `--reset`
  locale o un sentiero nuovo; la prova vera è nei test.
- **Il futuro import dall'Excel del registro storico tornerà a scrivere in `manual_data`**, dove
  scrivono anche gli operatori. Precedenza e provenienza di quel valore vanno decise in quel
  ticket, non qui.

## Out of scope

- Pulizia dei `manual_data` già scritti sui sentieri esistenti (vedi Rischi)
- Import del valore dal registro storico (Excel) in `manual_data`: lavoro separato
- Modifiche a `ApiTrackResponse` e a `ManualTrackData` / `EcTrackPropertiesData` del package

## Moduli toccati

Tutto nel repo `forestas`, nessuna modifica a `wm-package`.

- `app/Dto/Import/TrackPropertiesData.php`
- `app/Services/Import/SardegnaSentieriImportService.php`
- `tests/Unit/Dto/TrackPropertiesDataTest.php` (nuovo)
- `tests/Feature/Import/SardegnaSentieriImportServiceTest.php`
