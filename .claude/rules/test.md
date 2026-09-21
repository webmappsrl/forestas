---
paths:
  - "tests/**"
  - "phpunit.xml"
---

# Trappole: test

**Il DB reale contiene dati importati da processi lunghi: distruggerli è inaccettabile.** È la
regola in cima al `CLAUDE.md`, non una sfumatura.

L'isolamento è garantito da `phpunit.xml`, che imposta `DB_DATABASE=forestas_testing`;
`RefreshDatabase` è attivo sui test in `tests/Feature`. Restano da verificare, prima di lanciare la
suite:

- **`.env.testing` esiste e punta a `forestas_testing`.** Non è versionato (contiene chiavi): si
  crea dal modello `.env.testing-example` — vedi
  [docs/howto/setup-progetto.md](../../docs/howto/setup-progetto.md). Senza di esso i test girano
  comunque, ma senza `JWT_SECRET`, e quelli che firmano un token falliscono in modo poco leggibile
- **Il database `forestas_testing` esiste sulla macchina.** Se non esiste i test falliscono con
  «database does not exist»: non ricadono sul DB reale, ma non partono
- **Se l'isolamento non è verificabile, non lanciare i test**: chiedere consenso esplicito
- **Vale anche per i subagent**: vanno istruiti esplicitamente a non lanciare test senza aver
  verificato l'isolamento
- **I test che toccano wm-package non stanno qui**: la suite del package usa il database
  `wm_package` (`wm-package/phpunit.xml.dist`), da creare una volta con PostGIS (oc:8469)
- **PHPStan vede i test come `PHPUnit\Framework\TestCase`**, non come `Tests\TestCase`: il binding
  di Pest non è interpretato, e gli helper Laravel risultano assenti. `phpstan.neon.dist` ignora
  quei due identifier su `tests/*` — non aggiungere ignore nuovi per lo stesso motivo
