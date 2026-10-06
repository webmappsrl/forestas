---
paths:
  - "app/Nova/**"
  - "app/Providers/**"
---

# Trappole: Nova

- **Il gate blocca `Guest` *e* `Sus`** (`NovaServiceProvider::gate()`): il client SUS è un canale
  programmatico verso un ente esterno e non deve vedere il backoffice. Aggiungere un ruolo tecnico
  senza toccare il gate gli regala Nova (oc:8333)
- **Le Resource del progetto estendono quelle del package**, non le riscrivono:
  `class App extends Wm\WmPackage\Nova\App {}`. Serve a personalizzare label e campi tenendo la
  logica base nel package
- **La sezione di menu del Catasto si dichiara in `NovaServiceProvider::boot()` con la chiave
  `__('Trail registry')` e le sole voci di forestas**: il package la ritrova confrontando
  l'etichetta tradotta con `__('Trail registry')`, e ci mette davanti le proprie con
  `injectMenuSectionItems()`, solo a dominio acceso; conserva il `canSee` della sezione e la mostra
  solo se almeno una voce è visibile. Con un'altra chiave in italiano non si vede nulla (tutte e
  due diventano «Catasto», voce che fornisce il package), ma in inglese il menu mostra due sezioni.
  Una voce aggiunta lì con un `canSee` falso per tutti non si vede, e se era l'unica visibile
  sparisce l'intera sezione. Le nuove sezioni vanno dopo quella `Media`; `Tools` è cercata per nome
  dal package, rinominarla la rende invisibile (oc:8489, oc:8700, oc:8672)
- **Le altre trappole Nova del Catasto Sentieri stanno nel package**, sezione «Trappole» di
  `wm-package/docs/resources/TrailRegistry.md`: titolo di una Resource che non può essere una
  colonna enum, colonne obbligatorie dichiarate nullable, componenti Vue con render function
- I trait riusabili stanno in `app/Nova/Traits/`: `FiltersUsersByRoleTrait` (filtra gli utenti
  relatibili per ruolo), `HidesAppFromIndexTrait` (nasconde il campo `app` dall'index)
- Le policy di `Role`, `Permission` e `Tile` sono registrate in `AppServiceProvider::boot()`; le
  policy progetto-specifiche si aggiungono lì con `Gate::policy(...)`
