# Plan: Layer "In Home" Boolean + AddToConfigHome Action

## Goal

1. **Boolean `In Home`** nella index della resource `Layer` — mostra se il layer è presente nel `config_home` dell'app
2. **Action `Aggiungi alla Home`** — bulk action che aggiunge i layer selezionati alla coda di `config_home` dell'app, saltando i duplicati

---

## Phase 0: Documentation Discovery (DONE)

### Struttura JSON `config_home` confermata

**Fonte:** `wm-package/src/Nova/Flexible/Resolvers/ConfigHomeResolver.php`

Il campo `config_home` nell'app è salvato nel DB come:
```json
{
  "HOME": [
    { "box_type": "layer", "layer": 15, "title": "Tracce Forestali" },
    { "box_type": "title", "title": "Titolo sezione" }
  ]
}
```

- Un layer in home è identificato da `box_type === 'layer'` e `layer === <id locale del Layer>`
- Il `title` è il nome stringa del layer (`$layer->getStringName()`)

### Pattern read/write config_home confermato

**Fonte:** `wm-package/src/Jobs/UpdateAppConfigHomeLayerIdsJob.php:54`

Lettura: `$app->getRawOriginal('config_home')` — bypassa il `FlexibleCast`, restituisce il JSON grezzo stringa

Scrittura sicura (bypassa FlexibleCast):
```php
DB::table('apps')->where('id', $app->id)->update(['config_home' => json_encode($data)]);
```

### Pattern campo computed nell'index confermato

**Fonte:** `wm-package/src/Nova/TaxonomyWhere.php:45-50`
```php
Boolean::make('Geometry', function () {
    $model = $this->resource;
    return ! is_null($model->geometry);
})->onlyOnIndex(),
```

### Relazione Layer → App confermata

**Fonte:** `wm-package/src/Models/Layer.php:67-70`
```php
public function appOwner(): BelongsTo
{
    return $this->belongsTo(App::class, 'app_id');
}
```

### Metodo nome layer confermato

**Fonte:** `wm-package/src/Nova/Flexible/Resolvers/ConfigHomeResolver.php:81-87`
```php
$title = $layer->getStringName();
if (empty($title)) {
    $title = 'Layer #'.$layer->id;
}
```

### File Nova Layer da modificare

- `wm-package/src/Nova/Layer.php` — aggiungere field boolean + action

---

## Phase 1: Boolean `In Home` nella index di Layer

### Cosa implementare

**Modifica:** `wm-package/src/Nova/Layer.php`

Aggiungere campo `Boolean::make('In Home', ...)` nella lista `fields()`, visibile solo in index (`->onlyOnIndex()`).

La closure legge il `config_home` grezzo dell'app del layer e controlla se l'id del layer è presente:

```php
Boolean::make('In Home', function () {
    /** @var \Wm\WmPackage\Models\Layer $layer */
    $layer = $this->resource;

    if (! $layer->app_id) {
        return false;
    }

    $app = $layer->appOwner;
    if (! $app) {
        return false;
    }

    $raw  = $app->getRawOriginal('config_home');
    if (empty($raw)) {
        return false;
    }

    $data = json_decode($raw, true);
    $home = $data['HOME'] ?? [];

    return collect($home)->contains(
        fn ($item) => ($item['box_type'] ?? '') === 'layer'
            && (int) ($item['layer'] ?? 0) === $layer->id
    );
})->onlyOnIndex(),
```

### Verification

```bash
grep -n "In Home\|inHome\|config_home" wm-package/src/Nova/Layer.php
# → deve trovare le righe del campo Boolean
```

---

## Phase 2: Action `AddToConfigHome`

### File da creare

**Crea:** `wm-package/src/Nova/Actions/AddLayersToConfigHomeAction.php`

Logica:
- NO `$standalone` — opera sui `$models` Layer selezionati
- Raggruppa i layer per `app_id`
- Per ogni app: legge il JSON grezzo, aggiunge i layer non ancora presenti in fondo alla HOME, salva con `DB::table`
- Skippa i layer già presenti (no duplicati)
- Restituisce messaggio con count aggiunti e count saltati

```php
<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class AddLayersToConfigHomeAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Aggiungi alla Home');
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $added   = 0;
        $skipped = 0;

        // Raggruppa i layer per app
        $byApp = $models->groupBy('app_id');

        foreach ($byApp as $appId => $layers) {
            $app = $layers->first()->appOwner;
            if (! $app) {
                $skipped += $layers->count();
                continue;
            }

            $raw  = $app->getRawOriginal('config_home');
            $data = ! empty($raw) ? json_decode($raw, true) : [];
            $home = $data['HOME'] ?? [];

            // Set degli id già in home per lookup veloce
            $presentIds = collect($home)
                ->where('box_type', 'layer')
                ->pluck('layer')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($layers as $layer) {
                if (in_array($layer->id, $presentIds, true)) {
                    $skipped++;
                    continue;
                }

                $title     = $layer->getStringName();
                $home[]    = [
                    'box_type' => 'layer',
                    'layer'    => $layer->id,
                    'title'    => ! empty($title) ? $title : 'Layer #' . $layer->id,
                ];
                $presentIds[] = $layer->id;
                $added++;
            }

            $data['HOME'] = $home;
            DB::table('apps')
                ->where('id', $app->id)
                ->update(['config_home' => json_encode($data)]);
        }

        $msg = "Aggiunti {$added} layer alla home.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} già presenti, saltati)";
        }

        return Action::message($msg);
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
```

### Registra l'action nella resource Layer

**Modifica:** `wm-package/src/Nova/Layer.php`

Aggiungere import e action in `actions()`:
```php
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;

public function actions(NovaRequest $request): array
{
    return [
        ...parent::actions($request),  // o array esistente
        new AddLayersToConfigHomeAction,
    ];
}
```

### Verification

```bash
# File esiste
ls wm-package/src/Nova/Actions/AddLayersToConfigHomeAction.php

# Nessun $standalone
grep "standalone" wm-package/src/Nova/Actions/AddLayersToConfigHomeAction.php
# → nessun risultato

# Registrata nella resource
grep "AddLayersToConfigHome" wm-package/src/Nova/Layer.php
```

---

## Phase 3: Verifica Finale

### Checklist

- [ ] `Boolean 'In Home'` visibile nella index Layer (onlyOnIndex)
- [ ] Il boolean usa `getRawOriginal('config_home')` non il cast FlexibleCast
- [ ] `AddLayersToConfigHomeAction` esiste, NO `$standalone`
- [ ] L'action raggruppa per app_id prima di scrivere (un solo DB update per app)
- [ ] L'action skippa layer già presenti in HOME
- [ ] Il salvataggio usa `DB::table('apps')->update(...)` (bypass FlexibleCast)
- [ ] `vendor/bin/phpstan analyse` non introduce nuovi errori

### Anti-pattern da evitare

- NON leggere `$app->config_home` (passa per FlexibleCast → Collection di oggetti Flexible, non JSON)
- NON usare `$app->update(['config_home' => ...])` (il cast FlexibleCast potrebbe corrompere il valore)
- NON duplicare layer già presenti in HOME
- NON caricare `appOwner` dentro un loop senza groupBy (N+1 query)
