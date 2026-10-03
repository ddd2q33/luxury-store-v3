{{--
    Barra superior de la app. El ÚNICO sitio donde está el botón de abrir/cerrar
    el menú: el mismo control sirve para el drawer móvil y para colapsar el
    sidebar de escritorio, igual que en una app nativa. Dentro del sidebar no
    hay ningún botón (ver `x-panel.sidebar`).

    Antes la barra era solo móvil (`x-show="movil"`), porque en escritorio el
    colapsar se controlaba desde un botón dentro del propio sidebar. Al quitar
    ese botón, la barra pasó a existir también en escritorio: si no, al colapsar
    el sidebar en un escritorio no quedaría ninguna forma de volver a abrirlo.

    Contenido: SOLO el botón de menú. Sin cerrar sesión, sin avatar y sin
    título (el título de cada módulo lo dibuja su propia vista Livewire, y el
    legacy tampoco lo tiene aquí).

    Dónde están el usuario y el salir, entonces:
      - `x-panel.drawer` lleva el bloque de usuario (avatar, nombre) y el botón
        "Cerrar sesión", dentro del propio menú, que es donde se espera en una
        app móvil.
      - El avatar tampoco es la única puerta a `/profile`: ya existe como módulo
        del menú (`PanelMenu`, uri `profile`, visible para cualquiera logueado).
        Por eso quitarlo de aquí no deja la pagina sin entrada.
      - El sidebar de escritorio sigue SIN bloque de usuario, como el legacy.

    La altura sale de `--panel-barra` (app.css), que comparten la barra, el
    drawer y el backdrop. NO escribir un alto a mano aquí: se descuadrarían.
--}}
<div class="panel-mobilebar sticky top-0 z-[55] flex items-center gap-1 bg-white/95 backdrop-blur border-b border-gray-200 px-3"
     style="height: var(--panel-barra);
            padding-top: env(safe-area-inset-top);">

    {{-- Un solo boton para los dos casos. El icono cambia con el estado:
         barras = menu cerrado, aspa = menu abierto. --}}
    <button type="button"
            class="panel-menu-btn panel-mobilebar-btn flex items-center justify-center w-11 h-11 shrink-0 rounded-xl text-slate-700 transition-colors"
            @click="alternarMenu()"
            :aria-expanded="menuAbierto() ? 'true' : 'false'"
            aria-controls="panel-menu"
            :aria-label="menuAbierto() ? 'Cerrar menú' : 'Abrir menú'"
            :title="menuAbierto() ? 'Cerrar menú' : 'Abrir menú'">
        <x-heroicon name="bars-3" class="w-[22px] h-[22px]" x-show="!menuAbierto()" />
        <x-heroicon name="x-mark" class="w-[22px] h-[22px]" x-show="menuAbierto()" x-cloak />
    </button>
</div>