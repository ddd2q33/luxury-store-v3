<?php
// productos/index.php - Gestión de Productos con Búsqueda y Filtros
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('productos');

// ====================================================================
// CONSULTAS INICIALES
// ====================================================================
$proveedores = mysqli_query($conn, "SELECT * FROM proveedores ORDER BY nombre ASC");
$categorias = mysqli_query($conn, "SELECT * FROM categorias ORDER BY nombre ASC");

// ====================================================================
// ⚙️ LÓGICA DE PROCESAMIENTO (GUARDAR/EDITAR/ELIMINAR)
// ====================================================================

// 🔹 Guardar nuevo producto
if (isset($_POST['guardar'])) {
    $nombre = trim($_POST['nombre']);
    $codigo_barras = trim($_POST['codigo_barras']);
    $categoria = intval($_POST['categoria']);
    $precio = floatval($_POST['precio']);
    $stock = intval($_POST['stock']);
    $stock_minimo = intval($_POST['stock_minimo'] ?? 5);
    $proveedor = trim($_POST['proveedor']);
    $descripcion = trim($_POST['descripcion']);

    if ($nombre != '') {
        if (!empty($codigo_barras)) {
            $check = $conn->prepare("SELECT id FROM productos WHERE codigo_barras = ?");
            $check->bind_param("s", $codigo_barras);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                echo "<script>alert('❌ El código de barras ya está registrado');</script>";
                $error_codigo = true;
            }
            $check->close();
        }
        
        if (!isset($error_codigo)) {
            $stmt = $conn->prepare("INSERT INTO productos (nombre, codigo_barras, categoria_id, precio, stock, stock_minimo, proveedor, descripcion)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiddiss", $nombre, $codigo_barras ?: null, $categoria, $precio, $stock, $stock_minimo, $proveedor, $descripcion);
            
            if ($stmt->execute()) {
                echo "<script>alert('✅ Producto agregado correctamente'); window.location='./';</script>";
            } else {
                echo "<script>alert('❌ Error: " . addslashes($stmt->error) . "');</script>";
            }
            $stmt->close();
        }
    }
}

// 🔹 Editar producto
if (isset($_POST['editar'])) {
    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $codigo_barras = trim($_POST['codigo_barras']);
    $categoria = intval($_POST['categoria']);
    $precio = floatval($_POST['precio']);
    $stock = intval($_POST['stock']);
    $stock_minimo = intval($_POST['stock_minimo'] ?? 5);
    $proveedor = trim($_POST['proveedor']);
    $descripcion = trim($_POST['descripcion']);

    if (!empty($codigo_barras)) {
        $check = $conn->prepare("SELECT id FROM productos WHERE codigo_barras = ? AND id != ?");
        $check->bind_param("si", $codigo_barras, $id);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            echo "<script>alert('❌ El código de barras ya está registrado');</script>";
            $error_codigo = true;
        }
        $check->close();
    }
    
    if (!isset($error_codigo)) {
        $stmt = $conn->prepare("UPDATE productos SET
                  nombre=?,
                  codigo_barras=?,
                  categoria_id=?,
                  precio=?,
                  stock=?,
                  stock_minimo=?,
                  proveedor=?,
                  descripcion=?
                  WHERE id=?");
        $stmt->bind_param("ssiddissi", $nombre, $codigo_barras ?: null, $categoria, $precio, $stock, $stock_minimo, $proveedor, $descripcion, $id);
        
        if ($stmt->execute()) {
            echo "<script>alert('✏️ Producto actualizado'); window.location='./';</script>";
        } else {
            echo "<script>alert('❌ Error: " . addslashes($stmt->error) . "');</script>";
        }
        $stmt->close();
    }
}

// 🔴 Eliminar producto
if (isset($_POST['eliminar'])) {
    $id = intval($_POST['eliminar']);
    $stmt = $conn->prepare("DELETE FROM productos WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo "<script>alert('🗑️ Producto eliminado'); window.location='./';</script>";
}

// ====================================================================
// 📈 LÓGICA DE BÚSQUEDA, FILTROS Y PAGINACIÓN
// ====================================================================

// Obtener filtros de la URL
$filtro_nombre = isset($_GET['nombre']) ? trim($_GET['nombre']) : '';
$filtro_categoria = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;
$filtro_proveedor = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$filtro_stock_bajo = isset($_GET['stock_bajo']) && $_GET['stock_bajo'] == '1' ? true : false;

// Construir consulta WHERE
$where_conditions = [];
$params = [];

if ($filtro_nombre != '') {
    $like_nombre = mysqli_real_escape_string($conn, "%$filtro_nombre%");
    $where_conditions[] = "p.nombre LIKE '$like_nombre'";
}

if ($filtro_categoria > 0) {
    $where_conditions[] = "p.categoria_id = $filtro_categoria";
}

if ($filtro_proveedor != '') {
    $like_proveedor = mysqli_real_escape_string($conn, "%$filtro_proveedor%");
    $where_conditions[] = "p.proveedor LIKE '$like_proveedor'";
}

if ($filtro_stock_bajo) {
    $where_conditions[] = "p.stock <= p.stock_minimo";
}

$where_sql = '';
if (count($where_conditions) > 0) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Paginación
$productos_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$pagina_actual = max(1, $pagina_actual);
$offset = ($pagina_actual - 1) * $productos_por_pagina;

// Contar total de productos con filtros
$count_query = "SELECT COUNT(*) AS total FROM productos p $where_sql";
$total_result = mysqli_query($conn, $count_query);
$total_productos = mysqli_fetch_assoc($total_result)['total'];
$total_paginas = ceil($total_productos / $productos_por_pagina);

// Consulta de productos con filtros y paginación
$query_productos = "
    SELECT p.*, c.nombre AS categoria_nombre
    FROM productos p
    LEFT JOIN categorias c ON p.categoria_id = c.id
    $where_sql
    ORDER BY p.id DESC
    LIMIT $productos_por_pagina OFFSET $offset
";
$result = mysqli_query($conn, $query_productos);

// Datos para las tarjetas móviles
$productos_movil = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($pm = mysqli_fetch_assoc($result)) {
        $productos_movil[] = [
            'id' => (int)$pm['id'],
            'codigo_barras' => $pm['codigo_barras'] ?? '',
            'nombre' => $pm['nombre'],
            'categoria_nombre' => $pm['categoria_nombre'] ?? '',
            'categoria_id' => (int)$pm['categoria_id'],
            'precio' => floatval($pm['precio']),
            'stock' => (int)$pm['stock'],
            'stock_minimo' => (int)$pm['stock_minimo'],
            'proveedor' => $pm['proveedor'] ?? '',
            'descripcion' => $pm['descripcion'] ?? '',
        ];
    }
    mysqli_data_seek($result, 0);
}

