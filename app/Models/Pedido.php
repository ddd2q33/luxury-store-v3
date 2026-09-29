<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    public const ESTADOS = ['pendiente', 'confirmado', 'preparando', 'listo', 'entregado', 'cancelado'];

    /**
     * Etiquetas en espanol para la UI. `estado` es un varchar(30), NO un enum de
     * MySQL, asi que la BD no garantiza que solo aparezcan estos seis valores:
     * por eso la vista siempre cae con `?? $estado` en vez de assumptions.
     */
    public const ETIQUETAS = [
        'pendiente' => 'Pendiente',
        'confirmado' => 'Confirmado',
        'preparando' => 'Preparando',
        'listo' => 'Listo',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
    ];

    /** Estados abiertos: siguen consumiendo stock reservado y requieren atención. */
    public const ESTADOS_ABIERTOS = ['pendiente', 'confirmado', 'preparando', 'listo'];

    protected $table = 'pedidos';

    /** La tabla solo tiene created_at (como updated_at). */
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'numero_pedido', 'cliente_id', 'cliente_nombre', 'cliente_telefono',
        'fecha_pedido', 'fecha_entrega', 'estado', 'total', 'metodo_pago', 'observaciones',
    ];

    protected $casts = [
        'total' => 'float',
        'fecha_pedido' => 'datetime',
        'fecha_entrega' => 'date',
    ];

    public function detalles(): HasMany
    {
        return $this->hasMany(PedidoDetalle::class, 'pedido_id');
    }
}
