<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Compra extends Model
{
    protected $table = 'compras';

    /** Tabla legacy: columna `fecha` con default de BD, sin updated_at. */
    public $timestamps = false;

    protected $casts = [
        'total_general' => 'float',
        'fecha' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }
}
