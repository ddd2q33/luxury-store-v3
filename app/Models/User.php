<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'nombre',
        'username',
        'email',
        'rol',
        'estado',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'ultimo_login' => 'datetime',
    ];

    /**
     * La tabla legacy `usuarios` no tiene `created_at` ni `updated_at`.
     * Se sobrescribe usesTimestamps() porque la propiedad $timestamps ya
     * está declarada en Authenticatable y PHP no permite redeclararla.
     * No agregar esas columnas con migraciones (ver AGENTS.md).
     */
    public function usesTimestamps(): bool
    {
        return false;
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', 'activo');
    }

    /**
     * ¿Puede entrar a este módulo? El mapa rol -> permisos vive en
     * App\Support\Roles, que es la fuente única (decidido 2026-10-03).
     *
     * OJO: no usar `$this->rol === 'x'` por ahí. Este método además exige que la
     * cuenta esté activa, así que una cuenta desactivada pierde el acceso aunque
     * el rol siga siendo el mismo.
     */
    public function puede(string $modulo): bool
    {
        return Roles::usuarioPuede($this, $modulo);
    }

    /** Acción puntual dentro de un módulo (anular venta, etc). */
    public function puedeAccion(string $accion): bool
    {
        return Roles::usuarioPuedeAccion($this, $accion);
    }

    /**
     * Control total. Es el ÚNICO chequeo que el legacy entendía por admin, así
     * que se deja como estaba y no se reemplaza por `puede('configuracion')`:
     * otras cosas dependen de él (Configuracion, EnsureEsAdmin, PanelMenu).
     */
    public function esAdmin(): bool
    {
        return $this->rol === Roles::ADMIN;
    }

    /**
     * Lo que puede hacer el admin, menos administrar cuentas: ve reportes,
     * empleados y finanzas, pero no toca roles ni contraseñas.
     */
    public function esSupervisor(): bool
    {
        return $this->rol === Roles::SUPERVISOR;
    }

    /** Admin o supervisor: los dos que pueden ver cifras de todo el negocio. */
    public function esSupervision(): bool
    {
        return $this->esAdmin() || $this->esSupervisor();
    }

    public function esOperador(): bool
    {
        return $this->rol === Roles::OPERADOR;
    }

    public function esCajero(): bool
    {
        return $this->rol === Roles::CAJERO;
    }

    public function esEmpleado(): bool
    {
        return $this->rol === Roles::EMPLEADO;
    }

    /**
     * Avatar del panel. Réplica de `luxury_nav_avatar_url()` del legacy
     * (app_nav.php:2-28): toma `usuarios.imagen_perfil`, busca el archivo en
     * `public/uploads/avatars/` y, si no está, cae al `default.svg`.
     *
     * El chequeo de existencia NO es opcional: el admin tiene
     * `imagen_perfil = user_1_1775367584.png` y ese archivo no está en la
     * carpeta (hay 7 con otros timestamps), así que sin verificar la verdad
     * sale un 404 con el icono roto.
     */
    public function avatarUrl(): string
    {
        $default = asset('assets/img/avatars/default.svg');

        $archivo = trim((string) $this->imagen_perfil);

        if ($archivo === '') {
            return $default;
        }

        // Solo el nombre del archivo: `imagen_perfil` viene de la BD y no debe
        // poder escalar a otra ruta con `../`.
        $archivo = basename($archivo);

        if (! preg_match('/\.(jpe?g|png|gif|webp)$/i', $archivo)) {
            return $default;
        }

        $ruta = public_path('uploads/avatars/' . $archivo);

        return is_file($ruta) ? asset('uploads/avatars/' . rawurlencode($archivo)) : $default;
    }

    public function estaActivo(): bool
    {
        return $this->estado === 'activo';
    }
}
