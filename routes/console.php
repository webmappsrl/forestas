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
    Schedule::command('sardegnasentieri:import --reset')
        ->dailyAt('06:00')
        // In coda, non in parallelo: la lista delle anomalie si ricostruisce
        // guardando l'intero archivio, e leggerlo a importazione in corso
        // significherebbe fotografare un catasto a metà. I codici invece si
        // scrivono già durante l'importazione, tracciato per tracciato.
        ->then(fn () => Artisan::call('wm-package:trail-registry-normalize', ['--force' => true]));
} else {
    Schedule::command('sardegnasentieri:import')->hourly();
}
