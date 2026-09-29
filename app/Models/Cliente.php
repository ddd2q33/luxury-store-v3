<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    /** La tabla usa `creado_en` y no tiene columna de actualización. */
    const CREATED_AT = 'creado_en';
    const UPDATED_AT = null;

    protected $table = 'clientes';

    protected $fillable = ['nombre', 'telefono', 'correo', 'direccion', 'ciudad', 'notas', 'tipo'];

    protected $casts = [
        'creado_en' => 'datetime',
    ];
}
