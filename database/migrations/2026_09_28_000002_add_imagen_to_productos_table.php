<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la imagen del producto como MEDIUMBLOB en la tabla legacy `productos`.
 * Autorizado por el usuario (2026-09-28): el catálogo PDF la usa para mostrar
 * el producto en el catálogo virtual.
 *
 * MEDIUMBLOB (16 MB) porque las fotos reales de producto suelen pesar más que
 * un BLOB simple (64 KB). Se guarda la imagen dentro de la BD, como pidió el
 * usuario, para no depender de carpetas que se pierden al migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('productos', 'imagen')) {
            DB::statement('ALTER TABLE `productos` ADD COLUMN `imagen` MEDIUMBLOB NULL AFTER `descripcion`');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('productos', 'imagen')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->dropColumn('imagen');
            });
        }
    }
};