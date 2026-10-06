<?php

namespace App\Services\RegistroCatastale;

use Illuminate\Support\Facades\DB;

/**
 * Porta sul sentiero i campi del registro catastale (oc:8540).
 *
 * Non carica e non salva EcTrack: aggiorna solo le proprie chiavi del JSONB
 * con update atomici, perche' la catena DEM salva l'intero `properties` e due
 * processi che lo fanno insieme si cancellano i valori a vicenda (oc:8660).
 * Niente eventi del modello, quindi nessuna catena DEM accodata.
 */
class RegistroCatastaleTrackWriter
{
    /** Intestazione normalizzata => campo. */
    private const TEXT_COLUMNS = ['origineda' => 'from', 'destinazionea' => 'to'];

    private const LENGTH_COLUMN = 'lunghezzam';

    private const DURATION_COLUMNS = ['tpercorrenzaa' => 'duration_forward', 'tpercorrenzar' => 'duration_backward'];

    /** «EVENTIUALE Meta intermedia»: si cerca il finale, se Forestas corregge il refuso non cambia nulla. */
    private const VIA_SUFFIX = 'metaintermedia';

    /** `manual_data` come oggetto, anche quando manca o e' JSON null. */
    private const MANUAL = "(case when jsonb_typeof(properties->'manual_data') = 'object' then properties->'manual_data' else '{}'::jsonb end)";

    public function __construct(private readonly RegistroValueSanitizer $sanitizer) {}

    public function evaluate(RegistroCatastaleParsedRow $row, int $trackId): RegistroTrackWrite
    {
        $text = [];
        $via = null;
        $manual = [];
        $invalid = [];
        $lengthCell = null;
        $durationCells = [];

        foreach ($row->cells as $cell) {
            $key = preg_replace('/[^a-z0-9]/', '', mb_strtolower($cell['header']));

            if (isset(self::TEXT_COLUMNS[$key])) {
                $text[self::TEXT_COLUMNS[$key]] = $this->sanitizer->text($cell['value']);
            } elseif (str_ends_with($key, self::VIA_SUFFIX)) {
                $via = $this->sanitizer->text($cell['value']);
            } elseif ($key === self::LENGTH_COLUMN) {
                $lengthCell = $cell;
            } elseif (isset(self::DURATION_COLUMNS[$key])) {
                $durationCells[self::DURATION_COLUMNS[$key]] = $cell;
            }
        }

        $lengthKm = null;

        if ($lengthCell !== null) {
            $length = $this->sanitizer->lengthKm($lengthCell['value']);
            $lengthKm = $length->value;

            if ($length->isInvalid()) {
                $invalid[] = ['column' => $lengthCell['header'], 'value' => $lengthCell['value']];
            } elseif (! $length->isEmpty()) {
                $manual['distance'] = $length->value;
            }
        }

        foreach ($durationCells as $field => $cell) {
            $duration = $this->sanitizer->durationMinutes($cell['value'], $lengthKm);

            if ($duration->isInvalid()) {
                $invalid[] = ['column' => $cell['header'], 'value' => $cell['value']];
            } elseif (! $duration->isEmpty()) {
                $manual[$field] = $duration->value;
            }
        }

        return new RegistroTrackWrite($trackId, $text['from'] ?? null, $text['to'] ?? null, $via, $manual, $invalid);
    }

    /**
     * Ogni update ha la sua condizione: un valore gia' uguale non si
     * riscrive, cosi' `updated_at` cambia solo quando cambia il dato e gli
     * export incrementali non riscaricano tutto a ogni giro.
     */
    public function apply(RegistroTrackWrite $write): bool
    {
        $changed = 0;
        $table = config('wm-package.ec_track_table', 'ec_tracks');
        // Come Eloquent: fuso dell'app, non quello della sessione PostgreSQL.
        $now = now()->toDateTimeString();

        if ($write->manual !== []) {
            $json = json_encode($write->manual);
            $changed += DB::update(
                "update {$table} set properties = jsonb_set(coalesce(properties, '{}'::jsonb), '{manual_data}', ".self::MANUAL." || ?::jsonb), updated_at = ? where id = ? and not (".self::MANUAL." @> ?::jsonb)",
                [$json, $now, $write->trackId, $json],
            );
        }

        // Origine e destinazione: vince Drupal, quindi solo se vuote.
        foreach (['from' => $write->from, 'to' => $write->to] as $key => $value) {
            if ($value !== null) {
                $changed += DB::update(
                    "update {$table} set properties = jsonb_set(coalesce(properties, '{}'::jsonb), '{{$key}}', to_jsonb(?::text)), updated_at = ? where id = ? and coalesce(properties->>'{$key}', '') = ''",
                    [$value, $now, $write->trackId],
                );
            }
        }

        if ($write->via !== null) {
            $changed += DB::update(
                "update {$table} set properties = jsonb_set(coalesce(properties, '{}'::jsonb), '{via}', to_jsonb(?::text)), updated_at = ? where id = ? and properties->>'via' is distinct from ?",
                [$write->via, $now, $write->trackId, $write->via],
            );
        }

        return $changed > 0;
    }
}