// Mini-stats para el dashboard de productos
$stats_total = (int)$total_productos;
$stats_bajo = 0;
$stats_valor = 0.0;
$qr = mysqli_query($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(precio*stock),0) AS v, COALESCE(SUM((stock <= stock_minimo)),0) AS b FROM productos");
if ($qr && $rw = mysqli_fetch_assoc($qr)) {
    $stats_bajo = (int)$rw['b'];
    $stats_valor = (float)$rw['v'];
}

// Función para construir URLs con filtros
function buildUrl($pagina = null, $reset_page = false) {
    $params = [];
    
    if (isset($_GET['nombre']) && $_GET['nombre'] != '') {
        $params[] = 'nombre=' . urlencode($_GET['nombre']);
    }
    if (isset($_GET['categoria']) && $_GET['categoria'] > 0) {
        $params[] = 'categoria=' . intval($_GET['categoria']);
    }
    if (isset($_GET['proveedor']) && $_GET['proveedor'] != '') {
        $params[] = 'proveedor=' . urlencode($_GET['proveedor']);
    }
    if (isset($_GET['stock_bajo']) && $_GET['stock_bajo'] == '1') {
        $params[] = 'stock_bajo=1';
    }
    
    if ($pagina !== null && !$reset_page) {
        $params[] = 'pagina=' . $pagina;
    }
    
    return '?' . implode('&', $params);
}

