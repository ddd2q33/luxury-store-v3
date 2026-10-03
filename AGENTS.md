# luxury-store-v3 — Convenciones del proyecto

## Desplegar en producción (checklist, 2026-10-02)

El repo se desarrolla en `APP_ENV=local` + `APP_DEBUG=true` a propósito: el panel es
un back-office local con datos reales, y con debug se depura más rápido. Ese estado
**no es publicable**. Antes de exponerlo en un servidor:

1. `APP_ENV=production` y `APP_DEBUG=false`. Con `true`, Ignition muestra rutas
   absolutas, el código fuente, las variables de entorno y el SQL ejecutado.
2. `APP_URL` con el dominio real, y `SESSION_SECURE_COOKIE` **sin tocar**:
   `config/session.php` lo deduce de `APP_ENV` y en `production` sale `true`.
   Ponerlo en `true` a mano en local ROMPE el login (el navegador no acepta la
   cookie por `http://localhost` y no hay forma de iniciar sesión).
3. `composer install --no-dev --optimize-autoloader` y `npm ci && npm run build`.
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
   Ojo: tras cachear config, cambiar `.env` ya no tiene efecto hasta `config:clear`.
5. `php artisan storage:link` (los avatares y el catálogo leen de `public/`).
6. MySQL accesible SOLO desde 127.0.0.1, con usuario propio y no `root` sin clave
   (que es lo que trae `.env.example` por defecto).
7. Verificar que `.env` nunca se haya commiteado: ese archivo tiene el `APP_KEY`, que
   es lo que firma los snapshots de Livewire **y** cifra sesión y cookies. Si se
   filtró, `php artisan key:generate` **invalida todas las sesiones abiertas**.
8. `composer audit` sigue reportando vulnerabilidades sin parche (Laravel 9 y PHP 8.0
   están EOL): aceptable solo en local, **no publicar así**.

### Railway / Railpack (2026-10-03)

El build en Railway usa **Railpack** (no Nixpacks). Tres cosas que ya nos costaron un
build fallido y no hay que repetir:

1. La versión de PHP la decide **solo `composer.json > require > php`**. Railpack le
   hace `strings.CutPrefix($v, '^')` y usa el resto **literal**: `^8.0.2` → `8.0.2` →
   busca `dunglas/frankenphp:php8.0.2-trixie`, que no existe → *"No version available
   for php 8.0.2"*. Railpack solo soporta **PHP 8.2+**. Por eso `require.php` ahora es
   `^8.2` (resuelve a `php8.2.34-trixie`, verificado en Docker Hub).
   - **`RAILPACK_PHP_VERSION` NO existe** (Railway sugiere esa variable en el diagnóstico,
     pero no está en el código de Railpack 0.40.1 ni en las docs). No perder tiempo con eso.
   - `railpack.json > packages` **no overridea** `composer.json`: el propio Railpack lo
     documenta como "seed" de menor prioridad. La fuente real es `composer.json`.
2. Desplegar con `require.php ^8.2` rompe el **PHP 8.0.30 local** (XAMPP): `composer`
   genera `vendor/composer/platform_check.php` que mataba `artisan` con fatal error.
   Se resolvió en `composer.json` con `config.platform.php = "8.2.0"` (resuelve deps
   como 8.2 en local) **y** `config.platform-check = false` (no genera el check que
   reventaba en 8.0). Si algún día se actualiza el XAMPP a 8.2+, quitar las dos.
3. `start-container.sh` de Railpack corre **`php artisan migrate --force` por defecto**,
   y eso en este proyecto está prohibido (el schema legacy ya existe y las migraciones
   de Laravel no son las de la BD real). Se bloqueó con `railpack.json` →
   `deploy.variables.RAILPACK_SKIP_MIGRATIONS = "true"`. **No quitar.**

Otras advertencias del despliegue en Railway que el build no cubre:
- El build corre `php artisan config:cache`, que **hornea `APP_KEY` en el build**. Si
  `APP_KEY` no está seteada antes de construir, el runtime muere con
  `MissingAppKeyException`. Cargarla en las Variables del servicio.
- La app **no funciona sin la BD legacy** (`productos`, `ventas`, etc.). Railway crea
  MySQL vacío: hay que importar el dump o apuntar `DB_*` a un MySQL que ya tenga `luxury`.
- Railpack corre en `APP_ENV=production`/`APP_DEBUG=false` en la imagen, pero `APP_URL`
  y el resto de variables de producción siguen siendo responsabilidad de Railway.

## REGLA PRINCIPAL: todo es responsive, siempre

**Cada componente, vista, tabla, formulario y sección debe funcionar en celular, tablet y escritorio.**
No se acepta código que solo funcione en desktop.

- Mobile-first: estilos base sin prefijo, luego `sm:` `md:` `lg:` `xl:`.
- Puntos de verificación obligatorios al revisar UI: **375px**, **768px**, **1280px**.
- Las tablas anchas NO se muestran con scroll horizontal como solución única. En móvil se
  transforman a tarjetas/listas apiladas con los campos clave, o se ocultan columnas
  secundarias de forma explícita (no por accidente del desborde).
- Áreas táctiles mínimo **44x44px**. Nada de UI que dependa de `hover` (no existe en táctil).
- Formularios de captura (caja, ventas, stock): `inputmode="numeric"` en campos de cifras,
  labels visibles, sin placeholders como único rótulo.
- Navegación: sidebar de escritorio colapsa a drawer en móvil.

## Base de datos

- BD: MySQL/MariaDB `luxury` (`.env` → `DB_DATABASE=luxury`). Host 127.0.0.1:3306, root sin clave.
- Es una BD **legacy en español**, no el schema de Laravel. Tablas: `productos`, `categorias`,
  `ventas`, `venta_detalles`, `caja`, `caja_detalles`, `movimientos_caja`, `movimientos_stock`,
  `inventario_movimientos`, `compras`, `proveedores`, `clientes`, `empleados`, `usuarios`,
  `deudas`, `gastos`, `ingresos_mercancia`, `abonos_proveedores`, `historial_proveedores`,
  `historial_deuda_proveedores`.
- Clientes = `clientes` (consumidores). Usuarios del panel = `usuarios` (con `rol`
  enum admin/cajero/empleado y `estado` activo/inactivo). No confundirlos.
- `usuarios` tiene su propio schema: `nombre`, `username`, `email`, `rol`, `estado`,
  `ultimo_login`, `imagen_perfil`, `password`. No tiene `remember_token` ni `email_verified_at`.

## Prohibido

- `php artisan migrate:fresh`, `migrate:reset` o tests con `RefreshDatabase` sobre `luxury`.
  DESTRUYEN los datos reales de ventas y caja.
- No se crean tablas de dominio con migraciones sin preguntar: el schema ya existe.
- No mover el proyecto a `C:\xampp\htdocs`. Vive en el repo; XAMPP solo aporta binarios.

## Stack (verificar antes de asumir)

- Laravel **9.52.22** (EOL, feb-2024) sobre PHP **8.0.30** (EOL, dic-2023). Symfony 6.0.x.
- Livewire **2.12.8** (v3 exige Laravel 10+). Breeze **1.19.2** (v2 es Laravel 10+).
- Vite 4 + `laravel-vite-plugin` 0.7. Tailwind vía Breeze.
- `composer.json` tiene `config.audit.block-insecure: false` porque Composer 2.9 bloquea
  Laravel 9 por advisories. `composer audit` reporta vulnerabilidades conocidas sin parche:
  es aceptable solo en local, **no publicar así**.

