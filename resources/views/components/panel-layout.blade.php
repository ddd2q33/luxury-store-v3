<!DOCTYPE html>
{{--
    Réplica de `oldluxury/views/partials/app_nav.php` en Laravel.

    Dos decisiones que NO son las de un layout de Breeze normal:

    1. `movil` NO sale de un breakpoint de Tailwind. El legacy define el modo
       "app móvil" con JS (app_nav.php:64-84): pantalla compacta Y táctil, o
       ventana de 1024px o menos. Así una ventana angosta en escritorio
       conserva el menú fijo. Ver `.panel-drawer` en resources/css/app.css.
    2. El tema oscuro se aplica ANTES de pintar (script de app_nav.php:69-70)
       para que no haya un destello blanco al recargar.
--}}
{{--
    OJO con el `x-init` de abajo.

    Alpine 3 evalúa su contenido como UNA EXPRESIÓN, no como un bloque de
    sentencias. Con un bloque multilínea (try {...} const aplicar = ...)
    Alpine lo envuelve en un AsyncFunction y revienta con
    "SyntaxError: Unexpected token 'try'", sin avisar de forma visible.

    El síntoma es que TODO ese código nunca corría: `movil` quedaba siempre en
    false, la preferencia `lux-sidebar-collapsed` nunca se leía ni se guardaba,
    y el resize no recalculaba nada. Por eso el sidebar se reiniciaba expandido
    en cada recarga.

    Por eso va como una IIFE (() => { ... })(): una sola expresión que sí
    puede contener varias sentencias. NO volverlo a un bloque de sentencias
    sueltas: se rompe otra vez, en silencio.

    Dentro de la IIFE, `movil`, `collapsed`, `open` y `$watch` se escriben SIN
    `this.`: la flecha hereda el scope de Alpine, pero con `this` dentro de la
    IIFE `this` es el objeto global y `$watch` no existe
    ("this.$watch is not a function").
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      x-data="{
          open: false,
          collapsed: false,
          movil: false,
          /* Un solo control para el menu, en la barra superior. En movil abre y
             cierra el drawer; en escritorio colapsa y expande el sidebar. Las
             dos acciones comparten boton e icono, como en una app nativa. */
          menuAbierto() { return this.movil ? this.open : !this.collapsed },
          alternarMenu() { if (this.movil) { this.open = !this.open } else { this.collapsed = !this.collapsed } },
      }"
      x-init="(() => {
          try { document.documentElement.classList.toggle('dark', localStorage.getItem('luxTema') === 'dark') } catch (e) {}
          const aplicar = () => {
              const compacto = Math.min(window.screen?.width || 9999, window.screen?.height || 9999) <= 1024;
              const tactil = window.matchMedia
                  ? window.matchMedia('(pointer:coarse) and (hover:none)').matches
                  : ('ontouchstart' in window);
              const esMovil = (window.innerWidth <= 1024) || (tactil && compacto);
              document.documentElement.classList.toggle('luxury-is-mobile', esMovil);
              movil = esMovil;
              // Al pasar a escritorio se cierra el drawer; al pasar a móvil se
              // descolapsa, para que el menu quede disponible (app_nav.php:221).
              if (esMovil) { collapsed = false } else { open = false }
          };
          aplicar();
          window.addEventListener('resize', aplicar);
          window.addEventListener('orientationchange', aplicar);
          // Guardar el colapso (app_nav.php:216-218).
          try { collapsed = localStorage.getItem('lux-sidebar-collapsed') === '1' } catch (e) {}
          $watch('collapsed', v => { try { localStorage.setItem('lux-sidebar-collapsed', v ? '1' : '0') } catch (e) {} });
      })()">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- El tema se pinta antes que el CSS para evitar el destello. --}}
        <script>
            try {
                if (localStorage.getItem('luxTema') === 'dark') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        </script>

        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

        {{-- Modo app móvil, igual que app_nav.php:64-84.Va en <head> para que
             el CSS ya aplique el offset correcto antes del primer pintado. --}}
        <script>
            (function () {
                function esLayoutMovil() {
                    try {
                        var compacto = Math.min((window.screen && window.screen.width) || 9999,
                                                (window.screen && window.screen.height) || 9999) <= 1024;
                        var tactil = window.matchMedia
                            ? window.matchMedia('(pointer:coarse) and (hover:none)').matches
                            : ('ontouchstart' in window);
                        return (window.innerWidth <= 1024) || (tactil && compacto);
                    } catch (e) { return window.innerWidth <= 1024; }
                }
                document.documentElement.classList.toggle('luxury-is-mobile', esLayoutMovil());
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    {{-- OJO: este <body> NO debe llevar `x-data`. Todo el estado del panel
         (open, collapsed, movil) vive en el x-data del <html>, y Alpine
         hereda el scope de los ancestros, asi que aqui se lee igual.

         Con un `x-data` vacio en el body se rompian tres cosas a la vez:
         1. `$root` es el ancestro mas cercano con x-data (addRootSelector
            devuelve '[x-data]'), asi que el boton de la barra escribia
            `collapsed` en el scope del body y el sidebar nunca se colapsaba.
         2. `@keydown.escape.window="open = false"` cerraba el `open` del body,
            no el del <html>: la tecla Escape no cerraba el drawer.
         3. `:class="open && movil ? ..."` era siempre falso, asi que
            `body.panel-menu-abierto { overflow: hidden }` no se aplicaba nunca
            y el drawer no bloqueaba el scroll del fondo. --}}
    <body class="font-sans antialiased bg-gray-100"
          @keydown.escape.window="open = false"
          :class="open && movil ? 'panel-menu-abierto' : ''">
        <div class="min-h-screen">
            {{-- Sidebar fijo de escritorio. `hidden lg:block` es solo red de
                 seguridad: en modo app móvil lo oculta `x-show` sin importar
                 el ancho, igual que el legacy.

                 El unico boton para abrir/cerrar esta en la barra superior, no
                 aqui dentro: si estuviera dentro, colapsar el sidebar lo
                 esconderia junto con el logo y no habria forma de recuperarlo
                 sin un segundo boton flotando en el layout. --}}
            <div class="hidden lg:block fixed inset-y-0 left-0 z-[200] w-64 shrink-0 panel-sidebar-colapsable"
                 x-show="!movil"
                 :class="collapsed ? 'is-colapsado' : ''"
                 x-cloak>
                <x-panel.sidebar />
            </div>

            <x-panel.drawer />

            {{-- Columna principal.
                 La barra va PRIMERO dentro de `.panel-main`: es `sticky top-0`,
                 asi que se queda pegada arriba al hacer scroll y el boton de
                 abrir el menu sigue disponible en cualquier seccion, que es lo
                 que se pidio. Si fuera `fixed` habria que reservar el hueco a
                 mano en cada vista.

                 El titulo y subtitulo los dibuja cada componente Livewire dentro de
                 $slot, no aqui: mantenerlos en el layout hacia que se vieran dos
                 veces y gastaba un bloque de altura sin proposito. --}}
            <div class="panel-main flex-1 min-w-0 flex flex-col"
                 :class="collapsed ? 'is-colapsado' : ''">
                <x-panel.mobile-bar />

                <main class="flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>

        {{-- iOS standalone: que los enlaces abran dentro de la vista de la app
             en vez de Safari (app_nav.php:231-238). --}}
        <script>
            if (window.navigator && window.navigator.standalone === true) {
                document.addEventListener('click', function (e) {
                    var a = e.target.closest('a[href]');
                    if (a && a.target !== '_blank' && a.getAttribute('href')
                        && a.getAttribute('href').charAt(0) !== '#') {
                        e.preventDefault();
                        location.href = a.href;
                    }
                });
            }
        </script>

        {{-- Tema claro/oscuro: UN solo punto de control.
             Lo usan el atajo de teclado (Shift+D) y el switch de Configuración, asi
             que no pueden desincronizarse. Se guarda en `localStorage` (es
             preferencia del navegador, igual que en el legacy), no en la BD.
             Quien solo cambia la clase del <html> y avisa con el evento
             `tema-cambiado`: asi el switch de Configuración se repositiona y las
             gráficas del dashboard (que viven en un canvas) se redibujan. --}}
        <script>
            window.luxTema = {
                oscuro: function () {
                    return document.documentElement.classList.contains('dark');
                },
                aplicar: function (oscuro) {
                    document.documentElement.classList.toggle('dark', oscuro);
                    try { localStorage.setItem('luxTema', oscuro ? 'dark' : 'light'); } catch (e) {}
                    window.dispatchEvent(new CustomEvent('tema-cambiado', { detail: { oscuro: oscuro } }));
                },
                alternar: function () {
                    this.aplicar(!this.oscuro());
                },
            };

            // Atajo: Shift+D alterna el tema desde cualquier pantalla del panel.
            // Se ignora al escribir (input, textarea, select, contenteditable) y
            // con Ctrl/Alt/Meta pulsados, para no secuestrar teclas del sistema ni
            // cambiar el tema mientras se busca o se captura algo.
            document.addEventListener('keydown', function (e) {
                if (e.ctrlKey || e.altKey || e.metaKey || !e.shiftKey) return;
                if (e.key !== 'D') return;
                var t = e.target;
                if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
                window.luxTema.alternar();
                e.preventDefault();
            });
        </script>

        @livewireScripts
    </body>
</html>
