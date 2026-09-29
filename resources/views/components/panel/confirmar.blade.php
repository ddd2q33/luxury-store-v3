@props([
    'titulo',
    'descripcion',
    'confirmar',
    'textoConfirmar' => 'Eliminar',
    'wire' => null,
])

{{-- Confirmación destructiva. El overlay va a z-[60] para quedar por encima de
     los formularios (z-50) sin apilar otra capa de fondo. --}}
<div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center"
     role="dialog" aria-modal="true" @keydown.escape.window="{{ $wire ? '$set('.chr(39).$wire.chr(39).', null)' : '' }}">

    <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm"
         @click="{{ $wire ? '$set('.chr(39).$wire.chr(39).', null)' : '' }}"></div>

    <div class="relative w-full sm:max-w-sm bg-white rounded-t-3xl sm:rounded-3xl p-6 shadow-2xl">
        <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>

        <div class="flex items-center gap-3">
            <span class="flex items-center justify-center w-11 h-11 rounded-2xl bg-rose-50 shrink-0">
                <x-heroicon name="exclamation-triangle" class="w-6 h-6 text-rose-500" />
            </span>
            <h3 class="text-lg font-bold text-gray-900">{{ $titulo }}</h3>
        </div>

        <p class="mt-3 text-sm text-gray-600 leading-relaxed">{{ $descripcion }}</p>

        <div class="mt-5 grid grid-cols-2 gap-3">
            <button type="button"
                    wire:click="{{ $wire ? '$set('.chr(39).$wire.chr(39).', null)' : '' }}"
                    class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">
                Cancelar
            </button>
            <button type="button" wire:click="{{ $confirmar }}"
                    wire:loading.attr="disabled" wire:target="{{ $confirmar }}"
                    class="rounded-xl bg-rose-600 py-3 font-bold text-white hover:bg-rose-700 min-h-[48px] active:scale-[0.98] transition">
                {{ $textoConfirmar }}
            </button>
        </div>
    </div>
</div>
