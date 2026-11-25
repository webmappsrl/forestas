> Ticket: oc:9038
> Stato: seguito, con 4 divergenze annotate nelle note del package

# Piano — la parte di Forestas

Il piano del lavoro è uno solo e vive nel package, dove sta il codice:
[wm-package/docs/features/9038-attivita-primaria-del-sentiero/plan.md](../../../wm-package/docs/features/9038-attivita-primaria-del-sentiero/plan.md).

Di quei sette task, **uno solo tocca questo repo**: il Task 7, la documentazione delle API, la cui
prosa vive in `config/scramble.php` e non nel package. Nel corso del lavoro se ne sono aggiunti due
interventi che il piano non prevedeva, entrambi nati da una review:

- il payload del pulsante «Prova una domanda completa» in
  `resources/views/vendor/scramble/docs.blade.php`, che senza il campo nuovo avrebbe fatto rispondere
  `422` a ogni prova fatta da Engineering dalla pagina;
- `app/Http/Controllers/Api/Sus/SusTestDecisionController.php`, il canale con cui il cliente prova il
  flusso intero, che chiamava l'approvazione senza passarle le scelte e quindi consegnava sempre una
  decisione vuota.

Più la migration pubblicata dal comando del package, che su questo repo è un file versionato.
