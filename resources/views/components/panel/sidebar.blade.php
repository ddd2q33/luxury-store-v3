{{--
    Sidebar de escritorio. Réplica de `oldluxury/views/partials/app_nav.php:154`:
    fondo negro degradado, logo centrado arriba, rejilla de 4 columnas, borde
    derecho, sombra y NINGÚN bloque de usuario al pie (en el legacy eso vive
    solo en la barra móvil, y `x-panel.mobile-bar` ya lo tiene).

    El contenedor de este <aside> es el que se desplaza al colapsar
    (`.panel-sidebar-colapsable`), por eso aquí no hay `sticky` ni `fixed`:
    el alto lo impone el contenedor, que va de arriba abajo.
--}}
<aside class="flex flex-col w-64 h-full text-gray-300 border-r"
       style="background: linear-gradient(to bottom, #000, #000, #111827);
              border-color: rgba(31, 41, 55, .5);
              box-shadow: 0 25px 50px -12px rgba(0,0,0,.5);">

    <div class="relative py-6 flex flex-col items-center justify-center border-b border-gray-800/30 flex-shrink-0">
        {{-- Ocultar el panel.
             Va por un evento de window y no por `$root.collapsed`: `$root` es el
             ancestro mas cercano con `x-data`, asi que basta con que alguien
             agregue un `x-data` en un contenedor intermedio para que el boton
             vuelva a escribir en el scope equivocado (y el sidebar deje de
             colapsarse). El evento se escucha en el <html>, que es quien tiene
             `collapsed`. Ver panel-layout.blade.php. --}}
        <button type="button"
                @click="$dispatch('colapsar-sidebar')"
                class="hidden lg:flex absolute top-3 right-3 items-center justify-center w-[34px] h-[34px] rounded-[10px] bg-white/10 text-slate-300 transition-colors hover:bg-white/[0.22]"
                aria-label="Ocultar menú"
                title="Ocultar menú">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5M4.5 4.5l7.5 7.5-7.5 7.5" />
            </svg>
        </button>

        <a href="{{ route('dashboard') }}"
           class="flex items-center justify-center w-full min-h-[44px] px-3"
           aria-label="Luxury Premium Store — Inicio">
            <img src="{{ asset('logo.webp') }}"
                 alt="Luxury Premium Store"
                 width="125" height="112"
                 class="panel-logo block h-28 w-auto max-w-full object-contain drop-shadow-xl">
        </a>
    </div>

    <x-panel.nav-groups />
</aside>
