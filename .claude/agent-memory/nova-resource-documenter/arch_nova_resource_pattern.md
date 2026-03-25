---
name: Nova resource extension pattern
description: Come le risorse Nova del progetto estendono wm-package, menu sezioni, gate accesso
type: project
---

Pattern estensione: app/Nova/<Resource> extends Wm\WmPackage\Nova\<Resource>.
Il progetto sovrascrive solo ciò che differisce (tipicamente actions aggiuntive).
Esempio: App\Nova\TaxonomyWhere aggiunge solo ImportTaxonomyWhereFromLayersAction via ...parent::actions($request).

Gerarchia Nova: app/Nova/<Resource> > Wm\WmPackage\Nova\<Resource> > AbstractTaxonomyResource > Laravel\Nova\Resource

Menu Nova (NovaServiceProvider):
- Admin (solo Administrator): App, User, Media
- UGC: UgcPoi, UgcTrack
- EC: EcPoi, EcTrack, Layer
- Taxonomies: TaxonomyPoiType, TaxonomyActivity, TaxonomyWhere
- Files (solo Administrator): Icons

Gate: !$user->hasRole('Guest') — i Guest non accedono a Nova.
Policies aggiuntive si registrano in AppServiceProvider::boot() via Gate::policy().

wm-package path: /Users/bongiu/Documents/geobox2/forestas/wm-package/

Documentazione a due livelli: quando una risorsa ha sia una classe base in wm-package che una customizzazione in app/, creare due file Markdown distinti:
- wm-package/docs/resources/<Resource>.md — documentazione completa della classe base (modello, fields, filters, actions, behaviors)
- docs/resources/<Resource>.md — solo le customizzazioni app-level, con link al parent doc

Il file app-level documenta solo i delta rispetto al parent (actions aggiuntive, sorgenti specifiche, menu placement).

**How to apply:** per aggiungere una nuova risorsa, creare app/Nova/<Resource> che estende il corrispettivo wm-package, e aggiungere MenuItem nella sezione corretta in NovaServiceProvider. Per documentare, seguire il pattern a due livelli descritto sopra.
