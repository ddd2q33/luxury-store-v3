<div class="bg-white rounded-2xl border border-gray-200 p-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <h3 class="font-semibold text-gray-900">Últimas ventas</h3>
        <div class="relative sm:w-64">
            <input type="search"
                   wire:model.debounce.400ms="search"
                   placeholder="Buscar cliente o #venta..."
                   class="w-full rounded-xl border-gray-200 text-sm pl-9 focus:border-indigo-500 focus:ring-indigo-500">
            <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
        </div>
    </div>

    {{-- Escritorio: tabla --}}
    <div class="hidden md:block overflow-hidden">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
                    <th class="py-2.5 pr-4 font-semibold">#</th>
                    <th class="py-2.5 pr-4 font-semibold">Cliente</th>
                    <th class="py-2.5 pr-4 font-semibold">Fecha</th>
                    <th class="py-2.5 pr-4 font-semibold text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($ventas as $venta)
                    <tr class="hover:bg-gray-50">
                        <td class="py-3 pr-4 text-gray-500">{{ $venta->id }}</td>
                        <td class="py-3 pr-4 font-medium text-gray-900">{{ $venta->cliente_nombre ?: 'Consumidor Final' }}</td>
                        <td class="py-3 pr-4 text-gray-500">{{ $venta->fecha_venta->format('d/m/Y H:i') }}</td>
                        <td class="py-3 pr-4 text-right font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::format($venta->total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-500">Sin resultados para "{{ $search }}".</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Móvil: tarjetas --}}
    <div class="md:hidden space-y-2">
        @forelse ($ventas as $venta)
            <div class="rounded-xl border border-gray-200 p-3.5">
                <div class="flex items-center justify-between gap-3">
                    <span class="font-medium text-gray-900 truncate">{{ $venta->cliente_nombre ?: 'Consumidor Final' }}</span>
                    <span class="font-bold text-gray-900 tabular-nums shrink-0">{{ \App\Support\Money::format($venta->total) }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-xs text-gray-500">
                    <span>Venta #{{ $venta->id }}</span>
                    <span>{{ $venta->fecha_venta->format('d/m/Y H:i') }}</span>
                </div>
            </div>
        @empty
            <p class="py-6 text-center text-gray-500">Sin resultados para "{{ $search }}".</p>
        @endforelse
    </div>

    {{-- Paginación --}}
    <div class="mt-4">
        {{ $ventas->links() }}
    </div>
</div>
