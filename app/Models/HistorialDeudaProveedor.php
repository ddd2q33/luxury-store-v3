<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistorialDeudaProveedor extends Model
{
    protected $table = 'historial_deuda_proveedores';

    protected $fillable = [
        'proveedor_id', 'tipo_movimiento', 'monto_anterior', 'monto_nuevo',
        'diferencia', 'razon', 'fecha_registro', 'usuario_id',
    ];

    /** Tabla legacy sin timestamps. */
    public $timestamps = false;

    protected $casts = [
        'monto_anterior' => 'float',
        'monto_nuevo' => 'float',
        'diferencia' => 'float',
        'fecha_registro' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
