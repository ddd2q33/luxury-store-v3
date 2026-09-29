@props(['evento' => 'panel-toast'])

{{-- Notificación flotante compartida. Todos los módulos del panel usan el
     mismo evento y este componente, para que el estilo nunca se disperse.
     Errores Livewire (que no pasan por el evento) se detectan aquí. --}}
<div x-data="{ visible: false, mensaje: '', tipo: 'ok', t: null }"
     @{{ $evento }}.window="mensaje = ($event.detail && $event.detail.mensaje) || 'Listo'; tipo = ($event.detail && $event.detail.tipo) || 'ok'; visible = true; clearTimeout(t); t = setTimeout(() => visible = false, 3500)"
     x-on:error="visible = true; clearTimeout(t); t = setTimeout(() => visible = false, 4000); tipo = 'error'; mensaje = ($event.detail && $event.detail.message) || Object.values(($event.detail && $event.detail.errors) || {})[0]?.[0] || 'Revisa los datos del formulario.'"
     x-show="visible" x-cloak x-transition.opacity.duration.200ms
     class="fixed bottom-6 right-4 z-[70] max-w-xs">

    {{-- nowrap: los mensajes largos no deben empujar el borde en móvil --}}
    <div class="rounded-2xl px-4 py-3 shadow-2xl text-sm font-medium flex items-start gap-2.5 border"
         :class="tipo === 'error'
             ? 'bg-rose-600 border-rose-500 text-white'
             : 'bg-emerald-600 border-emerald-500 text-white'">
        <span class="flex items-center justify-center w-5 h-5 rounded-full bg-white/20 text-xs shrink-0 font-bold"
              x-text="tipo === 'error' ? '✕' : '✓'"></span>
        <span x-text="mensaje"></span>
    </div>
</div>