## Contexto

`luxury-store` es una zapatería/ropa. La BD tiene datos reales en producción
(541+ ventas, 106 productos). El proyecto es un **dashboard/back-office**, no una tienda
pública: productos, categorías, ventas, caja, inventario, proveedores, clientes.

## Migración desde `oldluxury/` (sistema anterior)

El sistema viejo vive en `oldluxury/` (PHP plano + mysqli, ~18k líneas, 20 módulos).
Se migra **por fases verificadas** con rediseño Breeze (no se copia el HTML viejo).

### Migrado (verificado contra BD real)
- Auth completa sobre `usuarios` (login/registro/logout, `ultimo_login`, bloqueo de inactivos).
- Layout del panel: `components/panel-layout.blade.php`. Menú: `App\Support\PanelMenu`
  (activo por URI). La navegación es una **réplica de
  `oldluxury/views/partials/app_nav.php`** (note: el directorio es `partials`, con `s`).
- Dashboard: `DashboardController` + KPIs + gráficas Chart.js (global via `window.Chart`)
  + `VentasRecientes` (Livewire: búsqueda + paginación). Tiene además un cartel de
  alertas de pedidos y la "Cola de pedidos" (ver abajo).
- Reportes: `/reportes` admin-only, `App\Http\Livewire\Reportes` (7 presets + rango
  manual), SOLO LECTURA. En `clientes()` el alias del `selectRaw` debe ser
  `total_gastado` porque es el que lee el mapper; con `AS total` la tabla salía en $0.
- Modelos legacy: `Venta, VentaDetalle, MovimientoCaja, Caja, Producto, Categoria,
  Cliente, Proveedor, AbonoProveedor, Compra, Pedido, Gasto` — todos con timestamps
  desactivados o `CREATED_AT` custom. No agregar `created_at/updated_at` a tablas legacy.
- Helper `App\Support\Money` ($1.234.567 estilo CO).

### La navegación: reglas que no se pueden romper
- `PanelMenu::grupos()` da a cada item **dos** etiquetas: `label` (larga, drawer y
  `sr-only`) y `corto` (la del sidebar de escritorio: "Prov", "Mov", "Factura", "Dev",
  "Config"). En una rejilla de `grid-cols-4` no caben las largas. `normalizar()`
  rellena `corto` si alguien agrega un item y se le olvida.
- El sidebar NO lleva bloque de usuario ni botón de salir: eso vive solo en
  `x-panel.mobile-bar`, igual que el legacy.
- La rejilla es de 4 columnas y los grupos **siempre abiertos** con `<hr>` de
  separador. No volver al acordeón con `x-collapse` ni al store `window.__navGrupos`
  que se eliminó.
- El item activo se pinta con la clase CSS `.panel-nav-tile.activo` (cuadro blanco,
  texto e icono negros), NO con utilidades de Tailwind. Esa regla está **fuera de
  `@layer`** a propósito: dentro, un `bg-white/10` de la vista la pisaría.
- El colapso de escritorio usa clases propias, no utilidades: `.panel-sidebar-colapsable`
  (translateX -100%) y `.panel-main.is-colapsado` (margin-left 0), alternadas por
  Alpine con `localStorage` en `lux-sidebar-collapsed` (NO `panel-collapsed`: esa
  clave viene de una versión anterior del layout y ya no la lee nadie). Con
  utilidades `lg:ml-0` / `lg:ml-64`
  NO es seguro: ambas son del mismo eje y gana la que Tailwind imprima última, no la
  que agregue Alpine.
- `x-panel.nav-groups` se usa en el sidebar **y** en el drawer: por eso lleva
  `@click="open = false"` (inofensivo en escritorio, cierra el drawer en móvil).
- Logotipo: `logo.webp`, `h-28`, centrado, con `panel-logo` para el hover.

### Modo "app móvil" NO es un breakpoint
El legacy no decide móvil/escritorio con Tailwind: lo calcula en JS y pone
`html.luxury-is-mobile` (app_nav.php:64-84).
`compacto = min(screen.width, screen.height) <= 1024`, `tactil =
matchMedia('(pointer:coarse) and (hover:none)')`, `movil = (innerWidth <= 1024) ||
(tactil && compacto)`. El estado vive en `movil` (x-data del `<html>` en
`panel-layout`), no en `lg:`. Los `hidden lg:block` del markup son solo red de
seguridad.

**Ojo con la contradicción del legacy**: el comentario de `app_nav.php:71-74`
dice "en escritorio SIEMPRE layout de escritorio, aunque la ventana sea angosta",
pero la función que tiene debajo devuelve `true` para cualquier
`innerWidth <= 1024`, sin importar el puntero. O sea que el comentario miente: una
ventana de 900px en un escritorio SÍ sale con drawer. Se replica la **función**
(que es la que el legacy ejecuta de verdad), no el comentario. No "arreglar" la
expresión para que el sidebar se quede fijo a 900px: eso sería un cambio de
comportamiento, no una réplica.

### Tema oscuro
`html.dark` + `localStorage.luxTema`, toggle en Configuración → Apariencia (ya no es
el placeholder). El CSS (`html.dark .panel-main ...` en `resources/css/app.css`) pisa
por selector las clases de Tailwind con `!important`, igual que el legacy
(app_nav.php:124-143). **No son tokens de color**: si una vista nueva usa clases que
no están en esa lista, se verá clara en modo oscuro. La alternativa sería
`darkMode: 'class'`, que es un trabajo aparte. El script que lo aplica va en `<head>`
para que no haya destello blanco.

**Un solo punto de control:** `window.luxTema` (script al final del body de
`panel-layout.blade.php`) es lo único que cambia el tema, con `oscuro()`,
`aplicar(bool)` y `alternar()`. Lo usan el atajo de teclado y el switch de
Configuración, así que no pueden desincronizarse.
- **Atajo: `Shift+D`**, en cualquier pantalla del panel. Se ignora si se pulsa con
  Ctrl/Alt/Meta o si el foco está en `input`/`textarea`/`select`/`contenteditable`
  (si no, escribir una "D" en el buscador cambiaría el tema). Para cambiar la tecla
  se toca solo el `if (e.key !== 'D')` de ese script.
- Quien cambia el tema **debe** despachar `tema-cambiado` con
  `detail.oscuro`: así el switch de Configuración se reposiciona
  (`@tema-cambiado.window`) y las gráficas del dashboard se redibujan. Si se
  cambia la clase del `<html>` a mano, el switch queda mintiendo.
- El tema sigue siendo preferencia del navegador (localStorage), **no** de la BD:
  no cambia al entrar desde el celular.

### Avatar
`User::avatarUrl()` replica `luxury_nav_avatar_url()` (app_nav.php:2-28): usa
`usuarios.imagen_perfil`, busca en `public/uploads/avatars/` y cae a
`public/assets/img/avatars/default.svg`. **El chequeo de existencia es obligatorio**:
el admin tiene `imagen_perfil = user_1_1775367584.png` y ese archivo NO está (hay 7
con otros timestamps), así que sin verificar sale un 404. Se hace `basename()` y se
filtra la extensión porque el valor viene de la BD y no debe poder escapar de la
carpeta. Para cambiar un avatar hay que subir el archivo a `public/uploads/avatars/`.

