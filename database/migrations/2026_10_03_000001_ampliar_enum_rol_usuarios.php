<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía el enum `usuarios.rol` para los roles nuevos del panel.
 *
 * Autorizado por el usuario (2026-10-03): pidió cuentas múltiples por tipo de
 * empleado con "administrador (control total)" y una escala de permisos real.
 * Decidió: ampliar el enum y mantener el mapa rol->permisos en código
 * (App\Support\Roles), NO crear tablas de dominio nuevas.
 *
 * Se agregan:
 * - `supervisor`: todo menos Configuración (sí ve reportes, empleados y finanzas).
 * - `operador`:   acceso amplio heredado, sin Configuración, reportes ni empleados.
 *   Existe para que las dos cuentas que YA existían (cajero y empleado) conserven
 *   exactamente el acceso que tenían antes de este cambio, en vez de que se les
 *   recortara al empezar a filtrar las rutas por permiso.
 *
 * Los valores viejos ('admin','cajero','empleado') se conservan tal cual, así
 * que esta migración no cambia el acceso de ninguna cuenta existente por sí sola:
 * eso lo hace el paso de datos de abajo, que es explícito y reversible.
 *
 * NO se agregan timestamps: `usuarios` es legacy.
 */
return new class extends Migration
{
    /**
     * Orden importa: el enum debe declararse completo, no se agregan de a uno.
     */
    private const ROLES = "enum('admin','supervisor','operador','cajero','empleado')";

    public function up(): void
    {
        if (! Schema::hasColumn('usuarios', 'rol')) {
            return;
        }

        // MySQL no tiene "ADD VALUE" en un enum: hay que redefinir la columna.
        // El default NO se toca: sigue siendo 'cajero', como en el legacy.
        DB::statement(
            "ALTER TABLE `usuarios` MODIFY `rol` ".self::ROLES." NOT NULL DEFAULT 'cajero'"
        );

        $this->reubicarCuentasHeredadas();
    }

    /**
     * Las dos cuentas legacy quedan como `operador`, que tiene EXACTAMENTE los
     * mismos módulos que tenían antes (todo salvo reportes, empleados y
     * configuración). Sin esto, un `cajero` que entrara a mirar la caja
     * perdería de golpe el acceso a proveedores y finanzas.
     *
     * Solo toca las filas que hoy no son admin, para no tocar el admin real.
     */
    private function reubicarCuentasHeredadas(): void
    {
        DB::table('usuarios')
            ->whereIn('rol', ['cajero', 'empleado'])
            ->update(['rol' => 'operador']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('usuarios', 'rol')) {
            return;
        }

        // Los `operador` no tienen equivalente previo: se devuelven a 'cajero',
        // que era el estado del legacy con acceso amplio.
        DB::table('usuarios')->where('rol', 'operador')->update(['rol' => 'cajero']);

        DB::table('usuarios')->where('rol', 'supervisor')->update(['rol' => 'admin']);

        DB::statement(
            "ALTER TABLE `usuarios` MODIFY `rol` enum('admin','cajero','empleado') NOT NULL DEFAULT 'cajero'"
        );
    }
};