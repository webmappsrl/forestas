<?php

namespace App\Nova;

use App\Models\RegistroCatastaleRow;
use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use Laravel\Nova\Nova;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly as TrailRegistryAnomalyModel;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * Il contenuto della Tab «Registro» sulla scheda di un codice (oc:8539).
 *
 * Tre casi, in quest'ordine:
 *
 * 1. **Il codice ha una riga del foglio agganciata** (`trail_registry_code_id`
 *    valorizzato su una riga del mirror): tabella a due colonne con le celle
 *    cosi' come le ha scritte il foglio, piu' foglio, riga e data
 *    dell'ultimo import.
 * 2. **Nessuna riga, ma la traccia del codice e' finita in un'anomalia del
 *    registro**: la riga non e' arrivata a scriversi nel mirror proprio
 *    perche' l'aggancio ha prodotto un'anomalia (es. numero diverso da
 *    quello sul foglio) — si mostra un avviso col link alla scheda
 *    dell'anomalia, calcolato in lettura perche' gli id cambiano a ogni
 *    giro di import.
 * 3. **Ne' l'una ne' l'altra**: il foglio non ha (ancora, o mai avuto) una
 *    riga per questo codice.
 *
 * Tutti gli output del foglio passano da `e()`: sono testo libero scritto da
 * chiunque abbia accesso al foglio Google, non dati fidati.
 */
class RegistroTabRenderer
{
    public static function render(TrailRegistryCodeModel $code): string
    {
        $row = RegistroCatastaleRow::query()
            ->where('trail_registry_code_id', $code->id)
            ->first();

        if ($row !== null) {
            return static::renderRow($row);
        }

        $anomaly = static::findAnomaly($code);

        if ($anomaly !== null) {
            return static::renderAnomalyNotice($anomaly);
        }

        return '<p>'.e(__('Il registro non ha una riga per questo codice.')).'</p>';
    }

    /**
     * Il detail di una riga nell'elenco «Righe del registro» (oc:8700): la
     * stessa tabella della Tab, dallo stesso metodo, cosi' le due viste non
     * divergono. Per una riga senza codice aggiunge dove cercarne il motivo:
     * numero prenotato, anomalia o righe multiple li distingue solo la lista
     * delle anomalie, filtro provenienza Registro.
     */
    public static function renderRowDetail(RegistroCatastaleRow $row): string
    {
        $html = static::renderRow($row);

        if ($row->trail_registry_code_id !== null) {
            return $html;
        }

        return '<p style="margin-bottom:12px">'
            .e(__('Questa riga non è agganciata a nessun codice.')).' '
            .e(__('Se è finita in anomalia la trovi in Catasto › Anomalie, filtro provenienza Registro.'))
            .'</p>'.$html;
    }

    protected static function renderRow(RegistroCatastaleRow $row): string
    {
        $cells = collect($row->cells)->map(fn (array $cell) => sprintf(
            '<tr><td style="padding:4px 12px 4px 0;font-weight:600">%s</td><td style="padding:4px 0">%s</td></tr>',
            e((string) ($cell['header'] ?? '')),
            e((string) ($cell['value'] ?? '')),
        ))->implode('');

        $meta = sprintf(
            '<p style="margin-top:12px;font-size:0.875rem">%s: %s &middot; %s: %s &middot; %s: %s</p>',
            e(__('Foglio')),
            e($row->sheet_name),
            e(__('Registry row')),
            e((string) $row->row_number),
            e(__('Importato il')),
            e($row->imported_at->format('d/m/Y H:i')),
        );

        return '<table style="width:100%;font-size:0.875rem">'.$cells.'</table>'.$meta;
    }

    /**
     * Cerca l'anomalia del registro che riguarda questo codice, in tre modi:
     *
     * - per traccia: `ec_track_id` dell'anomalia uguale alla traccia a cui il
     *   codice e' assegnato (NUMERO_DIVERSO e TRACCIA_SENZA_CODICE lo
     *   valorizzano sempre con la traccia agganciata dal link);
     * - per codice: `context.code`, che il matcher scrive per NUMERO_DIVERSO
     *   nella stessa forma di TrailRegistryCode::code (es. `ZSSG506C`);
     * - fra i candidati: `context.candidates`, i codici completi che il
     *   ripiego ha trovato per RIPIEGO_AMBIGUO (anomalia senza traccia).
     */
    protected static function findAnomaly(TrailRegistryCodeModel $code): ?TrailRegistryAnomalyModel
    {
        $query = TrailRegistryClasses::anomaly()::query()
            ->where('source', RegistroAnomalyTypes::SOURCE)
            ->where(function ($q) use ($code) {
                $q->where('context->code', $code->code)
                    ->orWhereJsonContains('context->candidates', $code->code);

                if ($code->ec_track_id !== null) {
                    $q->orWhere('ec_track_id', $code->ec_track_id);
                }
            });

        return $query->first();
    }

    protected static function renderAnomalyNotice(TrailRegistryAnomalyModel $anomaly): string
    {
        $url = rtrim(Nova::path(), '/').'/resources/'.TrailRegistryAnomaly::uriKey().'/'.$anomaly->id;

        return '<p style="color:#b91c1c">'
            .e(__('La riga del registro per questo codice è finita in anomalia:')).' '
            .'<a href="'.e(url($url)).'" target="_blank" rel="noopener">'.e(__('vedi l\'anomalia')).'</a>'
            .'</p>';
    }
}
