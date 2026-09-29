<?php

namespace App\Http\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Config. En el legacy este módulo (`oldluxury/configuracion/index.php`)
 * trabajaba SOLO sobre la tabla `usuarios`: alta, cambio de rol, activación y
 * borrado, más una sección de "Apariencia" que no se migra porque no hay dónde
 * guardarla (ver la nota de la vista).
 *
 * No se borran usuarios: sin claves foráneas, cajas/abonos/devoluciones guardan
 * `usuario_id` y el borrado dejaría referencias colgadas. Para ese caso está
 * desactivar.
 */
class Configuracion extends PanelComponent
{
    public ?int $porEditar = null;

    public string $nuevoPassword = '';

    public string $confirmarPassword = '';

    /**
     * El middleware `admin` protege la ruta, pero el endpoint de Livewire es
     * publico: sin esta guarda un cajero podria montar el componente.
     */
    public function mount(): void
    {
        abort_unless(Auth::user()?->esAdmin(), 403, 'No tienes permisos para acceder a esta seccion.');
    }

    protected function rules(): array
    {
        return [
            'nuevoPassword' => ['required', 'string', 'min:8'],
            'confirmarPassword' => ['required', 'same:nuevoPassword'],
        ];
    }

    protected $messages = [
        'nuevoPassword.required' => 'Escribe la nueva contraseña.',
        'nuevoPassword.min' => 'La contraseña debe tener al menos 8 caracteres.',
        'confirmarPassword.same' => 'Las contraseñas no coinciden.',
    ];

    /**
     * Busca el usuario o lanza DomainException. NO se usa findOrFail(): su
     * ModelNotFoundException es un RuntimeException y PanelComponent::ejecutar()
     * solo atrapa DomainException, asi que se veria una pantalla en blanco.
     */
    private function usuario(int $id): User
    {
        $usuario = User::find($id);

        if (! $usuario) {
            throw new \DomainException('Ese usuario ya no existe.');
        }

        return $usuario;
    }

    /** Bloquea cambios que dejen la tienda sin un administrador activo. */
    private function asegurarQuedaAdmin(int $usuarioId, string $nuevoRol, string $nuevoEstado, string $motivo): void
    {
        $quedan = User::query()
            ->where('rol', 'admin')
            ->where('estado', 'activo')
            ->where('id', '!=', $usuarioId)
            ->count();

        if ($quedan === 0) {
            throw new \DomainException($motivo);
        }
    }

    public function render()
    {
        $usuarios = User::query()->orderBy('id')->get();

        $tablas = [
            'Productos' => 'productos',
            'Categorías' => 'categorias',
            'Ventas' => 'ventas',
            'Clientes' => 'clientes',
            'Proveedores' => 'proveedores',
            'Empleados' => 'empleados',
            'Usuarios' => 'usuarios',
            'Cajas' => 'cajas',
            'Movimientos de caja' => 'movimientos_caja',
            'Devoluciones' => 'devoluciones',
            'Gastos' => 'gastos',
            'Abonos a proveedores' => 'abonos_proveedores',
        ];

        $conteos = [];
        foreach ($tablas as $etiqueta => $tabla) {
            $conteos[$etiqueta] = Schema::hasTable($tabla) ? DB::table($tabla)->count() : null;
        }

        return view('livewire.configuracion', [
            'usuarios' => $usuarios,
            'conteos' => $conteos,
            'adminsActivos' => User::where('rol', 'admin')->where('estado', 'activo')->count(),
            // Fuente unica: config/empresa.php. Antes los literales estaban
            // duplicados aquí, así que corregirlos en un sitio los dejaba
            // cambiados en el otro. Ver el archivo para por que son editables
            // a mano y no en la BD.
            'empresa' => config('empresa'),
            // Los datos de empresa van para documentos: etiquetas legibles, no
            // las claves crudas del archivo de configuracion.
            'etiquetas' => [
                'nombre' => 'Nombre',
                'nit' => 'NIT',
                'direccion' => 'Dirección',
                'telefono' => 'Teléfono',
                'email' => 'Correo',
                'web' => 'Sitio web',
                'gracias' => 'Mensaje de cierre',
                'pais_whatsapp' => 'Prefijo WhatsApp',
            ],
            'sistema' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'livewire' => \Composer\InstalledVersions::getPrettyVersion('livewire/livewire') ?? '—',
                'entorno' => app()->environment(),
                'debug' => config('app.debug') ? 'activado' : 'desactivado',
                'zona' => config('app.timezone', '—'),
                'bd' => config('database.connections.mysql.database', '—'),
                'servidor' => config('database.connections.mysql.host', '—'),
                'app_name' => config('app.name', '—'),
            ],
        ]);
    }

    // ====================================================================
    // Usuarios
    // ====================================================================

    public function cambiarRol(int $id, string $rol): void
    {
        $this->ejecutar(function () use ($id, $rol) {
            if (! in_array($rol, ['admin', 'cajero', 'empleado'], true)) {
                throw new \DomainException('Ese rol no existe.');
            }

            $usuario = $this->usuario($id);

            if ((int) Auth::id() === $usuario->id && $rol !== 'admin') {
                throw new \DomainException('No te puedes quitar a ti mismo el rol de administrador.');
            }

            if ($usuario->rol === 'admin' && $rol !== 'admin' && $usuario->estado === 'activo') {
                $this->asegurarQuedaAdmin(
                    $id,
                    $rol,
                    $usuario->estado,
                    'No puedes quitarle el rol de admin al único administrador activo.'
                );
            }

            $usuario->update(['rol' => $rol]);
        }, 'Rol actualizado');
    }

    public function cambiarEstado(int $id): void
    {
        $this->ejecutar(function () use ($id) {
            $usuario = $this->usuario($id);

            if ((int) Auth::id() === $usuario->id) {
                throw new \DomainException('No puedes desactivar tu propia cuenta desde aquí.');
            }

            $nuevo = $usuario->estado === 'activo' ? 'inactivo' : 'activo';

            if ($nuevo === 'inactivo' && $usuario->rol === 'admin') {
                $this->asegurarQuedaAdmin(
                    $id,
                    $usuario->rol,
                    $nuevo,
                    'No puedes desactivar al único administrador activo.'
                );
            }

            $usuario->update(['estado' => $nuevo]);
        }, 'Estado actualizado');
    }

    public function abrirPassword(int $id): void
    {
        $this->reset(['nuevoPassword', 'confirmarPassword']);
        $this->resetValidation();

        if (! User::find($id)) {
            $this->toastError('Ese usuario ya no existe.');

            return;
        }

        $this->porEditar = $id;
    }

    public function guardarPassword(): void
    {
        if (! $this->porEditar) {
            $this->toastError('No hay ningún usuario seleccionado.');

            return;
        }

        // Sin esta llamada las reglas() no se aplican y se guardaba cualquier
        // cadena, incluso de menos de 8 caracteres.
        $this->validate();

        $ok = $this->ejecutar(function () {
            $usuario = $this->usuario($this->porEditar);
            $usuario->update(['password' => Hash::make($this->nuevoPassword)]);
        }, 'Contraseña actualizada');

        if ($ok) {
            $this->reset(['nuevoPassword', 'confirmarPassword', 'porEditar']);
        }
    }

    public function cerrarPassword(): void
    {
        $this->reset(['nuevoPassword', 'confirmarPassword', 'porEditar']);
    }
}
