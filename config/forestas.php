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

];
