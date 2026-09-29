<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbonoProveedor extends Model
{
    protected $table = 'abonos_proveedores';

    protected $fillable = [
        'proveedor_id', 'monto', 'deuda_anterior', 'deuda_nueva',
        'fecha_abono', 'referencia', 'usuario_id',
    ];

    /** Tabla legacy sin timestamps. */
    public $timestamps = false;

    protected $casts = [
        'monto' => 'float',
        'deuda_anterior' => 'float',
        'deuda_nueva' => 'float',
        'fecha_abono' => 'datetime',
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
