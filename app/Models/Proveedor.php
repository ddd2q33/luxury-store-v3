<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    /**
     * Tabla legacy: fecha_creacion con default de BD, sin updated_at.
     *
     * OJO: 'deuda' NO se usa. El sistema viejo (oldluxury) solo leia y escribia
     * 'saldo_deuda'; la columna 'deuda' quedo huerfana en la BD. Toda mutacion
     * de deuda pasa por App\Support\DeudaProveedorService sobre 'saldo_deuda'.
     */
    public $timestamps = false;

    protected $fillable = [
        'nombre', 'contacto', 'telefono', 'correo', 'direccion',
        'descripcion_deuda_inicial',
    ];

    protected $casts = [
        'saldo_deuda' => 'float',
        'deuda' => 'float',
        'fecha_creacion' => 'datetime',
    ];

    public function abonos(): HasMany
    {
        return $this->hasMany(AbonoProveedor::class, 'proveedor_id');
    }

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class, 'proveedor_id');
    }

    public function historialDeuda(): HasMany
    {
        return $this->hasMany(HistorialDeudaProveedor::class, 'proveedor_id');
    }
}