// Limpiar filtros
function clearFiltersUrl() {
    return './';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos - Luxury POS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <style>
        :root{
            --bg:#f2f4f9;
            --card:#ffffff;
            --card-2:#f7f9fc;
            --border:#e6eaf0;
            --border-soft:#f1f5f9;
            --text:#0f172a;
            --muted:#66738c;
            --accent:#2563eb;
            --accent-soft:#eff4ff;
            --accent-ring:rgba(37,99,235,.32);
        }
        html.dark{
            --bg:#0a0f1e;
            --card:#111a2e;
            --card-2:#0e1626;
            --border:#1e2a44;
            --border-soft:#182238;
            --text:#e6ebf4;
            --muted:#8fa1bd;
            --accent:#5b8cff;
            --accent-soft:#182744;
            --accent-ring:rgba(91,140,255,.35);
        }
        html{-webkit-tap-highlight-color:transparent}
        body{
            font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,sans-serif;
            background:var(--bg);color:var(--text);
        }
        @keyframes cardIn{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}
        .card-in{animation:cardIn .35s cubic-bezier(.16,1,.3,1) both}
        .no-scrollbar::-webkit-scrollbar{display:none}
        .no-scrollbar{-ms-overflow-style:none;scrollbar-width:none}
        .stock-bajo{color:#ef4444;font-weight:bold;animation:pulse 1.5s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
        input:focus,select:focus,textarea:focus{outline:none!important}
        .chip{
            display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;
            padding:.48rem 1rem;border-radius:9999px;font-size:.73rem;font-weight:700;
            color:var(--muted);background:var(--card);border:1px solid var(--border);
            transition:all .2s;
        }
        .chip.active{
            color:#fff;background:var(--accent);border-color:var(--accent);
            box-shadow:0 6px 16px var(--accent-ring);
        }
        .input-app{
            width:100%;border:1px solid var(--border);background:var(--card-2);
            border-radius:.9rem;padding:.78rem 1rem;font-size:16px;color:var(--text);
            transition:border-color .2s, box-shadow .2s, background .2s;
        }
        .input-app:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 3px var(--accent-ring);
            background:var(--card);
        }
        .tabla-prod input,.tabla-prod select{background:var(--card-2)!important;border-color:var(--border)!important;color:var(--text)!important}
        .filtros-panel label{color:var(--muted)}
        .filtros-panel input:not([type=checkbox]),.filtros-panel select{background:var(--card-2)!important;border-color:var(--border)!important;color:var(--text)!important}
        .filtros-panel span{color:var(--text)}
        #modalProducto label,#modalEditarProducto label{color:var(--text)}
        #modalProducto label i,#modalEditarProducto label i{color:var(--muted)}
        #modalProducto input,#modalProducto select,#modalProducto textarea,
        #modalEditarProducto input,#modalEditarProducto select,#modalEditarProducto textarea{
            background:var(--card-2)!important;border-color:var(--border)!important;color:var(--text)!important;
        }
        #modalProducto .modal-sheet,#modalEditarProducto .modal-sheet{background:var(--card)!important}
        #modalProducto .modal-head,#modalEditarProducto .modal-head,#modalProducto .modal-foot,#modalEditarProducto .modal-foot{background:var(--card)!important;border-color:var(--border-soft)!important}
        #modalProducto h3,#modalEditarProducto h3{color:var(--text)}
        #modalProducto p.note,#modalEditarProducto .muted{color:var(--muted)}
        #modalProducto .x-btn,#modalEditarProducto .x-btn{color:var(--muted)}
        /* transición de tema suave */
        body, .tabla-prod td { transition: background .25s ease, color .25s ease; }

        /* ===== Modal editar producto: pulido mobile ===== */
        #modalEditarProducto .modal-sheet{animation:editSheetUp .28s cubic-bezier(.22,1,.36,1)}
        @keyframes editSheetUp{from{transform:translateY(28px);opacity:.5}to{transform:translateY(0);opacity:1}}
        #modalEditarProducto .edit-grabber{width:44px;height:5px;border-radius:9999px;background:var(--border);margin:10px auto 2px;flex-shrink:0}
        #modalEditarProducto input,#modalEditarProducto select,#modalEditarProducto textarea{font-size:16px!important;touch-action:manipulation}
        #modalEditarProducto select{
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E")!important;
            background-repeat:no-repeat!important;
            background-position:right .9rem center!important;
            background-size:14px!important;
            padding-right:2.6rem!important;
        }
        #modalEditarProducto .grid-cols-3 .input-app{padding-left:.6rem;padding-right:.6rem;text-align:center}
        #modalEditarProducto .modal-foot{padding-bottom:calc(.75rem + env(safe-area-inset-bottom))}
        @media (min-width:640px){ #modalEditarProducto .modal-sheet{animation:none} #modalEditarProducto .edit-grabber{display:none} }
    </style>
</head>
<body class="font-app bg-[var(--bg)] text-[var(--text)]">

<?php luxury_render_nav_start('productos'); ?>

<div class="p-0 space-y-3 md:space-y-6">
    <!-- Encabezado -->
    <div class="flex justify-between items-end gap-3">
        <div>
            <h1 class="text-lg md:text-xl font-extrabold tracking-tight">Productos</h1>
            <p class="text-[11px] md:text-xs text-[var(--muted)] mt-0.5"><?= number_format($total_productos) ?> productos · <?= number_format($stats_bajo) ?> bajo stock</p>
        </div>
        <button onclick="toggleModal(true)" class="hidden md:inline-flex items-center gap-2 bg-[var(--accent)] hover:opacity-90 text-white px-4 py-2.5 rounded-xl font-bold shadow-lg shadow-[var(--accent-ring)] transition active:scale-95">
            <i class="fas fa-plus"></i> Nuevo Producto
        </button>
    </div>

    <!-- Búsqueda + tema (móvil) -->
    <div class="md:hidden flex items-center gap-2">
        <form method="GET" class="flex-1 relative" action="">
            <?php if ($filtro_categoria > 0): ?><input type="hidden" name="categoria" value="<?= $filtro_categoria ?>"><?php endif; ?>
            <?php if ($filtro_proveedor !== ''): ?><input type="hidden" name="proveedor" value="<?= htmlspecialchars($filtro_proveedor) ?>"><?php endif; ?>
            <?php if ($filtro_stock_bajo): ?><input type="hidden" name="stock_bajo" value="1"><?php endif; ?>
            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-[var(--muted)] text-sm pointer-events-none"></i>
            <input type="text" name="nombre" value="<?= htmlspecialchars($filtro_nombre) ?>" placeholder="Buscar producto..."
                   class="input-app pl-11 rounded-full">
            <?php if ($filtro_nombre !== ''): ?>
                <a href="<?= clearFiltersUrl() ?>" class="absolute right-3 top-1/2 -translate-y-1/2 w-7 h-7 rounded-full bg-[var(--card)] border border-[var(--border)] text-[var(--muted)] flex items-center justify-center text-[11px]">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Chips de categorías (móvil) -->
    <div class="md:hidden -mx-3 px-3">
        <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
            <a href="<?= clearFiltersUrl() ?>" class="chip <?= (!$filtro_categoria && !$filtro_stock_bajo) ? 'active' : '' ?>">
                <i class="fas fa-th-large"></i> Todos
            </a>
            <?php
            mysqli_data_seek($categorias, 0);
            while ($cat = mysqli_fetch_assoc($categorias)) {
                $ct_url = '?categoria=' . $cat['id'];
                if ($filtro_nombre !== '') $ct_url .= '&nombre=' . urlencode($filtro_nombre);
                if ($filtro_stock_bajo) $ct_url .= '&stock_bajo=1';
                $ct_cls = ($filtro_categoria == $cat['id']) ? ' active' : '';
                echo "<a href='$ct_url' class='chip$ct_cls'>" . htmlspecialchars($cat['nombre']) . "</a>";
            }
            $sb_url = '?stock_bajo=1';
            if ($filtro_nombre !== '') $sb_url .= '&nombre=' . urlencode($filtro_nombre);
            $sb_cls = $filtro_stock_bajo ? ' active' : '';
            echo "<a href='$sb_url' class='chip$sb_cls'><i class='fas fa-exclamation-triangle'></i> Stock bajo</a>";
            ?>
        </div>
    </div>

    <!-- Mini-stats (móvil) -->
    <div class="md:hidden grid grid-cols-3 gap-2.5">
        <div class="card-in bg-[var(--card)] border border-[var(--border)] rounded-2xl p-3.5">
            <span class="w-9 h-9 rounded-xl bg-blue-500/10 text-blue-500 flex items-center justify-center"><i class="fas fa-box text-sm"></i></span>
            <div class="text-2xl font-extrabold mt-2.5 leading-none tracking-tight"><?= number_format($stats_total) ?></div>
            <div class="text-[10px] font-bold uppercase tracking-widest text-[var(--muted)] mt-1">Productos</div>
        </div>
        <div class="card-in bg-[var(--card)] border border-[var(--border)] rounded-2xl p-3.5" style="animation-delay:60ms">
            <span class="w-9 h-9 rounded-xl bg-rose-500/10 text-rose-500 flex items-center justify-center"><i class="fas fa-exclamation-triangle text-sm"></i></span>
            <div class="text-2xl font-extrabold mt-2.5 leading-none tracking-tight"><?= number_format($stats_bajo) ?></div>
            <div class="text-[10px] font-bold uppercase tracking-widest text-[var(--muted)] mt-1">Bajo stock</div>
        </div>
        <div class="card-in bg-[var(--card)] border border-[var(--border)] rounded-2xl p-3.5" style="animation-delay:120ms">
            <span class="w-9 h-9 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center"><i class="fas fa-dollar-sign text-sm"></i></span>
            <div class="text-xl font-extrabold mt-2.5 leading-none tracking-tight truncate">$<?= number_format($stats_valor, 0) ?></div>
            <div class="text-[10px] font-bold uppercase tracking-widest text-[var(--muted)] mt-1">Inventario</div>
        </div>
    </div>

    <!-- Panel de Filtros (escritorio) -->
    <div class="hidden md:block bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5">
        <h3 class="text-sm font-bold text-[var(--muted)] mb-4 flex items-center">
            <i class="fas fa-sliders-h mr-2 text-[var(--accent)]"></i> Filtrar y buscar
        </h3>
        
        <form method="GET" action="" class="filtros-panel space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Búsqueda por nombre -->
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">
                        <i class="fas fa-search mr-1"></i> Nombre
                    </label>
                    <input type="text" name="nombre" value="<?= htmlspecialchars($filtro_nombre) ?>" 
                           placeholder="Buscar por nombre..." 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                </div>
                
                <!-- Filtro por categoría -->
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">
                        <i class="fas fa-tags mr-1"></i> Categoría
                    </label>
                    <select name="categoria" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                        <option value="0">Todas las categorías</option>
                        <?php
                        mysqli_data_seek($categorias, 0);
                        while ($cat = mysqli_fetch_assoc($categorias)) {
                            $selected = ($filtro_categoria == $cat['id']) ? 'selected' : '';
                            echo "<option value='{$cat['id']}' $selected>" . htmlspecialchars($cat['nombre']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <!-- Filtro por proveedor -->
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">
                        <i class="fas fa-truck mr-1"></i> Proveedor
                    </label>
                    <select name="proveedor" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                        <option value="">Todos los proveedores</option>
                        <?php
                        mysqli_data_seek($proveedores, 0);
                        while ($prov = mysqli_fetch_assoc($proveedores)) {
                            $selected = ($filtro_proveedor == $prov['nombre']) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($prov['nombre']) . "' $selected>" . htmlspecialchars($prov['nombre']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <!-- Stock bajo -->
                <div class="flex items-end">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="stock_bajo" value="1" <?= $filtro_stock_bajo ? 'checked' : '' ?> class="w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500">
                        <span class="ml-2 text-sm text-gray-700">
                            <i class="fas fa-exclamation-triangle text-red-500 mr-1"></i> Stock bajo
                        </span>
                    </label>
                </div>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" class="inline-flex items-center gap-2 bg-[var(--accent)] hover:opacity-90 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition active:scale-95">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <a href="<?= clearFiltersUrl() ?>" class="inline-flex items-center gap-2 bg-[var(--card-2)] hover:bg-[var(--border)] text-[var(--muted)] px-5 py-2.5 rounded-xl text-sm font-bold border border-[var(--border)] transition active:scale-95">
                    <i class="fas fa-eraser"></i> Limpiar
                </a>
            </div>
        </form>
    </div>

    <!-- Tabla de Productos (escritorio) -->
    <div class="hidden md:block bg-[var(--card)] border border-[var(--border)] rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm tabla-prod">
                <thead class="bg-[var(--card-2)] text-[var(--muted)] uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3.5 text-left font-bold">#</th>
                        <th class="px-4 py-3.5 text-left font-bold">Código Barras</th>
                        <th class="px-4 py-3.5 text-left font-bold">Nombre</th>
                        <th class="px-4 py-3.5 text-left font-bold">Categoría</th>
                        <th class="px-4 py-3.5 text-right font-bold">Precio</th>
                        <th class="px-4 py-3.5 text-center font-bold">Stock</th>
                        <th class="px-4 py-3.5 text-center font-bold">Stock Mín.</th>
                        <th class="px-4 py-3.5 text-left font-bold">Proveedor</th>
                        <th class="px-4 py-3.5 text-center font-bold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                    <?php
                    if (mysqli_num_rows($result) > 0) {
                        $i = $offset + 1;
                        while ($row = mysqli_fetch_assoc($result)) {
                            // Resetear punteros
                            mysqli_data_seek($categorias, 0); 
                            mysqli_data_seek($proveedores, 0);
                            
                            $stock_bajo = ($row['stock'] <= $row['stock_minimo']);
                            $stock_class = $stock_bajo ? 'stock-bajo' : '';
                            
                            echo "
                            <tr class='hover:bg-[var(--card-2)] transition'>
                                <form method='POST'>
                                    <input type='hidden' name='id' value='{$row['id']}'>
                                    <input type='hidden' name='_csrf' value='" . csrf_token() . "'>
                                    <td class='px-4 py-3 font-medium'>{$i}</td>
                                    <td class='px-4 py-3'>
                                        <input type='text' name='codigo_barras' value='" . htmlspecialchars($row['codigo_barras'] ?? '') . "' 
                                               class='w-full border rounded-lg p-1.5 font-mono text-xs focus:ring-2 focus:ring-blue-500'>
                                    </td>
                                    <td class='px-4 py-3'>
                                        <input type='text' name='nombre' value='" . htmlspecialchars($row['nombre']) . "' 
                                               class='w-full border rounded-lg p-1.5 text-sm focus:ring-2 focus:ring-blue-500' required>
                                    </td>
                                    <td class='px-4 py-3'>
                                        <select name='categoria' class='w-full border rounded-lg p-1.5 text-sm focus:ring-2 focus:ring-blue-500'>";
                                            while ($cat = mysqli_fetch_assoc($categorias)) {
                                                $sel = ($cat['id'] == $row['categoria_id']) ? "selected" : "";
                                                echo "<option value='{$cat['id']}' $sel>" . htmlspecialchars($cat['nombre']) . "</option>";
                                            }
                            echo "      </select>
                                    </td>
                                    <td class='px-4 py-3 text-right'>
                                        <input type='number' name='precio' value='{$row['precio']}' step='0.01' 
                                               class='w-full border rounded-lg p-1.5 text-right text-sm focus:ring-2 focus:ring-blue-500' required>
                                    </td>
                                    <td class='px-4 py-3 text-center'>
                                        <input type='number' name='stock' value='{$row['stock']}' 
                                               class='w-24 border rounded-lg p-1.5 text-center text-sm $stock_class focus:ring-2 focus:ring-blue-500'>
                                    </td>
                                    <td class='px-4 py-3 text-center'>
                                        <input type='number' name='stock_minimo' value='{$row['stock_minimo']}' 
                                               class='w-20 border rounded-lg p-1.5 text-center text-sm focus:ring-2 focus:ring-blue-500'>
                                    </td>
                                    <td class='px-4 py-3'>
                                        <select name='proveedor' class='w-full border rounded-lg p-1.5 text-sm focus:ring-2 focus:ring-blue-500'>";
                                            while ($prov = mysqli_fetch_assoc($proveedores)) {
                                                $sel = ($prov['nombre'] == $row['proveedor']) ? "selected" : "";
                                                echo "<option value='" . htmlspecialchars($prov['nombre']) . "' $sel>" . htmlspecialchars($prov['nombre']) . "</option>";
                                            }
                            echo "      </select>
                                    </td>
                                    <td class='px-4 py-3 text-center'>
                                        <div class='flex items-center justify-center gap-2'>
                                            <button type='submit' name='editar' class='bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1.5 rounded-lg transition' title='Guardar'>
                                                <i class='fas fa-save'></i>
                                            </button>
                                            <button onclick='eliminarProducto({$row['id']})' class='bg-red-500 hover:bg-red-600 text-white px-2 py-1.5 rounded-lg transition' title='Eliminar'>
                                                <i class='fas fa-trash'></i>
                                            </button>
                                        </div>
                                    </td>
                                </form>
                            </tr>";
                            $i++;
                        }
                    } else {
                        echo "<tr><td colspan='9' class='text-center py-12 text-[var(--muted)]'>
                                <i class='fas fa-box-open text-4xl mb-3 block opacity-40'></i>
                                No hay productos con estos filtros
                              </td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Lista de Productos (móvil) -->
    <div id="productos-movil" class="md:hidden space-y-3"></div>

    <!-- Paginación (móvil) -->
    <?php if ($total_paginas > 1): ?>
        <div class="md:hidden flex items-center gap-2 bg-[var(--card)] border border-[var(--border)] rounded-2xl p-2">
            <?php if ($pagina_actual > 1): ?>
                <a href="<?= buildUrl($pagina_actual - 1) ?>" class="flex-1 flex items-center justify-center gap-1.5 py-3 rounded-xl border border-[var(--border)] text-xs font-bold text-[var(--muted)] transition active:scale-95">
                    <i class="fas fa-chevron-left text-[10px]"></i> Anterior
                </a>
            <?php else: ?>
                <span class="flex-1 flex items-center justify-center gap-1.5 py-3 rounded-xl text-xs font-bold text-[var(--border)] pointer-events-none">
                    <i class="fas fa-chevron-left text-[10px]"></i> Anterior
                </span>
            <?php endif; ?>
            <span class="text-xs font-extrabold text-[var(--muted)] px-1 flex-shrink-0"><?= $pagina_actual ?> / <?= $total_paginas ?></span>
            <?php if ($pagina_actual < $total_paginas): ?>
                <a href="<?= buildUrl($pagina_actual + 1) ?>" class="flex-1 flex items-center justify-center gap-1.5 py-3 rounded-xl bg-[var(--accent)] text-white text-xs font-bold transition active:scale-95">
                    Siguiente <i class="fas fa-chevron-right text-[10px]"></i>
                </a>
            <?php else: ?>
                <span class="flex-1 flex items-center justify-center gap-1.5 py-3 rounded-xl text-xs font-bold text-[var(--border)] pointer-events-none">
                    Siguiente <i class="fas fa-chevron-right text-[10px]"></i>
                </span>
            <?php endif; ?>
        </div>

        <!-- Paginación (escritorio) -->
        <div class="hidden md:flex justify-center items-center gap-1.5 mt-6">
            <?php if ($pagina_actual > 1): ?>
                <a href="<?= buildUrl($pagina_actual - 1) ?>" class="px-3.5 py-2 border border-[var(--border)] bg-[var(--card)] rounded-xl hover:bg-[var(--card-2)] text-sm font-bold text-[var(--muted)] transition">
                    <i class="fas fa-chevron-left"></i> Anterior
                </a>
            <?php endif; ?>

            <?php 
            $rango = 2;
            $inicio = max(1, $pagina_actual - $rango);
            $fin = min($total_paginas, $pagina_actual + $rango);

            if ($inicio > 1) {
                echo '<a href="' . buildUrl(1) . '" class="px-3.5 py-2 rounded-xl border border-[var(--border)] bg-[var(--card)] hover:bg-[var(--card-2)] text-sm font-bold transition">1</a>';
                if ($inicio > 2) {
                    echo '<span class="px-2 py-2 text-[var(--muted)] font-bold">...</span>';
                }
            }

            for ($i = $inicio; $i <= $fin; $i++):
                $clase = ($i === $pagina_actual) ? 'bg-[var(--accent)] text-white shadow-lg shadow-[var(--accent-ring)]' : 'bg-[var(--card)] text-[var(--muted)] border border-[var(--border)] hover:bg-[var(--card-2)]';
            ?>
                <a href="<?= buildUrl($i) ?>" class="px-3.5 py-2 rounded-xl text-sm font-bold transition <?= $clase ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($fin < $total_paginas): ?>
                <?php if ($fin < $total_paginas - 1): ?>
                    <span class="px-2 py-2 text-[var(--muted)] font-bold">...</span>
                <?php endif; ?>
                <a href="<?= buildUrl($total_paginas) ?>" class="px-3.5 py-2 rounded-xl border border-[var(--border)] bg-[var(--card)] hover:bg-[var(--card-2)] text-sm font-bold transition"><?= $total_paginas ?></a>
            <?php endif; ?>

            <?php if ($pagina_actual < $total_paginas): ?>
                <a href="<?= buildUrl($pagina_actual + 1) ?>" class="px-3.5 py-2 border border-[var(--border)] bg-[var(--card)] rounded-xl hover:bg-[var(--card-2)] text-sm font-bold text-[var(--muted)] transition">
                    Siguiente <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Resumen de filtros activos (escritorio) -->
    <?php if ($filtro_nombre || $filtro_categoria > 0 || $filtro_proveedor || $filtro_stock_bajo): ?>
        <div class="hidden md:block mt-4 text-center text-xs text-[var(--muted)]">
            <i class="fas fa-filter mr-1"></i> Filtros aplicados:
            <?php if ($filtro_nombre): ?>
                <span class="bg-[var(--card)] border border-[var(--border)] px-2 py-1 rounded mx-1">Nombre: <?= htmlspecialchars($filtro_nombre) ?></span>
            <?php endif; ?>
            <?php if ($filtro_categoria > 0): ?>
                <span class="bg-[var(--card)] border border-[var(--border)] px-2 py-1 rounded mx-1">Categoría filtrada</span>
            <?php endif; ?>
            <?php if ($filtro_proveedor): ?>
                <span class="bg-[var(--card)] border border-[var(--border)] px-2 py-1 rounded mx-1">Proveedor: <?= htmlspecialchars($filtro_proveedor) ?></span>
            <?php endif; ?>
            <?php if ($filtro_stock_bajo): ?>
                <span class="bg-rose-500/10 text-rose-500 px-2 py-1 rounded mx-1"><i class="fas fa-exclamation-triangle mr-1"></i>Solo stock bajo</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- FAB Nuevo Producto (móvil) -->
<button onclick="toggleModal(true)" aria-label="Nuevo Producto"
        class="md:hidden fixed bottom-24 right-5 z-[60] w-14 h-14 rounded-full bg-[var(--accent)] hover:opacity-90 text-white shadow-xl shadow-[var(--accent-ring)] flex items-center justify-center active:scale-90 transition-all duration-200 hover:-translate-y-0.5">
    <i class="fas fa-plus text-xl"></i>
</button>

<!-- MODAL NUEVO PRODUCTO -->
<div id="modalProducto" class="hidden fixed inset-0 bg-black/60 z-[100] flex items-end sm:items-center justify-center" onclick="toggleModal(false)">
    <div class="modal-sheet bg-white w-[calc(100%-2rem)] sm:max-w-lg sm:w-full rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[92dvh] sm:max-h-[90vh] overflow-hidden" onclick="event.stopPropagation()">
        <div class="modal-head sticky top-0 bg-white border-b border-gray-100 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-500 flex items-center justify-center">
                    <i class="fas fa-barcode"></i>
                </span>
                <h3 class="font-bold text-lg leading-tight">Nuevo Producto</h3>
            </div>
            <button type="button" onclick="toggleModal(false)" class="x-btn w-10 h-10 rounded-full hover:bg-gray-100 flex items-center justify-center">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" class="flex flex-col flex-1 overflow-hidden">
            <?= csrf_field() ?>
            <div class="overflow-y-auto px-5 py-4 space-y-4">
                <div>
                    <label class="flex items-center gap-1 text-sm font-semibold mb-1">
                        <i class="fas fa-barcode text-gray-400"></i> Código de Barras
                    </label>
                    <div class="flex gap-2">
                        <input name="codigo_barras" type="text" placeholder="Escanea o escribe el código..." style="font-size:16px"
                               class="input-app font-mono text-sm">
                        <button type="button" id="btnGenerarCodigo" class="bg-[var(--card-2)] border border-[var(--border)] text-[var(--muted)] px-4 rounded-xl text-sm font-bold transition active:scale-95 flex-shrink-0">
                            <i class="fas fa-random"></i>
                        </button>
                    </div>
                    <p class="note text-xs mt-1.5">Código único. Puedes escanearlo o generarlo automáticamente.</p>
                </div>
                
                <div>
                    <label class="flex items-center gap-1 text-sm font-semibold mb-1">
                        <i class="fas fa-tag text-gray-400"></i> Nombre del producto *
                    </label>
                    <input name="nombre" type="text" placeholder="Ej. Zapato deportivo Nike" style="font-size:16px"
                           class="input-app" required>
                </div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="flex items-center gap-1 text-sm font-semibold mb-1">
                            <i class="fas fa-layer-group text-gray-400"></i> Categoría *
                        </label>
                        <select name="categoria" class="input-app" required>
                            <option value="">Seleccionar...</option>
                            <?php
                            mysqli_data_seek($categorias, 0);
                            while ($cat = mysqli_fetch_assoc($categorias)) {
                                echo "<option value='{$cat['id']}'>" . htmlspecialchars($cat['nombre']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="flex items-center gap-1 text-sm font-semibold mb-1">
                            <i class="fas fa-truck text-gray-400"></i> Proveedor *
                        </label>
                        <select name="proveedor" class="input-app" required>
                            <option value="">Seleccionar...</option>
                            <?php
                            mysqli_data_seek($proveedores, 0);
                            while ($prov = mysqli_fetch_assoc($proveedores)) {
                                echo "<option value='" . htmlspecialchars($prov['nombre']) . "'>" . htmlspecialchars($prov['nombre']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="flex items-center gap-1 text-sm font-semibold mb-1">
                        <i class="fas fa-dollar-sign text-gray-400"></i> Precio Unitario *
                    </label>
                    <input name="precio" type="number" step="0.01" inputmode="decimal" placeholder="Ej. 120000" style="font-size:16px"
                           class="input-app" required>
                </div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="flex items-center gap-1 text-sm font-semibold mb-1">
                            <i class="fas fa-boxes text-gray-400"></i> Stock Inicial
                        </label>
                        <input name="stock" type="number" inputmode="numeric" placeholder="Ej. 50" value="0" style="font-size:16px"
                               class="input-app">
                    </div>
                    <div>
                        <label class="flex items-center gap-1 text-sm font-semibold mb-1">
                            <i class="fas fa-exclamation text-gray-400"></i> Stock Mínimo
                        </label>
                        <input name="stock_minimo" type="number" inputmode="numeric" value="5" placeholder="Ej. 5" style="font-size:16px"
                               class="input-app">
                        <p class="note text-xs mt-1.5">Alerta cuando baje de este número</p>
                    </div>
                </div>
                
                <div>
                    <label class="flex items-center gap-1 text-sm font-semibold mb-1">
                        <i class="fas fa-align-left text-gray-400"></i> Descripción
                    </label>
                    <textarea name="descripcion" rows="3" placeholder="Detalles del producto..." style="font-size:16px"
                              class="input-app"></textarea>
                </div>
            </div>

            <div class="modal-foot sticky bottom-0 bg-white border-t border-gray-100 px-5 py-3 flex gap-3">
                <button type="button" onclick="toggleModal(false)" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition active:scale-95">
                    <i class="fas fa-times mr-1"></i> Cancelar
                </button>
                <button type="submit" name="guardar" class="flex-1 bg-[var(--accent)] hover:opacity-90 text-white font-bold py-3 rounded-xl transition active:scale-95">
                    <i class="fas fa-save mr-1"></i> Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR PRODUCTO -->
<div id="modalEditarProducto" class="hidden fixed inset-0 bg-black/60 z-[100] flex items-end sm:items-center justify-center" onclick="cerrarEditar()">
    <div class="modal-sheet bg-white w-full sm:max-w-lg sm:w-[calc(100%-2rem)] rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[92dvh] sm:max-h-[90vh] overflow-hidden" onclick="event.stopPropagation()">

        <div class="edit-grabber" aria-hidden="true"></div>

        <div class="modal-head sticky top-0 bg-white border-b border-gray-100 px-4 pt-2 pb-3 flex items-center gap-3">
            <span class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-500 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-pen"></i>
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="font-bold leading-tight">Editar Producto</h3>
                <p id="e_titulo" class="muted text-[11px] truncate mt-0.5"></p>
            </div>
            <button type="button" onclick="cerrarEditar()" class="x-btn w-10 h-10 rounded-full hover:bg-gray-100 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" class="flex flex-col flex-1 overflow-hidden">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="e_id">

            <div class="overflow-y-auto px-4 sm:px-5 py-4 space-y-3.5" id="e_body_scroll">
                <div>
                    <label class="flex items-center gap-1 text-[13px] font-semibold text-gray-700 mb-1">
                        <i class="fas fa-barcode text-gray-400"></i> Código de Barras
                    </label>
                    <input id="e_codigo" name="codigo_barras" type="text" autocomplete="off" enterkeyhint="next"
                           placeholder="Código del producto" class="input-app font-mono text-sm">
                </div>

                <div>
                    <label class="flex items-center gap-1 text-[13px] font-semibold text-gray-700 mb-1">
                        <i class="fas fa-tag text-gray-400"></i> Nombre del producto *
                    </label>
                    <input id="e_nombre" name="nombre" type="text" autocomplete="off" enterkeyhint="next" class="input-app" required>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="flex items-center gap-1 text-[13px] font-semibold text-gray-700 mb-1">
                            <i class="fas fa-layer-group text-gray-400"></i> Categoría *
                        </label>
                        <select id="e_categoria" name="categoria" class="input-app" required>
                            <option value="">Seleccionar...</option>
                            <?php
                            mysqli_data_seek($categorias, 0);
                            while ($cat = mysqli_fetch_assoc($categorias)) {
                                echo "<option value='{$cat['id']}'>" . htmlspecialchars($cat['nombre']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="flex items-center gap-1 text-[13px] font-semibold text-gray-700 mb-1">
                            <i class="fas fa-truck text-gray-400"></i> Proveedor
                        </label>
                        <select id="e_proveedor" name="proveedor" class="input-app">
                            <option value="">Sin proveedor</option>
                            <?php
                            mysqli_data_seek($proveedores, 0);
                            while ($prov = mysqli_fetch_assoc($proveedores)) {
                                echo "<option value='" . htmlspecialchars($prov['nombre']) . "'>" . htmlspecialchars($prov['nombre']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2.5">
                    <div>
                        <label class="flex items-center gap-1 text-[13px] font-semibold text-gray-700 mb-1">
                            <i class="fas fa-dollar-sign text-gray-400"></i> Precio *
                        </label>
                        <input id="e_precio" name="precio" type="number" step="0.01" min="0" inputmode="decimal" class="input-app font-bold" required>
                    </div>
                    <div>
                        <label class="flex items-center gap-1 text-[13px] font-semibold text-gray-700 mb-1">
                            <i class="fas fa-boxes text-gray-400"></i> Stock
                        </label>
                        <input id="e_stock" name="stock" type="number" min="0" inputmode="numeric" class="input-app">
                    </div>
                    <div>
                        <label class="flex items-center gap-1 text-[13px] font-semibold text-gray-700 mb-1">
                            <i class="fas fa-exclamation text-gray-400"></i> Mínimo
                        </label>
                        <input id="e_stockmin" name="stock_minimo" type="number" min="0" inputmode="numeric" class="input-app">
                    </div>
                </div>

                <div>
                    <label class="flex items-center gap-1 text-[13px] font-semibold text-gray-700 mb-1">
                        <i class="fas fa-align-left text-gray-400"></i> Descripción
                    </label>
                    <textarea id="e_descripcion" name="descripcion" rows="3" enterkeyhint="done" class="input-app"></textarea>
                </div>
            </div>

             <div class="modal-foot sticky bottom-0 bg-white border-t border-gray-100 px-4 sm:px-5 pt-3 flex gap-3">
                 <button type="button" onclick="cerrarEditar()" aria-label="Cancelar" class="w-14 sm:flex-1 sm:w-auto flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold rounded-xl transition active:scale-95 h-[46px]">
                     <i class="fas fa-times sm:mr-1"></i><span class="hidden sm:inline">Cancelar</span>
                 </button>
                 <button type="submit" name="editar" class="flex-1 h-[46px] bg-[var(--accent)] hover:opacity-90 text-white font-bold rounded-xl transition active:scale-95 shadow-lg shadow-[var(--accent-ring)]">
                     <i class="fas fa-save mr-1.5"></i> Guardar cambios
                 </button>
             </div>
        </form>
    </div>
</div>

<script>
    const CSRF_TOKEN = '<?= csrf_token() ?>';
    const PRODUCTOS = <?= json_encode($productos_movil, JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function formatoMoneda(n) {
        const v = Number(n) || 0;
        return v.toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function renderCardsProductos() {
        const c = document.getElementById('productos-movil');
        if (!c) return;
        if (!PRODUCTOS.length) {
            c.innerHTML = '<div class="card-in bg-[var(--card)] border border-dashed border-[var(--border)] rounded-3xl p-10 text-center text-[var(--muted)]"><i class="fas fa-box-open text-4xl mb-3 block opacity-40"></i><p class="text-sm font-bold">Sin productos</p><p class="text-xs mt-1 opacity-70">Prueba con otros filtros</p></div>';
            return;
        }
        c.innerHTML = PRODUCTOS.map((p, i) => {
            const bajo = p.stock <= p.stock_minimo;
            const hue = (p.id * 47) % 360;
            const inicial = (p.nombre || '?').trim().charAt(0).toUpperCase();
            return `
            <div class="card-in bg-[var(--card)] border ${bajo ? 'border-rose-400/50' : 'border-[var(--border)]'} rounded-3xl p-4 shadow-sm" style="animation-delay:${i * 45}ms">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-white font-extrabold text-lg shrink-0" style="background:linear-gradient(135deg,hsl(${hue} 72% 58%),hsl(${(hue + 40) % 360} 72% 44%))">${escapeHtml(inicial)}</div>
                    <div class="min-w-0 flex-1">
                        <div class="font-bold text-[15px] leading-tight truncate flex items-center gap-1.5">
                            ${escapeHtml(p.nombre)}
                            ${bajo ? '<i class="fas fa-exclamation-circle text-rose-500 text-xs"></i>' : ''}
                        </div>
                        <div class="text-[11px] text-[var(--muted)] font-mono mt-0.5">${p.codigo_barras ? escapeHtml(p.codigo_barras) : 'Sin código'}</div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-[var(--accent)] text-lg font-extrabold leading-none tracking-tight">$${formatoMoneda(p.precio)}</div>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <span class="px-2.5 py-1 rounded-lg bg-[var(--accent-soft)] text-[var(--accent)] text-[11px] font-bold">${escapeHtml(p.categoria_nombre || 'Sin categoría')}</span>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[11px] font-bold ${bajo ? 'bg-rose-500/10 text-rose-500' : 'bg-emerald-500/10 text-emerald-500'}">
                        <i class="fas fa-boxes"></i> ${p.stock} <span class="opacity-60">/ mín ${p.stock_minimo}</span>
                    </span>
                    ${p.proveedor ? '<span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-[var(--card-2)] border border-[var(--border)] text-[var(--muted)] text-[11px] font-bold"><i class="fas fa-truck"></i> ' + escapeHtml(p.proveedor) + '</span>' : ''}
                </div>

                <div class="mt-3.5 flex gap-2">
                    <button onclick="abrirEditar(${p.id})" class="flex-1 inline-flex items-center justify-center gap-2 bg-[var(--accent-soft)] text-[var(--accent)] font-bold py-2.5 rounded-xl text-sm transition active:scale-95">
                        <i class="fas fa-pen text-xs"></i> Editar
                    </button>
                    <button onclick="eliminarProducto(${p.id})" aria-label="Eliminar" class="w-12 inline-flex items-center justify-center bg-rose-500/10 text-rose-500 font-bold py-2.5 rounded-xl text-sm transition active:scale-95">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>`;
        }).join('');
    }

    function abrirEditar(id) {
        const p = PRODUCTOS.find(x => x.id === id);
        if (!p) return;
        document.getElementById('e_titulo').textContent = p.nombre;
        document.getElementById('e_id').value = p.id;
        document.getElementById('e_codigo').value = p.codigo_barras || '';
        document.getElementById('e_nombre').value = p.nombre;
        document.getElementById('e_categoria').value = p.categoria_id || '';
        document.getElementById('e_precio').value = p.precio;
        document.getElementById('e_stock').value = p.stock;
        document.getElementById('e_stockmin').value = p.stock_minimo;
        document.getElementById('e_proveedor').value = p.proveedor || '';
        document.getElementById('e_descripcion').value = p.descripcion || '';
        const sheetEditar = document.getElementById('modalEditarProducto');
        sheetEditar.classList.remove('hidden');
        const scrollEditar = sheetEditar.querySelector('.overflow-y-auto');
        if (scrollEditar) scrollEditar.scrollTop = 0;
        document.body.style.overflow = 'hidden';
    }

    function cerrarEditar() {
        document.getElementById('modalEditarProducto').classList.add('hidden');
        document.body.style.overflow = '';
    }

    /* Mobile UX: el teclado no tapa el campo activo + Escape cierra el modal */
    document.getElementById('modalEditarProducto').addEventListener('focusin', function (e) {
        if (e.target.matches('input, select, textarea')) {
            setTimeout(function () { e.target.scrollIntoView({ block: 'center', behavior: 'smooth' }); }, 300);
        }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !document.getElementById('modalEditarProducto').classList.contains('hidden')) cerrarEditar();
    });

    renderCardsProductos();

    function eliminarProducto(id) {
        if (!confirm('¿Eliminar este producto?')) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="_csrf" value="' + CSRF_TOKEN + '">' +
            '<input type="hidden" name="eliminar" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }

    const modalProducto = document.getElementById("modalProducto");
    const toggleModal = (show) => {
        modalProducto.classList.toggle("hidden", !show);
        document.body.style.overflow = show ? 'hidden' : '';
        if (show) {
            // Limpiar formulario
            modalProducto.querySelector('input[name="codigo_barras"]').value = '';
            modalProducto.querySelector('input[name="nombre"]').value = '';
            modalProducto.querySelector('select[name="categoria"]').value = '';
            modalProducto.querySelector('select[name="proveedor"]').value = '';
            modalProducto.querySelector('input[name="precio"]').value = '';
            modalProducto.querySelector('input[name="stock"]').value = '0';
            modalProducto.querySelector('input[name="stock_minimo"]').value = '5';
            modalProducto.querySelector('textarea[name="descripcion"]').value = '';
        }
    };
    
    // Generar código de barras aleatorio
    document.getElementById('btnGenerarCodigo').addEventListener('click', function() {
        const codigoInput = modalProducto.querySelector('input[name="codigo_barras"]');
        const prefijo = '750';
        let aleatorio = '';
        for (let i = 0; i < 10; i++) {
            aleatorio += Math.floor(Math.random() * 10);
        }
        codigoInput.value = prefijo + aleatorio;
    });
    
    // Escáner automático en el modal
    let scannerActivo = false;
    let codigoBuffer = '';
    let timeoutScanner = null;
    
    function activarScannerModal() {
        scannerActivo = true;
        const input = document.querySelector('input[name="codigo_barras"]');
        input.placeholder = '🔴 Escáner activo - Escanea el código...';
        input.classList.add('bg-blue-50');
    }
    
    function desactivarScannerModal() {
        scannerActivo = false;
        codigoBuffer = '';
        if (timeoutScanner) clearTimeout(timeoutScanner);
        const input = document.querySelector('input[name="codigo_barras"]');
        input.placeholder = 'Escanea o escribe el código de barras...';
        input.classList.remove('bg-blue-50');
    }
    
    document.addEventListener('keydown', function(e) {
        if (!scannerActivo) return;
        const input = document.querySelector('input[name="codigo_barras"]');
        if (!input || input.disabled) return;
        
        if (e.key === 'Enter') {
            e.preventDefault();
            if (codigoBuffer.length > 0) {
                input.value = codigoBuffer;
                codigoBuffer = '';
            }
            if (timeoutScanner) clearTimeout(timeoutScanner);
            return;
        }
        
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            codigoBuffer += e.key;
            if (timeoutScanner) clearTimeout(timeoutScanner);
            timeoutScanner = setTimeout(() => {
                codigoBuffer = '';
            }, 100);
        }
    });
    
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (!modalProducto.classList.contains('hidden')) {
                const input = document.querySelector('input[name="codigo_barras"]');
                if (input) {
                    input.addEventListener('focus', activarScannerModal);
                    input.addEventListener('blur', desactivarScannerModal);
                    observer.disconnect();
                }
            }
        });
    });
    
    observer.observe(modalProducto, { attributes: true });
</script>

<?php luxury_render_nav_end(); ?>
</body>
</html>