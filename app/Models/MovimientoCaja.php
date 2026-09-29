<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoCaja extends Model
{
    public const TIPO_INGRESO = 'ingreso';
    public const TIPO_EGRESO = 'egreso';
    public const TIPO_VENTA = 'venta';
    public const TIPO_DEVOLUCION = 'devolucion';

    protected $table = 'movimientos_caja';

    protected $fillable = [
        'caja_id', 'tipo', 'metodo_pago', 'monto', 'descripcion', 'fecha', 'venta_id',
    ];

    /** Tabla legacy sin timestamps. */
    public $timestamps = false;

    protected $casts = [
        'monto' => 'float',
        'fecha' => 'datetime',
    ];

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }
}
