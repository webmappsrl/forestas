<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password dell'utente Admin Team
    |--------------------------------------------------------------------------
    |
    | Usata da SardegnaSentieriSeeder per creare team@webmapp.it. Se vuota il
    | seeder non crea l'utente. Va letta da qui e non con env(): il deploy
    | esegue `php artisan optimize`, che congela la configurazione e rende
    | env() nulla.
    |
    */

    'admin_password' => env('APP_ADMIN_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Dizionario delle traduzioni per i TaxonomyPoiType
    |--------------------------------------------------------------------------
    |
    | Percorso al file usato da SyncTaxonomyPoiTypeTranslationsCommand quando
    | non viene passata l'opzione da riga di comando.
    |
    */

    'taxonomy_poi_types_dictionary' => env('TAXONOMY_POI_TYPES_DICTIONARY'),

    /*
    |--------------------------------------------------------------------------
    | Registro catastale dei sentieri (oc:8539)
    |--------------------------------------------------------------------------
    |
    | Il file Google che Forestas mantiene con il CAI, condiviso con chi ha il
    | link. Solo l'URL: i fogli si ricavano a ogni giro.
    |
    */

    'registro_catastale' => [
        'url' => env('REGISTRO_CATASTALE_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sardegna Sentieri: file dalla copia del DB Drupal (oc:8900)
    |--------------------------------------------------------------------------
    |
    | La copia ha solo il percorso dei file (`public://...`): l'URL si ricostruisce
    | su questa base e il file si scarica da lì. Il default è il sito pubblico,
    | così nessun server deve aggiungere la variabile al `.env`; si cambia il
    | giorno in cui i file di Drupal vengono spostati.
    |
    */

    'sardegnasentieri' => [
        'files_url' => env('SARDEGNASENTIERI_FILES_URL', 'https://www.sardegnasentieri.it/sites/default/files'),

        // Il lock che scripts/drupal-dump-reload.sh tiene durante la ricarica
        // della copia: lo script lo scrive in storage/drupal-dump/ sull'host,
        // che è la stessa cartella vista dal container.
        'reload_lock_path' => storage_path('drupal-dump/reload.lock'),

        // Dove sardegnasentieri:compare-source salva le risposte delle API.
        'compare_cache_path' => storage_path('app/sardegnasentieri-compare-cache'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Classi di inclusività A1-A6 (oc:8910)
    |--------------------------------------------------------------------------
    |
    | Intervalli del punteggio (somma dei tre valori, da 12 a 100), estremi compresi.
    | Il Tavolo Tecnico Regionale può cambiarli ogni anno. Le classi già scritte
    | nell'indice Elasticsearch non si aggiornano da sole: dopo una modifica serve
    | un reindex (basta quello normale, il mapping non cambia).
    |
    | ATTENZIONE: solo A1 e A2 vengono dalla call; le soglie di A3-A6 sono
    | provvisorie, da sostituire con quelle del regolamento regionale.
    |
    */

    'inclusivity_classes' => [
        'A1' => ['min' => 80, 'max' => 100],
        'A2' => ['min' => 57, 'max' => 79],
        'A3' => ['min' => 43, 'max' => 56],
        'A4' => ['min' => 29, 'max' => 42],
        'A5' => ['min' => 15, 'max' => 28],
        'A6' => ['min' => 0, 'max' => 14],
    ],

];
