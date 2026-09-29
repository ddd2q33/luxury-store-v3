<?php
// pages/inventario.php - Control de Inventario MEJORADO
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('inventario');

// ====================================================================
// FUNCIONES AUXILIARES
// ====================================================================
function fmtV($n) { return '$' . number_format((float)$n, 0, ',', '.'); }
function fmtNum($n) { return number_format((float)$n, 0, ',', '.'); }

function tipoBadgeInventario($tipo) {
    return match($tipo) {
        'Entrada' => "<span class='px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700'><i class='fas fa-arrow-down mr-1'></i>ENTRADA</span>",
        'Salida' => "<span class='px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700'><i class='fas fa-arrow-up mr-1'></i>SALIDA</span>",
        'Ajuste' => "<span class='px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-700'><i class='fas fa-balance-scale mr-1'></i>AJUSTE</span>",
        default => "<span class='px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-700'>$tipo</span>",
    };
}

// Validación de stock
function validarStock($conn, $producto_id, $cantidad, $tipo) {
    if ($tipo == 'Salida') {
        $result = mysqli_query($conn, "SELECT stock, nombre FROM productos WHERE id=$producto_id");
        $producto = mysqli_fetch_assoc($result);
        if ($producto['stock'] < $cantidad) {
            return ['ok' => false, 'message' => "Stock insuficiente para '{$producto['nombre']}'. Disponible: {$producto['stock']}, Solicitado: $cantidad"];
        }
    }
    return ['ok' => true];
}

// ====================================================================
// MÉTRICAS DEL INVENTARIO
// ====================================================================

// Total productos
$total_productos = $conn->query("SELECT COUNT(*) as total FROM productos")->fetch_assoc()['total'] ?? 0;

// Productos con stock bajo (< 5)
$productos_bajo_stock = $conn->query("SELECT COUNT(*) as total FROM productos WHERE stock < 5")->fetch_assoc()['total'] ?? 0;

// Productos sin stock (= 0)
$productos_sin_stock = $conn->query("SELECT COUNT(*) as total FROM productos WHERE stock = 0")->fetch_assoc()['total'] ?? 0;

// Valor total del inventario
$valor_inventario = $conn->query("SELECT COALESCE(SUM(stock * precio), 0) as total FROM productos")->fetch_assoc()['total'] ?? 0;

