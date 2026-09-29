<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'nombre', 'categoria_id', 'proveedor_id', 'precio', 'stock',
        'stock_minimo', 'proveedor', 'detalles', 'descripcion', 'imagen',
    ];

    /** Tabla legacy: solo tiene fecha_creacion con default de BD. */
    public $timestamps = false;

    protected $casts = [
        'precio' => 'float',
        'stock' => 'integer',
        'stock_minimo' => 'integer',
        'fecha_creacion' => 'datetime',
        'tiene_imagen' => 'boolean',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(InventarioMovimiento::class, 'producto_id');
    }

    /**
     * Un solo lugar define "requiere reposición" en SQL, para que el Dashboard,
     * el módulo Stock y los filtros de Productos/Inventario no se contradigan.
     * Refleja exactamente estadoStock(): negativos, agotados y por debajo del
     * mínimo (> 0) que el usuario haya definido.
     */
    public function scopeNecesitaReposicion(Builder $q): Builder
    {
        return $q->where(function (Builder $q) {
            $q->where('stock', '<=', 0)
                ->orWhere(function (Builder $q) {
                    $q->where('stock_minimo', '>', 0)
                        ->whereColumn('stock', '<=', 'stock_minimo');
                });
        });
    }

    /**
     * Estado de existencias, en un solo lugar para que inventario, stock,
     * productos e ingresos muestren exactamente lo mismo.
     *
     * - negativo: el legacy tiene saldos en negativo (dato corrupto a corregir con Ajuste)
     * - agotado:  sin unidades
     * - bajo:      por debajo del mínimo (> 0) definido por el usuario
     * - ok:        nivel sano
     */
    public function estadoStock(): string
    {
        if ($this->stock < 0) {
            return 'negativo';
        }

        if ($this->stock === 0) {
            return 'agotado';
        }

        if ($this->stock_minimo > 0 && $this->stock <= $this->stock_minimo) {
            return 'bajo';
        }

        return 'ok';
    }

    /** Etiqueta legible del estado de stock. */
    public static function etiquetaEstadoStock(string $estado): string
    {
        return [
            'negativo' => 'Stock negativo',
            'agotado'  => 'Agotado',
            'bajo'     => 'Stock bajo',
            'ok'       => 'Disponible',
        ][$estado] ?? '—';
    }

    // ====================================================================
    // Imagen (MEDIUMBLOB en la tabla legacy): opcional, pensada para el
    // catálogo PDF. Se guarda en la BD para no depender de carpetas.
    // ====================================================================

    public function tieneImagen(): bool
    {
        return ! empty($this->imagen);
    }

    /** Mime real de la imagen almacenada (jp*g, png, webp…). */
    public function imagenMime(): ?string
    {
        if (! $this->tieneImagen()) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($this->imagen);

        return $mime && str_starts_with($mime, 'image/') ? $mime : 'image/jpeg';
    }

    /** Data URI lista para <img src="…">: la usa el listado y las vistas. */
    public function imagenDataUri(): ?string
    {
        if (! $this->tieneImagen()) {
            return null;
        }

        return 'data:' . $this->imagenMime() . ';base64,' . base64_encode($this->imagen);
    }

    /**
     * Data URI para incrustar en el PDF. Dompdf recibe la imagen como URI de
     * datos (no puede leer blobs de la BD directamente). Las tarjetas del
     * catálogo que no tienen imagen NO llaman a esto: placeholder inline.
     */
    public function imagenPdf(): ?string
    {
        return $this->imagenDataUri();
    }
}
