<div class="space-y-5">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="book-open" class="w-6 h-6 text-indigo-600" />
                Catálogo virtual
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                Toma los productos del catálogo y arma un PDF para compartir o imprimir.
            </p>
        </div>
    </div>

    {{-- Avisos sobre datos reales: sin precio y sin foto --}}
    <div class="grid gap-3 sm:grid-cols-2">
        @if ($resumen['sinPrecio'] > 0)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
                <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                <p class="text-sm text-amber-800 leading-relaxed">
                    <span class="font-bold">{{ number_format($resumen['sinPrecio'], 0, ',', '.') }} productos sin precio.</span>
                    El legacy nunca cargó los precios, así que en el PDF saldrán como
                    <em>Sin precio</em> en vez de $0. Asígnalos en
                    <a href="{{ route('productos') }}" class="font-bold underline">Productos</a> y el catálogo mejora.
                </p>
            </div>
        @endif
        @if ($resumen['sinImagen'] > 0)
            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 flex items-start gap-3">
                <x-heroicon name="photo" class="w-5 h-5 text-indigo-500 shrink-0 mt-0.5" />
                <p class="text-sm text-indigo-800 leading-relaxed">
                    <span class="font-bold">{{ number_format($resumen['sinImagen'], 0, ',', '.') }} productos sin imagen.</span>
                    La imagen es opcional: sin foto el PDF muestra un marcador de posición.
                    Agrégalas desde <a href="{{ route('productos') }}" class="font-bold underline">Productos → Editar</a>.
                </p>
            </div>
        @endif
    </div>

    {{-- ================= Filtros ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 space-y-4">
            <h3 class="font-bold text-gray-900 text-sm">¿Qué va en el catálogo?</h3>

            <div class="space-y-2.5">
                <label class="flex items-start gap-3 cursor-pointer min-h-[44px] py-1.5">
                    <input type="checkbox" wire:model.live="soloDisponibles"
                           class="mt-0.5 w-5 h-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 shrink-0">
                    <span>
                        <span class="block text-sm font-semibold text-gray-800">Solo lo que hay disponible</span>
                        <span class="block text-xs text-gray-500">
                            Excluye los agotados. Desmárcalo si quieres el catálogo completo, con lo que no está disponible.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-3 cursor-pointer min-h-[44px] py-1.5">
                    <input type="checkbox" wire:model.live="soloConImagen"
                           class="mt-0.5 w-5 h-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 shrink-0">
                    <span>
                        <span class="block text-sm font-semibold text-gray-800">Solo productos con imagen</span>
                        <span class="block text-xs text-gray-500">
                            Deja fuera los que todavía no tienen foto. Sirve para limpiar el catálogo antes de imprimirlo.
                        </span>
                    </span>
                </label>
            </div>

            <div>
                <label for="cat-categoria" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">
                    Categoría
                </label>
                <select id="cat-categoria" wire:model.live="categoriaId"
                        class="rounded-xl border-gray-200 text-sm w-full sm:w-72 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Todas las categorías</option>
                    @foreach ($categorias as $id => $nombreCat)
                        <option value="{{ $id }}">{{ $nombreCat }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Resumen de lo que se va a exportar + botón --}}
        <div class="p-4 sm:p-5 bg-gray-50/60 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-sm font-bold text-gray-900">
                    {{ number_format($total, 0, ',', '.') }}
                    {{ $total === 1 ? 'producto' : 'productos' }} en el PDF
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ number_format($conImagen, 0, ',', '.') }} con imagen ·
                    {{ number_format($sinPrecio, 0, ',', '.') }} sin precio ·
                    archivo {{ $nombreArchivo }}
                </p>
            </div>

            <div class="flex flex-col sm:flex-row gap-2.5">
                <button type="button" wire:click="reiniciar"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 min-h-[44px] hover:bg-gray-50 transition">
                    <x-heroicon name="arrow-path" class="w-4 h-4" />
                    Limpiar filtros
                </button>
                <a href="{{ $urlPdf }}" target="_blank" rel="noopener"
                   @class([
                       'rounded-xl px-5 py-2.5 text-sm font-bold min-h-[44px] transition inline-flex items-center justify-center gap-2 shadow-lg',
                       'bg-indigo-600 text-white hover:bg-indigo-700 shadow-indigo-600/20' => $total > 0,
                       'bg-gray-300 text-gray-500 cursor-not-allowed shadow-none pointer-events-none' => $total === 0,
                   ])>
                    <x-heroicon name="printer" class="w-4 h-4" />
                    Descargar PDF
                </a>
            </div>
        </div>

        {{-- ================= Vista previa (las primeras tarjetas del PDF) ================= --}}
        <div class="p-4 sm:p-5">
            <h3 class="font-bold text-gray-900 text-sm mb-1">Vista previa</h3>
            <p class="text-xs text-gray-500 mb-4">
                @if ($total > 24)
                    Primeras 24 tarjetas de {{ number_format($total, 0, ',', '.') }}. El PDF lleva todas.
                @else
                    Así se verá el catálogo en el PDF.
                @endif
            </p>

            @if ($total === 0)
                <div class="py-14 text-center text-gray-400 text-sm">
                    No hay productos con estos filtros.
                    @if ($soloConImagen)
                        Prueba a desmarcar <em>Solo productos con imagen</em>.
                    @endif
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    @foreach ($productos as $producto)
                        <div class="border border-gray-200 rounded-xl overflow-hidden bg-white flex flex-col">
                            <div class="aspect-square bg-gray-50 flex items-center justify-center overflow-hidden">
                                @if ($producto['imagen'])
                                    <img src="{{ $producto['imagen'] }}" alt="{{ $producto['nombre'] }}"
                                         class="w-full h-full object-cover">
                                @else
                                    <x-heroicon name="photo" class="w-8 h-8 text-gray-300" />
                                @endif
                            </div>
                            <div class="p-2.5 flex-1 flex flex-col">
                                <p class="text-xs text-gray-400 truncate">{{ $producto['categoria'] }}</p>
                                <p class="text-sm font-semibold text-gray-900 leading-snug line-clamp-2">
                                    {{ $producto['nombre'] }}
                                </p>
                                <p class="mt-1.5 text-sm font-bold tabular-nums
                                          {{ $producto['precio'] ? 'text-gray-900' : 'text-amber-600' }}">
                                    {{ $producto['precio'] ? \App\Support\Money::format($producto['precio']) : 'Sin precio' }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
