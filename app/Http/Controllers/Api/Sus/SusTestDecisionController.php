<?php

namespace App\Http\Controllers\Api\Sus;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailApplicationTransitionException;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailChoicesException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Rules\DifficultyMatchesActivities;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Lo strumento di prova del canale SUS: approva o respinge una domanda
 * trasmessa come farebbe l'istruttore in Nova, cosi' chi integra prova da solo
 * tutti gli esiti della lettura — il numero del sentiero a domanda approvata,
 * il motivo a domanda respinta, il 409 su una conferma dopo la decisione.
 *
 * Non fa parte delle API: le rotte stanno sotto `docs/api/sus/test/`, fuori da
 * `api/v1/sus` e quindi dalla documentazione di Scramble, ed esistono solo dove
 * `sus.test_tools` e' acceso (dev e collaudo). La decisione e' quella vera,
 * `TrailRegistryService::approve()` e `reject()`, la stessa delle Action Nova.
 * Chi prova non sceglie niente: l'approvazione conferma la proposta del SUS cosi'
 * com'e' (attivita', primaria e difficolta' di `api_data`), come farebbe
 * l'istruttore che non cambia nulla nel modulo di Nova (oc:9038).
 */
class SusTestDecisionController extends Controller
{
    public function approve(int $application, TrailRegistryService $service): JsonResponse
    {
        $found = $this->find($application);

        if ($found === null) {
            return $this->notFound();
        }

        try {
            $service->approve($found, auth('api')->id(), $this->proposedChoices($found));
        } catch (InvalidTrailApplicationTransitionException $e) {
            return $this->conflict($e);
        } catch (InvalidTrailChoicesException $e) {
            // La transazione e' tornata indietro: la domanda e' ancora in
            // istruttoria. Succede, per esempio, dove la famiglia della
            // primaria non e' ancora installata.
            return response()->json([
                'error' => __('Approval stopped: :reason', ['reason' => $e->getMessage()]),
            ], 422);
        }

        return response()->json(['id' => $found->id, 'status' => $found->status->value]);
    }

    public function reject(Request $request, int $application, TrailRegistryService $service): JsonResponse
    {
        $reason = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ])['rejection_reason'];

        $found = $this->find($application);

        if ($found === null) {
            return $this->notFound();
        }

        try {
            $service->reject($found, $reason, auth('api')->id());
        } catch (InvalidTrailApplicationTransitionException $e) {
            return $this->conflict($e);
        }

        return response()->json([
            'id' => $found->id,
            'status' => $found->status->value,
            'rejection_reason' => $found->rejection_reason,
        ]);
    }

    /**
     * La proposta del SUS come scelte dell'operatore. `api_data.difficulty` e'
     * una mappa per attivita' (`hiking => E`), mentre il service vuole il livello
     * per famiglia (`hiking-difficulty => E`): la traduzione usa la mappa della
     * Rule, la stessa dell'Action Nova. Due attivita' della stessa famiglia con
     * livelli diversi non hanno una proposta univoca: la famiglia resta senza.
     *
     * @return array{activities: list<mixed>, difficulty: array<string, string>, primary_activity: mixed}
     */
    private function proposedChoices(TrailApplication $application): array
    {
        $apiData = is_array($application->api_data) ? $application->api_data : [];
        $proposed = is_array($apiData['difficulty'] ?? null) ? $apiData['difficulty'] : [];
        $families = DifficultyMatchesActivities::activityFamilies();

        $levels = [];

        foreach ($proposed as $activity => $level) {
            $family = $families[$activity] ?? null;

            if ($family !== null && is_string($level) && $level !== '') {
                $levels[$family][$level] = true;
            }
        }

        $difficulty = [];

        foreach ($levels as $family => $found) {
            if (count($found) === 1) {
                $difficulty[$family] = array_key_first($found);
            }
        }

        return [
            'activities' => (array) ($apiData['activities'] ?? []),
            'difficulty' => $difficulty,
            'primary_activity' => $apiData['primary_activity'] ?? null,
        ];
    }

    /**
     * Solo le domande del canale: lo strumento serve a provare il canale, e una
     * domanda creata d'ufficio in Nova si decide da Nova.
     */
    private function find(int $id): ?TrailApplication
    {
        abort_unless((bool) config('sus.test_tools'), 404);

        $class = TrailRegistryClasses::application();

        return $class::query()->where('source', 'api')->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => __('Application not found.')], 404);
    }

    /**
     * Solo una domanda in istruttoria si decide: una in compilazione va prima
     * trasmessa, una gia' decisa non si decide di nuovo.
     */
    private function conflict(InvalidTrailApplicationTransitionException $e): JsonResponse
    {
        return response()->json([
            'error' => __('Only an application under review can be approved or rejected.'),
            'status' => $e->from->value,
        ], 409);
    }
}
