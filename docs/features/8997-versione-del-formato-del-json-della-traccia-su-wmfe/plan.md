> Ticket: oc:8997
> Stato: da approvare

# Versione del formato del JSON della traccia su wmfe — piano (forestas)

> **Per chi esegue:** questo piano si esegue task per task con
> `superpowers:executing-plans`. I blocchi «Commit» sono **istruzioni testuali, non azioni**:
> in questo progetto non si committa durante l'esecuzione, il primo commit lo fa il dev alla
> chiusura. Durante il lavoro `overview.md` non si tocca: ogni cambiamento a un requisito va in
> `notes.md`, sezione «Modifiche ai requisiti».

**Obiettivo:** forestas è il primo shard a scrivere due versioni del JSON della traccia su `wmfe`,
e il sito `wp-forestas` continua a leggere il file senza versione come oggi.

**Architettura:** tutto il meccanismo sta nel package; qui c'è la dichiarazione delle versioni
attive in una variabile d'ambiente, la prova che `properties.forestas` sopravviva al refactor del
package, e il puntatore del submodule.

**Stack:** PHP 8.4 nel container, Laravel 12, Pest/PHPUnit, PostgreSQL + PostGIS.

**Spec:** [overview.md](overview.md). Il meccanismo è in
[wm-package/docs/features/8997-…/overview.md](../../../wm-package/docs/features/8997-versione-del-formato-del-json-della-traccia-su-wmfe/overview.md).

**Ordine fra i repo:** questo piano si esegue **dopo** quello del package
([wm-package/docs/features/8997-…/plan.md](../../../wm-package/docs/features/8997-versione-del-formato-del-json-della-traccia-su-wmfe/plan.md)),
e il merge segue lo stesso ordine: prima il package, poi lo shard. **Il bump del puntatore del
submodule non è un passo di questo piano**: si fa alla chiusura, quando il package è stato
mergiato, prendendo l'hash con `git -C wm-package rev-parse HEAD` sul develop aggiornato. Durante
l'esecuzione non si committa, quindi un hash preso adesso sarebbe il develop di oggi, senza il
lavoro; e il package fa squash merge, quindi l'hash del branch non esisterà mai su develop.

## Vincoli globali

- Identificatori nel codice **sempre in inglese**; commenti, docblock e documentazione in italiano.
- Nessun commit durante l'esecuzione.
- Il DB reale di questo repo contiene dati importati: prima di lanciare la suite va verificato
  l'isolamento come da [.claude/rules/test.md](../../../.claude/rules/test.md). Il database di test
  è `forestas_testing`.
- Comando dei test: `docker exec php-forestas bash -lc 'vendor/bin/pest <path>'`.
- Nessun comando nuovo e nessuna migration: il bootstrap dei file di una versione nuova si fa con
  l'Action Nova «Update Tracks on AWS» della Resource `EcTrack`, che esiste già
  (`ExecuteEcTrackDataChainAction` con il solo `UpdateEcTrackAwsJob`, `wm-package/src/Nova/EcTrack.php:174-176`).
- **Il submodule `wm-package/` è lo stesso working tree in cui si è appena eseguito il piano del
  package**: i test di questo piano nascono quindi già verdi, perché il codice nuovo è sul disco.
  La fase rossa di questi test l'ha vista il piano del package; qui servono come prova che il
  punto di estensione dello shard non sia stato perso.

## Review Focus

1. **`properties.forestas` nel file senza versione**: è il blocco che legge `wp-forestas`, nasce in
   `App\Models\EcTrack::getDetailGeojson()` (`app/Models/EcTrack.php:83-101`) e il package non sa
   che esista. Test nel Task 2.
2. **`properties.forestas` anche nella v1**: non è una forma vecchia delle tassonomie, è un dato
   dello shard, e la v1 deve averlo identico. Test nel Task 2.
3. **`{}` contro `[]`**: `app/Models/EcTrack.php:91` cura la differenza fra oggetto vuoto e array
   vuoto; un confronto fatto su `json_decode(..., true)` non la vede. Dove conta, il test confronta
   il JSON come stringa.