### Estado de la BD (verificado, no asumir)
- `usuarios` SÍ tiene `remember_token` (AGENTS.md anterior decía que no).
- La tabla de caja se llama `cajas` (no `caja`).
- `venta_detalles` está **vacía** en producción (0 de 542 ventas) → las facturas
  **no pueden mostrar líneas de producto** y una devolución **no puede reponer stock**.
  Es un hueco del legacy, no un bug. Los módulos lo dicen con un aviso visible.
- `ventas.total_general` está en 0.00 en todas las ventas: la columna real es `total`.
  `ventas.estado` usa `Completada` / `Anulada` (con mayúscula inicial).
- `proveedores` tiene TRES columnas de deuda: `saldo_deuda` (la que usa el legacy),
  `deuda` (**huérfana**, el viejo nunca la lee/escribe) y `descripcion_deuda_inicial`.
  Solo se toca `saldo_deuda`.
- `movimientos_caja.tipo` es un enum: `ingreso|egreso|venta|devolucion`. Caja ya muestra
  los del turno; Finanzas → Movimientos los muestra globales.
- `historial_deuda_proveedores.tipo_movimiento` solo admite `saldo_inicial|ajuste`.
  Los abonos NO se registran ahí: se auditan en `abonos_proveedores`, que ya guarda
  `deuda_anterior` y `deuda_nueva`.
- `gastos` está vacía (4 columnas: descripcion, monto, fecha, hora) y `deudas`,
  `historial_proveedores`, `historial_deuda_proveedores` también.
- `password_resets` existe → el flujo de recuperación de contraseña funciona.
- `pedidos` solo tiene `created_at`; `productos`/`proveedores`/`compras` tienen columnas
  `fecha*` con default de BD.
- `empleados` tiene 8 columnas y **está vacía** (el legacy nunca la alimentó):
  `id, nombre, cargo, telefono, correo, salario, fecha_ingreso, creado_en`.
  - `nombre` y `cargo` son **NOT NULL**: los dos son obligatorios en el formulario.
  - Solo existe `creado_en` (timestamp con default de BD) y **no hay** `actualizado_en`:
    el modelo usa `CREATED_AT = 'creado_en'` y `UPDATED_AT = null`.
  - `empleados` **NO es** el acceso al panel. Eso es `usuarios` (rol/estado). No mezclarlas.
- `usuarios` no tiene claves foráneas apuntando a ella, pero `cajas`, `abonos_proveedores`,
  `devoluciones`, `historial_deuda_proveedores` e `historial_proveedores` sí guardan
  `usuario_id`. Por eso **no se borran usuarios**: se desactivan (`estado`).
- `usuarios` tiene 3 filas con ids 1, 2 y 4 (no hay 3): 1 = `admin@luxury.com` (admin),
  2 = `empleado@luxury.com` (empleado), 4 = `avellaenando@gmail.com` (cajero, dato de
  prueba). El hash de contraseña **no es recuperable**: si una prueba lo pisa, solo se
  recupera del dump `luxury.sql`. Por eso los tests HTTP guardan el hash, hacen commit,
  y lo restauran al final.

### Datos que parecen errores pero son reales (no "arreglarlos")
- **540 de 542 ventas tienen `ventas.cliente_id = 0`**, que es un centinela de "venta de
  mostrador", no una clave foránea válida. Solo hay 1 fila en `clientes` (id 16, dato de
  prueba). Por eso las ventas se muestran con `ventas.cliente_nombre` y **nunca** con la
  relación `belongsTo(Cliente::class)`: el join saldría vacío en 540 facturas.
- `proveedores` tiene 3 filas con ids **15, 16 y 17** (no 1..3). `compras.proveedor_id`
  usa 15 y 16, y ambas **sí existen**: no hay compras huérfanas, el nombre del proveedor se
  resuelve por join sin problema. (Corrección 2026-09-28: una versión anterior de este
  archivo afirmaba lo contrario y estaba mal.)
- **`productos.precio` está en `0.00` en los 106 productos.** El legacy nunca lo cargó.
  Consecuencia: valor de inventario, márgenes, reportes por categoría y todo lo que
  dependa del precio dan **$0 o son inválidos**. No es un bug de código: es un dato
  faltante. Cualquier KPI monetario de producto necesita el precio real primero.
- 10 productos tienen `stock` **negativo** (el legacy lo permitía; el peor es -1231).
  `Producto::estadoStock()` los marca `negativo` y se corrigen con Ajuste, no a mano.
- `productos.stock_minimo` **existe pero está en 0 en los 106 productos**: el legacy nunca
  la llenó. Con mínimo 0 el estado `bajo` **no puede dispararse** (solo `agotado` y
  `negativo`). Por eso Stock y el Dashboard dicen cuántos productos están "sin mínimo
  definir": la alerta preventiva no sirve hasta que el usuario los configure.

### Definición única de "stock bajo" (no volver a inventar otra)
`App\Models\Producto::scopeNecesitaReposicion()` es el único lugar que define en SQL qué
productos requieren reposición: `stock <= 0` **o** (`stock_minimo > 0` y
`stock <= stock_minimo`). Refleja exactamente `estadoStock()`. Verificado con 0 desajustes
en los 106 productos. Lo usan `Stock` (alertas), `DashboardController` (tarjeta) y debe
usarlo cualquier vista nueva. **El Dashboard antes usaba un umbral fijo de 5**
(`whereBetween('stock',[1,4])`) e ignoraba `stock_minimo`; ya no lo hace.

### Alertas y "Cola de pedidos" del Dashboard
La regla de qué es una alerta vive SOLO en `DashboardController`: un pedido
"por atender" es `pendiente` **o** con `fecha_entrega` pasada (vencido). Los `listo`
no alertan (no requieren decisión) y `entregado/cancelado` se ignoran siempre.
Detalles que no cambiar a mano:
- Estados abiertos = `Pedido::ESTADOS_ABIERTOS` (el modelo es la fuente única;
  no repetir el array en cada vista). Etiquetas en español = `Pedido::ETIQUETAS`,
  siempre con fallback `?? $estado` porque `estado` es varchar, no enum.
- `fecha_entrega` es NULL cuando el cliente no pactó fecha: esos pedidos **nunca**
  se cuentan como vencidos (si no se marcarían solos para siempre).
- El flag `vencido`/`diasAtraso` lo calcula el controller y se compara **por día**
  (`startOfDay()`), no contra `now()`: un pedido que se entrega HOY no está vencido.
  La vista no vuelve a derivarlo, o duplica la regla en dos sitios.
- La cola es la consulta `pedidosCola`: abiertos, ordenados con los vencidos primero
  y sin fecha al final. No es una tabla con scroll: bloque responsivo de filas táctiles.

### Los tres bugs que casi no se ven en el Dashboard (2026-09-28)
1. **Un `</div>` de más.** `dashboard.blade.php` tenía 87 cierres contra 86
   aperturas. El sobrante cerraba el wrapper `p-4 sm:p-6 lg:p-8 space-y-6` a mitad
   de la página, así que desde "Evolución mensual" para abajo todas las secciones
   quedaban como **hermanas de `.panel-main`**: sin padding y sin `space-y-6`. El
   navegador lo corrige solo y la página "se ve normal", por eso pasó tanto tiempo.
   Al tocar el markup, contar `<div>`/`</div>` (`%TEMP%\opencode\divdepth.php`
   avisa el punto donde se descuadra). El HTML renderizado se comprueba por HTTP
   verificando el **ancestro**: cada sección tiene que seguir colgando de
   `.panel-main` (`test_dashboard_oscuro.php`), porque contar divs del HTML final
   tampoco sirve (DOMDocument también los "arregla").
