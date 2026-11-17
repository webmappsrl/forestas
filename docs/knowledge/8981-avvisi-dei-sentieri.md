# Avvisi dei sentieri

Come un operatore pubblica l'avviso di un sentiero e come il sito WordPress lo legge.

## Come funziona oggi

### Il dato

- Un avviso è il flag `properties->not_accessible` acceso, con il testo in
  `properties->not_accessible_message` (traducibile). Sono campi del wm-package che esistevano già;
  la data `properties->not_accessible_updated_at` la scrive il modello del package — regola in
  [wm-package/docs/knowledge/8981-data-dell-avviso-su-ectrack.md](../../wm-package/docs/knowledge/8981-data-dell-avviso-su-ectrack.md).
- Per l'app un avviso resta `not_accessible`, cioè sentiero non accessibile: la label «Avviso» è
  solo di Forestas.
- Un avviso per sentiero, nessuno storico, nessuna scadenza: si spegne togliendo il flag.

### In Nova

- Nella Resource `EcTrack` di Forestas i tre campi stanno nel tab `Status`, dopo la percorribilità
  e prima dello storico, sotto l'Heading «Avviso»: «Pubblica avviso», «Messaggio Avviso», «Data avviso» (solo
  nel detail, mostrata in Europe/Rome). Nessuno dei tre compare nell'index. Dal tab Info del package si tolgono filtrandoli per
  attributo (`App\Nova\EcTrack::NOTICE_ATTRIBUTES`), senza copiare il tab.
- Compaiono anche con il dominio Catasto spento; in quel caso nel tab mancano la percorribilità e
  lo storico, che dipendono da `WM_TRAIL_REGISTRY_ENABLED`.

### L'API per il sito

- `GET /api/v1/avvisi`, pubblica come `api/ec/track`: niente token né throttle, perché espone dati
  già presenti nel JSON del dettaglio.
- Forma:

  ```json
  {
    "data": [
      {
        "track_id": 769,
        "ref": "T 521",
        "name": {"it": "…", "en": "…"},
        "message": {"it": "Le forti piogge hanno reso difficoltoso il passaggio"},
        "published_at": "2026-11-17T10:50:02+00:00"
      }
    ],
    "generated_at": "…"
  }
  ```

- Solo flag acceso, testo italiano non vuoto e traccia non marcata `deleted_from_source`. Nel
  messaggio le lingue vuote non escono; un messaggio senza italiano non va sul sito, e l'help del
  campo in Nova lo dice.
- Ordine: `published_at` decrescente, senza data in fondo, a parità `track_id` decrescente. Nessuna
  paginazione: «meno recenti prima» lo fa il sito invertendo l'elenco.

### Cosa deve sapere chi usa l'API (Rubens, il cliente)

- **`published_at` è l'ultimo aggiornamento del testo italiano**, non la prima pubblicazione:
  correggere un refuso riporta l'avviso in cima. È in UTC (`+00:00`): il sito converte l'ora.
- **Su UAT gli avvisi si perdono ogni mattina alle 06:00**, con il reset notturno che tronca
  `ec_tracks`, e `track_id` cambia per il `RESTART IDENTITY`: un avviso inserito per prova non
  sopravvive alla notte, e un `track_id` tenuto in cache a cavallo delle 06:00 punta a un altro
  sentiero.
- **Il sito deve distinguere un errore dell'API (5xx, timeout) da un elenco vuoto**: «nessun
  avviso» mostrato per un errore sembra un'informazione corretta.

## Perché così

- **Riusare `not_accessible` invece di un dato nuovo** (oc:8981, scrum 17/11): un avviso è un valore
  volatile, anche di un giorno; per Forestas cambiano solo label e posizione, la logica resta.
- **La data nel wm-package e non in Forestas** (oc:8981, scrum 17/11): la data di creazione cambia a
  ogni modifica della traccia; una data dedicata serve a chiunque usi `not_accessible`.
- **La data segue il testo italiano** (oc:8981, dev 17/11): «ultimo aggiornamento» invece di «prima
  pubblicazione», con la conseguenza del refuso detta sopra.
- **API in Forestas e pubblica** (oc:8981, dev 17/11): serve al solo sito di Forestas; i dati sono
  già pubblici nel dettaglio della traccia.
- **Perdita notturna su UAT accettata** (oc:8981, dev 17/11), come per l'inclusività
  ([classificazioni-e-inclusivita.md](classificazioni-e-inclusivita.md)).

## Come ci siamo arrivati

- **Avviso come stato della percorribilità** (scrum 17/11, superata): un avviso può non riguardare
  la percorribilità («alle 20 si chiude il sentiero») e dura poco.
- **API nel wm-package** (scrum 16/11, superata dal dev il 17/11): serve solo a Forestas.
- **News WordPress con il tag «avvisi»** (call con il cliente del 16/10, scartata nella stessa call).
