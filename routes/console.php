<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Allineamento con Sardegna Sentieri.
 *
 * Due regimi, scelti da un interruttore esplicito e non dal nome
 * dell'ambiente.
 *
 * Il nome non serve: i due server si chiamano `develop` e `production` — il
 * collaudo gira come `production` perche' un vero ambiente di produzione non
 * esiste ancora. Legare il troncamento a quei nomi vorrebbe dire che, il
 * giorno in cui la produzione vera nascera', erediterebbe la regola e si
 * cancellerebbe i dati ogni notte. (Il codice precedente cercava `staging`,
 * che non e' il nome di nessuno dei due: il ramo con `--reset` non e' mai
 * scattato.)
 *
 * Con l'interruttore acceso (server di sviluppo e collaudo): una volta al
 * giorno si cancella tutto ciò che viene dall'importazione e lo si riprende
 * da capo, così la piattaforma è lo specchio di Drupal — ciò che il gestore
 * ha eliminato alla fonte sparisce anche qui, cosa che un'importazione
 * incrementale non saprebbe fare.
 *
 * Spento (produzione, e le macchine di sviluppo): importazione oraria
 * incrementale, che aggiorna senza distruggere.
 */
if (env('SARDEGNASENTIERI_DAILY_RESET', false)) {
    // Il ricalcolo delle anomalie non sta più qui. Stava in un `->then()`
    // che partiva alla fine del comando, ma il comando accoda i job e ritorna:
    // il normalize leggeva un archivio ancora vuoto e scriveva zero anomalie
    // senza segnalare nulla. Ora è agganciata al batch dei job, dentro il
    // comando, che è l'unico punto a sapere quando l'archivio è completo
    // (oc:8607).
    Schedule::command('sardegnasentieri:import --reset')->dailyAt('06:00');
} else {
    Schedule::command('sardegnasentieri:import')->hourly();
}
