<div class="space-y-5" @keydown.escape="[cerrarForm, cerrarDetalle].forEach(f => $wire[f]()); $wire.porEliminar = null">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="shopping-bag" class="w-6 h-6 text-indigo-600" />
                Productos
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                {{ $stats['total'] }} productos · {{ number_format($stats['unidades'], 0, ',', '.') }} unidades en stock
            </p>
        </div>
        <button type="button" wire:click="nuevo"
                class="rounded-xl bg-indigo-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-indigo-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-indigo-600/20">
            + Nuevo producto
        </button>
    </div>

    {{-- Alertas sobre datos reales del catálogo --}}
    @if ($stats['sinPrecio'] > 0 || $stats['negativos'] > 0)
        <div class="grid gap-3 sm:grid-cols-2">
            @if ($stats['sinPrecio'] > 0)
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
                    <x-heroicon name="currency-dollar" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                    <p class="text-sm text-amber-800 leading-relaxed">
                        <span class="font-bold">{{ $stats['sinPrecio'] }} productos sin precio.</span>
                        La valoración del inventario aparece en $0 hasta que les asignes precio.
                    </p>
                </div>
            @endif
            @if ($stats['negativos'] > 0)
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 flex items-start gap-3">
                    <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
                    <p class="text-sm text-rose-800 leading-relaxed">
                        <span class="font-bold">{{ $stats['negativos'] }} productos con stock negativo</span>
                        (saldo heredado del sistema viejo). Corrígelos con un ajuste de inventario.
                    </p>
                </div>
            @endif
        </div>
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Productos" :valor="$stats['total']" tono="indigo" icono="shopping-bag" />
        <x-panel.stat etiqueta="Unidades" :valor="number_format($stats['unidades'], 0, ',', '.')" tono="slate" icono="archive-box" />
        <x-panel.stat etiqueta="Valor inventario" :valor="\App\Support\Money::format($stats['valor'])" tono="emerald" icono="banknotes" />
        <x-panel.stat etiqueta="Agotados" :valor="$stats['agotados']" tono="amber" icono="exclamation-triangle" />
    </div>

    {{-- Filtros + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 space-y-3">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                <h3 class="font-bold text-gray-900 text-sm">Catálogo</h3>

                <div class="flex flex-col sm:flex-row gap-2.5">
                    <select wire:model.live="filtroCategoria"
                            class="rounded-xl border-gray-200 text-sm sm:w-44 focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Todas las categorías</option>
                        @foreach ($categorias as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="orden"
                            class="rounded-xl border-gray-200 text-sm sm:w-40 focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="nombre">Nombre A–Z</option>
                        <option value="stock">Menor stock</option>
                        <option value="precio">Precio</option>
                    </select>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search"
                       placeholder="Nombre, proveedor, descripción…"
                       class="rounded-xl border-gray-200 text-sm flex-1 focus:border-indigo-500 focus:ring-indigo-500">

                <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl overflow-x-auto">
                    @php
                        $estados = [
                            ''           => 'Todos',
                            'agotado'    => 'Agotados',
                            'bajo'       => 'Stock bajo',
                            'negativo'   => 'Negativos',
                            'sin_precio' => 'Sin precio',
                        ];
                    @endphp
                    @foreach ($estados as $valor => $texto)
                        <button type="button" wire:click="$set('filtroEstado', '{{ $valor }}')"
                                class="px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap min-h-[38px] transition"
                                @class([
                                    'bg-white text-indigo-600 shadow-sm' => $filtroEstado === $valor,
                                    'text-gray-500 hover:text-gray-700'   => $filtroEstado !== $valor,
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
                        <th class="px-5 py-3 font-bold">Producto</th>
                        <th class="px-5 py-3 font-bold">Categoría</th>
                        <th class="px-5 py-3 font-bold text-right">Precio</th>
                        <th class="px-5 py-3 font-bold text-right">Stock</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($productos as $producto)
                        @php $estado = $producto->estadoStock(); @endphp
                        <tr class="hover:bg-gray-50/70" wire:key="prod-{{ $producto->id }}">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    @if ($producto->tiene_imagen)
                                        <img src="{{ route('productos.imagen', $producto->id) }}" alt=""
                                             loading="lazy"
                                             class="w-9 h-9 rounded-xl object-cover border border-gray-200 shrink-0 bg-gray-50">
                                    @else
                                        <span class="flex items-center justify-center w-9 h-9 rounded-xl shrink-0 @switch($estado)
                                                @case('ok') bg-emerald-50 text-emerald-600 @break
                                                @case('bajo') bg-amber-50 text-amber-600 @break
                                                @case('agotado') bg-gray-100 text-gray-400 @break
                                                @default bg-rose-50 text-rose-600 @endswitch">
                                            <x-heroicon name="shopping-bag" class="w-5 h-5" />
                                        </span>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="font-semibold text-gray-900 truncate">{{ $producto->nombre }}</p>
                                        @if ($producto->proveedor)
                                            <p class="text-xs text-gray-400 truncate">{{ $producto->proveedor }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-gray-600">
                                <span class="inline-block rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600">
                                    {{ $producto->categoria?->nombre ?? '—' }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums">
                                @if ($producto->precio)
                                    <span class="font-bold text-gray-900">{{ \App\Support\Money::format((float) $producto->precio) }}</span>
                                @else
                                    <span class="text-xs font-semibold text-amber-600">Sin precio</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                <span class="font-bold tabular-nums @switch($estado)
                                        @case('ok') text-emerald-600 @break
                                        @case('bajo') text-amber-600 @break
                                        @case('agotado') text-gray-400 @break
                                        @default text-rose-600 @endswitch">{{ $producto->stock }}</span>
                                @if ($estado !== 'ok')
                                    <span class="block text-[10px] font-bold uppercase tracking-wide
                                        @switch($estado)
                                        @case('bajo') text-amber-600 @break
                                        @case('agotado') text-gray-400 @break
                                        @default text-rose-600 @endswitch">
                                        {{ \App\Models\Producto::etiquetaEstadoStock($estado) }}
                                    </span>
                                @elseif ($producto->stock_minimo > 0)
                                    <span class="block text-[10px] text-gray-400 tabular-nums">mín. {{ $producto->stock_minimo }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="verDetalle({{ $producto->id }})" class="text-indigo-600 hover:text-indigo-700 text-sm font-semibold">Ver</button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="editar({{ $producto->id }})" class="text-gray-600 hover:text-gray-800 text-sm font-semibold">Editar</button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="pedirEliminar({{ $producto->id }})" class="text-rose-600 hover:text-rose-700 text-sm font-semibold">Eliminar</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-14 text-center text-gray-400">
                            {{ ($search !== '' || $filtroCategoria !== '' || $filtroEstado !== '') ? 'Sin resultados para este filtro.' : 'Aún no hay productos.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($productos as $producto)
                @php $estado = $producto->estadoStock(); @endphp
                <div class="p-4" wire:key="prod-m-{{ $producto->id }}">
                    <div class="flex items-start gap-3">
                        @if ($producto->tiene_imagen)
                            <img src="{{ route('productos.imagen', $producto->id) }}" alt=""
                                 loading="lazy"
                                 class="w-10 h-10 rounded-xl object-cover border border-gray-200 shrink-0 bg-gray-50">
                        @else
                            <span class="flex items-center justify-center w-10 h-10 rounded-xl shrink-0 @switch($estado)
                                    @case('ok') bg-emerald-50 text-emerald-600 @break
                                    @case('bajo') bg-amber-50 text-amber-600 @break
                                    @case('agotado') bg-gray-100 text-gray-400 @break
                                    @default bg-rose-50 text-rose-600 @endswitch">
                                <x-heroicon name="shopping-bag" class="w-5 h-5" />
                            </span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 leading-snug">{{ $producto->nombre }}</p>
                            <p class="text-xs text-gray-400 mt-0.5 truncate">
                                {{ $producto->categoria?->nombre ?? 'Sin categoría' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Precio</p>
                            <p class="text-sm font-bold text-gray-900 tabular-nums">
                                {{ $producto->precio ? \App\Support\Money::format((float) $producto->precio) : '—' }}
                            </p>
                        </div>
                        <div class="rounded-xl py-2 @switch($estado)
                                @case('ok') bg-emerald-50 @break
                                @case('bajo') bg-amber-50 @break
                                @case('agotado') bg-gray-50 @break
                                @default bg-rose-50 @endswitch">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Stock</p>
                            <p class="text-sm font-bold tabular-nums @switch($estado)
                                    @case('ok') text-emerald-600 @break
                                    @case('bajo') text-amber-600 @break
                                    @case('agotado') text-gray-400 @break
                                    @default text-rose-600 @endswitch">{{ $producto->stock }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Mínimo</p>
                            <p class="text-sm font-bold text-gray-600 tabular-nums">{{ $producto->stock_minimo }}</p>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <button type="button" wire:click="verDetalle({{ $producto->id }})" class="rounded-lg bg-gray-100 py-2.5 text-xs font-bold text-gray-700 min-h-[42px] active:scale-[0.98] transition">Ver</button>
                        <button type="button" wire:click="editar({{ $producto->id }})" class="rounded-lg bg-indigo-50 py-2.5 text-xs font-bold text-indigo-700 min-h-[42px] active:scale-[0.98] transition">Editar</button>
                        <button type="button" wire:click="pedirEliminar({{ $producto->id }})" class="rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px] active:scale-[0.98] transition">Eliminar</button>
                    </div>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin productos.</p>
            @endforelse
        </div>

        @if ($productos->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $productos->links() }}
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
                        {{ $editandoId ? 'Editar producto' : 'Nuevo producto' }}
                    </h3>
                </div>

                <form wire:submit="guardar" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="prod-nombre" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Nombre *</label>
                        <input id="prod-nombre" type="text" wire:model="nombre"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Nike Air Max 90" autocomplete="off">
                        @error('nombre') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="prod-cat" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Categoría *</label>
                        <select id="prod-cat" wire:model="categoria_id"
                                class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Selecciona una categoría…</option>
                            @foreach ($categorias as $id => $nombreCat)
                                <option value="{{ $id }}">{{ $nombreCat }}</option>
                            @endforeach
                        </select>
                        @error('categoria_id') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label for="prod-precio" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Precio</label>
                            <input id="prod-precio" type="number" wire:model="precio" inputmode="numeric" step="0.01" min="0"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="0.00">
                            @error('precio') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="prov" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Proveedor</label>
                            <input id="prov" type="text" wire:model="proveedor"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Nombre del proveedor" autocomplete="off">
                        </div>
                    </div>

                    {{-- Stock: solo editable al crear. Al editar va bloqueado a propósito
                         para que todo cambio de existencias pase por el ledger. --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label for="prod-stock" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">
                                Stock {{ $editandoId ? '(solo lectura)' : 'inicial' }}
                            </label>
                            <input id="prod-stock" type="number" wire:model="stock" inputmode="numeric" min="0" step="1"
                                   @disabled($editandoId)
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-400 disabled:cursor-not-allowed">
                            @error('stock') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="prod-min" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Stock mínimo</label>
                            <input id="prod-min" type="number" wire:model="stock_minimo" inputmode="numeric" min="0" step="1"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Aviso al bajar de aquí">
                            @error('stock_minimo') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($editandoId)
                        <p class="rounded-xl bg-indigo-50 border border-indigo-100 px-3.5 py-2.5 text-xs text-indigo-800 leading-relaxed">
                            El stock no se modifica desde aquí. Usa <strong>Ingreso</strong> para sumar mercancía,
                            o <strong>Stock → Ajustar</strong> para un conteo físico. Así cada cambio queda
                            registrado en el historial de inventario.
                        </p>
                    @endif

                    <div>
                        <label for="prod-desc" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Descripción</label>
                        <textarea id="prod-desc" wire:model="descripcion" rows="2"
                                  class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="Material, tallas, colores, referencias…"></textarea>
                    </div>

                    {{-- Imagen OPCIONAL: se usa en el catálogo PDF. Si no se
                         sube ninguna, el catálogo pone un marcador de posición. --}}
                    <div>
                        <label for="prod-imagen" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">
                            Imagen del producto <span class="normal-case font-normal text-gray-400">(opcional)</span>
                        </label>

                        <div class="flex items-start gap-3.5">
                            @if ($preview = $this->previewImagen())
                                <img src="{{ $preview }}" alt="Vista previa"
                                     class="w-20 h-20 rounded-xl object-cover border border-gray-200 shrink-0 bg-gray-50">
                            @else
                                <span class="w-20 h-20 rounded-xl border border-dashed border-gray-300 bg-gray-50 flex items-center justify-center text-gray-300 shrink-0">
                                    <x-heroicon name="photo" class="w-7 h-7" />
                                </span>
                            @endif

                            <div class="flex-1 min-w-0">
                                <input id="prod-imagen" type="file" wire:model="imagenArchivo"
                                       accept="image/png,image/jpeg,image/webp,image/gif"
                                       class="block w-full text-xs text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2.5 file:text-xs file:font-bold file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer min-h-[44px]">
                                <p class="text-xs text-gray-400 mt-1.5">
                                    JPG, PNG, WEBP o GIF. Hasta 2 MB. Se guarda en la base de datos y se muestra en el catálogo PDF.
                                </p>

                                @if ($this->quitarImagen)
                                    <p class="text-xs font-semibold text-rose-600 mt-1.5">
                                        La imagen se eliminará al guardar.
                                    </p>
                                @endif
                            </div>
                        </div>

                        @error('imagenArchivo')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror

                        @if ($preview = $this->previewImagen())
                            <button type="button" wire:click="$set('quitarImagen', true); $set('imagenArchivo', null)"
                                    wire:loading.remove wire:target="imagenArchivo"
                                    class="mt-2.5 inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-rose-600 min-h-[38px] hover:bg-rose-100 transition">
                                <x-heroicon name="trash" class="w-4 h-4" />
                                Quitar imagen
                            </button>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="cerrarForm"
                                class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardar"
                                class="rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 min-h-[48px] active:scale-[0.98] transition">
                            <span wire:loading.remove wire:target="guardar">{{ $editandoId ? 'Guardar cambios' : 'Crear producto' }}</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ================= Modal: detalle con historial ================= --}}
    @if ($showDetalle && $productoDetalle)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarDetalle"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4 border-b border-gray-100">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <div class="flex items-start gap-3.5">
                        <span class="flex items-center justify-center w-12 h-12 rounded-2xl bg-indigo-600 text-white shrink-0">
                            <x-heroicon name="shopping-bag" class="w-6 h-6" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-lg font-bold text-gray-900 leading-snug">{{ $productoDetalle['nombre'] }}</h3>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ $productoDetalle['categoria'] }}
                                @if ($productoDetalle['proveedor'])
                                    · {{ $productoDetalle['proveedor'] }}
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl bg-gray-50 py-2.5">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Precio</p>
                            <p class="text-sm font-bold text-gray-900 tabular-nums">
                                {{ $productoDetalle['precio'] ? \App\Support\Money::format($productoDetalle['precio']) : 'Sin precio' }}
                            </p>
                        </div>
                        <div class="rounded-xl py-2.5 @switch($productoDetalle['estado'])
                                @case('ok') bg-emerald-50 @break
                                @case('bajo') bg-amber-50 @break
                                @case('agotado') bg-gray-50 @break
                                @default bg-rose-50 @endswitch">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Stock</p>
                            <p class="text-sm font-bold tabular-nums @switch($productoDetalle['estado'])
                                    @case('ok') text-emerald-600 @break
                                    @case('bajo') text-amber-600 @break
                                    @case('agotado') text-gray-400 @break
                                    @default text-rose-600 @endswitch">{{ $productoDetalle['stock'] }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 py-2.5">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Mínimo</p>
                            <p class="text-sm font-bold text-gray-600 tabular-nums">{{ $productoDetalle['stock_minimo'] }}</p>
                        </div>
                    </div>

                    @if ($productoDetalle['descripcion'])
                        <p class="mt-3 rounded-xl bg-gray-50 border border-gray-100 px-3.5 py-2.5 text-sm text-gray-600 leading-relaxed">
                            {{ $productoDetalle['descripcion'] }}
                        </p>
                    @endif
                </div>

                <div class="p-6 overflow-y-auto flex-1">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">
                        Historial de movimientos ({{ $productoDetalle['totalMovimientos'] }})
                    </p>

                    @if (count($productoDetalle['movimientos']) > 0)
                        <ul class="divide-y divide-gray-100 text-sm">
                            @foreach ($productoDetalle['movimientos'] as $m)
                                <li class="py-2.5">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="inline-flex items-center gap-2 font-semibold
                                            @switch($m['tipo'])
                                                @case('Entrada') text-emerald-600 @break
                                                @case('Salida')  text-rose-600 @break
                                                @default text-indigo-600 @endswitch">
                                            <x-heroicon name="@switch($m['tipo']) @case('Entrada') trending-up @break @case('Salida') trending-down @break @default chart-bar @endswitch" class="w-4 h-4" />
                                            {{ $m['tipo'] }}
                                        </span>
                                        <span class="text-xs text-gray-400 tabular-nums">{{ $m['fecha'] }}</span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5 tabular-nums">
                                        Cantidad: <span class="font-bold">{{ $m['cantidad'] }}</span>
                                        @if ($m['observaciones'])
                                            <span class="block text-gray-400 mt-0.5">{{ $m['observaciones'] }}</span>
                                        @endif
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-gray-400 py-3">
                            Sin movimientos registrados. Los ingresos, salidas y ajustes quedan aquí.
                        </p>
                    @endif
                </div>

                <div class="p-6 pt-4 grid grid-cols-2 gap-3 border-t border-gray-100">
                    <button type="button" wire:click="editar({{ $productoDetalle['id'] }}); cerrarDetalle()"
                            class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Editar</button>
                    <button type="button" wire:click="cerrarDetalle"
                            class="rounded-xl bg-gray-900 py-3 font-bold text-white min-h-[48px] active:scale-[0.98] transition">Cerrar</button>
                </div>
            </div>
        </div>
    @endif

    @if ($porEliminar)
        <x-panel.confirmar
            titulo="Eliminar producto"
            descripcion="El producto se borrará del catálogo. Solo es posible si nunca ha tenido movimientos de inventario."
            confirmar="eliminarConfirmado"
            wire="porEliminar" />
    @endif
</div>
