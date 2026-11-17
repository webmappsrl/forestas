<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EcTrack;
use App\Support\DeletedFromSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Gli avvisi dei sentieri (oc:8981): pubblica come `api/ec/track`, perché sono dati già
 * esposti nel JSON del dettaglio della traccia.
 */
class NoticeController extends Controller
{
    /**
     * Tutti gli avvisi attivi: sentiero con flag acceso e testo italiano non vuoto. Query stretta
     * (mai geometria né properties intere): il flag si filtra in SQL, il testo italiano e
     * l'ordine si controllano in PHP.
     */
    public function index(): JsonResponse
    {
        /** @var Collection<int, EcTrack&object{ref: ?string, message: ?string, published_at: ?string}> $rows */
        $rows = EcTrack::query()
            ->select('id', 'name')
            ->selectRaw("properties->>'ref' AS ref")
            ->selectRaw("properties->'not_accessible_message' AS message")
            ->selectRaw("properties->>'".EcTrack::NOT_ACCESSIBLE_UPDATED_AT."' AS published_at")
            // Solo i flag accesi (true/1/"1"/"true", come `isNotAccessibleOn()`); niente operatore
            // `?` di jsonb, che PDO scambia per un segnaposto.
            ->whereRaw("lower(properties->>'not_accessible') in ('true', '1')")
            ->whereRaw(DeletedFromSource::notDeletedSql('ec_tracks'))
            ->get();

        /** @var list<array{track_id: int, ref: ?string, name: array<string, string>, message: array<string, string>, published_at: ?string}> $notices */
        $notices = [];
        foreach ($rows as $row) {
            $message = json_decode((string) $row->message, true);
            if (EcTrack::notAccessibleMessageText($message, 'it') === null) {
                continue;
            }

            $notices[] = [
                'track_id' => $row->id,
                'ref' => $row->ref,
                'name' => $row->getTranslations('name'),
                // Una stringa piatta (caricamento da foglio) vale come italiano; nell'array si
                // tolgono le lingue vuote e gli spazi attorno ai testi.
                'message' => is_array($message) ? self::cleanMessage($message) : ['it' => trim((string) $message)],
                'published_at' => $row->published_at,
                'published_ts' => $row->published_at === null ? null : (strtotime($row->published_at) ?: null),
            ];
        }

        // Più recenti prima, senza data in fondo; a parità di data (o senza) id maggiore prima.
        usort($notices, fn (array $a, array $b): int => [$a['published_ts'] === null, $b['published_ts'], $b['track_id']]
            <=> [$b['published_ts'] === null, $a['published_ts'], $a['track_id']]);

        $notices = array_map(function (array $notice): array {
            unset($notice['published_ts']);

            return $notice;
        }, $notices);

        return response()->json([
            'data' => $notices,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, string>
     */
    private static function cleanMessage(array $message): array
    {
        $clean = [];
        foreach ($message as $locale => $text) {
            if (is_string($text) && trim($text) !== '') {
                $clean[$locale] = trim($text);
            }
        }

        return $clean;
    }
}
