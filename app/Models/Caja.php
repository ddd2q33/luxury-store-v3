<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caja extends Model
{
    public const ESTADO_ABIERTA = 'abierta';
    public const ESTADO_CERRADA = 'cerrada';

    protected $table = 'cajas';

    protected $fillable = [
        'fecha_apertura', 'fecha_cierre', 'saldo_inicial', 'saldo_final',
        'total_ventas', 'total_ingresos', 'total_egresos', 'saldo_esperado',
        'tipo_pago', 'estado', 'usuario_id', 'observaciones',
    ];

    /** Tabla legacy sin timestamps. */
    public $timestamps = false;

    protected $casts = [
        'saldo_inicial' => 'float',
        'total_ventas' => 'float',
        'total_ingresos' => 'float',
        'total_egresos' => 'float',
        'saldo_esperado' => 'float',
        'saldo_final' => 'float',
        'fecha_apertura' => 'datetime',
        'fecha_cierre' => 'datetime',
        'reabierta' => 'boolean',
    ];

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class, 'caja_id');
    }
}
