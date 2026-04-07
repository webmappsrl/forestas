# Design: TipoEnte Enum

**Data:** 2026-04-07
**Scope:** Sostituire il campo `tipo_ente` integer raw su `Ente` con un enum forestas-specifico, risolto durante l'import tramite l'API Drupal `/taxonomy/term/{tid}`.

---

## Contesto

Il modello `Ente` aveva `tipo_ente` come intero grezzo (Drupal term ID). Il vocabolario Drupal `tipo_ente_istituzione_societa` non era accessibile via `/ss/tassonomia/tipo_ente` (ritorna array vuoto). L'endpoint `/taxonomy/term/{tid}?_format=json` è invece accessibile e restituisce il label del term.

Drupal andrà in disuso dopo la migrazione — l'enum è definitivo e non dipende da Drupal.

**5 tipi identificati (campionamento reale):**

| Drupal ID | Label                      | Slug enum                      |
|-----------|----------------------------|--------------------------------|
| 4699      | Complesso forestale        | `complesso-forestale`          |
| 4700      | Ente partner               | `ente-partner`                 |
| 4701      | Altre Pubbliche Istituzioni| `altre-pubbliche-istituzioni`  |
| 4702      | Privato/associazione       | `privato-associazione`         |
| 4703      | Comune                     | `comune`                       |

---

## Architettura

### Enum `TipoEnte` (`app/Enums/TipoEnte.php`)

Backed enum `string` con slug forestas come valore. Conosce la mappatura Drupal solo tramite `getDrupalId()`.

```php
enum TipoEnte: string
{
    case ComplessoForestale    = 'complesso-forestale';
    case EntePartner           = 'ente-partner';
    case AltrePubbliche        = 'altre-pubbliche-istituzioni';
    case PrivatoAssociazione   = 'privato-associazione';
    case Comune                = 'comune';

    public function getDrupalId(): int
    {
        return match($this) {
            self::ComplessoForestale   => 4699,
            self::EntePartner          => 4700,
            self::AltrePubbliche       => 4701,
            self::PrivatoAssociazione  => 4702,
            self::Comune               => 4703,
        };
    }

    public static function fromDrupalId(int $id): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->getDrupalId() === $id) {
                return $case;
            }
        }
        return null;
    }

    public function label(): string
    {
        return match($this) {
            self::ComplessoForestale   => __('tipo_ente.complesso_forestale'),
            self::EntePartner          => __('tipo_ente.ente_partner'),
            self::AltrePubbliche       => __('tipo_ente.altre_pubbliche_istituzioni'),
            self::PrivatoAssociazione  => __('tipo_ente.privato_associazione'),
            self::Comune               => __('tipo_ente.comune'),
        };
    }
}
```

### Migrazione

La colonna `tipo_ente` su `entes` cambia da `integer nullable` a `string nullable` per contenere lo slug.

### Modello `Ente`

Cast cambia da `'tipo_ente' => 'integer'` a `'tipo_ente' => TipoEnte::class`. Laravel gestisce il cast enum automaticamente tramite il backing value (slug).

### Import (`SardegnaSentieriImportService`)

Flusso per ogni nodo ente con `field_tipo_ente[0].target_id`:

1. Tenta `TipoEnte::fromDrupalId($id)` — se trovato, usa `$enum->value`
2. Se non trovato (ID non mappato nell'enum):
   - Chiama `/taxonomy/term/{id}?_format=json` per ottenere il label
   - Slugifica il label con `Str::slug($label)`
   - Salva lo slug grezzo nel campo `tipo_ente`
   - Logga `Log::warning("TipoEnte Drupal ID {$id} non mappato: slug '{$slug}'")`
3. Lo slug non mappato nel DB è già la chiave per aggiungere il case mancante all'enum in seguito

### Client (`SardegnaSentieriClient`)

Aggiunta di `getTaxonomyTerm(int $id): array` — chiama `/taxonomy/term/{id}?_format=json`. Usata solo nel fallback import per ID non mappati.

### Nova `Ente`

Il campo `tipo_ente` passa da `Text::make(...)->readonly()` a `Select::make(...)` con opzioni derivate da `TipoEnte::cases()`.

---

## Gestione ID non mappati

L'approccio "slug-first" garantisce che:
- L'import non si blocca mai per ID Drupal sconosciuti
- Lo slug salvato nel DB è già il futuro `value` del case da aggiungere
- Il log di warning rende visibili i gap senza richiedere intervento immediato

---

## Stato implementazione

| Task | Stato |
|------|-------|
| Enum `TipoEnte` con `getDrupalId()`, `fromDrupalId()`, `label()` | pendente |
| Migrazione `alter_tipo_ente_on_entes_to_string` | pendente |
| Cast su `Ente` model | pendente |
| `getTaxonomyTerm()` su `SardegnaSentieriClient` | pendente |
| Logica import con fallback slug + warning | pendente |
| Nova `Ente` campo Select | pendente |
