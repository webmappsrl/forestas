# Piano: Aggiunta tab Map alla Nova App resource

## Contesto

Aggiungere una tab `map` al `Tab::group('App', [...])` in `wm-package/src/Nova/App.php`.
I campi già esistono come colonne nel DB e sono già mappati in `AppConfigService::config_section_map()` — bisogna solo esporre l'editing in Nova.

> **Piano vivo** — aggiornare se cambiano i requisiti.

---

## Phase 0 — Documentation Discovery (COMPLETATA)

**Findings chiave:**

- **File target:** `wm-package/src/Nova/App.php` — aggiungere `Tab::make('map', $this->map_tab())` e implementare il metodo
- **config.json:** `wm-package/src/Services/Models/App/AppConfigService.php` → `config_section_map()` (riga 273)
- **AppTiles:** esiste in `Wm\WmPackage\Enums\AppTiles`, usare `(new AppTiles)->oldval()` per le opzioni tiles
- **Multiselect:** usare `Outl1ne\MultiselectField\Multiselect` (già importata nella classe)
- **AppZoom:** non esiste — usare valori letterali: zoom min=1, max=20
- **WmEmbedmapsField:** non in composer — da saltare; usare solo il `Text::make` per display `map_bbox`
- **Pattern esistente:** gli altri tab usano `->hideFromIndex()` su quasi tutti i campi; alcune sezioni usano `NovaTabTranslatable`

**Colonne DB esistenti (tutte già in `ec_tracks` migration):**

| Colonna | Tipo | Default | → config.json |
|---------|------|---------|---------------|
| `tiles` | json | webmapp tile | `MAP.tiles` |
| `tiles_label` | translatable | - | `MAP.controls.tiles[].label` |
| `data_label` | translatable | - | `MAP.controls.data[].label` (titolo) |
| `pois_data_label` | translatable | - | `MAP.controls.data[pois].label` |
| `pois_data_default` | bool | - | `MAP.controls.data[pois].default` |
| `pois_data_icon` | text (SVG) | - | `MAP.controls.data[pois].icon` |
| `tracks_data_label` | translatable | - | `MAP.controls.data[tracks].label` |
| `tracks_data_default` | bool | - | `MAP.controls.data[tracks].default` |
| `tracks_data_icon` | text (SVG) | - | `MAP.controls.data[tracks].icon` |
| `map_def_zoom` | integer | 12 | `MAP.defZoom` |
| `map_max_zoom` | integer | 16 | `MAP.maxZoom` |
| `map_min_zoom` | integer | 12 | `MAP.minZoom` |
| `map_max_stroke_width` | integer | 6 | `MAP.maxStrokeWidth` |
| `map_min_stroke_width` | integer | 3 | `MAP.minStrokeWidth` |
| `map_bbox` | string (JSON) | null | `MAP.bbox` |
| `start_end_icons_min_zoom` | integer | 10 | `MAP.start_end_icons_min_zoom` |
| `ref_on_track_min_zoom` | integer | 10 | `MAP.ref_on_track_min_zoom` |
| `gps_accuracy_default` | integer | 10 | `GEOLOCATION.gps_accuracy_default` |
| `alert_poi_radius` | integer | 100 | `MAP.alert_poi_radius` |
| `flow_line_quote_orange` | integer | 800 | `MAP.flow_line_quote_orange` |
| `flow_line_quote_red` | integer | 1500 | `MAP.flow_line_quote_red` |

**Anti-pattern identificati:**
- NON importare `AppZoom` — non esiste
- NON usare `WmEmbedmapsField` — non è in composer
- NON usare `OptimistDigital\MultiselectField` — rimpiazzata da `Outl1ne\MultiselectField\Multiselect`
- `->hideFromIndex()` su tutti i campi della tab (pattern degli altri tab)

---

## Phase 1 — Aggiunta tab map (unica fase)

**File da modificare:**
- `wm-package/src/Nova/App.php`

### Step 1: aggiungere `Tab::make('map', ...)` al gruppo

Nel metodo `fields()`, all'interno di `Tab::group('App', [...])`, aggiungere dopo `'analytics'`:

```php
Tab::make('map', $this->map_tab()),
```

### Step 2: aggiungere import

```php
use Wm\WmPackage\Enums\AppTiles;
```

(già importata `Multiselect`, `Boolean`, `Number`, `Select`, `Text`, `Heading`, `NovaTabTranslatable`, `Textarea`)

### Step 3: implementare `map_tab()`

