<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = [
        'nombre', 'cargo', 'telefono', 'correo', 'salario', 'fecha_ingreso',
    ];

    /**
     * Tabla legacy: solo tiene `creado_en` (timestamp por defecto de MySQL) y
     * ningun `actualizado_en`. Se mapea CREATED_AT y se desactiva UPDATED_AT
     * para que Eloquent no intente escribir una columna que no existe.
     */
    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $casts = [
        'salario' => 'float',
        'fecha_ingreso' => 'date',
    ];
}
