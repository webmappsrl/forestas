<?php

namespace App\Http\Controllers\Api\Sus;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailApplicationTransitionException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
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
            $service->approve($found, auth('api')->id());
        } catch (InvalidTrailApplicationTransitionException $e) {
            return $this->conflict($e);
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
