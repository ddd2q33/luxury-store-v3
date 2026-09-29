<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'cliente_id', 'cliente_nombre', 'fecha_venta', 'total', 'estado',
        'total_general', 'metodo_pago', 'monto_recibido', 'cambio', 'motivo',
    ];

    /**
     * Nombres que NO son un cliente real: son la venta de mostrador.
     * 524 de 542 filas de `ventas` usan 'Cliente General' con `cliente_id = 0`
     * (0 no es una clave foranea valida, es un centinela).
     *
     * Vive AQUI y no en cada vista porque el Dashboard y los Reportes lo usan
     * para no contar un fantasma como mejor cliente del negocio.
     */
    public const CLIENTES_SENTINELA = [
        '', 'Cliente General', 'Cliente general',
        'Consumidor Final', 'consumidor final', 'Mostrador',
    ];

    /** Una fila que no identifica a un cliente concreto. */
    public function scopeSinClienteIdentificado($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('cliente_nombre')
                ->orWhereIn('cliente_nombre', self::CLIENTES_SENTINELA);
        });
    }

    /** Una fila que sí identifica a un cliente real (para rankings y reportes). */
    public function scopeConClienteIdentificado($query)
    {
        return $query->whereNotNull('cliente_nombre')
            ->whereNotIn('cliente_nombre', self::CLIENTES_SENTINELA);
    }

    /** Tabla legacy sin timestamps. */
    public $timestamps = false;

    protected $casts = [
        'fecha_venta' => 'datetime',
        'total' => 'float',
        'total_general' => 'float',
    ];

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class, 'venta_id');
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class, 'venta_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class, 'venta_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /** Solo las ventas que se pueden devolver: no anuladas. */
    public function scopeDevolubles($query)
    {
        return $query->where('estado', '!=', 'Anulada');
    }
}
