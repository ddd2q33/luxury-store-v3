<?php
// pages/gastos.php - Versión que trabaja con GASTOS (egresos de caja)
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('gastos');

// ====================================================================
// AJAX: Historial de gastos por DÍA
// ====================================================================
if (isset($_GET['dia_detalle']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dia_detalle'])) {
    $dia = $_GET['dia_detalle'];
    $conn->set_charset("utf8mb4");

    $sql = "SELECT id, tipo, metodo_pago, monto, descripcion, fecha, venta_id 
            FROM movimientos_caja 
            WHERE tipo = 'egreso' AND DATE(fecha) = ?
            ORDER BY fecha DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dia);
    $stmt->execute();
    $gastos_dia = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $total_gastos = array_sum(array_column($gastos_dia, 'monto'));
    $cantidad = count($gastos_dia);

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');

    if (empty($gastos_dia)) {
        echo '<p class="text-xs text-gray-400 py-6 text-center">Sin gastos registrados este día.</p>';
        exit;
    }

    $iconos_metodo = ['Efectivo'=>'💵', 'Nequi'=>'📱', 'Transferencia/QR'=>'📲', 'Datafono'=>'💳'];
    ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-red-50 border-b border-red-200">
                <tr class="text-[10px] font-bold text-red-600 uppercase tracking-wider">
                    <th class="px-4 py-2.5">Hora</th>
                    <th class="px-4 py-2.5">Método</th>
                    <th class="px-4 py-2.5">Descripción</th>
                    <th class="px-4 py-2.5 text-right">Monto</th>
                 </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($gastos_dia as $gasto): ?>
                <tr class="hover:bg-red-50 transition-colors">
                    <td class="px-4 py-3 font-mono text-xs text-gray-400 whitespace-nowrap">
                        <?= date('H:i', strtotime($gasto['fecha'])) ?>
                     </td>
                    <td class="px-4 py-3 text-xs">
                        <span class="flex items-center gap-1">
                            <?= $iconos_metodo[$gasto['metodo_pago']] ?? '💰' ?>
                            <?= htmlspecialchars($gasto['metodo_pago'] ?? 'Efectivo') ?>
                        </span>
                     </td>
                    <td class="px-4 py-3 text-xs text-gray-700 max-w-xs">
                        <span class="font-medium"><?= htmlspecialchars($gasto['descripcion']) ?></span>
                     </td>
                    <td class="px-4 py-3 text-right font-bold text-sm text-red-600 whitespace-nowrap">
                        - $<?= number_format($gasto['monto'], 2) ?>
                     </td>
                 </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-red-50 border-t border-red-200">
                 <tr>
                    <td colspan="3" class="px-4 py-3 text-right font-bold text-xs text-red-600">Total gastos:</td>
                    <td class="px-4 py-3 text-right font-extrabold text-base text-red-700">-$<?= number_format($total_gastos, 2) ?></td>
                 </tr>
                 <tr>
                    <td colspan="3" class="px-4 py-2 text-right text-xs text-gray-500">N° transacciones:</td>
                    <td class="px-4 py-2 text-right text-xs font-semibold text-gray-600"><?= $cantidad ?></td>
                 </tr>
            </tfoot>
        </table>
    </div>
    <?php
    exit;
}

