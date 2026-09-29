{{--
    Barra móvil. Réplica de `oldluxury/views/partials/app_nav.php:181-186`:
    hamburguesa, hueco elástico, cerrar sesión y avatar. SIN título — en el
    legacy tampoco lo hay, porque el título de cada módulo va dentro de su
    propia vista Livewire.

    Solo se muestra en modo "app móvil" (`movil`), no por ancho de ventana: una
    ventana angosta en escritorio mantiene el sidebar fijo, como en el legacy.

    El logout y el avatar SÓLO viven aquí: el sidebar de escritorio no lleva
    bloque de usuario, igual que el legacy.
--}}
<div class="panel-mobilebar sticky top-0 z-[55] flex items-center gap-1 bg-white/95 backdrop-blur border-b border-gray-200 px-3 py-2"
     style="padding-top: calc(0.5rem + env(safe-area-inset-top));
            min-height: calc(52px + env(safe-area-inset-top));"
     x-show="movil"
     x-cloak>
    <button type="button"
            class="panel-mobilebar-btn flex items-center justify-center w-[38px] h-[38px] rounded-xl text-slate-700 active:bg-slate-100 transition-colors"
            @click="open = true"
            aria-label="Abrir menú">
        <x-heroicon name="bars-3" class="w-[18px] h-[18px]" />
    </button>

    <div class="flex-1"></div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit"
                class="flex items-center justify-center w-[38px] h-[38px] rounded-xl text-rose-600 active:bg-rose-100 transition-colors"
                aria-label="Cerrar sesión"
                title="Cerrar sesión">
            <x-heroicon name="power" class="w-4 h-4" />
        </button>
    </form>

    <a href="{{ url('profile') }}"
       aria-label="Mi perfil"
       title="Mi perfil"
       class="shrink-0">
        <img src="{{ Auth::user()?->avatarUrl() }}"
             alt="{{ Auth::user()?->nombre }}"
             class="panel-mobilebar-avatar w-8 h-8 rounded-[10px] object-cover border-2 border-white"
             style="box-shadow: 0 0 0 1px #e2e8f0;">
    </a>
</div>
