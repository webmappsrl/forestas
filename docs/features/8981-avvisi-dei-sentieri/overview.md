> Ticket: oc:8981

# Avvisi dei sentieri: label e posizione in Nova, API per il sito

Parte di forestas. La data dell'avviso nasce nel wm-package:
[wm-package/docs/features/8981-avvisi-dei-sentieri/overview.md](../../../wm-package/docs/features/8981-avvisi-dei-sentieri/overview.md).

## Cosa cambia

- Nella Resource Nova `EcTrack` di forestas i campi `properties->not_accessible` e
  `properties->not_accessible_message` escono dal tab **Info** ed entrano nel tab **Status**
  (`App\Nova\EcTrack::STATUS_TAB`, lo stesso di `WalkabilityTaxonomyInstaller::TAB`), dopo la nota
  della percorribilità e prima dello storico, con le label «Avviso» e «Messaggio Avviso». Sotto di
  loro, la data dell'avviso in sola lettura (prodotta dal wm-package).
- Nuova rotta pubblica `GET /api/v1/avvisi`, senza token come le rotte `api/ec/track` e
  `api/ec/poi` del wm-package, che restituisce tutti
  gli avvisi accesi per la pagina `/avvisi` (Tavola 10) e la fascia della home del sito WordPress.

Il dato non cambia: un avviso è `not_accessible = true` con il suo `not_accessible_message`
(traducibile). Arriva già nel JSON del dettaglio della traccia, perché sta in `properties`.

## Perché

Il cliente vuole che l'operatore inserisca l'avviso una volta sola nel backend e che compaia su app
e sito: nella fascia della home, in alto nella scheda e nella pagina `/avvisi` (call con il cliente
del 16/10). Allo scrum del 17/11 si è deciso di non creare un dato nuovo: si riusano i due campi che
esistono già, si cambiano solo label e posizione per Forestas («customizzazione del cliente», non
standard del package) e la logica resta quella. Il sito non ha oggi nessuna API che restituisca
tutti gli avvisi insieme: la fascia della home e `/avvisi` ne hanno bisogno.

Il frontend WordPress (fascia della scheda, fascia della home, pagina `/avvisi`) lo fa Rubens su
questo contratto.

## Requisiti

- [ ] I due campi non compaiono più nel tab Info della Resource `EcTrack` di forestas; il tab Info
      del package resta invariato per gli altri shard.
- [ ] Nel tab `Status`, dopo la nota della percorribilità e prima dello storico: `Boolean`
      «Avviso» (`properties->not_accessible`), `Textarea` traducibile «Messaggio Avviso»
      (`properties->not_accessible_message`) con un help che dice che senza messaggio l'avviso non
      compare sul sito, data dell'avviso in sola lettura.
- [ ] I tre campi stanno nel tab `Status` anche se la percorribilità non ha termini o il dominio
      Catasto è spento, con un Heading «Avviso» proprio, così non si leggono come parte della
      validazione.
- [ ] Le label hanno testo base in inglese e traduzione italiana in `lang/it.json` («Avviso»,
      «Messaggio Avviso», «Data avviso»).
- [ ] `GET /api/v1/avvisi` pubblica, senza `VerifyWpApiToken` né altri middleware di
      autenticazione, come le rotte `api/ec/track` e `api/ec/poi` del wm-package (dev, 17/11): i
      dati sono gli stessi che il JSON del dettaglio della traccia espone già senza token. Forma:
      ```json
      {
        "data": [
          {
            "track_id": 123,
            "ref": "T 521",
            "name":    { "it": "Da Is Muralleddas a Texile", "en": "…" },
            "message": { "it": "Sentiero non percorribile.", "en": "…" },
            "published_at": "2026-11-17T10:05:00+01:00"
          }
        ],
        "generated_at": "…"
      }
      ```
- [ ] Solo le tracce con `not_accessible = true` e testo italiano del messaggio non vuoto, escluse
      quelle marcate `properties->forestas->deleted_from_source = true`.
- [ ] Ordine per `published_at` dal più recente; le tracce senza data in fondo. Nessuna
      paginazione: «meno recenti prima» lo fa il sito invertendo l'elenco.
- [ ] `ref` da `properties['ref']`, `name` dalla colonna traducibile `name`, `message` da
      `not_accessible_message` con tutte le lingue presenti; `published_at` dalla data del wm-package,
      `null` se assente.
- [ ] Test di feature sulla rotta: accesso senza token, filtro, ordinamento, forma del JSON.
- [ ] Pagina di conoscenza sugli avvisi, con: la perdita notturna su UAT; `published_at` come
      ultimo aggiornamento del testo italiano (una correzione riporta l'avviso in cima, deciso
      17/11); su UAT `track_id` cambia ogni notte per il `RESTART IDENTITY`; il sito deve
      distinguere un errore dell'API (5xx, timeout) da un elenco vuoto; per l'app un avviso resta
      `not_accessible`, cioè sentiero non accessibile.

## Rischi

- **Su UAT gli avvisi si perdono ogni mattina alle 06:00**: il reset notturno tronca `ec_tracks`.
  Accettato come per l'inclusività (dev, 17/11): va scritto nella pagina di conoscenza e detto a
  Rubens e al cliente, perché un avviso inserito per prova sparisce il giorno dopo.
- **I campi spostati non devono dipendere dalla percorribilità**: `walkabilityTabFields()`
  restituisce `[]` quando il dominio Catasto è spento o non ci sono termini. Mitigazione: i campi
  dell'avviso si aggiungono alla chiave `STATUS_TAB` in `taxonomyTabExtraFields()` di forestas, con
  un Heading proprio, fuori dal risultato di quel metodo.
- **Togliere i campi dal tab Info senza duplicare il package**: forestas usa `getInfoTabFields()`
  del package. Mitigazione: filtrare i campi per attributo (`properties->not_accessible`,
  `properties->not_accessible_message`, la data) e coprirlo con un test che fallisce se ricompaiono
  in Info.
- **Una correzione del testo riporta l'avviso in cima** a `/avvisi` e alla fascia della home: è la
  regola scelta (dev, 17/11), da dichiarare nella pagina di conoscenza.
- **Un avviso acceso senza messaggio** non va sul sito: l'API lo esclude, l'help del campo lo dice.
- **L'API la consuma il sito con una cache sua**: un avviso spento può restare visibile fino alla
  scadenza della cache del sito (un'ora, come per gli enti). Da dire a Rubens.

## Out of scope

- Frontend WordPress: fascia della scheda, fascia della home, pagina `/avvisi` (Rubens).
- L'app.
- Più avvisi per sentiero, storico degli avvisi, scadenza.
- Protezione degli avvisi dal reset notturno.
- Rapporto con la percorribilità del Catasto (oc:8989): l'avviso resta un dato a sé.

## Moduli toccati

Repo forestas:
- `app/Nova/EcTrack.php` — spostamento dei campi, label, data in sola lettura
- `routes/api.php` — rotta `v1/avvisi`
- `app/Http/Controllers/Api/` — nuovo controller degli avvisi
- `lang/it.json` — traduzioni delle label
- `tests/Feature/` — test della rotta
- `docs/knowledge/` e `CLAUDE.md` — pagina di conoscenza e riga d'indice

Repo wm-package: vedi la sua overview.