2. **La regla muerta del tema oscuro.** El legacy cubría
   `bg-green-100 / bg-red-100 / bg-yellow-100 / bg-blue-100`, pero **ninguna vista
   usa verde/rojo/amarillo/azul de Tailwind**: usan `emerald`, `rose`, `amber`,
   `indigo`, `sky`, `slate` y `violet`. Esa regla no aplicaba a NADA y por eso los
   chips, los mosaicos de los KPIs y los banners de alerta se veían clarísimos en
   oscuro. Ahora cada tinte se conserva como **translúcido del mismo color** (para
   no perder el código visual) y su texto oscuro se aclara; si se neutralizaran a
   gris, el texto dejaría de contrastar. `test_dashboard_oscuro.php` extrae las
   clases de color del HTML y las cruza con las reglas del CSS compilado: avisa de
   cualquier clase nueva que se quede clara en oscuro.
3. **Chart.js no lo cubre el CSS.** Un `<canvas>` ignora las clases de Tailwind:
   la grilla, las etiquetas y el borde del dona hay que colorearlos a mano, y
   además hay que **destruir y recrear** las gráficas cuando cambia el tema (un
   `MutationObserver` sobre la clase del `<html>`), o quedan con la grilla
   clarísima sobre el fondo oscuro. Las 5 gráficas viven en
   `crearGraficas()`; los datos se releen de los `data-*` del canvas, no de
   variables de PHP.

### OJO: el pipeline de escritura pierde acentos y mojibakea
Al editar archivos en este repo se han visto DOS fallos que no rompen nada hasta que
los ves en pantalla:
1. **Mojibake por doble codificación** (`MÃ©todos`, `Â·`, `dÃ­as`): pasó en
   `dashboard.blade.php` y `DashboardController.php`. Hay que buscar `Ã`, `Â`, `â`
   con `Ã¡` / `Ã©` / `Ã³` / `Ãº` / `Ã±`.
   (Los tres ejemplos de esta línea son LITERALES de lo que se ve corrupto en
   pantalla, no texto roto del archivo: no "arreglarlos".)
2. **Acentos borrados en silencio** (`tenia`, `numero`, `minimo`, `aqui`): el texto
   queda en ASCII válido, así que ningún validador de UTF-8 lo detecta.
Revisar texto en español después de cada cambio, sobre todo en comentarios y texto
visible. En `app/Models/User.php`, `PanelMenu`, `app.css` y los componentes
`panel/*` corrige el caso 1 (ya reparado). Los archivos del Dashboard se repararon
completo; si un acento vuelve a faltar, es el caso 2.

### Cerrar una caja: siempre con el servicio
`App\Services\CajaService::cerrar($caja)` calcula los totales desde los movimientos y
escribe `saldo_final`/`fecha_cierre`/`estado`. No editar `cajas` a mano ni con SQL suelto.

### Zona horaria: `America/Bogota`, NUNCA UTC (2026-10-03)
`config/app.php` tiene `'timezone' => 'America/Bogota'` y `'locale' => 'es'`. **No volver
a `UTC`/`en`**, que fue lo que había y estaba mal por dos razones:

- Los datos que hay en la BD los escribió el legacy con
  `date_default_timezone_set('America/Bogota')` (`oldluxury/config.php:3`). Con `UTC`,
  `now()` daba 5 horas menos y una venta hecha a las 8pm se fechaba en el día **siguiente**,
  inflando "ventas de hoy" del Dashboard y moviendo el corte de caja.
- `locale` afecta a Carbon: `Caja` pinta `now()->isoFormat('dddd D [de] MMMM')` y el
  Dashboard usa `translatedFormat('d M Y')`. Con `en` salía "Friday 2 de October".

- El `php.ini` de XAMPP dice `date.timezone = Europe/Berlin`, pero Laravel lo pisa con
  `config/app.php` al arrancar. No hay que tocar el php.ini (afectaría a otros proyectos
  de XAMPP). Solo los scripts que NO pasan por Laravel (`php -r` suelto) usan Berlin.
- `.env` **no** define `APP_TIMEZONE`/`APP_LOCALE`: si algún día los define, pisan la
  config y el síntoma reaparece.