4. **Dichiarazione assente sui server**: `deploy_prod.sh` esegue `php artisan optimize` e congela
   la configurazione, quindi la variabile va scritta **prima** del deploy, su ogni server, come già
   documentato per `WP_API_TOKEN` in [docs/howto/deploy-automatico.md](../../howto/deploy-automatico.md).
   Senza, la v1 non nasce e nessuno se ne accorge.
5. **Il bootstrap dei file della versione nuova**: oggi il reset notturno gira su entrambi i
   server e i file nascono da soli, ma dipende da una variabile per server. Vedi il Task 3,
   step 3.

---

### Task 1: Dichiarare le versioni attive

Realizza [R1](overview.md#r1).

**File:**
- Modify: `.env-example`
- Modify: `.env` (locale, non tracciato)
- Modify: `docs/howto/deploy-automatico.md` — la variabile nuova fra i passaggi a mano

**Interfaces:**
- Consuma: `wm-package.wmfe_track_versions`, letta da `WMFE_TRACK_VERSIONS` (Task 2 del package).

- [ ] **Step 1: Verificare dove vanno dichiarate le variabili per i server**

Run: `grep -rn "WP_API_TOKEN\|WM_TRAIL_REGISTRY_ENABLED" .env-example .env-deploy docs/howto/deploy-automatico.md`
Expected: mostra in quali file una variabile per-shard va aggiunta, e come è scritto il passaggio a
mano di `WP_API_TOKEN`, che è il precedente da seguire.

- [ ] **Step 2: Aggiungere la dichiarazione**

```dotenv
# oc:8997: versioni del JSON della traccia scritte su wmfe, in ordine.
# `legacy` è il file senza versione che leggono le app installate, `v1` il contratto nuovo.
# Assente: resta attiva la sola `legacy`. Va aggiunta a mano nel .env di ogni server,
# prima del deploy: `php artisan optimize` congela la configurazione.
WMFE_TRACK_VERSIONS=legacy,v1
```

- [ ] **Step 3: Scrivere il passaggio a mano nella procedura di deploy**

In `docs/howto/deploy-automatico.md`, accanto agli altri: **prima del deploy**, su **ogni** server,
scrivere `WMFE_TRACK_VERSIONS=legacy,v1` nel `.env`. Scritta dopo, non viene letta e la versione
nuova non nasce, senza nessun segnale.

- [ ] **Step 4: Verificare che la configurazione arrivi**

Run: `docker exec php-forestas php artisan tinker --execute="echo config('wm-package.wmfe_track_versions');"`
Expected: `legacy,v1`. Se stampa `legacy`, la configurazione è in cache: `php artisan config:clear`.

- [ ] **Step 5: Commit** — `feat(oc:8997): forestas scrive il JSON della traccia in legacy e v1`

---

### Task 2: La prova che `properties.forestas` resti in entrambi i file

Realizza [R3](overview.md#r3) e copre il rischio principale di questo repo.

**File:**
- Create: `tests/Feature/EcTrack/ForestasPropertiesInTrackVersionsTest.php`
- Modify: `tests/Feature/EcTrack/TrackDetailForestasTest.php:163` — il commento che descrive come
  `UpdateEcTrackAwsJob` scrive il file non è più vero e va riallineato
- Riferimento: `app/Models/EcTrack.php:83-101`

**Interfaces:**
- Consuma: `UpdateEcTrackAwsJob`, `Wm\WmPackage\Enums\WmfeTrackVersion`, il modello
  `App\Models\EcTrack` (quello di `EC_TRACK_MODEL`).

- [ ] **Step 1: Scrivere il test**

```php
public function test_forestas_block_survives_in_the_unversioned_file(): void
{
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);
    Storage::fake('wmfe');

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $legacy = json_decode(Storage::disk('wmfe')->get($this->path($track, '')), true);
    $this->assertArrayHasKey('forestas', $legacy['properties']);
    $this->assertArrayHasKey('registry_code', $legacy['properties']['forestas']);
}

public function test_forestas_block_is_identical_in_v1(): void
{
    // stesso blocco, confrontato come stringa JSON: `{}` e `[]` non si distinguono
    // dopo json_decode con associative true, e app/Models/EcTrack.php:91 li cura apposta
}

public function test_layer_name_is_present_and_sardegnasentieri_id_is_not(): void
{
    // le due modifiche che App\Models\EcTrack fa in uscita restano in entrambe le versioni
}
```

`path()` compone il percorso da `config('wm-package.shard_name')`, **mai scritto a mano**: in
locale `SHARD_NAME` non è valorizzata e vale `APP_NAME`, quindi il percorso è `/forestas/tracks/…`
e non `/forestasdev/tracks/…`, che è quello del server dev.

- [ ] **Step 2: Lanciare i test**

Run: `docker exec php-forestas bash -lc 'vendor/bin/pest tests/Feature/EcTrack/ForestasPropertiesInTrackVersionsTest.php'`
Expected: PASS. Qui non c'è codice da scrivere in forestas: i test sono verdi perché il submodule
sul disco porta già il meccanismo. Se uno resta rosso, è un difetto del refactor del package e va
corretto **lì**, non aggirato qui.

- [ ] **Step 3: Riallineare il commento di `TrackDetailForestasTest`**

La riga 163 dice come il job scrive il file passando da `EcTrackResource`: va riscritta con il
percorso nuovo, altrimenti è una fonte sbagliata per chi la leggerà fra sei mesi.

- [ ] **Step 4: Commit** — `test(oc:8997): properties.forestas presente in entrambe le versioni del JSON`

---

### Task 3: Verifica sul server e messa in opera

Realizza [R2](overview.md#r2).

**File:**
- Modify: `docs/howto/deploy-automatico.md` — il bootstrap dei file della versione nuova

- [ ] **Step 1: Lanciare la suite dello shard**

Prima: verificare l'isolamento del database come da `.claude/rules/test.md`.
Run: `docker exec php-forestas bash -lc 'vendor/bin/pest'`
Expected: PASS. Ogni fallimento preesistente va riportato per nome.

- [ ] **Step 2: Verificare i due file in locale**

Run: `docker exec php-forestas php artisan tinker --execute="\Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob::dispatchSync(\App\Models\EcTrack::first());"`
poi, sul bucket MinIO del container, controllare che esistano sia `tracks/{id}.json` sia
`tracks/v1/{id}.json` sotto il prefisso di `config('wm-package.shard_name')`.
Expected: entrambi presenti, e il `v1` senza `taxonomy`, `taxonomy_where`, `taxonomyWheres`.
È una verifica in locale: su MinIO, non su S3. La prova sul server dev si fa dopo il deploy, con
`curl` sui due URL pubblici.

- [ ] **Step 3: Scrivere il bootstrap nella procedura di deploy**

Il reset notturno è acceso su entrambi i server, quindi dopo il primo deploy i file della v1
nascono da soli alla prima notte: l'Action Nova «Update Tracks on AWS» della Resource `EcTrack`
serve solo a non aspettarla.
La riga da scrivere dice questo, e dice anche da cosa dipende — `SARDEGNASENTIERI_DAILY_RESET` nel
`.env` di ciascun server ([routes/console.php:34](../../../routes/console.php)), non il nome
dell'ambiente: il giorno che su un server viene spento, quell'Action da comodità diventa
obbligatoria.

- [ ] **Step 4: Controllo dei documenti della feature**

Run: `/Users/bongiu/Documents/claude-marketplace/plugins/wm-skills/scripts/feature-docs-check.sh docs/features/8997-versione-del-formato-del-json-della-traccia-su-wmfe prima-del-commit`
Expected: nessun errore.

- [ ] **Step 5: Commit** — `docs(oc:8997): messa in opera delle versioni del JSON della traccia`

Il bump del puntatore del submodule **non si fa qui**: è il primo passo della chiusura, dopo il
merge del package.
