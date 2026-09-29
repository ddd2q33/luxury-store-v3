<div class="space-y-5" @keydown.escape="cerrarForm(); $wire.porEliminar = null">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="archive-box" class="w-6 h-6 text-indigo-600" />
                Categorías
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                {{ $stats['total'] }} categorías · {{ $stats['productos'] }} productos clasificados
            </p>
        </div>
        <button type="button" wire:click="nuevo"
                class="rounded-xl bg-indigo-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-indigo-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-indigo-600/20">
            + Nueva categoría
        </button>
    </div>

    {{-- Aviso de categorías vacías: dato real de esta BD --}}
    @if ($stats['vacias'] > 0)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
            <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
            <p class="text-sm text-amber-800 leading-relaxed">
                <span class="font-bold">{{ $stats['vacias'] }} categorías sin productos.</span>
                Revisa si son duplicados (por ejemplo <em>Calzado</em> y <em>Calzado general</em>) y
                muéveles sus productos o elimínalas para dejar el catálogo limpio.
            </p>
        </div>
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Total categorías" :valor="$stats['total']" tono="indigo" icono="archive-box" />
        <x-panel.stat etiqueta="Con productos" :valor="$stats['conProductos']" tono="emerald" icono="shopping-bag" />
        <x-panel.stat etiqueta="Vacías" :valor="$stats['vacias']" tono="amber" icono="exclamation-triangle" />
        <x-panel.stat etiqueta="Productos" :valor="$stats['productos']" />
    </div>

    {{-- Búsqueda + filtros + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">Catálogo de categorías</h3>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar categoría…"
                       class="rounded-xl border-gray-200 text-sm sm:w-56 focus:border-indigo-500 focus:ring-indigo-500">

                <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl overflow-x-auto">
                    @php
                        $filtros = [
                            'todas'          => 'Todas',
                            'con_productos'  => 'Con productos',
                            'vacias'         => 'Vacías',
                        ];
                    @endphp
                    @foreach ($filtros as $valor => $texto)
                        <button type="button" wire:click="$set('filtro', '{{ $valor }}')"
                                class="px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap min-h-[38px] transition"
                                @class([
                                    'bg-white text-indigo-600 shadow-sm' => $filtro === $valor,
                                    'text-gray-500 hover:text-gray-700'   => $filtro !== $valor,
                                ])>
                            {{ $texto }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Categoría</th>
                        <th class="px-5 py-3 font-bold">Descripción</th>
                        <th class="px-5 py-3 font-bold text-right">Productos</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($categorias as $categoria)
                        <tr class="hover:bg-gray-50/70" wire:key="cat-{{ $categoria->id }}">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 shrink-0">
                                        <x-heroicon name="archive-box" class="w-5 h-5" />
                                    </span>
                                    <span class="font-semibold text-gray-900">{{ $categoria->nombre }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-gray-500 max-w-md">
                                <span class="line-clamp-1">{{ $categoria->descripcion ?: '—' }}</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                @if ($categoria->productos_count > 0)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 tabular-nums">
                                        {{ $categoria->productos_count }}
                                    </span>
                                @else
                                    <span class="text-xs font-semibold text-gray-400">Vacía</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="editar({{ $categoria->id }})" class="text-indigo-600 hover:text-indigo-700 text-sm font-semibold">Editar</button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="pedirEliminar({{ $categoria->id }})" class="text-rose-600 hover:text-rose-700 text-sm font-semibold">Eliminar</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-14 text-center text-gray-400">
                            {{ $search !== '' || $filtro !== 'todas' ? 'Sin resultados para este filtro.' : 'Aún no hay categorías.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($categorias as $categoria)
                <div class="p-4" wire:key="cat-m-{{ $categoria->id }}">
                    <div class="flex items-center gap-3">
                        <span class="flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 shrink-0">
                            <x-heroicon name="archive-box" class="w-5 h-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 truncate">{{ $categoria->nombre }}</p>
                            <p class="text-xs text-gray-400">
                                @if ($categoria->productos_count > 0)
                                    {{ $categoria->productos_count }} producto(s)
                                @else
                                    Sin productos
                                @endif
                            </p>
                        </div>
                        @if ($categoria->productos_count > 0)
                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 tabular-nums shrink-0">
                                {{ $categoria->productos_count }}
                            </span>
                        @endif
                    </div>

                    @if ($categoria->descripcion)
                        <p class="mt-2 text-xs text-gray-500 leading-relaxed line-clamp-2">{{ $categoria->descripcion }}</p>
                    @endif

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="editar({{ $categoria->id }})"
                                class="rounded-lg bg-indigo-50 py-2.5 text-xs font-bold text-indigo-700 min-h-[42px] active:scale-[0.98] transition">Editar</button>
                        <button type="button" wire:click="pedirEliminar({{ $categoria->id }})"
                                class="rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px] active:scale-[0.98] transition">Eliminar</button>
                    </div>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin categorías.</p>
            @endforelse
        </div>

        @if ($categorias->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $categorias->links() }}
            </div>
        @endif
    </div>

    {{-- ================= Modal: formulario ================= --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarForm"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">
                        {{ $editandoId ? 'Editar categoría' : 'Nueva categoría' }}
                    </h3>
                </div>

                <form wire:submit="guardar" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="cat-nombre" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Nombre *</label>
                        <input id="cat-nombre" type="text" wire:model="nombre"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Calzado, Ropa, Accesorios…" autocomplete="off">
                        @error('nombre') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="cat-desc" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Descripción</label>
                        <textarea id="cat-desc" wire:model="descripcion" rows="3"
                                  class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="Para qué sirve esta categoría, qué entra en ella…"></textarea>
                        @error('descripcion') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="cerrarForm"
                                class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardar"
                                class="rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 min-h-[48px] active:scale-[0.98] transition">
                            <span wire:loading.remove wire:target="guardar">{{ $editandoId ? 'Guardar cambios' : 'Crear categoría' }}</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($porEliminar)
        <x-panel.confirmar
            titulo="Eliminar categoría"
            descripcion="La categoría se borrará del catálogo. Solo es posible si no tiene productos asociados."
            confirmar="eliminarConfirmado"
            wire="porEliminar" />
    @endif
</div>
