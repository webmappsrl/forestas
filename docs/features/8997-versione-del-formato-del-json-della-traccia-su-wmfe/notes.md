> Ticket: oc:8997
> impronta `2839febe1ec3b87c204e5b9745a979ea571d12b3` dell'overview approvata

# Note di lavorazione — forestas

Il meccanismo è nel package, e lì sta il grosso del diario:
[wm-package/docs/features/8997-…/notes.md](../../../wm-package/docs/features/8997-versione-del-formato-del-json-della-traccia-su-wmfe/notes.md).
Qui solo ciò che riguarda questo shard.

## Modifiche ai requisiti

Nessuna finora.

## Divergenze dal piano

Nessuna finora.

## Bug trovati

Nessuno in questo repo. Quelli trovati nel package stanno nelle sue note.

## Decisioni

- **Il reset notturno è acceso su entrambi i server** (dev e UAT), quindi i file della versione
  nuova nascono da soli alla prima notte e l'Action Nova «Update Tracks on AWS» serve solo a non
  aspettarla. Confermato dal dev in pianificazione; dipende da `SARDEGNASENTIERI_DAILY_RESET` nel
  `.env` di ciascun server, non dal nome dell'ambiente.
- **La variabile va scritta nel `.env` dei server prima del deploy**, come `WP_API_TOKEN`:
  `php artisan optimize` congela la configurazione, e una variabile aggiunta dopo non viene letta.
  Scritta in `.env-example` e in `docs/howto/deploy-automatico.md`, fra i passaggi a mano.
- **In locale il percorso dei file non è `forestasdev`.** `SHARD_NAME` non è valorizzata e vale
  `APP_NAME`, quindi i file nascono sotto `/forestas/tracks/`: i test compongono il percorso da
  `config('wm-package.shard_name')`, mai a mano.

## Follow-up

- `docs/howto/deploy-automatico.md:41` dice «orario sul dev, alle 06:00 su UAT» parlando
  dell'import: se il reset è acceso su entrambi, quella riga è sbagliata. È di un altro ticket.
