> Ticket: oc:9038
> Overview approvata il 2026-11-24

# Notes — la parte di Forestas

Il diario del lavoro è uno solo e vive nel package:
[wm-package/docs/features/9038-attivita-primaria-del-sentiero/notes.md](../../../wm-package/docs/features/9038-attivita-primaria-del-sentiero/notes.md).
Lì stanno le decisioni, le divergenze dal piano e i bug trovati, compresi i due che riguardano
questo repo e che sono elencati nel suo `plan.md`.

## Quello che riguarda solo questo repo

### 2026-11-24 — la migration della famiglia si pubblica e si committa qui
`wm-package:publish-missing-migrations --with=trail_registry` ha prodotto
`database/migrations/2026_11_24_162631_zz_2026_11_24_000001_install_primary_activity_taxonomy.php`,
identico byte per byte allo stub del package. Senza quel file committato il gate di CI
(`.github/workflows/run-tests.yml`, passo «Check wm-package stub vs schema DB») va rosso, e
soprattutto la famiglia non nasce: ogni approvazione con un'attività primaria si fermerebbe, perché
il termine non esiste. Eseguita in locale, con verifica prima/dopo delle famiglie accese: una sola
riga aggiunta, nessuna delle tredici esistenti toccata.

### 2026-11-24 — tre nomi diversi per la stessa attività, non riallineati
`hiking` è «Alte vie» nella `TaxonomyActivity` a database, «Escursionismo» nella tabella del campo 36
di `config/scramble.php`, e «Camminate giornaliere» nei filtri del sito
(`wp-forestas/themes/forestas-child/inc/search.php`, dove un commento li dichiara provvisori in
attesa di oc:8932). Tutto preesistente: questo lavoro **non** li ha toccati, perché cambiare la
tabella della pagina cambia ciò che Engineering legge ed è una decisione da prendere col cliente.

### 2026-11-24 — un difetto di forma preesistente, lasciato dov'era
In `config/scramble.php` il paragrafo «La creazione non si corregge, si rifa.» ha quattro spazi di
indentazione in più e in Markdown viene reso come blocco di codice. Non toccato: è fuori dal
perimetro di questo ticket.
