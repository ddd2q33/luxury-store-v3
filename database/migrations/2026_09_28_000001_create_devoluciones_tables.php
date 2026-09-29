<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones de ventas.
 *
 * UNICA migracion de dominio del proyecto, autorizada explicitamente por el
 * usuario. El sistema viejo (oldluxury/devoluciones/index.php) usaba estas dos
 * tablas pero no llegaron a la BD actual, por eso hay que crearlas.
 *
 * El INSERT del modulo viejo es la fuente del schema:
 *   INSERT INTO devoluciones
 *     (venta_id, cliente_nombre, motivo, total_devuelto, usuario_id, caja_id, fecha)
 *
 * NOTA: la columna devolucion_detalles.venta_detalle_id no tiene FK porque
 * venta_detalles esta vacia en produccion (0 de 542 ventas). El modulo trabaja
 * a nivel de venta; la tabla de detalle queda lista para cuando existan lineas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devoluciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('venta_id')->nullable();
            $table->string('cliente_nombre');
            $table->string('motivo');
            $table->decimal('total_devuelto', 12, 2)->default(0);
            $table->unsignedInteger('usuario_id')->nullable();
            $table->unsignedInteger('caja_id')->nullable();

            // Referencia al egreso que esta devolucion genero en caja, para que
            // anular la devolucion borre el movimiento exacto y no un LIKE
            // fragil sobre la descripcion.
            $table->unsignedInteger('movimiento_caja_id')->nullable();

            $table->dateTime('fecha')->useCurrent();
            $table->timestamps();

            $table->index('venta_id');
            $table->index('fecha');
        });

        Schema::create('devolucion_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('devolucion_id');
            $table->unsignedInteger('venta_detalle_id')->nullable();
            $table->unsignedInteger('cantidad')->default(1);
            $table->decimal('precio_unitario', 12, 2)->default(0);
            $table->timestamps();

            $table->index('devolucion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devolucion_detalles');
        Schema::dropIfExists('devoluciones');
    }
};
