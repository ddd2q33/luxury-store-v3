<div class="space-y-5" @keydown.escape="cerrarPassword()">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div>
        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <x-heroicon name="shield-check" class="w-6 h-6 text-indigo-600" />
            Configuración
        </h2>
        <p class="text-sm text-gray-500 mt-0.5">
            Acceso al panel, datos de la empresa e información del sistema
        </p>
    </div>

    @if ($adminsActivos <= 1)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
            <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
            <p class="text-sm text-amber-800 leading-relaxed">
                <span class="font-bold">Solo queda {{ $adminsActivos }} administrador activo.</span>
                Por seguridad el sistema no te deja quitarle el rol ni desactivar a la última
                cuenta admin. Crea o activa otro administrador antes de cambiar este.
            </p>
        </div>
    @endif

    {{-- ================= Usuarios del panel ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100">
            <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
                <x-heroicon name="users" class="w-4 h-4 text-gray-400" />
                Usuarios del panel
            </h3>
            <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                Tabla <span class="font-medium">usuarios</span>. El rol define a qué módulos entra
                cada persona: los admin-only (Empleados y este mismo Config) se ocultan a los demás.
                <span class="font-medium">No se borran usuarios</span>: sin claves foráneas, cajas,
                abonos y devoluciones guardan su <span class="font-medium">usuario_id</span> y el borrado
                dejaría referencias colgadas. Para retirar a alguien, desactívalo.
            </p>
        </div>

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Nombre</th>
                        <th class="px-5 py-3 font-bold">Usuario</th>
                        <th class="px-5 py-3 font-bold">Rol</th>
                        <th class="px-5 py-3 font-bold">Estado</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($usuarios as $u)
                        <tr wire:key="cfg-{{ $u->id }}" @class(['opacity-60' => $u->estado !== 'activo'])>
                            <td class="px-5 py-3">
                                <span class="font-semibold text-gray-900 block">{{ $u->nombre }}</span>
                                @if ((int) Auth::id() === $u->id)
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-500">tú</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-gray-600">
                                <span class="block">{{ $u->username }}</span>
                                <span class="block text-xs text-gray-400">{{ $u->email }}</span>
                            </td>
                            <td class="px-5 py-3">
                                {{-- El select envía el valor; la validación real vive en cambiarRol(). --}}
                                <select wire:change="cambiarRol({{ $u->id }}, $event.target.value)"
                                        class="rounded-lg border-gray-200 text-xs font-semibold py-2 pr-8 focus:border-indigo-500 focus:ring-indigo-500 min-h-[40px]"
                                        aria-label="Rol de {{ $u->nombre }}">
                                    @foreach (['admin' => 'Administrador', 'cajero' => 'Cajero', 'empleado' => 'Empleado'] as $valor => $texto)
                                        <option value="{{ $valor }}" @selected($u->rol === $valor)>{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-5 py-3">
                                <span @class([
                                    'inline-flex items-center gap-1.5 text-xs font-bold',
                                    'text-emerald-600' => $u->estado === 'activo',
                                    'text-rose-600'    => $u->estado === 'inactivo',
                                ])>
                                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                    {{ $u->estado }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="abrirPassword({{ $u->id }})"
                                        class="text-indigo-600 hover:text-indigo-700 text-sm font-semibold">Contraseña</button>
                                <span class="text-gray-200 mx-1">·</span>
                                @if ((int) Auth::id() === $u->id)
                                    <span class="text-gray-300 text-sm font-semibold">No editable</span>
                                @else
                                    <button type="button" wire:click="cambiarEstado({{ $u->id }})"
                                            @class([
                                                'text-sm font-semibold',
                                                'text-rose-600 hover:text-rose-700'   => $u->estado === 'activo',
                                                'text-emerald-600 hover:text-emerald-700' => $u->estado === 'inactivo',
                                            ])>
                                        {{ $u->estado === 'activo' ? 'Desactivar' : 'Activar' }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @foreach ($usuarios as $u)
                <div class="p-4" wire:key="cfg-m-{{ $u->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900">
                                {{ $u->nombre }}
                                @if ((int) Auth::id() === $u->id)
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-500">tú</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-400 truncate">{{ $u->username }} · {{ $u->email }}</p>
                        </div>
                        <span @class([
                            'text-xs font-bold shrink-0',
                            'text-emerald-600' => $u->estado === 'activo',
                            'text-rose-600'    => $u->estado === 'inactivo',
                        ])>{{ $u->estado }}</span>
                    </div>

                    <div class="mt-3">
                        <label for="rol-m-{{ $u->id }}" class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Rol</label>
                        <select id="rol-m-{{ $u->id }}" wire:change="cambiarRol({{ $u->id }}, $event.target.value)"
                                class="w-full rounded-xl border-gray-200 text-sm font-semibold min-h-[44px] focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach (['admin' => 'Administrador', 'cajero' => 'Cajero', 'empleado' => 'Empleado'] as $valor => $texto)
                                <option value="{{ $valor }}" @selected($u->rol === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="abrirPassword({{ $u->id }})"
                                class="rounded-lg bg-indigo-50 py-2.5 text-xs font-bold text-indigo-700 min-h-[42px] active:scale-[0.98] transition">Contraseña</button>
                        @if ((int) Auth::id() === $u->id)
                            <span class="rounded-lg bg-gray-50 py-2.5 text-xs font-bold text-gray-400 flex items-center justify-center min-h-[42px]">No editable</span>
                        @else
                            <button type="button" wire:click="cambiarEstado({{ $u->id }})"
                                    @class([
                                        'rounded-lg py-2.5 text-xs font-bold min-h-[42px] active:scale-[0.98] transition',
                                        'bg-rose-50 text-rose-600'         => $u->estado === 'activo',
                                        'bg-emerald-50 text-emerald-700'   => $u->estado === 'inactivo',
                                    ])>
                                {{ $u->estado === 'activo' ? 'Desactivar' : 'Activar' }}
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ================= Datos de la empresa ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100">
            <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
                <x-heroicon name="home" class="w-4 h-4 text-gray-400" />
                Datos de la empresa
            </h3>
        </div>

        <div class="p-4 sm:p-5">
            @if (($empresa['ejemplo'] ?? false))
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 mb-4">
                    <p class="text-sm text-amber-800 leading-relaxed">
                        <span class="font-bold">Solo lectura, y con datos de ejemplo.</span>
                        En el sistema anterior estos valores estaban escritos a mano en
                        <span class="font-medium">config.php</span> (“Calle 123”, “+57 300 123 4567”), nunca
                        en la base de datos. Ahora viven en
                        <span class="font-medium">config/empresa.php</span>: edita ese archivo y se actualizan
                        esta pantalla, las facturas y el mensaje de WhatsApp.
                        Guardarlos desde aquí exigiría crear una tabla de configuración, que hay que
                        autorizar antes.
                    </p>
                </div>
            @endif

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                {{-- `ejemplo` es una bandera, no un dato: no se muestra como campo. --}}
                @foreach ($empresa as $campo => $valor)
                    @if ($campo === 'ejemplo')
                        @continue
                    @endif
                    <div class="flex justify-between gap-4 border-b border-gray-100 pb-2">
                        <dt class="text-gray-500 capitalize shrink-0">
                            {{ $etiquetas[$campo] ?? $campo }}
                        </dt>
                        <dd class="font-medium text-gray-900 text-right break-all">{{ $valor }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    {{-- ================= Sistema ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100">
            <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
                <x-heroicon name="bars-3" class="w-4 h-4 text-gray-400" />
                Sistema
            </h3>
        </div>

        <div class="p-4 sm:p-5 grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
            <div>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Versiones</p>
                <dl class="text-sm space-y-1.5">
                    @foreach (['Nombre de la app' => $sistema['app_name'], 'Entorno' => $sistema['entorno'],
                               'Depuración' => $sistema['debug'], 'Zona horaria' => $sistema['zona'],
                               'PHP' => $sistema['php'], 'Laravel' => $sistema['laravel'],
                               'Livewire' => $sistema['livewire']] as $k => $v)
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 shrink-0">{{ $k }}</dt>
                            <dd class="font-medium text-gray-900 text-right tabular-nums truncate">{{ $v }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                    <p class="text-sm text-rose-800 leading-relaxed">
                        <span class="font-bold">Laravel {{ $sistema['laravel'] }} y PHP {{ $sistema['php'] }}
                        están fuera de soporte.</span>
                        No publiques el panel con estas versiones: hay vulnerabilidades conocidas
                        sin parche. Sirve para desarrollo local.
                    </p>
                </div>
            </div>

            <div>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Base de datos</p>
                <dl class="text-sm space-y-1.5 mb-4">
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 shrink-0">Base</dt>
                        <dd class="font-medium text-gray-900 truncate">{{ $sistema['bd'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 shrink-0">Servidor</dt>
                        <dd class="font-medium text-gray-900 truncate">{{ $sistema['servidor'] }}</dd>
                    </div>
                </dl>

                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Registros</p>
                <dl class="text-sm space-y-1.5">
                    @foreach ($conteos as $etiqueta => $total)
                        <div class="flex justify-between gap-4 border-b border-gray-50 pb-1">
                            <dt class="text-gray-500 truncate">{{ $etiqueta }}</dt>
                            <dd class="font-semibold text-gray-900 tabular-nums">
                                {{ $total === null ? '—' : number_format($total, 0, ',', '.') }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>

    {{-- ================= Apariencia =================
         El legacy guardaba el tema en localStorage (`luxTema`), no en la BD:
         es una preferencia del navegador, no del usuario. Por eso esto NO
         escribe en `usuarios` ni necesita tabla nueva. El tema se aplica antes
         de pintar en panel-layout, asi que el switch no parpadea. --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-5"
         x-data="{
             oscuro: document.documentElement.classList.contains('dark'),
             alternar() { window.luxTema.alternar(); }
         }"
         @tema-cambiado.window="oscuro = $event.detail.oscuro">
        <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
            <x-heroicon name="sun" class="w-4 h-4 text-gray-400" />
            Apariencia
        </h3>
        <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">
            El tema oscuro se guarda en este navegador (<span class="font-mono text-xs">localStorage</span>), igual
            que en el sistema anterior. No se guarda en la base de datos: es una preferencia de este equipo, no del
            usuario, así que no cambia al entrar desde el celular.
        </p>

        <button type="button"
                @click="alternar()"
                class="mt-3 w-full sm:w-auto flex items-center gap-3 min-h-[44px] px-4 py-2.5 rounded-xl border border-gray-200 hover:bg-gray-50 active:bg-gray-100 transition-colors text-sm font-medium text-gray-900"
                :aria-pressed="oscuro ? 'true' : 'false'">
            <span class="relative inline-flex items-center rounded-full transition-colors"
                  style="width:42px;height:24px;"
                  :style="oscuro ? 'background:#0f172a' : 'background:#cbd5e1'">
                <span class="absolute top-0.5 rounded-full bg-white transition-all"
                      style="width:20px;height:20px;"
                      :style="oscuro ? 'left:21px' : 'left:2px'"></span>
            </span>
            <span x-text="oscuro ? 'Tema oscuro activo' : 'Tema claro'"></span>
        </button>

        {{-- El atajo funciona en cualquier pantalla del panel, no solo aquí. --}}
        <p class="mt-3 text-xs text-gray-500 flex items-center gap-1.5">
            <span class="text-gray-400">Atajo:</span>
            <kbd class="font-mono text-[11px] px-1.5 py-0.5 rounded border border-gray-200 bg-gray-50 text-gray-700">Shift</kbd>
            <span class="text-gray-400">+</span>
            <kbd class="font-mono text-[11px] px-1.5 py-0.5 rounded border border-gray-200 bg-gray-50 text-gray-700">D</kbd>
            <span class="text-gray-400">alterna el tema desde cualquier pantalla. No funciona mientras escribes en un campo.</span>
        </p>
    </div>

    {{-- ================= Modal: contraseña ================= --}}
    @if ($porEditar)
        @php $objetivo = $usuarios->firstWhere('id', $porEditar); @endphp
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarPassword"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">Cambiar contraseña</h3>
                    <p class="text-sm text-gray-500 mt-0.5">
                        Para <span class="font-medium text-gray-900">{{ $objetivo?->nombre ?? 'este usuario' }}</span>
                        ({{ $objetivo?->username }})
                    </p>
                </div>

                <form wire:submit="guardarPassword" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="cfg-pass" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Nueva contraseña *</label>
                        <input id="cfg-pass" type="password" wire:model="nuevoPassword" autocomplete="new-password"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Mínimo 8 caracteres">
                        @error('nuevoPassword') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="cfg-pass2" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Repetir contraseña *</label>
                        <input id="cfg-pass2" type="password" wire:model="confirmarPassword" autocomplete="new-password"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Repite la contraseña">
                        @error('confirmarPassword') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="cerrarPassword"
                                class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardarPassword"
                                class="rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 min-h-[48px] active:scale-[0.98] transition">
                            <span wire:loading.remove wire:target="guardarPassword">Guardar</span>
                            <span wire:loading wire:target="guardarPassword">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
