<?php
// pages/reportes.php - Reportes Avanzados y Estadísticas Generales
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

require_admin();

// ── Fechas ───────────────────────────────────────────────────────────────────
$hoy = date('Y-m-d');
$inicio_mes = date('Y-m-01');
$inicio_anio = date('Y-01-01');

$fecha_inicio = isset($_GET['fecha_inicio']) && strtotime($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : $inicio_mes;
$fecha_fin = isset($_GET['fecha_fin']) && strtotime($_GET['fecha_fin']) ? $_GET['fecha_fin'] : $hoy;
$fecha_fin_sql = $fecha_fin . ' 23:59:59';

// ── FUNCIONES ────────────────────────────────────────────────────────────────
function fmtM($n) { 
    return '$' . number_format((float)$n, 0, ',', '.'); 
}
function fmtP($n) { 
    return number_format((float)$n, 1) . '%'; 
}
function fmtN($n) { 
    return number_format((float)$n, 0, ',', '.'); 
}

// ====================================================================
// 🔹 REPORTE GENERAL DEL NEGOCIO
// ====================================================================

// 1. Ventas del período
$total_ingresos = 0;
$total_ventas = 0;
$ticket_promedio = 0;
$venta_maxima = 0;
$venta_minima = 0;
$clientes_unicos = 0;

$stmt_ventas = $conn->prepare("
    SELECT 
        COUNT(*) as total_ventas,
        COALESCE(SUM(total), 0) as total_ingresos,
        COALESCE(AVG(total), 0) as ticket_promedio,
        COALESCE(MAX(total), 0) as venta_maxima,
        COALESCE(MIN(total), 0) as venta_minima,
        COUNT(DISTINCT cliente_id) as clientes_unicos
    FROM ventas
    WHERE fecha_venta BETWEEN ? AND ?
      AND LOWER(estado) IN ('completada', 'pagada')
");
if ($stmt_ventas) {
    $stmt_ventas->bind_param("ss", $fecha_inicio, $fecha_fin_sql);
    $stmt_ventas->execute();
    $resumen_ventas = $stmt_ventas->get_result()->fetch_assoc();
    $total_ingresos = (float)($resumen_ventas['total_ingresos'] ?? 0);
    $total_ventas = (int)($resumen_ventas['total_ventas'] ?? 0);
    $ticket_promedio = (float)($resumen_ventas['ticket_promedio'] ?? 0);
    $venta_maxima = (float)($resumen_ventas['venta_maxima'] ?? 0);
    $venta_minima = (float)($resumen_ventas['venta_minima'] ?? 0);
    $clientes_unicos = (int)($resumen_ventas['clientes_unicos'] ?? 0);
    $stmt_ventas->close();
}

// 2. Gastos (EGRESOS)
$total_egresos = 0;
$total_gastos = 0;
$gasto_promedio = 0;
$gasto_maximo = 0;
$gasto_minimo = 0;

$stmt_gastos = $conn->prepare("
    SELECT 
        COUNT(*) as total_gastos,
        COALESCE(SUM(monto), 0) as total_egresos,
        COALESCE(AVG(monto), 0) as gasto_promedio,
        COALESCE(MAX(monto), 0) as gasto_maximo,
        COALESCE(MIN(monto), 0) as gasto_minimo
    FROM movimientos_caja
    WHERE tipo = 'egreso' AND DATE(fecha) BETWEEN ? AND ?
");
if ($stmt_gastos) {
    $stmt_gastos->bind_param("ss", $fecha_inicio, $fecha_fin);
    $stmt_gastos->execute();
    $resumen_gastos = $stmt_gastos->get_result()->fetch_assoc();
    $total_egresos = (float)($resumen_gastos['total_egresos'] ?? 0);
    $total_gastos = (int)($resumen_gastos['total_gastos'] ?? 0);
    $gasto_promedio = (float)($resumen_gastos['gasto_promedio'] ?? 0);
    $gasto_maximo = (float)($resumen_gastos['gasto_maximo'] ?? 0);
    $gasto_minimo = (float)($resumen_gastos['gasto_minimo'] ?? 0);
    $stmt_gastos->close();
}

// 3. Productos
$total_productos = 0;
$stock_total = 0;
$valor_inventario = 0;
$productos_bajo_stock = 0;
$productos_agotados = 0;

$stmt_productos = $conn->prepare("
    SELECT 
        COUNT(*) as total_productos,
        COALESCE(SUM(stock), 0) as stock_total,
        COALESCE(SUM(stock * precio), 0) as valor_inventario,
        COUNT(CASE WHEN stock <= stock_minimo THEN 1 END) as productos_bajo_stock,
        COUNT(CASE WHEN stock = 0 THEN 1 END) as productos_agotados
    FROM productos
");
if ($stmt_productos) {
    $stmt_productos->execute();
    $resumen_productos = $stmt_productos->get_result()->fetch_assoc();
    $total_productos = (int)($resumen_productos['total_productos'] ?? 0);
    $stock_total = (int)($resumen_productos['stock_total'] ?? 0);
    $valor_inventario = (float)($resumen_productos['valor_inventario'] ?? 0);
    $productos_bajo_stock = (int)($resumen_productos['productos_bajo_stock'] ?? 0);
    $productos_agotados = (int)($resumen_productos['productos_agotados'] ?? 0);
    $stmt_productos->close();
}

// 4. Clientes y Proveedores
$total_clientes = 0;
$total_proveedores = 0;
$deuda_total = 0;
$proveedores_con_deuda = 0;

// Clientes
$result = $conn->query("SELECT COUNT(*) as total FROM clientes");
if ($result) {
    $total_clientes = (int)($result->fetch_assoc()['total'] ?? 0);
    $result->free();
}

// Proveedores
$result = $conn->query("SELECT COUNT(*) as total FROM proveedores");
if ($result) {
    $total_proveedores = (int)($result->fetch_assoc()['total'] ?? 0);
    $result->free();
}

// Deuda
$result = $conn->query("SELECT COALESCE(SUM(saldo_deuda), 0) as total FROM proveedores");
if ($result) {
    $deuda_total = (float)($result->fetch_assoc()['total'] ?? 0);
    $result->free();
}

$result = $conn->query("SELECT COUNT(*) as total FROM proveedores WHERE saldo_deuda > 0");
if ($result) {
    $proveedores_con_deuda = (int)($result->fetch_assoc()['total'] ?? 0);
    $result->free();
}

// 5. Empleados
$total_empleados = 0;
$nomina_mensual = 0;
$salario_promedio = 0;

$result = $conn->query("SELECT COUNT(*) as total, COALESCE(SUM(salario), 0) as nomina, COALESCE(AVG(salario), 0) as promedio FROM empleados");
if ($result) {
    $emp = $result->fetch_assoc();
    $total_empleados = (int)($emp['total'] ?? 0);
    $nomina_mensual = (float)($emp['nomina'] ?? 0);
    $salario_promedio = (float)($emp['promedio'] ?? 0);
    $result->free();
}

// 6. Caja
$caja_abierta = 0;
$cajas_cerradas = 0;
$total_cerradas = 0;

$result = $conn->query("SELECT COUNT(*) as total FROM cajas WHERE estado = 'abierta'");
if ($result) {
    $caja_abierta = (int)($result->fetch_assoc()['total'] ?? 0);
    $result->free();
}

$result = $conn->query("SELECT COUNT(*) as total FROM cajas WHERE estado = 'cerrada'");
if ($result) {
    $cajas_cerradas = (int)($result->fetch_assoc()['total'] ?? 0);
    $result->free();
}

$result = $conn->query("SELECT COALESCE(SUM(saldo_final), 0) as total FROM cajas WHERE estado = 'cerrada'");
if ($result) {
    $total_cerradas = (float)($result->fetch_assoc()['total'] ?? 0);
    $result->free();
}

$balance = $total_ingresos - $total_egresos;
$margen = $total_ingresos > 0 ? ($balance / $total_ingresos) * 100 : 0;

// ====================================================================
// 🔹 DATOS PARA GRÁFICOS AVANZADOS
// ====================================================================

// Ventas por hora del día (patrón de compras)
$ventas_por_hora = array_fill(0, 24, 0);
$sql_horas = "SELECT HOUR(fecha_venta) as hora, COALESCE(SUM(total), 0) as total 
              FROM ventas 
              WHERE fecha_venta BETWEEN ? AND ? AND LOWER(estado) IN ('completada', 'pagada')
              GROUP BY HOUR(fecha_venta)";
$stmt_horas = $conn->prepare($sql_horas);
if ($stmt_horas) {
    $stmt_horas->bind_param("ss", $fecha_inicio, $fecha_fin_sql);
    $stmt_horas->execute();
    $result_horas = $stmt_horas->get_result();
    while ($row = $result_horas->fetch_assoc()) {
        $ventas_por_hora[$row['hora']] = (float)$row['total'];
    }
    $stmt_horas->close();
}

// Ventas por día de la semana
$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$ventas_por_dia_semana = array_fill(0, 7, 0);
$sql_dias = "SELECT DAYOFWEEK(fecha_venta) as dia, COALESCE(SUM(total), 0) as total 
             FROM ventas 
             WHERE fecha_venta BETWEEN ? AND ? AND LOWER(estado) IN ('completada', 'pagada')
             GROUP BY DAYOFWEEK(fecha_venta)";
$stmt_dias = $conn->prepare($sql_dias);
if ($stmt_dias) {
    $stmt_dias->bind_param("ss", $fecha_inicio, $fecha_fin_sql);
    $stmt_dias->execute();
    $result_dias = $stmt_dias->get_result();
    while ($row = $result_dias->fetch_assoc()) {
        $idx = ($row['dia'] == 1) ? 6 : $row['dia'] - 2;
        if ($idx >= 0 && $idx < 7) {
            $ventas_por_dia_semana[$idx] = (float)$row['total'];
        }
    }
    $stmt_dias->close();
}

// Top 10 productos más vendidos
$top_productos = [];
$sql_top = "SELECT p.nombre, COALESCE(SUM(dv.cantidad), 0) as total_vendido, COALESCE(SUM(dv.subtotal), 0) as total_ventas
            FROM venta_detalles dv 
            JOIN productos p ON dv.producto_id = p.id 
            GROUP BY p.id 
            ORDER BY total_vendido DESC 
            LIMIT 10";
$result_top = $conn->query($sql_top);
if ($result_top) {
    while ($row = $result_top->fetch_assoc()) {
        $top_productos[] = $row;
    }
    $result_top->free();
}

// Top 10 clientes
$top_clientes = [];
$sql_top_clientes = "SELECT cliente_nombre, COUNT(*) as compras, COALESCE(SUM(total), 0) as total_gastado 
                     FROM ventas 
                     WHERE estado = 'Completada' AND cliente_nombre IS NOT NULL AND cliente_nombre != ''
                     GROUP BY cliente_nombre 
                     ORDER BY total_gastado DESC 
                     LIMIT 10";
$result_top_clientes = $conn->query($sql_top_clientes);
if ($result_top_clientes) {
    while ($row = $result_top_clientes->fetch_assoc()) {
        $top_clientes[] = $row;
    }
    $result_top_clientes->free();
}

// Ventas por categoría
$ventas_categoria = [];
$sql_cat = "SELECT c.nombre, COALESCE(SUM(dv.cantidad), 0) as total_vendido, COALESCE(SUM(dv.subtotal), 0) as total_ventas
            FROM venta_detalles dv 
            JOIN productos p ON dv.producto_id = p.id 
            LEFT JOIN categorias c ON p.categoria_id = c.id 
            GROUP BY c.id 
            ORDER BY total_ventas DESC";
$result_cat = $conn->query($sql_cat);
if ($result_cat) {
    while ($row = $result_cat->fetch_assoc()) {
        $ventas_categoria[] = $row;
    }
    $result_cat->free();
}

// Métodos de pago
$metodos_pago = [];
$sql_metodos = "SELECT metodo_pago, COUNT(*) as cantidad, COALESCE(SUM(monto), 0) as total 
                FROM movimientos_caja 
                WHERE tipo = 'venta' AND DATE(fecha) BETWEEN ? AND ?
                GROUP BY metodo_pago 
                ORDER BY total DESC";
$stmt_metodos = $conn->prepare($sql_metodos);
if ($stmt_metodos) {
    $stmt_metodos->bind_param("ss", $fecha_inicio, $fecha_fin);
    $stmt_metodos->execute();
    $result_metodos = $stmt_metodos->get_result();
    while ($row = $result_metodos->fetch_assoc()) {
        $metodos_pago[] = $row;
    }
    $stmt_metodos->close();
}

// Comparativa períodos
$ventas_periodo_anterior = 0;
$periodo_anterior = date('Y-m-d', strtotime($fecha_inicio) - ((strtotime($fecha_fin) - strtotime($fecha_inicio)) / 86400 + 1) * 86400);
$periodo_anterior_fin = date('Y-m-d', strtotime($fecha_inicio) - 86400);

$stmt_comp = $conn->prepare("
    SELECT COALESCE(SUM(total), 0) as total 
    FROM ventas 
    WHERE fecha_venta BETWEEN ? AND ? AND LOWER(estado) IN ('completada', 'pagada')
");
if ($stmt_comp) {
    $periodo_anterior_sql = $periodo_anterior_fin . ' 23:59:59';
    $stmt_comp->bind_param("ss", $periodo_anterior, $periodo_anterior_sql);
    $stmt_comp->execute();
    $ventas_periodo_anterior = (float)$stmt_comp->get_result()->fetch_assoc()['total'];
    $stmt_comp->close();
}

$variacion_ventas = $ventas_periodo_anterior > 0 
    ? (($total_ingresos - $ventas_periodo_anterior) / $ventas_periodo_anterior) * 100 
    : ($total_ingresos > 0 ? 100 : 0);
?>

<script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">


<!-- Chart.js -->
<script src="../assets/vendor/chartjs/chart.umd.min.js"></script>

<div class="max-w-7xl mx-auto space-y-5 p-4">

<!-- ── HEADER ────────────────────────────────────────────────────────────── -->
<div class="flex items-start justify-between flex-wrap gap-4">
  <div>
    <h1 class="text-2xl font-extrabold text-gray-800 flex items-center gap-2">
      <i class="fas fa-chart-pie text-indigo-500"></i> Reportes Avanzados
    </h1>
    <p class="text-xs text-gray-400 mt-0.5">
      <?= date('d/m/Y', strtotime($fecha_inicio)) ?> — <?= date('d/m/Y', strtotime($fecha_fin)) ?>
    </p>
  </div>

  <div class="flex flex-wrap gap-2 items-center">
    <form method="GET" class="flex items-center gap-2">
      <input type="hidden" name="page" value="reportes">
      <input type="date" name="fecha_inicio" value="<?= $fecha_inicio ?>"
             class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-indigo-400 outline-none">
      <span class="text-gray-300 text-xs">→</span>
      <input type="date" name="fecha_fin" value="<?= $fecha_fin ?>"
             class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-indigo-400 outline-none">
      <button type="submit"
              class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold transition-colors">
        <i class="fas fa-search"></i> Aplicar
      </button>
    </form>
  </div>
</div>

<!-- ── KPIS PRINCIPALES ─────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
  <div class="bg-gradient-to-br from-emerald-500 to-green-600 rounded-2xl p-5 text-white shadow-lg">
    <div class="flex justify-between items-start">
      <div>
        <p class="text-xs font-bold opacity-90 uppercase">Ingresos</p>
        <p class="text-2xl font-extrabold mt-1"><?= fmtM($total_ingresos) ?></p>
        <p class="text-xs mt-1 opacity-80"><?= fmtN($total_ventas) ?> ventas</p>
      </div>
      <i class="fas fa-chart-line text-3xl opacity-50"></i>
    </div>
  </div>
  
  <div class="bg-gradient-to-br from-red-500 to-rose-600 rounded-2xl p-5 text-white shadow-lg">
    <div class="flex justify-between items-start">
      <div>
        <p class="text-xs font-bold opacity-90 uppercase">Gastos</p>
        <p class="text-2xl font-extrabold mt-1"><?= fmtM($total_egresos) ?></p>
        <p class="text-xs mt-1 opacity-80"><?= fmtN($total_gastos) ?> egresos</p>
      </div>
      <i class="fas fa-arrow-down text-3xl opacity-50"></i>
    </div>
  </div>
  
  <div class="bg-gradient-to-br from-blue-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg">
    <div class="flex justify-between items-start">
      <div>
        <p class="text-xs font-bold opacity-90 uppercase">Balance</p>
        <p class="text-2xl font-extrabold mt-1"><?= fmtM($balance) ?></p>
        <p class="text-xs mt-1 opacity-80">Margen: <?= fmtP($margen) ?></p>
      </div>
      <i class="fas fa-scale-balanced text-3xl opacity-50"></i>
    </div>
  </div>
  
  <div class="bg-gradient-to-br from-purple-500 to-pink-600 rounded-2xl p-5 text-white shadow-lg">
    <div class="flex justify-between items-start">
      <div>
        <p class="text-xs font-bold opacity-90 uppercase">Ticket Promedio</p>
        <p class="text-2xl font-extrabold mt-1"><?= fmtM($ticket_promedio) ?></p>
        <p class="text-xs mt-1 opacity-80">Max: <?= fmtM($venta_maxima) ?></p>
      </div>
      <i class="fas fa-ticket text-3xl opacity-50"></i>
    </div>
  </div>
</div>

<!-- ── TARJETAS DE MÉTRICAS DEL NEGOCIO ─────────────────────────────────── -->
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
  <div class="bg-white rounded-xl p-3 shadow-sm border-l-4 border-emerald-500">
    <p class="text-[10px] text-gray-400 uppercase">Clientes</p>
    <p class="text-xl font-bold"><?= fmtN($total_clientes) ?></p>
  </div>
  <div class="bg-white rounded-xl p-3 shadow-sm border-l-4 border-blue-500">
    <p class="text-[10px] text-gray-400 uppercase">Proveedores</p>
    <p class="text-xl font-bold"><?= fmtN($total_proveedores) ?></p>
  </div>
  <div class="bg-white rounded-xl p-3 shadow-sm border-l-4 border-red-500">
    <p class="text-[10px] text-gray-400 uppercase">Deuda Proveedores</p>
    <p class="text-xl font-bold text-red-600"><?= fmtM($deuda_total) ?></p>
  </div>
  <div class="bg-white rounded-xl p-3 shadow-sm border-l-4 border-yellow-500">
    <p class="text-[10px] text-gray-400 uppercase">Productos</p>
    <p class="text-xl font-bold"><?= fmtN($total_productos) ?></p>
  </div>
  <div class="bg-white rounded-xl p-3 shadow-sm border-l-4 border-orange-500">
    <p class="text-[10px] text-gray-400 uppercase">Stock Bajo</p>
    <p class="text-xl font-bold text-orange-600"><?= fmtN($productos_bajo_stock) ?></p>
  </div>
  <div class="bg-white rounded-xl p-3 shadow-sm border-l-4 border-purple-500">
    <p class="text-[10px] text-gray-400 uppercase">Empleados</p>
    <p class="text-xl font-bold"><?= fmtN($total_empleados) ?></p>
  </div>
</div>

<!-- ── GRÁFICOS DE PATRONES DE COMPRA ───────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <h3 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
      <i class="fas fa-clock text-indigo-500"></i> Ventas por Hora del Día
    </h3>
    <div class="relative h-56">
      <canvas id="chartPorHora"></canvas>
    </div>
    <p class="text-center text-xs text-gray-400 mt-3">Horario de mayor actividad comercial</p>
  </div>
  
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <h3 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
      <i class="fas fa-calendar-week text-emerald-500"></i> Ventas por Día de la Semana
    </h3>
    <div class="relative h-56">
      <canvas id="chartPorDiaSemana"></canvas>
    </div>
    <p class="text-center text-xs text-gray-400 mt-3">Días con mayor volumen de ventas</p>
  </div>
  
</div>

<!-- ── GRÁFICOS DE PRODUCTOS Y CATEGORÍAS ───────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <h3 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
      <i class="fas fa-chart-bar text-blue-500"></i> Top 10 Productos Más Vendidos
    </h3>
    <div class="space-y-3 max-h-80 overflow-y-auto">
      <?php if (!empty($top_productos)):
        $max_ventas = max(array_column($top_productos, 'total_vendido'));
        foreach ($top_productos as $i => $prod):
          $porcentaje = $max_ventas > 0 ? ($prod['total_vendido'] / $max_ventas * 100) : 0;
      ?>
      <div>
        <div class="flex justify-between text-xs mb-1">
          <span class="font-medium text-gray-700 truncate max-w-[60%]"><?= ($i+1) ?>. <?= htmlspecialchars($prod['nombre']) ?></span>
          <span class="font-bold text-emerald-600"><?= fmtN($prod['total_vendido']) ?> und</span>
        </div>
        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full bg-gradient-to-r from-emerald-400 to-green-500 rounded-full" style="width: <?= $porcentaje ?>%"></div>
        </div>
      </div>
      <?php endforeach; else: ?>
      <p class="text-gray-400 text-center py-4">No hay datos disponibles</p>
      <?php endif; ?>
    </div>
  </div>
  
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <h3 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
      <i class="fas fa-chart-pie text-purple-500"></i> Ventas por Categoría
    </h3>
    <div class="relative h-48">
      <canvas id="chartCategorias"></canvas>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
      <?php foreach ($ventas_categoria as $cat): ?>
      <div class="flex justify-between items-center p-1">
        <span class="text-gray-600 truncate"><?= htmlspecialchars($cat['nombre'] ?? 'Sin categoría') ?></span>
        <span class="font-bold text-indigo-600"><?= fmtM($cat['total_ventas']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  
</div>

<!-- ── GRÁFICOS DE CLIENTES Y PAGOS ─────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <h3 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
      <i class="fas fa-crown text-amber-500"></i> Top 10 Clientes
    </h3>
    <div class="space-y-3 max-h-80 overflow-y-auto">
      <?php if (!empty($top_clientes)):
        $max_gasto = max(array_column($top_clientes, 'total_gastado'));
        foreach ($top_clientes as $i => $cli):
          $porcentaje = $max_gasto > 0 ? ($cli['total_gastado'] / $max_gasto * 100) : 0;
          $medalla = $i == 0 ? '🥇' : ($i == 1 ? '🥈' : ($i == 2 ? '🥉' : '📌'));
      ?>
      <div>
        <div class="flex justify-between text-xs mb-1">
          <span class="font-medium text-gray-700"><?= $medalla ?> <?= htmlspecialchars($cli['cliente_nombre']) ?></span>
          <span class="font-bold text-indigo-600"><?= fmtM($cli['total_gastado']) ?></span>
        </div>
        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full bg-gradient-to-r from-amber-400 to-orange-500 rounded-full" style="width: <?= $porcentaje ?>%"></div>
        </div>
        <p class="text-[10px] text-gray-400 mt-0.5"><?= $cli['compras'] ?> compras</p>
      </div>
      <?php endforeach; else: ?>
      <p class="text-gray-400 text-center py-4">No hay datos disponibles</p>
      <?php endif; ?>
    </div>
  </div>
  
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <h3 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
      <i class="fas fa-credit-card text-cyan-500"></i> Métodos de Pago
    </h3>
    <div class="relative h-48">
      <canvas id="chartMetodosPago"></canvas>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
      <?php foreach ($metodos_pago as $met): ?>
      <div class="flex justify-between items-center p-1 bg-gray-50 rounded-lg">
        <span class="text-gray-600"><?= $met['metodo_pago'] ?></span>
        <span class="font-bold text-emerald-600"><?= fmtM($met['total']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  
</div>

<!-- ── TABLA COMPARATIVA ────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100">
    <h3 class="font-bold text-gray-800 text-sm flex items-center gap-2">
      <i class="fas fa-chart-simple text-indigo-500"></i> Comparativa con Período Anterior
    </h3>
  </div>
  <div class="p-5">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="text-center p-4 bg-gray-50 rounded-xl">
        <p class="text-xs text-gray-400">Período Actual</p>
        <p class="text-2xl font-bold text-emerald-600"><?= fmtM($total_ingresos) ?></p>
        <p class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($fecha_inicio)) ?> - <?= date('d/m/Y', strtotime($fecha_fin)) ?></p>
      </div>
      <div class="text-center p-4 bg-gray-50 rounded-xl">
        <p class="text-xs text-gray-400">Período Anterior</p>
        <p class="text-2xl font-bold text-blue-600"><?= fmtM($ventas_periodo_anterior) ?></p>
        <p class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($periodo_anterior)) ?> - <?= date('d/m/Y', strtotime($periodo_anterior_fin)) ?></p>
      </div>
      <div class="text-center p-4 bg-gray-50 rounded-xl">
        <p class="text-xs text-gray-400">Variación</p>
        <p class="text-2xl font-bold <?= $variacion_ventas >= 0 ? 'text-green-600' : 'text-red-600' ?>">
          <?= $variacion_ventas >= 0 ? '▲' : '▼' ?> <?= abs(round($variacion_ventas, 1)) ?>%
        </p>
        <p class="text-xs text-gray-500">vs período anterior</p>
      </div>
    </div>
  </div>
</div>

</div>

<script>
// Gráfico Ventas por Hora
const ctxHora = document.getElementById('chartPorHora');
if (ctxHora) {
    new Chart(ctxHora, {
        type: 'line',
        data: {
            labels: <?= json_encode(range(0, 23)) ?>,
            datasets: [{
                label: 'Ventas ($)',
                data: <?= json_encode($ventas_por_hora) ?>,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99,102,241,0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#6366f1',
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { title: { display: true, text: 'Hora del día', font: { size: 10 } } },
                y: { beginAtZero: true, ticks: { callback: v => '$' + (v/1000 >= 1 ? (v/1000).toFixed(0)+'K' : v.toLocaleString('es-CO')) } }
            }
        }
    });
}

// Gráfico Ventas por Día de Semana
const ctxDiaSemana = document.getElementById('chartPorDiaSemana');
if (ctxDiaSemana) {
    new Chart(ctxDiaSemana, {
        type: 'bar',
        data: {
            labels: <?= json_encode($dias_semana) ?>,
            datasets: [{
                label: 'Ventas ($)',
                data: <?= json_encode($ventas_por_dia_semana) ?>,
                backgroundColor: 'rgba(16,185,129,0.7)',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => '$' + (v/1000 >= 1 ? (v/1000).toFixed(0)+'K' : v.toLocaleString('es-CO')) } }
            }
        }
    });
}

// Gráfico Categorías
const ctxCategorias = document.getElementById('chartCategorias');
if (ctxCategorias && <?= count($ventas_categoria) ?> > 0) {
    new Chart(ctxCategorias, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($ventas_categoria, 'nombre')) ?>,
            datasets: [{
                data: <?= json_encode(array_column($ventas_categoria, 'total_ventas')) ?>,
                backgroundColor: ['#10b981', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec489a', '#14b8a6', '#f97316', '#a855f7'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: { tooltip: { callbacks: { label: (ctx) => '💰 $' + ctx.raw.toLocaleString('es-CO') } } }
        }
    });
}

// Gráfico Métodos de Pago
const ctxMetodos = document.getElementById('chartMetodosPago');
if (ctxMetodos && <?= count($metodos_pago) ?> > 0) {
    new Chart(ctxMetodos, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($metodos_pago, 'metodo_pago')) ?>,
            datasets: [{
                data: <?= json_encode(array_column($metodos_pago, 'total')) ?>,
                backgroundColor: ['#10b981', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: { tooltip: { callbacks: { label: (ctx) => '💰 $' + ctx.raw.toLocaleString('es-CO') } } }
        }
    });
}
</script>