- Verificación por HTTP: `now()->format('H:i')` debe coincidir con la hora real de
  Colombia, y `isoFormat('dddd D [de] MMMM')` debe salir en español ("viernes 2 de
  octubre"). Los KPI del Dashboard en 0 no significan bug: la última venta real es de
  septiembre.
- **Filas escritas mientras la app estaba en UTC** (solo 2, ambas de prueba, en
  `cajas` id 72 y `ventas` id 547) tienen la hora corrida +5h. No se corrigen a mano:
  `cajas` solo se toca con `CajaService`.
- OJO: el código **no** usa funciones SQL de fecha (`NOW()`, `CURDATE()`); todo pasa por
  Carbon. Si alguna vez se agregan, hay que convertirlas o usar `whereDate` con Carbon.


### El diseño "no se ve": `public/hot`
Si `public/hot` existe (lo crea `npm run dev` y no lo borra al morir), `@vite` deja de
servir `public/build` y pasa a apuntar al dev server. Si ese server no corre —o si
guardó `http://[::1]:5173`, IPv6 sin corchetes—, **no cargan ni el CSS ni el JS**: la
página sale sin estilos, sin Alpine y sin Livewire. Es exactamente "el diseño se dañó".

- Diagnóstico: `Test-Path public\hot`. Si existe, `Remove-Item public\hot -Force`
  y recargar. Solo lo crea `npm run dev`, nunca `npm run build`.
- Verificación: el HTML servido debe contener `/build/assets/app-<hash>.css` y **no**
  `5173`.
- Consecuencia: tras cualquier `npm run build` cambian los hashes y el CSS viejo se
  borra. Una página cacheada que apunte al hash anterior queda sin estilo hasta
  recargar con Ctrl+F5.
- La regla `[x-cloak] { display: none !important; }` en `resources/css/app.css` debe
  quedar FUERA de `@layer` (Tailwind purga reglas de `@layer` que no ve en el contenido).
  Antes de darla por buena, compilar y buscar `[x-cloak]{` en el CSS de `public/build`.

### Bugs de Livewire 2 que ya nos mordieron (leer antes de usar `reset()`)
- **`$this->reset()` NO acepta valores por defecto** (eso es Livewire 3). En la 2.12 su
  firma es `reset(...$properties)` y el bucle hace `foreach ($properties as $property)`.
  Si se pasa `['stock' => '0']` recorre los **valores** e intenta leer la propiedad `'0'`,
   y revienta con `PropertyNotFoundException: Property [$0] not found`. Se pasaron solo
  nombres: `$this->reset('stock', 'stock_minimo')`; para forzar un valor, se asigna
  después (`$this->deudaInicial = '0'`, como hace `Proveedores`). Pasaba en
  `Productos::nuevo()`: el botón "Nuevo producto" daba un 500.
- **`temporaryUrl()` de un archivo recién subido solo funciona para mimes
  previsualizables** (png, gif, bmp, svg, jpg, jpeg, webp, mp4, mp3...). Para lo demás
  `TemporaryUploadedFile::temporaryUrl()` cae en `storage->temporaryUrl()` y el driver
  `local` lanza *"This driver does not support creating temporary URLs"*. Como el preview
  se dibuja **al elegir el archivo, antes de que `validate()` lo rechace**, un PDF en un
  campo `image` deja la pantalla en blanco. Por eso `Productos::previewImagen()` consulta
  `isPreviewable()` y envuelve la llamada en `try/catch (\Throwable)`; el mensaje de
  error al usuario lo pone la regla `image|mimes:...` al pulsar Guardar.
- Para probar el `max:` de una subida **no** sirve `UploadedFile::fake()->image()->size(3000)`:
  Livewire guarda el contenido **real** en el disco temporal, así que el tamaño inventado
  se pierde y la validación pasa. Hay que usar `createWithContent()` con bytes de verdad.
- **`wire:click="a(); b()"` SOLO ejecuta `a()` (2026-10-03).** No hay división de sentencias:
  `vendor/livewire/livewire/js/util/wire-directives.js:86` hace
  `method.match(/(.*?)\((.*)\)/s)` y el **segundo grupo es greedy**, así que en
  `editar(5); cerrarDetalle()` captura `5); cerrarDetalle(` como parámetros. El JS que
  arma es `return (function(){...})(5); cerrarDetalle()`, o sea que la segunda llamada
  queda **después del `return`** y es código muerto (sin error, sin warning). Pasó en
  `productos.blade.php` y el síntoma era que el botón "Editar" de la ficha dejaba el
  modal de detalle abierto encima del formulario (los dos a la vez). Y el mismo bug
  rompía `$set('quitarImagen', true); $set('imagenArchivo', null)`: solo aplicaba el
  primer `$set`, así que el archivo elegido no se limpiaba. **Regla: una acción por
  `wire:click`.** Si hacen falta dos, es un método en el componente, y el estado se
  deja desde PHP (que es el único sitio que sobrevive a la vista). Hay un test que
  vigila que nadie vuelva a encadenar: `ProductosModalTest::test_la_vista_no_encadena_sentencias_en_wire_click`.

### Bugs conocidos (verificados, sin corregir)
- **`Pedidos::cambiarEstadoConfirmado()` CORREGIDO (2026-09-28):** la excepción al
  reactivar un pedido cancelado con stock insuficiente se lanzaba como
  `RuntimeException` fuera de cualquier `try`, escapaba a pantalla en blanco, y usaba
  `Pedido::findOrFail()`. Ahora lanza `DomainException`, usa el helper
  `$this->pedido($id)` (con `with('detalles')`, nunca `findOrFail`) y todo el método
  está envuelto en `try/catch (\DomainException)` que tostea el error. Verificado con
  test transaccional: no excepción, pedido sigue `cancelado`, stock intacto.
- `CajaService` lanza `RuntimeException` (no `DomainException`) en `abrir/reabrir/
  registrarVenta/eliminarVenta`. Hoy no hay pantalla blanca porque `Caja.php` lo envuelve en
  `catch (\RuntimeException)` **local** en `abrirCaja`, `reabrirCaja`, `registrarVenta` y
  `eliminarVentaConfirmada`. Si alguien llama al service desde otro sitio sin ese `catch`,
  reaparece la pantalla en blanco. `cerrarCaja` **no** tiene try/catch: hoy es seguro solo
  porque `CajaService::cerrar()` no lanza excepciones.
  **(2026-09-29:** `abrir()` y `reabrir()` ahora corren DENTRO de `DB::transaction` con un
  `SELECT ... FOR UPDATE` sobre la caja abierta (`cajaActivaBloqueada()`), que cierra la
  carrera de dos aperturas simultaneas. El mensaje de error no cambia.
- `StockService` usaba `findOrFail()` en `salida()`/`ajuste()`/`aplicar()`: **CORREGIDO
  (2026-09-29).** Ahora `find()` + `DomainException('El producto no existe o fue eliminado.')`,
  que `PanelComponent::ejecutar()` tostea. Ademas el movimiento escribe
  `categoria_id = null` (no `0`) cuando el producto no tiene categoria: `0` reventaba
  contra la FK `inventario_movimientos_ibfk_2`.
- ~~`Facturacion::render()` muestra el chip `Anulada` pero la consulta lleva
  `where('estado', 'Completada')`~~ **CORREGIDO (2026-09-29):** el filtro de
  estado vive en la base de la consulta y las stats (total/cantidad/promedio)
  respetan el estado elegido. Antes elegir "Anulada" no mostraba nada.
- `productos.codigo_barras` **no existe** en la BD. El legacy lo leía/escribía en 5 sitios
  (formulario, `buscar`, `buscar_productos`, `scan` y lista), así que en producción esos
  endpoints devolvían 500 y el escáner estaba muerto. La app nueva **no** tiene barcode:
  `Caja::agregarPorCodigo()` busca por **nombre o id**. No agregar el campo al formulario sin
  crear antes la columna (migración de dominio = preguntar).

### Roles y permisos (2026-10-03)
La autorización vive **en código**, no en tablas: `app/Support/Roles.php` es la
fuente única y no se puede duplicar la matriz en las vistas.

- El enum de `usuarios.rol` pasó de 3 valores a 5. La migración
  `2026_10_03_000001_ampliar_enum_rol_usuarios.php` lo amplía y su `down()`
  revierte. **Al recrear `luxury_test` hay que repetir ese paso** (ver la
  sección de pruebas).
- **Por qué `operador` existe:** las cuentas que tenían `cajero` o `empleado`
  tenían en la práctica acceso a casi todo. Al partir el mapa en 5 roles, esas
  dos would've perdido módulos, así que se remapearon a `operador`
  (ids 2 y 4) para **conservar exactamente el acceso que ya tenían**. No
  inventar un sexto rol para "lo que había antes": es `operador`.
- Matriz:
  - `admin`: todo.
  - `supervisor`: todo menos `configuracion`.
  - `operador`: todo menos `configuracion`, `reportes` y `empleados`.
  - `cajero`: `dashboard`, `caja`, `ventas`, `clientes`, `pedidos`, `catalogo`.
  - `empleado`: los de `cajero` sin `caja`, más `ingreso`, `productos`,
    `inventario`, `stock`, `categorias`.
- `anular_venta` es una **acción**, no un módulo (borra la venta, su movimiento
  de caja y repone stock). Los tres botones de anulación se ocultan sin
  permiso, y `Ventas` la exige en el servidor.
- `perfil` NO es un módulo: lo ve cualquier usuario activo. El menú lo cuenta
  aparte, así que hay 20 módulos de negocio, no 21.
- `productos.precios` exige `admin` (pantalla de trabajo puntual, fuera del
  menú).
- Dos capas, y hacen falta las dos: `EnsurePermiso` va en la **ruta**, y
  `AppServiceProvider` lo suma a `Livewire::addPersistentMiddleware()` para que
  el endpoint de Livewire también revalide. Lo mismo que invariant 5 de
  Config: si no está en la lista persistente, el permiso no se revalida nunca.
- `PanelMenu::gruposVisibles()` filtra por permisos, así que el sidebar y el
  drawer se adaptan solos. Cada item del menú debe tener su clave en el mapa de
  `Roles`: hay un test que lo verifica.

### Admin (Perfil / Empleados / Config)
- **Perfil** (`/profile`, cualquier autenticado): sigue siendo `ProfileController` + partials,
  pero la vista usa `x-panel-layout` (antes usaba `layouts.app` de Breeze y se veía sin
  sidebar). Traducido al español y se eliminó el bloque de "correo sin verificar":
  `usuarios` no tiene `email_verified_at` y no hay correo saliente configurado.
- **Empleados** (`/empleados`, admin-only): CRUD de la tabla `empleados` + listado de
  solo lectura de `usuarios` para separar "personal" de "acceso".
- **Config** (`/configuracion`, admin-only): en el legacy `oldluxury/configuracion/index.php`
  trabajaba **solo sobre `usuarios`** (alta, rol, borrado) más una sección "Apariencia" sin
  persistencia. Aquí: **alta de usuario** (`nuevoUsuario()`/`guardarUsuario()`), cambio
  de rol, activar/desactivar y reset de contraseña.
  Los datos de empresa se muestran **en solo lectura y etiquetados como datos de ejemplo**,
  porque en el legacy eran literales en `config.php` ("Calle 123", "+57 300 123 4567"), no
  filas de ninguna tabla. Guardarlos exigiría una tabla de configuración: **preguntar antes**.

Invariantes de Config (si se tocan, mantenerlos):
1. No te degradas ni te desactivas a ti mismo.
2. `asegurarQuedaAdmin()` impide quitarle el rol o desactivar al último admin activo.
   Lanza `DomainException`; desde la UI es casi inalcanzable porque (1) dispara antes,
   pero se prueba por reflexión.
3. `guardarPassword()` DEBE llamar a `$this->validate()` (mínimo 8 chars y confirmación).
   Sin eso se guardaba cualquier cadena, incluso de 5 caracteres.
4. Nunca `findOrFail()` en un método de Livewire: su `ModelNotFoundException` es un
   `RuntimeException`, escapa de `ejecutar()` y deja pantalla en blanco. Usar
   `$this->usuario($id)`, que lanza `DomainException`.
5. El middleware `admin` protege la RUTA **y el endpoint de Livewire**, porque
   `AppServiceProvider::boot()` hace `Livewire::addPersistentMiddleware([EnsureEsAdmin::class])`.
   Antes (2026-10-02) NO lo protegía: la lista persistente por defecto de Livewire 2
   trae `Authenticate` pero no `EnsureEsAdmin`, así que el rol no se revalidaba nunca.
   `mount()` sigue siendo una segunda barrera (solo corre en la carga inicial).
   Ojo: `gatherRouteMiddleware()` filtra por el middleware de la ruta ORIGINAL,
   así que `EnsureEsAdmin` solo se re-ejecuta en rutas que declaran `admin`
   (empleados, configuración, reportes). No lo pongas global a la ligera.
6. El alta y el cambio de contraseña usan **juegos de reglas separados**: `rules()` solo
   valida `nuevoPassword`/`confirmarPassword` (lo consume `guardarPassword()`), y
   `reglasNuevoUsuario()` valida el alta (`Rule::unique` sobre `usuarios.username` y
   `usuarios.email`). No fusionarlas: si el alta metiera sus campos en `rules()`, cambiar
   una contraseña exigiría rellenar nombre/usuario/correo. El alta crea con
   `User::create()` + `Hash::make()` dentro de `ejecutar()`; un `QueryException` (carrera
   contra los índices UNIQUE de `usuarios`) se traduce a `DomainException` para que salga
   como toast y no como pantalla blanca.
7. Los modales cierran con Esc vía Alpine: `@keydown.escape="$wire.cerrarModales()"` en
   Config y `"$wire.cerrarForm(); $wire.porEliminar = null"` en Empleados. El método
   Livewire **necesita el prefijo `$wire.`**: sin él Alpine lo trata como función JS, tira
   `is not defined` y Esc no hace nada (era el bug). Al ser expresión de Alpine sí admite
   varias sentencias con `;` (a diferencia de `wire:click`, que solo ejecuta la primera).

### Migraciones de dominio autorizadas (son 2, ninguna más sin preguntar)
1. `database/migrations/2026_09_28_000001_create_devoluciones_tables.php` crea
   `devoluciones` y `devolucion_detalles`. El usuario lo aprobó explícitamente porque
   el legacy (`oldluxury/devoluciones/`) las usaba y se habían perdido.
   El schema salió del `INSERT INTO devoluciones (...)` del propio módulo viejo.
   `devoluciones.movimiento_caja_id` es una columna añadida por nosotros (no existía):
   enlaza la devolución con su egreso en caja para poder anular sin `LIKE` frágil.
2. `database/migrations/2026_09_28_000002_add_imagen_to_productos_table.php` añade
   **`productos.imagen MEDIUMBLOB NULL`** (una foto por producto, para el catálogo PDF).
   El usuario lo aprobó explícitamente y con la condición de que la imagen sea
   **opcional**: si no se sube, el producto se guarda igual y el catálogo dibuja un
   marcador de posición.

Cualquier otra tabla o columna nueva hay que **preguntar antes**. Ojo: esta migración
**ya se aplicó sobre `luxury`**, así que no hace falta volver a correrla; y como
`productos` es legacy, no se le puede pedir `created_at`/`updated_at`.

### Catálogo virtual en PDF (`/catalogo`)
Piezas: `CatalogoService` (qué productos entran y con qué datos), `CatalogoController`
(solo el PDF, ruta propia `/catalogo/pdf`), Livewire `Catalogo` (la pantalla y los
filtros) y `resources/views/pdfs/catalogo.blade.php` (que solo pinta).

- El PDF **no** se genera desde Livewire: un PDF de varios MB dentro del JSON de
  Livewire se rompe. Va por una ruta normal con `->download()`.
- Filtros: `?todos=1` (incluye agotados; por defecto solo `stock > 0`), `?imagen=1`
  (solo los que ya tienen foto) y `?categoria=ID`.
- **No hay tope de productos**: se quitó un `MAX_PRODUCTOS = 300` que además estaba
  mal aplicado (`->get()->take()` carga los 106 blobs y recién ahí recorta, y el
  `total` mentía). Un catálogo que se salta productos sin avisar es peor que uno
  lento; para achicar el PDF están los filtros.
- Los tres recuentos (`total`, `conImagen`, `sinPrecio`) van **en SQL**, no contando
  en PHP: contar en PHP obligaba a hidratar los 106 productos con su MEDIUMBLOB.
- El listado de productos y la vista previa **nunca** cargan todos los blobs: la
  pantalla pide solo las 24 tarjetas que pinta (`conImagenes: false` + una segunda
  consulta `whereIn`) y el listado usa la ruta `/productos/imagen/{id}`.
- `CatalogoService::resumen()` debe devolver `sinPrecio`: la vista lo lee. Si falta
  esa clave, `/catalogo` da un 500 con "Undefined array key" en el log.
- Los `precio` están en `0.00` en los 106 productos, así que el PDF y la pantalla
  muestran **"Sin precio"** (`CatalogoService::precioLegible()`), no `$0`.
- **dompdf necesita la extensión GD para incrustar PNG/GIF/WEBP.** Venía comentada
  en `C:\xampp\php\php.ini` (`;extension=gd`) y sin ella el PDF con imágenes moría
  con *"The PHP GD extension is required, but is not installed"*. Está activada.
  Sin `config/livewire.php`, el disco temporal de Livewire cae a `filesystems.default`
  (`local`), que por eso es el que se usó en las pruebas.

### Servicios: un solo punto de mutación
Igual que el stock, la deuda y las devoluciones NO se editan a mano en formularios.
- `App\Support\StockService` → `productos.stock` + `inventario_movimientos`.
- `App\Support\DeudaProveedorService` → `proveedores.saldo_deuda` +
  `historial_deuda_proveedores` + `abonos_proveedores`.
- `App\Support\DevolucionService` → `devoluciones` + su `movimientos_caja`.

Reglas: transacción + `lockForUpdate()`, lanzar `DomainException` (la captura
`PanelComponent::ejecutar()`; `RuntimeException` se escapa a pantalla en blanco) y
`ejecutar()` devuelve `bool` para NO cerrar el formulario si falló.

**Cómo se ejecuta la acción (2026-10-02, ya no hay `findOrFail()` en el panel).**
Los 18 `findOrFail()` que quedaban en componentes Livewire se reemplazaron por un
helper por componente (`cliente()`, `producto()`, `categoria()`, `proveedor()`,
`gasto()`, `abono()`, `devolucion()`) que hace `find()` y lanza `DomainException`.
Ojo: el helper solo es seguro si su llamada está dentro de algo que lo atrape:
- Cambia datos y merece confirmación → `ejecutar(fn () => ..., 'Mensaje')`.
- **Solo lee y llena un formulario o un detalle** (`editar()`, `verDetalle()`,
  `abrirAjuste()`, cargar un registro para anular) → `cargar(fn () => ...)`,
  que es `ejecutar()` SIN toast de éxito. Confirmar cada vez que se abre un
  formulario sería ruido, pero el error tiene que seguir viéndose.
- Si necesitas el nombre del registro para el mensaje de éxito, llámalo primero
  con `cargar()` y devuelve temprano si devuelve `null` (ver `Abonos::guardar()`).

### Reglas para próximos módulos
1. Todo módulo nuevo: ruta con `->middleware('auth')`, acceso por rol vía
   `Auth::user()->esAdmin()`. Los **admin-only son solo tres: empleados,
   configuración y reportes** (`web.php:105-109` y `PanelMenu` coinciden).
   **Proveedores NO es admin-only**: lo ven todos los autenticados, cajero
   incluido, y editan `saldo_deuda` por `DeudaProveedorService`. Decidido
   explícitamente el 2026-10-02; una versión anterior de esta línea listaba
   "proveedores" como admin-only y contradecía al código en tres sitios.
2. Registrar el módulo en `App\Support\PanelMenu::grupos()` para que aparezca en sidebar + drawer.
3. Vistas: el page wrapper es solo `<x-panel-layout><div class="p-4 sm:p-6 lg:p-8">
   @livewire('x')</div></x-panel-layout>`. El título y el subtítulo van **dentro** de la
   vista Livewire (`<h2>` + `<p>`), NO en un slot del layout: el layout ya no tiene
   header, para no duplicar el título. Mobile-first, tablas → tarjetas en móvil,
   targets 44px, sin depender de hover.
4. **Todo layout nuevo necesita `@livewireStyles` en `<head>` Y `@livewireScripts` antes
   de `</body>`** (ya nos pasó: sin eso Livewire no hidrata y el buscador no filtra).
5. Livewire: `PanelComponent` ya trae `use WithPagination` y
   `protected string $paginationTheme = 'tailwind';`. No los repitas en el
   componente: hereda de `PanelComponent`, no de `Livewire\Component`.
6. Verificar cada módulo con datos reales por HTTP/navegador antes de darlo por listo.
7. El puerto 8000 puede quedar sirviendo código viejo (instancia previa/OPcache):
   para probar código fresco usar otro puerto (`php artisan serve --port=8010`).
8. **Livewire 2.12 no tiene `$testable->errors()`** (eso es de la 3). En las pruebas
   usar `$testable->instance()->getErrorBag()` o `assertHasErrors()`.
9. Antes de_WRITAR en `usuarios` en pruebas: todo dentro de `DB::beginTransaction()` /
   `DB::rollBack()`. Si no, el script escribe en la BD real.
10. Toda vista nueva necesita `npm run build` (Tailwind purga por contenido): si se
    olvida, las clases nuevas no existen en `public/build` y la página se ve pelada.
11. El Dashboard está en **`/dashboard`**, no en `/`. Las rutas de Finanzas son
    `/movimientos`, `/gastos`, `/proveedores` (no viven bajo `/finanzas/`).

### Pruebas: suite VERDE contra una copia real del schema (2026-09-29)
- `phpunit.xml` apunta a `DB_CONNECTION=mysql` y **`DB_DATABASE=luxury_test`**.
  `luxury_test` ya NO es una base vacia: es **copia completa del schema legacy**
  (28 tablas, generada desde `luxury.sql` con `USE` reescrito) + las 2
  migraciones de dominio autorizadas. Ver counts: 542 ventas, 106 productos,
  3 usuarios.
- La suite usa `DatabaseTransactions` (NUNCA `RefreshDatabase`: dropea el schema
  legacy). Estado actual: **87 passed, 1 skipped** (no hay productos sin categoria
  en el dataset), ~2 s. Tras correrla, `luxury` y `luxury_test` quedan identicas.
- Recrear `luxury_test` si se pierde. El dump `storage/testschema/luxury_test.sql`
  es ANTERIOR a las 2 migraciones autorizadas, así que por sí solo deja la base
  desactualizada y la suite falla con "tabla no encontrada" (`devoluciones`):
  1. `mysql -u root < storage/testschema/luxury_test.sql` (el archivo tiene
     `USE luxury_test`, jamás toca `luxury`; está en .gitignore porque son datos
     reales).
  2. `ALTER TABLE luxury_test.productos ADD COLUMN imagen MEDIUMBLOB NULL;`
     (migración `2026_09_28_000002`).
  3. Correr la migración `2026_09_28_000001` (crea `devoluciones` +
     `devolucion_detalles`).
  4. Ampliar el enum de `usuarios.rol` a los 5 roles (ver "Roles y permisos").

- **OJO: `DB::statement('USE luxury_test')` NO sirve para el `Schema` builder.**
  Funciona para el SQL crudo (`DB::table()`, `DB::select()`), pero
  `Schema::hasTable()` consulta `information_schema` con
  `Connection::getDatabaseName()`, que quedó fijado en `luxury` al conectar: el
  `USE` solo cambia la base de la sesión MySQL, no ese valor. El síntoma es
  `Schema::hasTable('devoluciones')` devolviendo `true` sobre `luxury_test` y
  haciendo que la migración "se lo salte" sin crearla — un falso verde. Para
  correr migraciones contra `luxury_test` hay que registrar una conexión de
  verdad:
  ```php
  config(['database.connections.mysql_test' => array_merge(
      config('database.connections.mysql'), ['database' => 'luxury_test']
  )]);
  DB::setDefaultConnection('mysql_test');
  ```
  Por eso tampoco existe tabla `migrations` en `luxury_test`: `php artisan
  migrate` no sirve para upkeep del schema de pruebas.
- Tests de dominio nuevos: `tests/Feature/StockServiceTest.php` (incluye la
  regresion del findOrFail y la FK de categoria_id) y
  `tests/Feature/CajaServiceTest.php` (ventas, cambio, reversion atomica, cierre).
  Al verificar "que no se escribio nada", comparar conteos ANTES/DESPUES: las
  tablas legacy ya traen filas (791 movimientos de caja), no partir de cero.
- `tests/Feature/Auth/RegistrationTest.php` y `EmailVerificationTest.php` se eliminaron:
  prueban registro público y verificación de email, que este proyecto **ya no tiene**
  (`usuarios` no tiene `email_verified_at` y no hay ruta `/register`).
- Para probar por HTTP hay que **hacer commit** del cambio de password, no usar
  transacción: el proceso del servidor es otro y no ve lo no confirmado. Guardar el hash
  original **y `ultimo_login`**, y restaurar ambos al final. Si no, el password del admin
  se pierde y no es recuperable salvo por `luxury.sql`.

### Carga masiva de precios (`/productos/precios`, 2026-10-03)
Es la pantalla que llena `productos.precio`, que estaba en `0.00` en 105 de los 106
productos (el legacy nunca lo cargó, y **no se puede recuperar del historial**:
`movimientos_caja.descripcion` guarda el total del carrito, no el precio unitario — AF1
aparece a 120k, 130k, 140k, 150k y 160k — y solo 17 de 541 ventas casan con
`productos.nombre`).

- `App\Http\Livewire\ProductosPrecios` + `resources/views/livewire/productos-precios.blade.php`.
  Admin-only y **fuera de `PanelMenu`** a propósito: es una pantalla de trabajo puntual,
  se entra desde el aviso de "productos sin precio" en `/productos`.
- Dos vías de entrada, las dos acordadas: escribir el precio fila por fila (`wire:blur`,
  no `wire:model`: 106 filas escribiendo en la BD en cada tecla serían 106 viajes por
  segundo) o **pegar la columna desde Excel** (`analizarPegado()` + `aplicarPegado()`).
- El pegado asocia **por nombre normalizado** (sin tildes, mayúsculas ni signos): si no
  encuentra el nombre, o si hay productos repetidos, va a `sinAsociar` **con el motivo**;
  nunca se descarta en silencio.
- Guardar es **una sola transacción**: o se aplican todos los precios, o ninguno.
- Lo que quede vacío se escribe en `0.00` y sigue mostrando «Sin precio»
  (`CatalogoService::precioLegible()`). **No se agregó columna ni tabla**: `precio` ya
  existía, y el usuario cero los que no llenara.
- Escribe `producto->forceFill(['precio' => …])` directo, sin pasar por un servicio: el
  precio NO mueve stock ni genera `inventario_movimientos`, igual que
  `Stock::guardarMinimo()` con `stock_minimo`.
- `normalizarDecimal()` acepta `150000`, `150.000`, `1.234,56` y `150 000`. Está replicada
  en `Productos` y aquí a propósito: es `private` y no se quiere acoplar dos componentes.
- Tests: `tests/Feature/ProductosPreciosTest.php` (15). Usa nombres de productos REALES del
  dataset y hace `skipTest()` si no existe, en vez de dar un verde falso.
- **Efecto colateral en Caja, ya guardado (2026-10-03):** el carrito tomaba
  `(float) $producto->precio` sin mirar nada, asi que con 105 productos en `0.00` el
  cajero armaba el carrito y `CajaService::registrarVenta()` lo rechazaba con
  *"El total debe ser mayor a cero"*, un error sin relacion con lo que estaba pasando.
  `Caja::agregarProducto()` ahora lo corta antes, con un toast que dice donde cargar el
  precio. **No se perdio ninguna venta**: se verifico que no hay ni una venta
  `Completada` con `total = 0`. Ojo que `luxury_test` **no tiene ningun producto con
  precio** (el dump es anterior a que existiera ese dato), asi que
  `tests/Feature/CajaPrecioCeroTest.php` le pone precio dentro de la transaccion.
- `assertEmitted()` de Livewire 2.12 **solo lee `effects.emits`**: un
  `dispatchBrowserEvent()` cae en `effects.dispatches` y es invisible para el. Para
  verificar un toast hay que leer `$componente->payload['effects']['dispatches']` a mano.
  Ojo tambien que `Caja::toast()` sobreescribe el evento a **`caja-toast`**, no a
  `panel-toast`.

### Sondas CDP para probar UI (herramienta, no código del proyecto)
En `%TEMP%\opencode` hay sondas de Chrome headless que manejan la sesión por CDP y son la
única forma de probar de verdad lo que ve el usuario (Livewire modales, ancho de inputs,
qué se ve a 375px). **No van al repo.** Enseño lo aprendido, porque costó tiempo:
- El login tiene que ser un paso con `wait`: hacer el POST y en el paso siguiente hacer
  `Page.navigate` hace que la navegación gane la carrera y termine en `/login`.
- `cdp_flow.mjs` acepta `@archivo.json` en vez de JSON inline: PowerShell se come las
  comillas de un JSON pasado como argumento nativo (`node f.mjs $json` llega sin `"`).
- Los pasos `eval` ignoran el campo `wait`, así que un `eval` que hace `.click()` y
  después mide encuentra el DOM viejo y da falsos negativos ("el modal no abre" cuando sí).
  O se usa un paso `click`, o el `eval` espera.

### Endurecimiento y limpieza (2026-09-29)
- **`PanelComponent::hydrate()` exige sesion activa** (`Auth::user()?->estaActivo()`,
  `abort(401)`). El endpoint de Livewire no pasa por `auth`: esto cierra
  la puerta a snapshots JS de sesiones viejas o usuarios desactivados. No quitarlo.
  **(2026-10-02) Esto solo cubría 15 de los 20 componentes:** `Caja`, `Ventas`,
  `Pedidos`, `Clientes` y `VentasRecientes` heredaban de `Livewire\Component`, NO de
  `PanelComponent`, así que un usuario desactivado a mitad de sesión conservaba
  escritura sobre la caja y el stock. **Regla: todo componente del panel extiende
  `PanelComponent`; si necesita otro toast, sobreescribe `toast()` como `protected`
  (nunca `private`: bajar la visibilidad de un método `protected` del padre es error
  fatal de PHP) y mantén el nombre de evento que escucha su vista.**
- **Eliminados (con todas sus referencias):** `ContadorDemo` + ruta
  `/livewire-demo-test`, `RegisteredUserController` (no hay registro publico),
  `application-logo.blade.php` (0 usos).
- **`/` ya no es la landing de Laravel**: `welcome.blade.php` es una redireccion
  (meta-refresh) a `dashboard` con sesion o a `login` sin ella. El logout y el
  borrado de perfil llevan a `login`, no a `/`.
- La cookie de sesión **no** se llama `laravel_session`: es
  `luxury_premium_store_session` (ver `config('session.cookie')`).
- Con `Invoke-WebRequest` usar `-WebSession` + `session.cookie`; con curl hay que llamar
  `curl_close()` antes de reutilizar el cookie jar, si no la sesión nunca se guarda y
  todas las páginas devuelven el login (6333 bytes, el síntoma de "todo en 200 pero
  vacío").
