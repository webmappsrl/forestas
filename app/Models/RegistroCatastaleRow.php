<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * Mirror della riga di un foglio del registro catastale.
 *
 * @property int $id
 * @property string $sheet_gid
 * @property string $sheet_name
 * @property int $row_number
 * @property array $cells
 * @property string $link
 * @property int|null $trail_registry_code_id
 * @property string|null $area
 * @property string|null $sector
 * @property int|null $number
 * @property string $variant
 * @property-read string|null $sheet_number
 * @property Carbon $imported_at
 */
class RegistroCatastaleRow extends Model
{
    protected $table = 'registro_catastale_rows';

    protected $fillable = [
        'sheet_gid',
        'sheet_name',
        'row_number',
        'cells',
        'link',
        'area',
        'sector',
        'number',
        'variant',
        'trail_registry_code_id',
        'imported_at',
    ];

    protected $casts = [
        'cells' => 'array',
        'number' => 'integer',
        'imported_at' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * Relazione verso il codice del registro catastale.
     */
    public function trailRegistryCode(): BelongsTo
    {
        return $this->belongsTo(TrailRegistryClasses::code());
    }

    /**
     * Il numero come si legge sul foglio (oc:8700): settore + numero a due
     * cifre + variante, senza la variante `'0'` che vuol dire «nessuna».
     * `null` per una riga con solo il link.
     */
    protected function sheetNumber(): Attribute
    {
        return Attribute::get(fn () => $this->sector === null || $this->number === null
            ? null
            : $this->sector.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT).($this->variant !== '0' ? $this->variant : ''));
    }
}
