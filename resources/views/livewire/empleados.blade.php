<div class="space-y-5" @keydown.escape="cerrarForm(); $wire.porEliminar = null">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="users" class="w-6 h-6 text-indigo-600" />
                Empleados
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                Datos de personal: nombre, cargo, contacto y salario
            </p>
        </div>
        <button type="button" wire:click="nuevo"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-indigo-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-indigo-600/20">
            <x-heroicon name="plus" class="w-5 h-5" />
            Nuevo empleado
        </button>
    </div>

    {{-- Aviso: `empleados` y `usuarios` son tablas distintas --}}
    <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 flex items-start gap-3">
        <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-sky-500 shrink-0 mt-0.5" />
        <p class="text-sm text-sky-800 leading-relaxed">
            <span class="font-bold">Esta lista no es el acceso al panel.</span>
            El login usa la tabla <span class="font-medium">usuarios</span> (rol y estado), que se
            administra en <a href="{{ route('configuracion') }}" class="font-bold underline">Config</a>.
            Aquí solo se registran los datos de personal.
        </p>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Registrados" :valor="(string) $stats['total']" tono="indigo" icono="users" />
        <x-panel.stat etiqueta="Nómina mensual" :valor="\App\Support\Money::cents($stats['salarioTotal'])" tono="emerald" icono="currency-dollar" />
        <x-panel.stat etiqueta="Salario promedio" :valor="\App\Support\Money::cents($stats['promedio'])" tono="amber" icono="chart-bar" />
        <x-panel.stat etiqueta="Ingresaron este año" :valor="(string) $stats['esteAnio']" tono="slate" icono="calendar-days" />
    </div>

    {{-- ================= Listado de empleados ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">
                {{ $stats['total'] }} empleado(s) en total
            </h3>

            <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar nombre, cargo o correo…"
                   class="rounded-xl border-gray-200 text-sm sm:w-64 focus:border-indigo-500 focus:ring-indigo-500"
                   aria-label="Buscar empleados">
        </div>

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Nombre</th>
                        <th class="px-5 py-3 font-bold">Cargo</th>
                        <th class="px-5 py-3 font-bold">Contacto</th>
                        <th class="px-5 py-3 font-bold">Ingreso</th>
                        <th class="px-5 py-3 font-bold text-right">Salario</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($empleados as $emp)
                        <tr class="hover:bg-gray-50/70" wire:key="emp-{{ $emp->id }}">
                            <td class="px-5 py-3">
                                <span class="font-semibold text-gray-900 block">{{ $emp->nombre }}</span>
                            </td>
                            <td class="px-5 py-3 text-gray-600">{{ $emp->cargo }}</td>
                            <td class="px-5 py-3 text-gray-600 text-xs leading-relaxed">
                                @if ($emp->telefono) <span class="block">{{ $emp->telefono }}</span> @endif
                                @if ($emp->correo) <span class="block text-gray-400">{{ $emp->correo }}</span> @endif
                                @if (! $emp->telefono && ! $emp->correo) <span class="text-gray-300">—</span> @endif
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap text-gray-600 tabular-nums">
                                {{ $emp->fecha_ingreso?->format('d/m/Y') ?? '—' }}
                            </td>
                            <td class="px-5 py-3 text-right font-bold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ $emp->salario > 0 ? \App\Support\Money::cents($emp->salario) : '—' }}
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="editar({{ $emp->id }})"
                                        class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-700 text-sm font-semibold">
                                    <x-heroicon name="pencil-square" class="w-4 h-4" /> Editar
                                </button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="pedirEliminar({{ $emp->id }})"
                                        class="inline-flex items-center gap-1 text-rose-600 hover:text-rose-700 text-sm font-semibold">
                                    <x-heroicon name="trash" class="w-4 h-4" /> Eliminar
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-14 text-center text-gray-400">
                            @if ($search !== '')
                                Sin resultados para esta búsqueda.
                            @else
                                La tabla <span class="font-medium">empleados</span> llegó vacía desde el sistema
                                anterior: no hay personal registrado todavía.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($empleados as $emp)
                <div class="p-4" wire:key="emp-m-{{ $emp->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900">{{ $emp->nombre }}</p>
                            <p class="text-xs text-indigo-600 font-medium mt-0.5">{{ $emp->cargo }}</p>
                        </div>
                        <p class="font-bold text-gray-900 tabular-nums whitespace-nowrap shrink-0">
                            {{ $emp->salario > 0 ? \App\Support\Money::cents($emp->salario) : '—' }}
                        </p>
                    </div>

                    <div class="mt-2 text-xs text-gray-500 space-y-0.5">
                        @if ($emp->telefono) <p>{{ $emp->telefono }}</p> @endif
                        @if ($emp->correo) <p class="text-gray-400 break-all">{{ $emp->correo }}</p> @endif
                        @if ($emp->fecha_ingreso) <p class="text-gray-400">Ingreso: {{ $emp->fecha_ingreso->format('d/m/Y') }}</p> @endif
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="editar({{ $emp->id }})"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-indigo-50 py-2.5 text-xs font-bold text-indigo-700 min-h-[42px] active:scale-[0.98] transition">
                            <x-heroicon name="pencil-square" class="w-4 h-4" /> Editar
                        </button>
                        <button type="button" wire:click="pedirEliminar({{ $emp->id }})"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px] active:scale-[0.98] transition">
                            <x-heroicon name="trash" class="w-4 h-4" /> Eliminar
                        </button>
                    </div>
                </div>
            @empty
                <div class="py-14 px-4 text-center">
                    <p class="text-gray-400 text-sm">
                        @if ($search !== '')
                            Sin resultados para esta búsqueda.
                        @else
                            No hay personal registrado todavía.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>

        @if ($empleados->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $empleados->links() }}
            </div>
        @endif
    </div>

    {{-- ================= Usuarios del panel (solo lectura) ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100">
            <h3 class="font-bold text-gray-900 text-sm">Acceso al panel</h3>
            <p class="text-xs text-gray-500 mt-0.5">
                Usuarios de <span class="font-medium">usuarios</span> — solo lectura aquí.
                Roles y estado se cambian en <a href="{{ route('configuracion') }}" class="font-bold underline text-indigo-600">Config</a>.
            </p>
        </div>

        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Nombre</th>
                        <th class="px-5 py-3 font-bold">Usuario</th>
                        <th class="px-5 py-3 font-bold">Rol</th>
                        <th class="px-5 py-3 font-bold">Estado</th>
                        <th class="px-5 py-3 font-bold">Último ingreso</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($usuarios as $u)
                        <tr wire:key="usr-{{ $u->id }}" @class(['opacity-60' => $u->estado !== 'activo'])>
                            <td class="px-5 py-3 font-semibold text-gray-900">{{ $u->nombre }}</td>
                            <td class="px-5 py-3 text-gray-600">
                                <span class="block">{{ $u->username }}</span>
                                <span class="block text-xs text-gray-400">{{ $u->email }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span @class([
                                    'inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold',
                                    'bg-indigo-50 text-indigo-700' => $u->rol === 'admin',
                                    'bg-sky-50 text-sky-700'     => $u->rol === 'cajero',
                                    'bg-gray-100 text-gray-600'  => $u->rol === 'empleado',
                                ])>{{ $u->rol }}</span>
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
                            <td class="px-5 py-3 text-gray-500 text-xs tabular-nums whitespace-nowrap">
                                {{ $u->ultimo_login ? \Illuminate\Support\Carbon::parse($u->ultimo_login)->format('d/m/Y H:i') : 'Nunca' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="md:hidden divide-y divide-gray-100">
            @foreach ($usuarios as $u)
                <div class="p-4 flex items-center gap-3" wire:key="usr-m-{{ $u->id }}">
                    <span class="flex items-center justify-center w-10 h-10 rounded-full bg-indigo-500/20 text-indigo-700 font-bold shrink-0">
                        {{ mb_substr($u->nombre, 0, 1) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-gray-900 truncate">{{ $u->nombre }}</p>
                        <p class="text-xs text-gray-400 truncate">{{ $u->username }} · {{ $u->rol }}</p>
                    </div>
                    <span @class([
                        'text-xs font-bold shrink-0',
                        'text-emerald-600' => $u->estado === 'activo',
                        'text-rose-600'    => $u->estado === 'inactivo',
                    ])>{{ $u->estado }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ================= Modal: formulario ================= --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarForm"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">
                        {{ $editandoId ? 'Editar empleado' : 'Nuevo empleado' }}
                    </h3>
                </div>

                <form wire:submit="guardar" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="e-nombre" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Nombre *</label>
                        <input id="e-nombre" type="text" wire:model="nombre" autocomplete="off"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Nombre y apellido">
                        @error('nombre') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="e-cargo" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Cargo *</label>
                        <input id="e-cargo" type="text" wire:model="cargo" autocomplete="off"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Vendedor(a), cajero(a), administrador…">
                        @error('cargo') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="e-tel" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Teléfono</label>
                            <input id="e-tel" type="tel" inputmode="tel" wire:model="telefono" autocomplete="off"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="+57 300 000 0000">
                            @error('telefono') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="e-correo" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Correo</label>
                            <input id="e-correo" type="email" inputmode="email" wire:model="correo" autocomplete="off"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="correo@dominio.com">
                            @error('correo') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="e-salario" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Salario mensual</label>
                            <input id="e-salario" type="number" step="0.01" min="0" inputmode="decimal" wire:model="salario"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="0.00">
                            @error('salario') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="e-ingreso" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Fecha de ingreso</label>
                            <input id="e-ingreso" type="date" wire:model="fechaIngreso"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500">
                            @error('fechaIngreso') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="cerrarForm"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">
                            <x-heroicon name="x-mark" class="w-4 h-4" /> Cancelar
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardar"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 min-h-[48px] active:scale-[0.98] transition">
                            <x-heroicon name="check-circle" class="w-5 h-5" wire:loading.remove wire:target="guardar" />
                            <span wire:loading.remove wire:target="guardar">{{ $editandoId ? 'Guardar cambios' : 'Registrar' }}</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($porEliminar)
        <x-panel.confirmar
            titulo="Eliminar empleado"
            descripcion="Se borra de la tabla empleados. Esto no toca el acceso al panel, que vive en usuarios."
            confirmar="eliminarConfirmado"
            wire="porEliminar" />
    @endif
</div>
