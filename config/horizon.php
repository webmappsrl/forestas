<?php

// Il supervisor `print` esiste solo a dominio acceso (stessa variabile del package): a dominio
// spento non restano worker accesi a vuoto su una coda che nessuno riempie (oc:8982).
$print = env('WM_PRINT_ENABLED', false) ? ['supervisor-print' => []] : [];

return [
    'defaults' => [
        'supervisor-aws' => [
            'connection' => 'redis',
            'queue' => ['aws'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 30,
            'minProcesses' => 1,
            'balanceMaxShift' => 10,
            'balanceCooldown' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
        'supervisor-pbf' => [
            'connection' => 'redis',
            'queue' => ['pbf'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 10,
            'minProcesses' => 1,
            'balanceMaxShift' => 10,
            'balanceCooldown' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 5,
            'timeout' => 180,
            'nice' => 0,
        ],
        'supervisor-dem' => [
            'connection' => 'redis',
            'queue' => ['dem'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 6,
            'minProcesses' => 1,
            'balanceMaxShift' => 3,
            'balanceCooldown' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
        /*
         * L'import da Sardegna Sentieri sta su una coda sua.
         *
         * Sulla coda `default` i job di import finiscono dietro il
         * post-processing che loro stessi accodano — conversioni immagine e
         * relazioni, migliaia per ogni run. Un job che fallisce e viene
         * ritentato rientra in fondo a quella fila e gira ore dopo: il batch
         * resta aperto fino ad allora, e il ricalcolo delle anomalie
         * agganciato alla sua fine non parte. Su una coda dedicata un
         * ritentativo rientra dietro al massimo altri job di import (oc:8607).
         *
         * Il `timeout` sta sopra i 300 secondi del job, altrimenti sarebbe il
         * worker a troncarlo prima che il job possa gestire il proprio.
         */
        'supervisor-sardegnasentieri-import' => [
            'connection' => 'redis',
            'queue' => ['sardegnasentieri-import'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 5,
            'minProcesses' => 1,
            'balanceMaxShift' => 2,
            'balanceCooldown' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 5,
            'timeout' => 360,
            'nice' => 0,
        ],
        'supervisor-layers' => [
            'connection' => 'redis',
            'queue' => ['layers'],
            'balance' => 'simple',
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
        /*
         * Stampa PDF delle schede (oc:8982). Coda dedicata e pochi worker: xelatex
         * e' pesante (il limite di memoria del processo lo da' `prlimit`, non
         * `memory`).
         *
         * Il `timeout` di 420 s sta sopra i 390 s del Job (2 x compile 120 + mappa
         * 60 + foto 60 + 30) e sotto il `retry_after` di 660 s della connessione
         * redis: se fosse minore, Horizon ucciderebbe il Job prima del suo
         * timeout; se fosse maggiore, la coda lo riconsegnerebbe mentre gira.
         *
         * `tries` non e' impostato: il Job dichiara i propri (`$tries`,
         * `$maxExceptions`) e quelli hanno la precedenza sul valore del worker.
         */
        'supervisor-print' => [
            'connection' => 'redis',
            'queue' => ['print'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'maxTime' => 3600,
            'maxJobs' => 100,
            'memory' => 256,
            'timeout' => 420,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'local' => [
            ...$print,
            'supervisor-default' => [
                'balance' => 'simple',
                'maxProcesses' => 10,
            ],
            'supervisor-sardegnasentieri-import' => [
                'balance' => 'simple',
                'maxProcesses' => 10,
            ],
        ],
        'develop' => [
            ...$print,
            'supervisor-default' => [
                'maxProcesses' => 10,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 10,
                'balanceCooldown' => 3,
            ],
            'supervisor-pbf' => [
                'maxProcesses' => 6,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 3,
                'balanceCooldown' => 2,
            ],
            'supervisor-layers' => [
                'maxProcesses' => 4,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 2,
                'balanceCooldown' => 2,
            ],
            'supervisor-sardegnasentieri-import' => [
                'maxProcesses' => 5,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
        ],
        /*
         * `production` e' l'APP_ENV di UAT e della produzione, e finora il
         * progetto non lo dichiarava: i supervisor arrivavano tutti da
         * `wm-horizon` del package. Il merge del package e' per-supervisor e
         * non sovrascrive, quindi dichiararne uno solo qui non toglie gli
         * altri (oc:8607).
         */
        'production' => [
            ...$print,
            'supervisor-sardegnasentieri-import' => [
                'maxProcesses' => 5,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
        ],
        'staging' => [
            ...$print,
            'supervisor-default' => [
                'maxProcesses' => 10,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 10,
                'balanceCooldown' => 3,
            ],
            'supervisor-pbf' => [
                'maxProcesses' => 6,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 3,
                'balanceCooldown' => 2,
            ],
            'supervisor-layers' => [
                'maxProcesses' => 4,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 2,
                'balanceCooldown' => 2,
            ],
            'supervisor-sardegnasentieri-import' => [
                'maxProcesses' => 5,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
        ],
    ],
];
