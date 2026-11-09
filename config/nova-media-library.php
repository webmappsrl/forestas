<?php

/*
 * Su Drupal lo stesso file è riferito da più contenuti (813 foto di galleria su 2644, 221
 * immagini principali su 1498, 26 allegati su 168, 5 video su 8): la media library tiene una
 * copia per contenuto, e senza questa scelta la redazione dovrebbe ritrovare e ricaricare lo
 * stesso file ogni volta (oc:8932).
 *
 * Con `enable-existing-media` i campi che chiamano `enableExistingMedia()` mostrano anche la
 * scelta fra i file già presenti. Il file viene comunque duplicato sul disco: il pacchetto lo
 * copia ("Mimic copy behaviour"), quindi si risparmia la fatica di chi carica, non lo spazio.
 * Il default del pacchetto è `false`.
 */
return [
    'default-croppable' => true,
    'enable-existing-media' => true,
    'hide-media-collections' => [],
];
