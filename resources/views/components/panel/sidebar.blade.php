{{--
    Sidebar de escritorio. Réplica de `oldluxury/views/partials/app_nav.php:154`:
    fondo negro degradado, logo centrado arriba, rejilla de 4 columnas, borde
    derecho, sombra y NINGÚN bloque de usuario al pie (en el legacy eso vive
    solo en la barra superior, y `x-panel.mobile-bar` ya lo tiene).

    El contenedor de este <aside> es el que se desplaza al colapsar
    (`.panel-sidebar-colapsable`), por eso aquí no hay `sticky` ni `fixed`:
    el alto lo impone el contenedor, que va de arriba abajo.

    AQUÍ NO HAY NINGÚN BOTÓN. Ni para colapsar ni para expandir: el único
    control es el de la barra superior (`x-panel.mobile-bar`), que hace las dos
    cosas y vive en el sitio donde lo espera el usuario en cualquier app.

    Por eso el sidebar se sale ENTERO al colapsar (no se deja la franja de
    2.75rem de una versión anterior): con la barra siempre visible no hace
    falta dejarle sitio a un botón de recuperar, y el contenido se oculta
    entero para que no asome ni un píxel de la rejilla.
--}}
<aside class="relative flex flex-col w-64 h-full text-gray-300 border-r"
       style="background: linear-gradient(to bottom, #000, #000, #111827);
              border-color: rgba(31, 41, 55, .5);
              box-shadow: 0 25px 50px -12px rgba(0,0,0,.5);">

    <div class="relative py-6 px-6 flex flex-col items-center justify-center border-b border-gray-800/30 flex-shrink-0">
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