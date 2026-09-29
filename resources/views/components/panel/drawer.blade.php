@php
    $nombre = Auth::user()?->nombre;
@endphp

{{--
    Drawer móvil: en modo "app móvil" este componente ES el sidebar del legacy
    (app_nav.php:112-123), translated a -100% y con `.is-abierto` cuando
    `open` es true. En escritorio nunca se muestra: lo decide `movil` en
    panel-layout, no el ancho.

    El backdrop arranca debajo de la barra móvil (`inset` en `.panel-backdrop`),
    igual que app_nav.php:122.
--}}
<div class="panel-backdrop"
     x-show="movil && open"
     @click="open = false"
     x-cloak></div>

<aside class="panel-drawer fixed left-0 z-[200] w-72 flex flex-col text-gray-300"
       style="background: linear-gradient(to bottom, #000, #000, #111827);
              box-shadow: 0 25px 50px -12px rgba(0,0,0,.5);"
       x-show="movil && open"
       :class="open ? 'is-abierto' : ''"
       x-cloak>
    <div class="relative flex items-center justify-center px-16 py-5 border-b border-gray-800/30 flex-shrink-0">
        <a href="{{ route('dashboard') }}"
           @click="open = false"
           class="flex items-center justify-center min-h-[44px]"
           aria-label="Luxury Premium Store — Inicio">
            <img src="{{ asset('logo.webp') }}"
                 alt="Luxury Premium Store"
                 width="125" height="112"
                 class="panel-logo block h-28 w-auto max-w-full object-contain drop-shadow-xl">
        </a>
        <button type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center justify-center w-[34px] h-[34px] rounded-full bg-white/10 hover:bg-white/25"
                @click="open = false"
                aria-label="Cerrar menú">
            <x-heroicon name="x-mark" class="w-4 h-4" />
        </button>
    </div>

    {{-- El legacy cierra el drawer al pulsar cualquier enlace (app_nav.php:220);
         nav-groups lleva `@click="open = false"`, inofensivo en escritorio. --}}
    <x-panel.nav-groups />

    <div class="border-t border-gray-800/30 p-3 flex-shrink-0">
        <div class="flex items-center gap-3 px-1">
            <img src="{{ Auth::user()?->avatarUrl() }}"
                 alt="{{ $nombre }}"
                 class="panel-mobilebar-avatar w-8 h-8 rounded-[10px] object-cover shrink-0 border-2 border-white"
                 style="box-shadow: 0 0 0 1px #e2e8f0;">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-white truncate">{{ $nombre }}</p>
                {{-- Etiqueta fija por peticion del usuario. NO refleja usuarios.rol:
                     el rol real sigue siendo el de la BD y es lo que controla los
                     modulos admin-only. --}}
                <p class="text-xs text-gray-500 truncate">Admin</p>
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="mt-2">
            @csrf
            <button type="submit" class="w-full flex items-center gap-3 px-2.5 py-2.5 rounded-xl text-sm font-medium text-rose-300 hover:bg-rose-500/10 min-h-[44px]">
                <x-heroicon name="power" class="w-5 h-5 shrink-0" />
                Cerrar sesión
            </button>
        </form>
    </div>
</aside>
