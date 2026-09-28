<?php

namespace App\Models;

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
        'trail_registry_code_id',
        'imported_at',
    ];

    protected $casts = [
        'cells' => 'array',
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
}
