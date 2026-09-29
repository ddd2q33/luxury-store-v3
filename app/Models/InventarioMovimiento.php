<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger de movimientos de inventario (tabla legacy `inventario_movimientos`).
 *
 * Nota: el proyecto tiene DOS tablas de movimientos historicas:
 *  - `inventario_movimientos` (esta) → enum(Entrada|Salida|Ajuste), con categoria
 *    y observaciones. Es la que usa el modulo de Inventario.
 *  - `movimientos_stock` → tipo varchar(10) sin categoria ni observaciones,
 *    57 filas legacy. Se conserva intacta, no se escribe.
 *
 * IMPORTANTE: ninguna columna created_at/updated_at. Solo `fecha` (date).
 */
class InventarioMovimiento extends Model
{
    public const ENTRADA = 'Entrada';
    public const SALIDA = 'Salida';
    public const AJUSTE = 'Ajuste';

    public const TIPOS = [self::ENTRADA, self::SALIDA, self::AJUSTE];

    protected $table = 'inventario_movimientos';

    /** Tabla legacy: unicamente `fecha`. */
    public $timestamps = false;

    protected $fillable = [
        'producto_id',
        'categoria_id',
        'cantidad',
        'tipo',
        'observaciones',
        'fecha',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'fecha' => 'date',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function scopeEntradas($query)
    {
        return $query->where('tipo', self::ENTRADA);
    }

    public function scopeSalidas($query)
    {
        return $query->where('tipo', self::SALIDA);
    }
}
