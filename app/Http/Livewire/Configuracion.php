<?php

namespace App\Http\Livewire;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

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

    // ========== Formulario: nuevo usuario ==========
    public bool $showForm = false;

    public string $nombre = '';

    public string $username = '';

    public string $email = '';

    public string $rol = 'empleado';

    public string $estado = 'activo';

    public string $password = '';

    public string $passwordConfirm = '';

    /**
     * Segunda barrera del middleware. mount() corre solo en la carga inicial,
     * asi que esto solo cubre la primera pantalla; para las acciones
     * posteriores esta el middleware persistente de Livewire.
     */
    public function mount(): void
    {
        abort_unless(Auth::user()?->esAdmin(), 403, 'No tienes permisos para acceder a esta seccion.');
    }

    /**
     * El mapa rol -> permisos, para la ficha de cada usuario.
     *
     * @return array{value:string, label:string, resumen:string, total:bool, permisos:array<int, array{modulo:string, etiqueta:string}>, acciones:array<int, array{accion:string, etiqueta:string}>}
     */
    public function roles(): array
    {
        return collect(Roles::TODOS())
            ->map(fn (array $r, string $valor) => [
                'value' => $valor,
                'label' => $r['label'],
                'resumen' => $r['resumen'],
                'total' => $r['total'],
                'permisos' => array_map(
                    fn (string $m) => ['modulo' => $m, 'etiqueta' => Roles::etiquetaModulo($m)],
                    Roles::permisosDe($valor)
                ),
                'acciones' => array_map(
                    fn (string $a) => ['accion' => $a, 'etiqueta' => Roles::etiquetaAccion($a)],
                    $r['acciones']
                ),
            ])
            ->values()
            ->all();
    }

    protected function rules(): array
    {
        return [
            'nuevoPassword' => ['required', 'string', 'min:8'],
            'confirmarPassword' => ['required', 'same:nuevoPassword'],
        ];
    }

    /**
     * Reglas del alta de usuario. Van aparte de rules() porque rules() es el
     * juego que aplica `$this->validate()` en guardarPassword(): si se metieran
     * aquí, cambiar una contraseña exigiría rellenar nombre/username/email.
     */
    protected function reglasNuevoUsuario(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', Rule::unique('usuarios', 'username')],
            'email' => ['required', 'string', 'email', 'max:100', Rule::unique('usuarios', 'email')],
            'rol' => ['required', Rule::in(Roles::valores())],
            'estado' => ['required', Rule::in(['activo', 'inactivo'])],
            'password' => ['required', 'string', 'min:8'],
            'passwordConfirm' => ['required', 'same:password'],
        ];
    }

    protected $messages = [
        'nuevoPassword.required' => 'Escribe la nueva contraseña.',
        'nuevoPassword.min' => 'La contraseña debe tener al menos 8 caracteres.',
        'confirmarPassword.same' => 'Las contraseñas no coinciden.',
        'nombre.required' => 'Escribe el nombre completo.',
        'nombre.max' => 'El nombre no puede pasar de 100 caracteres.',
        'username.required' => 'Escribe el nombre de usuario.',
        'username.max' => 'El nombre de usuario no puede pasar de 50 caracteres.',
        'username.unique' => 'Ese nombre de usuario ya está en uso.',
        'email.required' => 'Escribe el correo.',
        'email.email' => 'El correo no tiene un formato válido.',
        'email.max' => 'El correo no puede pasar de 100 caracteres.',
        'email.unique' => 'Ese correo ya está registrado.',
        'rol.in' => 'Ese rol no existe.',
        'estado.in' => 'Ese estado no existe.',
        'password.required' => 'Escribe la contraseña.',
        'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        'passwordConfirm.required' => 'Repite la contraseña.',
        'passwordConfirm.same' => 'Las contraseñas no coinciden.',
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
            if (! Roles::existe($rol)) {
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

    // ====================================================================
    // Alta de usuario
    // ====================================================================

    public function nuevoUsuario(): void
    {
        $this->reset(['nombre', 'username', 'email', 'password', 'passwordConfirm', 'rol', 'estado']);
        $this->rol = 'empleado';
        $this->estado = 'activo';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function cerrarForm(): void
    {
        $this->reset(['nombre', 'username', 'email', 'password', 'passwordConfirm', 'rol', 'estado', 'showForm']);
        $this->resetValidation();
    }

    public function guardarUsuario(): void
    {
        $this->validate($this->reglasNuevoUsuario(), $this->messages);

        $ok = $this->ejecutar(function () {
            try {
                User::create([
                    'nombre' => trim($this->nombre),
                    'username' => trim($this->username),
                    'email' => trim($this->email),
                    'rol' => $this->rol,
                    'estado' => $this->estado,
                    'password' => Hash::make($this->password),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // La validación ya avisa de duplicados; esto cubre una carrera
                // entre el chequeo y el INSERT (los índices UNIQUE de `usuarios`).
                throw new \DomainException('No se pudo crear: el usuario o el correo ya existen.');
            }
        }, 'Usuario creado');

        if ($ok) {
            $this->cerrarForm();
        }
    }

    /**
     * Cierra cualquiera de los dos modales. La vista la usa en @keydown.escape.
     * Antes llamaba a cerrarPassword() sin `$wire.` desde Alpine, así que la
     * tecla Esc tiraba un error de JS y no cerraba nada.
     */
    public function cerrarModales(): void
    {
        $this->cerrarPassword();
        $this->cerrarForm();
    }
}