// ====================================================================
// AJAX: Detalle gastos de un MES
// ====================================================================
if (isset($_GET['mes_detalle']) && preg_match('/^\d{4}-\d{2}$/', $_GET['mes_detalle'])) {
    $mes = $_GET['mes_detalle'];
    $sql = "SELECT DATE(fecha) AS dia,
                   COUNT(*) AS cantidad,
                   SUM(monto) AS total_dia
            FROM movimientos_caja
            WHERE tipo = 'egreso' AND DATE_FORMAT(fecha, '%Y-%m') = ?
            GROUP BY DATE(fecha)
            ORDER BY dia DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $mes);
    $stmt->execute();
    $detalle = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($detalle, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// AJAX: Detalle gastos de un AÑO
// ====================================================================
if (isset($_GET['anio_detalle']) && preg_match('/^\d{4}$/', $_GET['anio_detalle'])) {
    $anio = $_GET['anio_detalle'];
    $sql = "SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes,
                   COUNT(*) AS cantidad,
                   SUM(monto) AS total_mes,
                   COUNT(DISTINCT DATE(fecha)) AS dias_activos,
                   MAX(monto) AS gasto_max,
                   MIN(monto) AS gasto_min,
                   AVG(monto) AS gasto_prom
            FROM movimientos_caja
            WHERE tipo = 'egreso' AND YEAR(fecha) = ?
            GROUP BY DATE_FORMAT(fecha, '%Y-%m')
            ORDER BY mes DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $anio);
    $stmt->execute();
    $detalle = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($detalle, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// CRUD: Agregar, Editar, Eliminar GASTOS en movimientos_caja
// ====================================================================

// Verificar si hay caja abierta
function getCajaActiva($conn) {
    $stmt = $conn->prepare("SELECT id FROM cajas WHERE estado = 'abierta' ORDER BY fecha_apertura DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $caja = $result->fetch_assoc();
    $stmt->close();
    return $caja;
}

// Add gasto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_gasto'])) {
    $caja_activa = getCajaActiva($conn);
    
    if (!$caja_activa) {
        echo "<script>alert('❌ No hay una caja abierta. Debes abrir caja primero.'); window.location='../caja/';</script>";
        exit;
    }
    
    $descripcion = trim($_POST['descripcion']);
    $monto = floatval($_POST['monto']);
    $metodo_pago = trim($_POST['metodo_pago'] ?? 'Efectivo');
    $fecha = $_POST['fecha'] ?? date('Y-m-d H:i:s');
    
    if (!empty($descripcion) && $monto > 0) {
        $stmt = $conn->prepare("INSERT INTO movimientos_caja (caja_id, tipo, metodo_pago, monto, descripcion, fecha) VALUES (?, 'egreso', ?, ?, ?, ?)");
        $stmt->bind_param("isds", $caja_activa['id'], $metodo_pago, $monto, $descripcion, $fecha);
        $stmt->execute();
        $stmt->close();
        
        echo "<script>alert('✅ Gasto registrado correctamente'); window.location='./';</script>";
    } else {
        echo "<script>alert('❌ Descripción vacía o monto inválido');</script>";
    }
    exit;
}

// Edit gasto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_gasto'])) {
    $id = intval($_POST['id']);
    $descripcion = trim($_POST['descripcion']);
    $monto = floatval($_POST['monto']);
    $metodo_pago = trim($_POST['metodo_pago'] ?? 'Efectivo');
    
    $stmt = $conn->prepare("UPDATE movimientos_caja SET descripcion=?, monto=?, metodo_pago=? WHERE id=? AND tipo='egreso'");
    $stmt->bind_param("sdsi", $descripcion, $monto, $metodo_pago, $id);
    $stmt->execute();
    $stmt->close();
    
    echo "<script>window.location='./';</script>";
    exit;
}

// Delete gasto
if (isset($_POST['delete'])) {
    $id = intval($_POST['delete']);
    
    // Verificar que sea un gasto
    $check = $conn->prepare("SELECT tipo FROM movimientos_caja WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $tipo = $check->get_result()->fetch_assoc();
    $check->close();
    
    if ($tipo && $tipo['tipo'] === 'egreso') {
        $stmt = $conn->prepare("DELETE FROM movimientos_caja WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    
    echo "<script>window.location='./';</script>";
    exit;
}

// ====================================================================
// ESTADÍSTICAS Y CONSULTAS PARA GRÁFICOS (solo gastos)
// ====================================================================

// Total gastos hoy
$gastos_hoy = (float)($conn->query("SELECT SUM(monto) FROM movimientos_caja WHERE tipo='egreso' AND DATE(fecha) = CURDATE()")->fetch_row()[0] ?? 0);
$gastos_ayer = (float)($conn->query("SELECT SUM(monto) FROM movimientos_caja WHERE tipo='egreso' AND DATE(fecha) = CURDATE() - INTERVAL 1 DAY")->fetch_row()[0] ?? 0);
$gastos_semana = (float)($conn->query("SELECT SUM(monto) FROM movimientos_caja WHERE tipo='egreso' AND YEARWEEK(fecha,1) = YEARWEEK(CURDATE(),1)")->fetch_row()[0] ?? 0);
$gastos_mes = (float)($conn->query("SELECT SUM(monto) FROM movimientos_caja WHERE tipo='egreso' AND MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetch_row()[0] ?? 0);
$gastos_anio = (float)($conn->query("SELECT SUM(monto) FROM movimientos_caja WHERE tipo='egreso' AND YEAR(fecha) = YEAR(CURDATE())")->fetch_row()[0] ?? 0);

// Gastos por método de pago
$gastos_metodo = [];
$res_met = $conn->query("SELECT metodo_pago, SUM(monto) as total FROM movimientos_caja WHERE tipo='egreso' AND fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY metodo_pago ORDER BY total DESC");
if ($res_met) {
    while ($row = $res_met->fetch_assoc()) {
        $gastos_metodo[] = $row;
    }
}

// Top 5 gastos más altos del mes
$top_gastos = [];
$res_top = $conn->query("SELECT descripcion, monto, fecha, metodo_pago FROM movimientos_caja WHERE tipo='egreso' AND MONTH(fecha) = MONTH(CURDATE()) ORDER BY monto DESC LIMIT 5");
if ($res_top) {
    while ($row = $res_top->fetch_assoc()) {
        $top_gastos[] = $row;
    }
}

// Gastos últimos 7 días
$gastos_7dias = [];
for ($i = 6; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-$i days"));
    $total = (float)($conn->query("SELECT SUM(monto) FROM movimientos_caja WHERE tipo='egreso' AND DATE(fecha) = '$fecha'")->fetch_row()[0] ?? 0);
    $gastos_7dias[] = [
        'fecha' => $fecha,
        'total' => $total,
        'dia' => date('d/m', strtotime($fecha))
    ];
}

// Gastos últimos 6 meses
$gastos_6meses = [];
for ($i = 5; $i >= 0; $i--) {
    $mes = date('Y-m', strtotime("-$i months"));
    $total = (float)($conn->query("SELECT SUM(monto) FROM movimientos_caja WHERE tipo='egreso' AND DATE_FORMAT(fecha, '%Y-%m') = '$mes'")->fetch_row()[0] ?? 0);
    $gastos_6meses[] = [
        'mes' => $mes,
        'total' => $total,
        'nombre' => date('M Y', strtotime($mes . '-01'))
    ];
}

// Función para porcentaje de cambio
function pctCambio($actual, $anterior) {
    if ($anterior == 0) return $actual > 0 ? 100 : 0;
    return round((($actual - $anterior) / $anterior) * 100, 1);
}

// ====================================================================
// PAGINACIÓN Y FILTRADO (solo gastos)
// ====================================================================
$limit = 3;
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$offset = ($page - 1) * $limit;
$searchTerm = isset($_GET['search_term']) ? trim($_GET['search_term']) : '';
$searchTermWildcard = "%" . $searchTerm . "%";
$metodoFilter = isset($_GET['metodo']) ? trim($_GET['metodo']) : '';

// Construir WHERE clause
$whereClause = "WHERE tipo = 'egreso'";
$params = [];
$types = "";

if (!empty($searchTerm)) {
    $whereClause .= " AND (descripcion LIKE ? OR CAST(monto AS CHAR) LIKE ?)";
    $params[] = $searchTermWildcard;
    $params[] = $searchTermWildcard;
    $types .= "ss";
}

if (!empty($metodoFilter)) {
    $whereClause .= " AND metodo_pago = ?";
    $params[] = $metodoFilter;
    $types .= "s";
}

// Total de registros
$total_records = 0;
$countSql = "SELECT COUNT(*) as total FROM movimientos_caja $whereClause";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total_records = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$total_pages = ceil($total_records / $limit);
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
$offset = ($page - 1) * $limit;

// Obtener gastos
$gastos = [];
$sql = "SELECT * FROM movimientos_caja $whereClause ORDER BY fecha DESC LIMIT ? OFFSET ?";
$fetchStmt = $conn->prepare($sql);

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

if (!empty($params)) {
    $fetchStmt->bind_param($types, ...$params);
}
$fetchStmt->execute();
$result = $fetchStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $gastos[] = $row;
}
$fetchStmt->close();

// Métodos de pago únicos para filtro
$metodos_pago = [];
$metRes = $conn->query("SELECT DISTINCT metodo_pago FROM movimientos_caja WHERE tipo='egreso' ORDER BY metodo_pago");
if ($metRes) {
    while ($row = $metRes->fetch_assoc()) {
        $metodos_pago[] = $row['metodo_pago'];
    }
}

$fecha_actual = date('d/m/Y');
$dia_semana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][date('w')];
$iconos_metodo = ['Efectivo'=>'💵', 'Nequi'=>'📱', 'Transferencia/QR'=>'📲', 'Datafono'=>'💳'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gastos - Luxury Store</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <script src="../assets/vendor/chartjs/chart.umd.min.js"></script>
</head>
<body class="bg-gray-100">
<?php luxury_render_nav_start('gastos'); ?>
<div class="">
    
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-gray-900 flex items-center gap-3">
            <i class="fas fa-money-bill-wave text-red-600"></i> 
            Control de Gastos
        </h1>
        <p class="text-gray-500 text-sm mt-2">
            <i class="far fa-calendar-alt mr-1"></i> <?= $dia_semana ?>, <?= $fecha_actual ?>
            <span class="ml-3 text-xs bg-gray-100 px-2 py-1 rounded-full">Movimientos tipo GASTO</span>
        </p>
    </div>

    <!-- Tarjetas de Resumen -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-lg p-5 border-l-4 border-red-500 hover:shadow-xl transition-all">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm">Gastos Hoy</p>
                    <p class="text-2xl font-bold text-gray-800">$<?= number_format($gastos_hoy, 2) ?></p>
                    <p class="text-xs mt-1 <?= pctCambio($gastos_hoy, $gastos_ayer) > 0 ? 'text-red-500' : 'text-green-500' ?>">
                        <i class="fas <?= pctCambio($gastos_hoy, $gastos_ayer) > 0 ? 'fa-arrow-up' : 'fa-arrow-down' ?> mr-1"></i>
                        <?= abs(pctCambio($gastos_hoy, $gastos_ayer)) ?>% vs ayer
                    </p>
                </div>
                <div class="bg-red-100 p-3 rounded-xl">
                    <i class="fas fa-calendar-day text-red-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-5 border-l-4 border-orange-500 hover:shadow-xl transition-all">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm">Gastos Semana</p>
                    <p class="text-2xl font-bold text-gray-800">$<?= number_format($gastos_semana, 2) ?></p>
                </div>
                <div class="bg-orange-100 p-3 rounded-xl">
                    <i class="fas fa-calendar-week text-orange-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-5 border-l-4 border-yellow-500 hover:shadow-xl transition-all">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm">Gastos Mes</p>
                    <p class="text-2xl font-bold text-gray-800">$<?= number_format($gastos_mes, 2) ?></p>
                </div>
                <div class="bg-yellow-100 p-3 rounded-xl">
                    <i class="fas fa-calendar-alt text-yellow-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-5 border-l-4 border-purple-500 hover:shadow-xl transition-all">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm">Gastos Año</p>
                    <p class="text-2xl font-bold text-gray-800">$<?= number_format($gastos_anio, 2) ?></p>
                </div>
                <div class="bg-purple-100 p-3 rounded-xl">
                    <i class="fas fa-chart-line text-purple-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficos -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Gráfico de gastos últimos 7 días -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-line text-red-500"></i> Gastos Últimos 7 Días
            </h2>
            <canvas id="gastosSemanaChart" height="200"></canvas>
        </div>

        <!-- Gráfico de gastos por método de pago -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-pie text-red-500"></i> Gastos por Método de Pago
            </h2>
            <canvas id="gastosMetodoChart" height="200"></canvas>
        </div>
    </div>

    <!-- Top 5 Gastos y Gráfico Mensual -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Top 5 gastos del mes -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-trophy text-yellow-500"></i> Top 5 Gastos del Mes
            </h2>
            <div class="space-y-3">
                <?php if (empty($top_gastos)): ?>
                    <p class="text-gray-500 text-center py-4">No hay gastos registrados este mes</p>
                <?php else: ?>
                    <?php foreach ($top_gastos as $gasto): ?>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg hover:bg-red-50 transition">
                        <div class="flex-1">
                            <p class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($gasto['descripcion']) ?></p>
                            <p class="text-xs text-gray-500 flex items-center gap-1">
                                <span><?= $iconos_metodo[$gasto['metodo_pago']] ?? '💰' ?></span>
                                <?= htmlspecialchars($gasto['metodo_pago'] ?? 'Efectivo') ?> • 
                                <?= date('d/m/Y', strtotime($gasto['fecha'])) ?>
                            </p>
                        </div>
                        <p class="font-bold text-red-600">$<?= number_format($gasto['monto'], 2) ?></p>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Gráfico de gastos últimos 6 meses -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-bar text-red-500"></i> Evolución Mensual
            </h2>
            <canvas id="gastosMensualChart" height="200"></canvas>
        </div>
    </div>

    <!-- Formulario de nuevo gasto -->
    <div class="bg-white shadow-xl rounded-xl p-6 mb-8 border border-gray-100">
        <h2 class="text-xl font-bold mb-4 text-gray-700 flex items-center gap-2">
            <i class="fas fa-minus-circle text-red-600"></i> Registrar Nuevo Gasto
        </h2>
        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <?= csrf_field() ?>
            <div class="lg:col-span-2">
                <label class="block text-sm font-medium text-gray-700">Descripción</label>
                <input type="text" name="descripcion" required placeholder="Ej: Compra de insumos, pago servicio..."
                    class="mt-1 w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-red-500 focus:border-red-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Monto ($)</label>
                <input type="number" step="0.01" name="monto" required placeholder="0.00"
                    class="mt-1 w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-red-500 focus:border-red-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Método de Pago</label>
                <select name="metodo_pago" class="mt-1 w-full border border-gray-300 rounded-lg px-4 py-2">
                    <option value="Efectivo">💵 Efectivo</option>
                    <option value="Nequi">📱 Nequi</option>
                    <option value="Transferencia/QR">📲 Transferencia/QR</option>
                    <option value="Datafono">💳 Datafono</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Fecha</label>
                <input type="datetime-local" name="fecha" value="<?= date('Y-m-d\TH:i') ?>"
                    class="mt-1 w-full border border-gray-300 rounded-lg px-4 py-2">
            </div>
            <div class="flex items-end">
                <button type="submit" name="add_gasto"
                    class="w-full bg-red-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-red-700 transition shadow-md flex items-center justify-center gap-2">
                    <i class="fas fa-save"></i> Registrar Gasto
                </button>
            </div>
        </form>
    </div>

    <!-- Filtros -->
    <div class="bg-gray-50 shadow rounded-xl p-4 mb-8 border border-gray-200">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="page" value="gastos">
            
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700">Buscar</label>
                <div class="flex mt-1">
                    <input type="text" name="search_term" placeholder="Descripción o monto..." 
                        value="<?= htmlspecialchars($searchTerm) ?>"
                        class="flex-1 border border-gray-300 rounded-l-lg px-4 py-2 focus:ring-red-500 focus:border-red-500">
                    <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded-r-lg hover:bg-red-600">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700">Método de Pago</label>
                <select name="metodo" class="mt-1 border border-gray-300 rounded-lg px-4 py-2">
                    <option value="">Todos</option>
                    <?php foreach ($metodos_pago as $met): ?>
                    <option value="<?= htmlspecialchars($met) ?>" <?= $metodoFilter == $met ? 'selected' : '' ?>>
                        <?= $iconos_metodo[$met] ?? '💰' ?> <?= htmlspecialchars($met) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if (!empty($searchTerm) || !empty($metodoFilter)): ?>
            <a href="./" class="text-red-500 hover:text-red-700 text-sm flex items-center gap-1 mb-2">
                <i class="fas fa-times-circle"></i> Limpiar
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabla de gastos -->
    <div class="bg-white shadow-xl rounded-xl p-6 border border-gray-100">
        <h2 class="text-xl font-bold mb-4 text-gray-700 flex items-center gap-2">
            <i class="fas fa-list-alt text-red-600"></i> Lista de Gastos (<?= $total_records ?> registros)
        </h2>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-red-50">
                    <tr>
                        <th class="py-3 px-4 text-left text-xs font-bold text-red-600 uppercase">#</th>
                        <th class="py-3 px-4 text-left text-xs font-bold text-red-600 uppercase">Método</th>
                        <th class="py-3 px-4 text-left text-xs font-bold text-red-600 uppercase">Descripción</th>
                        <th class="py-3 px-4 text-right text-xs font-bold text-red-600 uppercase">Monto</th>
                        <th class="py-3 px-4 text-left text-xs font-bold text-red-600 uppercase">Fecha</th>
                        <th class="py-3 px-4 text-center text-xs font-bold text-red-600 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (!empty($gastos)): ?>
                        <?php foreach ($gastos as $i => $gasto): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="py-3 px-4 text-sm text-gray-500"><?= $offset + $i + 1 ?></td>
                            <td class="py-3 px-4 text-sm">
                                <span class="flex items-center gap-1">
                                    <?= $iconos_metodo[$gasto['metodo_pago']] ?? '💰' ?>
                                    <?= htmlspecialchars($gasto['metodo_pago'] ?? 'Efectivo') ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-sm text-gray-800 max-w-xs"><?= htmlspecialchars($gasto['descripcion']) ?></td>
                            <td class="py-3 px-4 text-right font-bold text-red-600">$<?= number_format($gasto['monto'], 2) ?></td>
                            <td class="py-3 px-4 text-sm text-gray-500"><?= date('d/m/Y H:i', strtotime($gasto['fecha'])) ?></td>
                            <td class="py-3 px-4 text-center">
                                <button onclick="openEditModal(<?= $gasto['id'] ?>, '<?= htmlspecialchars($gasto['descripcion']) ?>', <?= $gasto['monto'] ?>, '<?= htmlspecialchars($gasto['metodo_pago'] ?? 'Efectivo') ?>')"
                                    class="bg-yellow-500 text-white p-2 rounded-lg hover:bg-yellow-600 transition mr-2">
                                    <i class="fas fa-edit text-xs"></i>
                                </button>
                                <button onclick="openDeleteModal(<?= $gasto['id'] ?>)"
                                    class="bg-red-500 text-white p-2 rounded-lg hover:bg-red-600 transition">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-8 text-gray-500">
                                <i class="fas fa-info-circle mr-2"></i> No hay gastos registrados
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <?php if ($total_pages > 1): ?>
        <div class="mt-6 flex justify-between items-center">
            <p class="text-sm text-gray-600">Página <?= $page ?> de <?= $total_pages ?></p>
            <div class="flex gap-2">
                <?php 
                $base_url = "?" . (!empty($searchTerm) ? "search_term=" . urlencode($searchTerm) : "") . (!empty($searchTerm) && !empty($metodoFilter) ? "&" : "") . (!empty($metodoFilter) ? "metodo=" . urlencode($metodoFilter) : "");
                ?>
                <?php if ($page > 1): ?>
                <a href="<?= $base_url ?>&p=<?= $page-1 ?>" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">Anterior</a>
                <?php endif; ?>
                <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                <a href="<?= $base_url ?>&p=<?= $i ?>" class="px-4 py-2 rounded-lg <?= $i == $page ? 'bg-red-600 text-white' : 'bg-gray-200 hover:bg-gray-300' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="<?= $base_url ?>&p=<?= $page+1 ?>" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">Siguiente</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modales -->
<div id="editModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-70 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 w-11/12 max-w-md">
        <h2 class="text-xl font-bold mb-4">Editar Gasto</h2>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="edit_id">
            <div class="mb-4">
                <label class="block text-sm font-medium">Descripción</label>
                <input type="text" name="descripcion" id="edit_descripcion" required class="w-full border rounded-lg px-4 py-2">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium">Monto</label>
                <input type="number" step="0.01" name="monto" id="edit_monto" required class="w-full border rounded-lg px-4 py-2">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium">Método de Pago</label>
                <select name="metodo_pago" id="edit_metodo" class="w-full border rounded-lg px-4 py-2">
                    <option value="Efectivo">💵 Efectivo</option>
                    <option value="Nequi">📱 Nequi</option>
                    <option value="Transferencia/QR">📲 Transferencia/QR</option>
                    <option value="Datafono">💳 Datafono</option>
                </select>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeEditModal()" class="bg-gray-400 text-white px-4 py-2 rounded-lg">Cancelar</button>
                <button type="submit" name="edit_gasto" class="bg-yellow-500 text-white px-4 py-2 rounded-lg">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-70 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 w-11/12 max-w-sm text-center">
        <i class="fas fa-exclamation-triangle text-red-500 text-5xl mb-4"></i>
        <h2 class="text-xl font-bold mb-2">Confirmar Eliminación</h2>
        <p class="text-gray-600 mb-6">¿Estás seguro de eliminar este gasto?</p>
        <div class="flex justify-center gap-3">
            <button onclick="closeDeleteModal()" class="bg-gray-400 text-white px-5 py-2 rounded-lg">Cancelar</button>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="delete" id="deleteConfirmId">
                <button type="submit" class="bg-red-500 text-white px-5 py-2 rounded-lg">Eliminar</button>
            </form>
        </div>
    </div>
</div>

<script>
// Gráficos
const ctxSemana = document.getElementById('gastosSemanaChart').getContext('2d');
new Chart(ctxSemana, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($gastos_7dias, 'dia')) ?>,
        datasets: [{
            label: 'Gastos',
            data: <?= json_encode(array_column($gastos_7dias, 'total')) ?>,
            borderColor: '#ef4444',
            backgroundColor: 'rgba(239, 68, 68, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { position: 'top' } }
    }
});

const ctxMetodo = document.getElementById('gastosMetodoChart').getContext('2d');
new Chart(ctxMetodo, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($gastos_metodo, 'metodo_pago')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($gastos_metodo, 'total')) ?>,
            backgroundColor: ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true
    }
});

const ctxMensual = document.getElementById('gastosMensualChart').getContext('2d');
new Chart(ctxMensual, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($gastos_6meses, 'nombre')) ?>,
        datasets: [{
            label: 'Gastos Mensuales',
            data: <?= json_encode(array_column($gastos_6meses, 'total')) ?>,
            backgroundColor: '#ef4444',
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { position: 'top' } }
    }
});

// Funciones modales
function openEditModal(id, descripcion, monto, metodo) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_descripcion').value = descripcion;
    document.getElementById('edit_monto').value = monto;
    document.getElementById('edit_metodo').value = metodo;
    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function openDeleteModal(id) {
    document.getElementById('deleteConfirmId').value = id;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}
</script>
<?php luxury_render_nav_end(); ?>
</body>
</html>