```php
protected function map_tab(): array
{
    $selectedTileLayers = is_null($this->model()->tiles) ? [] : json_decode($this->model()->tiles, true);
    $appTiles = new AppTiles;
    $t = $appTiles->oldval();

    return [
        // --- TILES ---
        Heading::make(
            <<<'HTML'
            <p><strong>Tiles Label</strong>: Text displayed for selecting tiles through the app.</p>
            HTML
        )->asHtml()->hideFromIndex(),
        NovaTabTranslatable::make([
            Text::make('Tiles Label'),
        ])->hideFromIndex(),
        Multiselect::make(__('Tiles'), 'tiles')
            ->options($t, $selectedTileLayers)
            ->reorderable()
            ->hideFromIndex()
            ->help(__('Select which tile layers will be used by the app, the order is the same as the insertion order, so the last one inserted will be the one visible first')),

        // --- DATA CONTROLS ---
        Heading::make(
            <<<'HTML'
            <ul>
              <li><p><strong>Data Label</strong>: Text to be displayed as the header of the data filter.</p></li>
              <li><p><strong>Pois Data Label</strong>: Text to be displayed for the POIs filter.</p></li>
              <li><p><strong>Tracks Data Label</strong>: Text to be displayed for the Tracks filter.</p></li>
            </ul>
            HTML
        )->asHtml()->hideFromIndex(),
        NovaTabTranslatable::make([
            Text::make('Data Label')->help(__('Text to be displayed as the header of the data filter.')),
            Text::make('Pois Data Label'),
            Text::make('Tracks Data Label'),
        ])->hideFromIndex(),
        Boolean::make('Show POIs data by default', 'pois_data_default')
            ->hideFromIndex()
            ->help(__('Turn this option off if you do not want to show POIs by default on the map.')),
        Textarea::make('POI Data Icon SVG', 'pois_data_icon')
            ->hideFromIndex()
            ->help(__('SVG icon shown in the filter for POIs')),
        Boolean::make('Show Tracks data by default', 'tracks_data_default')
            ->hideFromIndex()
            ->help(__('Turn this option off if you do not want to show all track layers by default on the map')),
        Textarea::make('Track Data Icon SVG', 'tracks_data_icon')
            ->hideFromIndex()
            ->help(__('SVG icon shown in the filter for Tracks')),

        // --- ZOOM & STROKE ---
        Heading::make(
            <<<'HTML'
            <p><strong>Map zoom and stroke settings.</strong></p>
            HTML
        )->asHtml()->hideFromIndex(),
        Number::make(__('Def Zoom'), 'map_def_zoom')
            ->min(1)->max(19)->step(0.1)->default(12)
            ->hideFromIndex()
            ->help(__('The default zoom level when the map is first loaded.')),
        Number::make(__('Max Zoom'), 'map_max_zoom')
            ->min(1)->max(20)->default(16)
            ->hideFromIndex()
            ->help(__('Maximum zoom level for the map')),
        Number::make(__('Min Zoom'), 'map_min_zoom')
            ->min(1)->max(20)->default(12)
            ->hideFromIndex()
            ->help(__('Minimum zoom level for the map')),
        Number::make(__('Max Stroke width'), 'map_max_stroke_width')
            ->min(0)->max(19)->default(6)
            ->hideFromIndex()
            ->help(__('Set max stroke width of line string, applied at max zoom level')),
        Number::make(__('Min Stroke width'), 'map_min_stroke_width')
            ->min(0)->max(19)->default(3)
            ->hideFromIndex()
            ->help(__('Set min stroke width of line string, applied at min zoom level')),

        // --- BBOX ---
        Text::make(__('Bounding BOX'), 'map_bbox')
            ->nullable()
            ->hideFromIndex()
            ->rules([
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $decoded = json_decode($value);
                    if (! is_array($decoded)) {
                        $fail('The '.$attribute.' is invalid. Follow the example [9.9456,43.9116,11.3524,45.0186]');
                    }
                },
            ])
            ->help(__('Bounding the map view. Example: [9.9456,43.9116,11.3524,45.0186]')),

        // --- ADVANCED MAP SETTINGS ---
        Heading::make(
            <<<'HTML'
            <p><strong>Advanced map display settings.</strong></p>
            HTML
        )->asHtml()->hideFromIndex(),
        Number::make(__('start_end_icons_min_zoom'))
            ->min(10)->max(20)
            ->hideFromIndex()
            ->help(__('Set minimum zoom at which start and end icons are shown in general maps (start_end_icons_show must be true)')),
        Number::make(__('ref_on_track_min_zoom'))
            ->min(10)->max(20)
            ->hideFromIndex()
            ->help(__('Set minimum zoom at which ref parameter is shown on tracks line in general maps (ref_on_track_show must be true)')),
        Number::make(__('alert_poi_radius'))
            ->default(100)
            ->hideFromIndex()
            ->help(__('Set the radius (in meters) of the activation circle with the center as the user position. The nearest POI inside the circle triggers the alert')),
        Number::make(__('flow_line_quote_orange'))
            ->default(800)
            ->hideFromIndex()
            ->help(__('Defines the elevation by which the track turns orange')),
        Number::make(__('flow_line_quote_red'))
            ->default(1500)
            ->hideFromIndex()
            ->help(__('Defines the elevation by which the track turns red')),

        // --- GPS ---
        Select::make(__('GPS Accuracy Default'), 'gps_accuracy_default')
            ->options([
                '5'   => '5 meters',
                '10'  => '10 meters',
                '20'  => '20 meters',
                '100' => '100 meters',
            ])
            ->hideFromIndex()
            ->help(__('Set the default GPS accuracy level for tracking.'))
            ->displayUsingLabels(),
    ];
}
```

### Verification

```bash
# Verifica PHPStan
vendor/bin/phpstan analyse wm-package/src/Nova/App.php

# Verifica visuale: aprire Nova in browser → App → dettaglio di un'app → tab "map"
# Verificare che tutti i campi siano presenti e salvabili

# Verifica che i valori si riflettano nel config.json
docker exec -it php-${APP_NAME} php artisan tinker --execute="
  \$app = \Wm\WmPackage\Models\App::first();
  \$app->map_max_zoom = 18;
  \$app->save();
  \$config = app(\Wm\WmPackage\Services\Models\App\AppConfigService::class, ['app' => \$app])->config();
  echo \$config['MAP']['maxZoom'];  // deve stampare 18
"
```

**Anti-pattern checklist:**
- [ ] NO `AppZoom::MIN_ZOOM/MAX_ZOOM` — usare valori letterali (1, 20)
- [ ] NO `WmEmbedmapsField` — non è in composer
- [ ] NO `OptimistDigital\MultiselectField` — usare `Outl1ne\MultiselectField\Multiselect`
- [ ] `->hideFromIndex()` su tutti i campi
- [ ] Nessun campo nuovo in DB — tutto già mappato in AppConfigService