// Movimientos del mes
$movimientos_mes = $conn->query("SELECT COUNT(*) as total FROM inventario_movimientos WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;

// Entradas del mes
$entradas_mes = $conn->query("SELECT COALESCE(SUM(cantidad), 0) as total FROM inventario_movimientos WHERE tipo = 'Entrada' AND MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;

// Salidas del mes
$salidas_mes = $conn->query("SELECT COALESCE(SUM(cantidad), 0) as total FROM inventario_movimientos WHERE tipo = 'Salida' AND MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;

// Top 5 productos más movidos
$top_productos = [];
$top_query = "SELECT p.nombre, COUNT(*) as movimientos, SUM(im.cantidad) as total_cantidad
              FROM inventario_movimientos im
              JOIN productos p ON im.producto_id = p.id
              GROUP BY im.producto_id
              ORDER BY movimientos DESC
              LIMIT 5";
$top_res = $conn->query($top_query);
if ($top_res) {
    while ($row = $top_res->fetch_assoc()) {
        $top_productos[] = $row;
    }
}

// ====================================================================
// MANEJO DE MOVIMIENTOS
// ====================================================================
if (isset($_POST['guardar'])) {
    $producto_id = intval($_POST['producto']);
    $categoria_id = intval($_POST['categoria']);
    $cantidad = intval($_POST['cantidad']);
    $tipo = mysqli_real_escape_string($conn, $_POST['tipo']);
    $observaciones = trim($_POST['observaciones']);
    $fecha = date('Y-m-d H:i:s');

    if ($producto_id <= 0 || $categoria_id <= 0 || $cantidad <= 0) {
        echo "<script>alert('❌ Error: Todos los campos son requeridos'); window.location='./';</script>";
        exit;
    }

    $validacion = validarStock($conn, $producto_id, $cantidad, $tipo);
    if (!$validacion['ok']) {
        echo "<script>alert('❌ " . addslashes($validacion['message']) . "'); window.location='./';</script>";
        exit;
    }

    mysqli_begin_transaction($conn);

    try {
        // Insertar movimiento
        $insert = mysqli_query($conn, "INSERT INTO inventario_movimientos (producto_id, categoria_id, cantidad, tipo, observaciones, fecha)
                                       VALUES ($producto_id, $categoria_id, $cantidad, '$tipo', '" . mysqli_real_escape_string($conn, $observaciones) . "', '$fecha')");
        if (!$insert) throw new Exception('Error insertando movimiento');

        // Actualizar stock
        $prod = mysqli_fetch_assoc(mysqli_query($conn, "SELECT stock FROM productos WHERE id=$producto_id FOR UPDATE"));
        $stock_actual = intval($prod['stock']);
        
        if ($tipo == 'Entrada' || $tipo == 'Ajuste') {
            $stock_actual += $cantidad;
        } elseif ($tipo == 'Salida') {
            $stock_actual -= $cantidad;
            if ($stock_actual < 0) $stock_actual = 0;
        }
        
        mysqli_query($conn, "UPDATE productos SET stock=$stock_actual WHERE id=$producto_id");

        // Registrar en movimientos_stock
        $tipo_mov = ($tipo == 'Entrada' || $tipo == 'Ajuste') ? 'entrada' : 'salida';
        mysqli_query($conn, "INSERT INTO movimientos_stock (producto_id, tipo, cantidad, fecha) VALUES ($producto_id, '$tipo_mov', $cantidad, NOW())");

        mysqli_commit($conn);
        echo "<script>alert('✅ Movimiento registrado correctamente'); window.location='./';</script>";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('❌ Error: " . addslashes($e->getMessage()) . "'); window.location='./';</script>";
    }
}

// ====================================================================
// PAGINACIÓN Y FILTROS
// ====================================================================
$movimientos_por_pagina = 8;
$pagina_actual = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$offset = ($pagina_actual - 1) * $movimientos_por_pagina;

$busqueda_q = isset($_GET['q']) ? mysqli_real_escape_string($conn, trim($_GET['q'])) : '';
$filtro_tipo = isset($_GET['tipo']) ? mysqli_real_escape_string($conn, $_GET['tipo']) : '';
$filtro_categoria = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;

$where = "WHERE 1=1";
if (!empty($busqueda_q)) $where .= " AND (p.nombre LIKE '%$busqueda_q%' OR im.observaciones LIKE '%$busqueda_q%')";
if (!empty($filtro_tipo)) $where .= " AND im.tipo = '$filtro_tipo'";
if ($filtro_categoria > 0) $where .= " AND im.categoria_id = $filtro_categoria";

$total_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM inventario_movimientos im JOIN productos p ON im.producto_id = p.id $where");
$total_movimientos = mysqli_fetch_assoc($total_result)['total'];
$total_paginas = max(1, ceil($total_movimientos / $movimientos_por_pagina));

if ($pagina_actual > $total_paginas && $total_paginas > 0) {
    $pagina_actual = $total_paginas;
    $offset = ($pagina_actual - 1) * $movimientos_por_pagina;
}

$movimientos = mysqli_query($conn, "SELECT im.*, p.nombre AS producto, p.stock AS stock_actual, c.nombre AS categoria
    FROM inventario_movimientos im
    JOIN productos p ON im.producto_id = p.id
    JOIN categorias c ON im.categoria_id = c.id
    $where
    ORDER BY im.fecha DESC
    LIMIT $movimientos_por_pagina OFFSET $offset");

$productos = mysqli_query($conn, "SELECT id, nombre, stock FROM productos ORDER BY nombre ASC");
$categorias = mysqli_query($conn, "SELECT id, nombre FROM categorias ORDER BY nombre ASC");

function get_pagination_link($page, $q, $tipo, $categoria) {
    $link = "?p=$page";
    if (!empty($q)) $link .= "&q=" . urlencode($q);
    if (!empty($tipo)) $link .= "&tipo=" . urlencode($tipo);
    if (!empty($categoria)) $link .= "&categoria=" . urlencode($categoria);
    return $link;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury POS | Control de Inventario</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <script src="../assets/vendor/fontawesome/js/all.min.js"></script>
    <style>
        @import url('../assets/vendor/inter/inter.css');
        * { font-family: 'Inter', sans-serif; }
        
        .stats-card { transition: all 0.3s; }
        .stats-card:hover { transform: translateY(-3px); box-shadow: 0 12px 25px -8px rgba(0,0,0,0.15); }
        
        .movimiento-row { transition: all 0.2s; }
        .movimiento-row:hover { background-color: #f8fafc; transform: translateX(2px); }
        
        .pagination a { transition: all 0.2s; }
        .pagination a:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
<?php luxury_render_nav_start('inventario'); ?>

<div class="">

    <!-- Header -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6 border border-gray-100">
        <div class="flex justify-between items-center flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-black text-gray-800 flex items-center gap-3">
                    <span class="bg-gradient-to-r from-indigo-500 to-purple-600 p-2 rounded-xl">
                        <i class="fas fa-warehouse text-white text-lg"></i>
                    </span>
                    Control de Inventario
                </h1>
                <p class="text-gray-500 text-sm mt-1 flex items-center gap-2">
                    <i class="fas fa-chart-line text-indigo-500"></i>
                    Gestión de stock y movimientos
                </p>
            </div>
            <div class="flex gap-3">
                <div class="bg-indigo-100 text-indigo-700 px-4 py-2 rounded-full text-sm font-bold">
                    <i class="fas fa-boxes mr-2"></i> <?= fmtNum($total_productos) ?> productos
                </div>
            </div>
        </div>
    </div>

    <!-- KPIs Principales -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <div class="stats-card bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl p-4 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-boxes mr-1"></i> Total Productos</p>
            <p class="text-2xl font-bold"><?= fmtNum($total_productos) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-amber-500 to-amber-600 rounded-xl p-4 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-exclamation-triangle mr-1"></i> Stock Bajo</p>
            <p class="text-2xl font-bold"><?= fmtNum($productos_bajo_stock) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-red-500 to-red-600 rounded-xl p-4 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-ban mr-1"></i> Sin Stock</p>
            <p class="text-2xl font-bold"><?= fmtNum($productos_sin_stock) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl p-4 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-dollar-sign mr-1"></i> Valor Inventario</p>
            <p class="text-xl font-bold"><?= fmtV($valor_inventario) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-4 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-arrow-down mr-1"></i> Entradas (mes)</p>
            <p class="text-2xl font-bold"><?= fmtNum($entradas_mes) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl p-4 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-arrow-up mr-1"></i> Salidas (mes)</p>
            <p class="text-2xl font-bold"><?= fmtNum($salidas_mes) ?></p>
        </div>
    </div>

    <!-- Formulario de nuevo movimiento -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6 border border-gray-100">
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
            <span class="w-8 h-8 bg-emerald-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-plus-circle text-emerald-500 text-sm"></i>
            </span>
            Registrar Nuevo Movimiento
        </h2>
        
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <?= csrf_field() ?>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Producto</label>
                <select name="producto" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-400 outline-none">
                    <option value="">Seleccionar producto...</option>
                    <?php if ($productos && mysqli_num_rows($productos) > 0): 
                        mysqli_data_seek($productos, 0);
                        while ($p = mysqli_fetch_assoc($productos)): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?> (Stock: <?= fmtNum($p['stock']) ?>)</option>
                        <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Categoría</label>
                <select name="categoria" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-400 outline-none">
                    <option value="">Seleccionar categoría...</option>
                    <?php if ($categorias && mysqli_num_rows($categorias) > 0): 
                        mysqli_data_seek($categorias, 0);
                        while ($c = mysqli_fetch_assoc($categorias)): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                        <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Tipo</label>
                <select name="tipo" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-400 outline-none">
                    <option value="Entrada">📥 Entrada (Aumenta stock)</option>
                    <option value="Salida">📤 Salida (Disminuye stock)</option>
                    <option value="Ajuste">⚖️ Ajuste (Corrección)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cantidad</label>
                <input type="number" name="cantidad" min="1" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-400 outline-none">
            </div>
            <div class="md:col-span-2 lg:col-span-4">
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Observaciones</label>
                <textarea name="observaciones" rows="2" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-400 outline-none" placeholder="Ej: Ajuste por error en stock, entrada de mercancía nueva..."></textarea>
            </div>
            <div class="lg:col-span-4 flex justify-end">
                <button type="submit" name="guardar" class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white px-6 py-3 rounded-xl font-bold transition-all shadow-md flex items-center gap-2">
                    <i class="fas fa-save"></i> Guardar Movimiento
                </button>
            </div>
        </form>
    </div>

    <!-- Filtros y búsqueda -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6 border border-gray-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <span class="w-8 h-8 bg-gray-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-filter text-gray-500 text-sm"></i>
                </span>
                Filtrar Movimientos
            </h2>
            
            <form method="GET" class="flex flex-wrap gap-3 w-full md:w-auto">
                <input type="hidden" name="page" value="inventario">
                <div class="relative flex-1 min-w-[200px]">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($busqueda_q) ?>" 
                           placeholder="Buscar producto u observaciones..." 
                           class="w-full pl-9 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
                </div>
                <select name="tipo" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
                    <option value="">Todos los tipos</option>
                    <option value="Entrada" <?= $filtro_tipo == 'Entrada' ? 'selected' : '' ?>>📥 Entrada</option>
                    <option value="Salida" <?= $filtro_tipo == 'Salida' ? 'selected' : '' ?>>📤 Salida</option>
                    <option value="Ajuste" <?= $filtro_tipo == 'Ajuste' ? 'selected' : '' ?>>⚖️ Ajuste</option>
                </select>
                <select name="categoria" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
                    <option value="0">Todas las categorías</option>
                    <?php if ($categorias && mysqli_num_rows($categorias) > 0): 
                        mysqli_data_seek($categorias, 0);
                        while ($cat = mysqli_fetch_assoc($categorias)): ?>
                            <option value="<?= $cat['id'] ?>" <?= $filtro_categoria == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['nombre']) ?></option>
                        <?php endwhile; endif; ?>
                </select>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition-all flex items-center gap-2">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <?php if (!empty($busqueda_q) || !empty($filtro_tipo) || $filtro_categoria > 0): ?>
                    <a href="./" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-xl text-sm font-bold transition-all flex items-center gap-2">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Tabla de movimientos -->
    <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
        <div class="bg-gradient-to-r from-gray-800 to-gray-900 text-white p-5">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-lg font-bold flex items-center gap-2">
                        <i class="fas fa-list-alt"></i> Historial de Movimientos
                    </h2>
                    <p class="text-xs opacity-70">Página <?= $pagina_actual ?> de <?= $total_paginas ?> · Total: <?= fmtNum($total_movimientos) ?> registros</p>
                </div>
                <div class="bg-white/20 px-3 py-1 rounded-full text-xs font-bold">
                    <?= $movimientos_por_pagina ?> por página
                </div>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-bold border-b">
                     
                        <th class="p-4 text-left">ID</th>
                        <th class="p-4 text-left">Producto</th>
                        <th class="p-4 text-left">Categoría</th>
                        <th class="p-4 text-center">Tipo</th>
                        <th class="p-4 text-right">Cantidad</th>
                        <th class="p-4 text-right">Stock Actual</th>
                        <th class="p-4 text-left">Fecha</th>
                        <th class="p-4 text-left">Observaciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if ($movimientos && mysqli_num_rows($movimientos) > 0): 
                        $i = $offset + 1;
                        while ($m = mysqli_fetch_assoc($movimientos)): ?>
                        <tr class="movimiento-row">
                            <td class="p-4 font-mono text-xs text-gray-400">#<?= $i++ ?>   </td>
                            <td class="p-4 font-medium text-gray-800"><?= htmlspecialchars($m['producto']) ?>   </td>
                            <td class="p-4 text-gray-500 text-xs"><?= htmlspecialchars($m['categoria']) ?>   </td>
                            <td class="p-4 text-center"><?= tipoBadgeInventario($m['tipo']) ?>   </td>
                            <td class="p-4 text-right font-bold <?= $m['tipo'] == 'Entrada' ? 'text-emerald-600' : ($m['tipo'] == 'Salida' ? 'text-red-600' : 'text-amber-600') ?>">
                                <?= $m['tipo'] == 'Entrada' ? '+' : ($m['tipo'] == 'Salida' ? '-' : '±') ?> <?= fmtNum($m['cantidad']) ?>
                            </td>
                            <td class="p-4 text-right font-mono <?= $m['stock_actual'] < 5 ? 'text-red-500 font-bold' : 'text-gray-600' ?>">
                                <?= fmtNum($m['stock_actual']) ?> uds
                            </td>
                            <td class="p-4 text-gray-500 text-xs"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?>   </td>
                            <td class="p-4 text-gray-500 text-xs max-w-[200px] truncate"><?= htmlspecialchars($m['observaciones'] ?: '—') ?>   </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="8" class="p-12 text-center text-gray-400">
                                <i class="fas fa-inbox text-5xl mb-3 opacity-30"></i><br>
                                <?= !empty($busqueda_q) || !empty($filtro_tipo) || $filtro_categoria > 0 ? 
                                    'No se encontraron movimientos con los filtros aplicados.' : 
                                    'No hay movimientos registrados aún.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Paginación mejorada -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-between items-center flex-wrap gap-3">
            <p class="text-xs text-gray-500">Mostrando <?= mysqli_num_rows($movimientos) ?> de <?= fmtNum($total_movimientos) ?> movimientos</p>
            <div class="flex gap-2">
                <?php if ($pagina_actual > 1): ?>
                    <a href="<?= get_pagination_link(1, $busqueda_q, $filtro_tipo, $filtro_categoria) ?>" class="w-9 h-9 flex items-center justify-center bg-white border rounded-xl text-xs hover:bg-gray-100"><i class="fas fa-angle-double-left"></i></a>
                    <a href="<?= get_pagination_link($pagina_actual - 1, $busqueda_q, $filtro_tipo, $filtro_categoria) ?>" class="px-3 py-2 bg-white border rounded-xl text-xs hover:bg-gray-100"><i class="fas fa-chevron-left"></i> Anterior</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $pagina_actual - 2);
                $end = min($total_paginas, $pagina_actual + 2);
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="<?= get_pagination_link($i, $busqueda_q, $filtro_tipo, $filtro_categoria) ?>" class="w-9 h-9 flex items-center justify-center rounded-xl text-sm font-medium transition <?= $i == $pagina_actual ? 'bg-indigo-600 text-white shadow-md' : 'bg-white border text-gray-600 hover:bg-gray-100' ?>"><?= $i ?></a>
                <?php endfor; ?>
                
                <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="<?= get_pagination_link($pagina_actual + 1, $busqueda_q, $filtro_tipo, $filtro_categoria) ?>" class="px-3 py-2 bg-white border rounded-xl text-xs hover:bg-gray-100">Siguiente <i class="fas fa-chevron-right"></i></a>
                    <a href="<?= get_pagination_link($total_paginas, $busqueda_q, $filtro_tipo, $filtro_categoria) ?>" class="w-9 h-9 flex items-center justify-center bg-white border rounded-xl text-xs hover:bg-gray-100"><i class="fas fa-angle-double-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Resumen de filtros activos -->
        <?php if (!empty($busqueda_q) || !empty($filtro_tipo) || $filtro_categoria > 0): ?>
        <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-500 flex justify-between items-center flex-wrap gap-2">
            <div><i class="fas fa-filter mr-1"></i> Filtros activos:
                <?php if (!empty($busqueda_q)): ?><span class="bg-gray-200 px-2 py-1 rounded-full ml-1">🔍 "<?= htmlspecialchars($busqueda_q) ?>"</span><?php endif; ?>
                <?php if (!empty($filtro_tipo)): ?><span class="bg-gray-200 px-2 py-1 rounded-full ml-1">📌 <?= $filtro_tipo ?></span><?php endif; ?>
                <?php if ($filtro_categoria > 0): ?><span class="bg-gray-200 px-2 py-1 rounded-full ml-1">📂 Categoría seleccionada</span><?php endif; ?>
            </div>
            <a href="./" class="text-red-500 hover:text-red-700"><i class="fas fa-times-circle mr-1"></i> Quitar todos los filtros</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Top 5 productos más movidos -->
    <?php if (!empty($top_productos)): ?>
    <div class="mt-6 bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <span class="w-8 h-8 bg-amber-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-trophy text-amber-500 text-sm"></i>
            </span>
            Top 5 Productos Más Movidos
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <?php $max_mov = max(array_column($top_productos, 'movimientos')); ?>
            <?php foreach ($top_productos as $i => $p): 
                $pct = $max_mov > 0 ? ($p['movimientos'] / $max_mov * 100) : 0;
                $medallas = ['🥇', '🥈', '🥉', '📦', '📦'];
            ?>
            <div class="bg-gray-50 rounded-xl p-4 text-center">
                <div class="text-2xl mb-2"><?= $medallas[$i] ?></div>
                <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($p['nombre']) ?></p>
                <p class="text-xs text-gray-500 mt-1"><?= fmtNum($p['movimientos']) ?> movimientos</p>
                <p class="text-xs font-bold text-indigo-600"><?= fmtNum($p['total_cantidad']) ?> unidades</p>
                <div class="mt-2 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-amber-400 to-orange-500 rounded-full" style="width: <?= $pct ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php luxury_render_nav_end(); ?>
</body>
</html>
