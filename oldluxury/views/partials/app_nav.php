<?php
if (!function_exists('luxury_nav_avatar_url')) {
    function luxury_nav_avatar_url($conn, $userId, $userName) {
        $avatar = $_SESSION['user_avatar'] ?? ($_SESSION['imagen_perfil'] ?? null);
        if (empty($avatar) && isset($conn) && $conn && $userId) {
            $stmt = $conn->prepare("SELECT imagen_perfil FROM usuarios WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $avatar = $row['imagen_perfil'] ?? null;
                $_SESSION['user_avatar'] = $avatar;
                $_SESSION['imagen_perfil'] = $avatar;
            }
        }
        if (!empty($avatar)) {
            $candidatas = [
                ['file' => dirname(__DIR__, 2) . "/uploads/avatars/" . $avatar, 'url' => "../uploads/avatars/" . rawurlencode($avatar)],
                ['file' => $_SERVER['DOCUMENT_ROOT'] . "/luxuryv3/uploads/avatars/" . $avatar, 'url' => "../uploads/avatars/" . rawurlencode($avatar)],
            ];
            foreach ($candidatas as $candidata) {
                if (file_exists($candidata['file'])) return $candidata['url'];
            }
        }
        return "../assets/img/avatars/default.svg";
    }
}

if (!function_exists('luxury_render_nav_start')) {
    function luxury_render_nav_start($active = '') {
        global $conn;
        $userId = $_SESSION['user_id'] ?? 0;
        $userName = $_SESSION['user_nombre'] ?? 'Usuario';
        $userRole = $_SESSION['rol'] ?? 'empleado';
        $isAdmin = ($userRole === 'admin');
        $avatarUrl = luxury_nav_avatar_url($conn ?? null, $userId, $userName);
        $userNameEsc = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $roleClass = $isAdmin ? 'bg-indigo-50 text-indigo-600 border border-indigo-100' : 'bg-slate-100 text-slate-600 border border-slate-200';
        $roleLabel = $isAdmin ? 'Administrador' : 'Staff';
        $items = [
            'dashboard' => ['../dashboard', 'fas fa-chart-line', 'Home', 'Principal'],
            'caja' => ['../caja', 'fas fa-cash-register', 'Caja', 'Principal'],
            'ventas' => ['../ventas', 'fas fa-shopping-cart', 'Ventas', 'Principal'],
            'clientes' => ['../clientes', 'fas fa-users', 'Clientes', 'Clientes'],
            'pedidos' => ['../pedidos', 'fas fa-clipboard-list', 'Pedidos', 'Clientes'],
            'ingresos' => ['../ingresos', 'fas fa-truck-loading', 'Ingreso', 'Inventario'],
            'productos' => ['../productos', 'fas fa-box', 'Productos', 'Inventario'],
            'inventario' => ['../inventario', 'fas fa-warehouse', 'Inventario', 'Inventario'],
            'stock' => ['../stock', 'fas fa-boxes', 'Stock', 'Inventario'],
            'categorias' => ['../categorias', 'fas fa-tags', 'Categorías', 'Inventario'],
            'proveedores' => ['../proveedores', 'fas fa-truck', 'Prov', 'Finanzas'],
            'movimientos' => ['../movimientos', 'fas fa-exchange-alt', 'Mov', 'Finanzas'],
            'facturacion' => ['../facturacion', 'fas fa-file-invoice-dollar', 'Factura', 'Finanzas'],
            'devoluciones' => ['../devoluciones', 'fas fa-rotate-left', 'Dev', 'Finanzas'],
            'gastos' => ['../gastos', 'fas fa-receipt', 'Gastos', 'Finanzas'],
            'abonos' => ['../abonos', 'fas fa-hand-holding-usd', 'Abonos', 'Finanzas'],
            'perfil' => ['../perfil', 'fas fa-user-circle', 'Perfil', 'Admin'],
            'empleados' => ['../empleados', 'fas fa-user-tie', 'Empleados', 'Admin'],
            'configuracion' => ['../configuracion', 'fas fa-cog', 'Config', 'Admin'],
        ];
        $groups = ['Principal', 'Clientes', 'Inventario', 'Finanzas', 'Admin'];
        echo <<<HTML
<script>
/* Modo app movil: solo telefonos/tabletas reales (puntero tactil + pantalla compacta).
   En escritorio SIEMPRE layout de escritorio, aunque la ventana sea angosta. */
(function(){
  try{
    if(localStorage.getItem('luxTema')==='dark'){document.documentElement.classList.add('dark');}
  }catch(e){}
  function esLayoutMovil(){
    try{
      var compacto = Math.min((window.screen&&window.screen.width)||9999,(window.screen&&window.screen.height)||9999) <= 1024;
      var tactil = window.matchMedia ? window.matchMedia('(pointer:coarse) and (hover:none)').matches : ('ontouchstart' in window);
      return (window.innerWidth <= 1024) || (tactil && compacto);
    }catch(e){ return window.innerWidth <= 1024; }
  }
  function aplicarLayout(){
    document.documentElement.classList.toggle('luxury-is-mobile', esLayoutMovil());
  }
  aplicarLayout();
  window.addEventListener('resize', aplicarLayout);
  window.addEventListener('orientationchange', aplicarLayout);
})();
</script>
<style>
.luxury-app-shell{display:flex}
.luxury-sidebar{width:16rem;background:linear-gradient(to bottom,#000,#000,#111827);color:#fff;height:100vh;position:fixed;left:0;top:0;display:flex;flex-direction:column;border-right:1px solid rgba(31,41,55,.5);box-shadow:0 25px 50px -12px rgba(0,0,0,.5);z-index:200;overscroll-behavior:contain}
.luxury-main{margin-left:16rem;width:calc(100% - 16rem);min-height:100vh}
.luxury-nav-item{transition:all .2s ease}
.luxury-nav-item:hover{transform:translateY(-2px)}
.luxury-nav-item.active{background:#fff;color:#000;box-shadow:0 4px 12px rgba(255,255,255,.2);transform:scale(1.05)}
.luxury-nav-item.active i{color:#000}
.luxury-scroll::-webkit-scrollbar{width:4px}
.luxury-scroll::-webkit-scrollbar-track{background:rgba(0,0,0,.1)}
.luxury-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:10px}
.luxury-scroll{-webkit-overflow-scrolling:touch}
a,button{-webkit-tap-highlight-color:transparent}
.luxury-nav-item:active{transform:scale(.94)}
.luxury-topbar{position:sticky;top:0;z-index:40;width:100%;background:rgba(255,255,255,.82);backdrop-filter:blur(10px);border:1px solid rgba(226,232,240,.6);box-shadow:0 1px 2px rgba(0,0,0,.05);padding:12px 16px;border-radius:16px;margin-bottom:20px}
.luxury-mobilebar{display:none}
.luxury-mobilebar-btn{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:12px;color:#334155;background:transparent;border:none;cursor:pointer;transition:background .2s}
.luxury-mobilebar-btn:active{background:#f1f5f9}
.luxury-mobilebar-title{flex:1;text-align:center;font-weight:800;font-size:15px;color:#0f172a;letter-spacing:-.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.luxury-mobilebar-spacer{width:32px}
.luxury-logout-btn{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:12px;color:#e11d48;background:transparent;border:none;cursor:pointer;text-decoration:none;transition:background .2s}
.luxury-logout-btn:active{background:#ffe4e6}
.luxury-mobilebar-avatar{width:32px;height:32px;border-radius:10px;object-fit:cover;border:2px solid #fff;box-shadow:0 0 0 1px #e2e8f0}
.luxury-sidebar-backdrop{display:none}
.luxury-sidebar-close{display:none}
/* Modo app movil: SOLO dispositivos reales (clase .luxury-is-mobile puesta por JS arriba). */
html.luxury-is-mobile .luxury-sidebar{transform:translateX(-100%);transition:transform .3s ease;will-change:transform}
html.luxury-is-mobile .luxury-sidebar.open{transform:translateX(0);box-shadow:0 0 60px rgba(0,0,0,.55)}
html.luxury-is-mobile .luxury-main{margin-left:0;width:100%}
html.luxury-is-mobile .luxury-mobilebar{display:flex;position:sticky;top:0;z-index:55;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 1px 0 rgba(15,23,42,.06);padding:8px 12px;padding-top:calc(8px + env(safe-area-inset-top));align-items:center;gap:4px;min-height:calc(52px + env(safe-area-inset-top))}
html.luxury-is-mobile .luxury-sidebar-backdrop{display:block;position:fixed;inset:0;z-index:199;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);opacity:0;visibility:hidden;transition:opacity .25s ease,visibility .25s ease}
html.luxury-is-mobile .luxury-sidebar-backdrop.open{opacity:1;visibility:visible}
html.luxury-is-mobile .luxury-sidebar.open ~ .luxury-sidebar-backdrop{opacity:1;visibility:visible}
html.luxury-is-mobile .luxury-sidebar-close{display:flex;position:absolute;top:10px;right:10px;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9999px;background:rgba(255,255,255,.12);color:#fff;border:none;cursor:pointer;z-index:201;transition:background .2s ease}
.luxury-sidebar-close:hover{background:rgba(255,255,255,.24)}
html.luxury-is-mobile .luxury-sidebar{top:calc(52px + env(safe-area-inset-top));height:calc(100vh - 52px - env(safe-area-inset-top))}
html.luxury-is-mobile .luxury-sidebar-backdrop{inset:calc(52px + env(safe-area-inset-top)) 0 0 0}
html.luxury-is-mobile body.luxury-sidebar-open{overflow:hidden}
/* Tema oscuro global (controlado desde Configuración) */
html.dark body{background:#0a0f1e}
html.dark .luxury-main{background:#0a0f1e;color:#e6ebf4}
html.dark .luxury-main .bg-white{background:#111a2e!important}
html.dark .luxury-main .bg-gray-50{background:#0e1626!important}
html.dark .luxury-main .bg-gray-100{background:#0e1626!important}
html.dark .luxury-main .bg-gray-200{background:#1e2a44!important}
html.dark .luxury-main .text-gray-900,html.dark .luxury-main .text-gray-800,html.dark .luxury-main .text-gray-700{color:#e6ebf4!important}
html.dark .luxury-main .text-gray-600,html.dark .luxury-main .text-gray-500{color:#8fa1bd!important}
html.dark .luxury-main .text-gray-400{color:#64748b!important}
html.dark .luxury-main .border-gray-100,html.dark .luxury-main .border-gray-200,html.dark .luxury-main .border-gray-300{border-color:#1e2a44!important}
html.dark .luxury-main input,html.dark .luxury-main select,html.dark .luxury-main textarea{background:#0e1626;color:#e6ebf4;border-color:#1e2a44}
html.dark .luxury-main table{color:#e6ebf4}
html.dark .luxury-main thead{background:#0e1626!important;color:#8fa1bd!important}
html.dark .luxury-main tbody tr:hover{background:#0e1626!important}
html.dark .luxury-main .bg-green-100,html.dark .luxury-main .bg-red-100,html.dark .luxury-main .bg-yellow-100,html.dark .luxury-main .bg-blue-100{background:rgba(255,255,255,.06)!important}
html.dark .luxury-mobilebar{background:rgba(10,15,30,.92);box-shadow:0 1px 0 rgba(255,255,255,.06)}
html.dark .luxury-mobilebar-btn{color:#94a3b8}
html.dark .luxury-mobilebar-btn:active{background:rgba(255,255,255,.08)}
html.dark .luxury-mobilebar-avatar{border-color:#111a2e;box-shadow:0 0 0 1px #1e2a44}
/* Colapsar sidebar en escritorio */
.luxury-sidebar-inline-toggle{display:none;position:absolute;top:12px;right:12px;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;background:rgba(255,255,255,.1);color:#cbd5e1;border:none;cursor:pointer;z-index:5;transition:background .2s}
.luxury-sidebar-inline-toggle:hover{background:rgba(255,255,255,.22)}
html:not(.luxury-is-mobile) .luxury-sidebar-inline-toggle{display:flex}
.luxury-reopen-btn{display:none;position:fixed;top:12px;left:12px;z-index:210;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:#0f172a;color:#fff;border:1px solid rgba(255,255,255,.12);box-shadow:0 10px 30px rgba(0,0,0,.28);cursor:pointer}
html.luxury-sidebar-collapsed .luxury-sidebar{transform:translateX(-100%);transition:transform .3s ease}
html.luxury-sidebar-collapsed .luxury-main{margin-left:0;width:100%}
html.luxury-sidebar-collapsed .luxury-reopen-btn{display:flex}
</style>
<div class="luxury-app-shell">
<aside class="luxury-sidebar" id="luxurySidebar">
<div class="relative py-6 flex flex-col items-center justify-center border-b border-gray-800/30 bg-gradient-to-b from-gray-900/40 to-transparent flex-shrink-0">
<button type="button" id="luxurySidebarClose" class="luxury-sidebar-close" aria-label="Cerrar menú"><i class="fas fa-times text-sm"></i></button>
<button type="button" id="luxurySidebarCollapse" class="luxury-sidebar-inline-toggle" aria-label="Ocultar menú" title="Ocultar menú"><i class="fas fa-angles-left text-sm"></i></button>
<img src="../assets/img/logo.png" alt="Store" class="max-h-28 w-auto object-contain hover:scale-105 transition-transform duration-500 drop-shadow-xl">
</div>
<nav class="flex-1 overflow-y-auto px-2 py-3 space-y-1.5 luxury-scroll">
HTML;
        foreach ($groups as $group) {
            if ($group === 'Admin' && !$isAdmin) continue;
            echo '<div class="text-[9px] font-black uppercase tracking-[2px] text-gray-500 px-3 mb-1 mt-1">' . $group . '</div>';
            echo '<div class="grid grid-cols-4 gap-1.5 px-1">';
            foreach ($items as $key => [$href, $icon, $label, $itemGroup]) {
                if ($itemGroup !== $group) continue;
                if ($key === 'proveedores' && !$isAdmin) continue;
                $activeClass = $key === $active ? ' active bg-white/10 text-white' : ' text-gray-400 hover:bg-gray-800 hover:text-white';
                echo '<a href="' . $href . '" class="luxury-nav-item flex flex-col items-center p-2 rounded-xl transition-all' . $activeClass . '">';
                echo '<i class="' . $icon . ' text-base"></i><span class="text-[8px] font-bold mt-1.5 text-center leading-tight">' . $label . '</span></a>';
            }
            echo '</div><hr class="border-gray-800/40 my-1.5 mx-3">';
        }
        echo <<<HTML
</nav>
</aside>
<div class="luxury-sidebar-backdrop" id="luxurySidebarBackdrop"></div>
<div class="luxury-main">
<button type="button" id="luxurySidebarReopen" class="luxury-reopen-btn" aria-label="Mostrar menú" title="Mostrar menú"><i class="fas fa-bars"></i></button>
<div class="luxury-mobilebar">
<button id="luxurySidebarToggle" class="luxury-mobilebar-btn" aria-label="Abrir menú"><i class="fas fa-bars text-lg"></i></button>
<div class="luxury-mobilebar-spacer" aria-hidden="true"></div>
<a href="../logout.php" class="luxury-logout-btn" aria-label="Cerrar sesión" title="Cerrar sesión"><i class="fas fa-power-off text-sm"></i></a>
<a href="../perfil" aria-label="Mi perfil"><img src="{$avatarUrl}" alt="" class="luxury-mobilebar-avatar"></a>
</div>
<main class="p-3 md:p-6">
HTML;
    }
}

if (!function_exists('luxury_render_nav_end')) {
    function luxury_render_nav_end() {
        echo <<<HTML
</main>
</div>
</div>
<script>
(function(){
  const sidebar=document.getElementById('luxurySidebar');
  const toggle=document.getElementById('luxurySidebarToggle');
  const backdrop=document.getElementById('luxurySidebarBackdrop');
  const closeBtn=document.getElementById('luxurySidebarClose');
  const isMobileLayout=()=>document.documentElement.classList.contains('luxury-is-mobile');
  function setSidebar(open){
    if(!sidebar) return;
    sidebar.classList.toggle('open',open);
    if(backdrop) backdrop.classList.toggle('open',open);
    document.body.classList.toggle('luxury-sidebar-open',open&&isMobileLayout());
  }
  if(toggle&&sidebar){toggle.addEventListener('click',()=>setSidebar(!sidebar.classList.contains('open')));}
  if(closeBtn){closeBtn.addEventListener('click',()=>setSidebar(false));}
  if(backdrop){backdrop.addEventListener('click',()=>setSidebar(false));}
  const collapseBtn=document.getElementById('luxurySidebarCollapse');
  const reopenBtn=document.getElementById('luxurySidebarReopen');
  function setCollapsed(c){document.documentElement.classList.toggle('luxury-sidebar-collapsed',c&&!isMobileLayout());}
  if(collapseBtn){collapseBtn.addEventListener('click',()=>setCollapsed(true));}
  if(reopenBtn){reopenBtn.addEventListener('click',()=>setCollapsed(false));}
  document.addEventListener('keydown',(e)=>{if(e.key==='Escape'){setSidebar(false);}});
  if(sidebar&&sidebar.querySelectorAll){sidebar.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>setSidebar(false)));}
  window.addEventListener('resize',()=>{const m=isMobileLayout(); if(!m){setSidebar(false);} else {setCollapsed(false);}});
  function updateClock(){const el=document.getElementById('luxuryNavClock'); if(el){el.textContent=new Date().toLocaleTimeString('es-CO');}}
  setInterval(updateClock,1000); updateClock();
  /* PWA: registrar service worker (una vez por sesión de página) */
  if(document.documentElement.classList.contains('luxury-is-mobile') && 'serviceWorker' in navigator && location.protocol.indexOf('http')===0){
    window.addEventListener('load',function(){
      navigator.serviceWorker.register('../sw.js',{scope:'../'}).catch(function(){});
    });
  }
  /* iOS standalone: que los enlaces abran dentro de la vista de la app */
  if(window.navigator.standalone===true){
    document.addEventListener('click',function(e){
      var a=e.target.closest('a[href]');
      if(a && a.target!=='_blank' && a.getAttribute('href') && a.getAttribute('href').charAt(0)!=='#'){
        e.preventDefault(); location.href=a.href;
      }
    });
  }
})();
</script>
HTML;
    }
}
?>
