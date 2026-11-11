<?php

declare(strict_types=1);

/**
 * `retry_after` della connessione redis più lungo del timeout di ogni supervisor di Horizon
 * (oc:8959). Se è più corto, un job ancora in corso torna disponibile e un secondo worker lo
 * prende: con `WithoutOverlapping::dontRelease()` la copia viene scartata ma, dentro un batch, è
 * contata come riuscita. Il batch dei sentieri correlati chiudeva così prima che l'ultimo blocco
 * avesse scritto, e i file delle tracce uscivano con i correlati incompleti.
 */
it('tiene retry_after della coda redis più lungo del timeout di ogni supervisor', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    $timeouts = collect(config('horizon.defaults'))
        ->merge(collect(config('horizon.environments'))->flatMap(fn ($supervisors) => $supervisors))
        ->filter(fn ($supervisor) => ($supervisor['connection'] ?? 'redis') === 'redis')
        ->map(fn ($supervisor) => (int) ($supervisor['timeout'] ?? 0))
        ->filter();

    expect($timeouts)->not->toBeEmpty()
        ->and($retryAfter)->toBeGreaterThan($timeouts->max());
});
