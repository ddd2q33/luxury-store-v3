<?php
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('ventas');

$user_id = $_SESSION['user_id'];
$user_nombre = $_SESSION['user_nombre'] ?? 'Usuario';
$user_rol = $_SESSION['rol'] ?? 'empleado';
$user_avatar = $_SESSION['user_avatar'] ?? ($_SESSION['imagen_perfil'] ?? null);

if (empty($user_avatar)) {
    $stmt_avatar = $conn->prepare("SELECT imagen_perfil FROM usuarios WHERE id = ?");
    if ($stmt_avatar) {
        $stmt_avatar->bind_param("i", $user_id);
        $stmt_avatar->execute();
        $result_avatar = $stmt_avatar->get_result();
        if ($row_avatar = $result_avatar->fetch_assoc()) {
            $user_avatar = $row_avatar['imagen_perfil'] ?? null;
        }
        $stmt_avatar->close();
    }
}



date_default_timezone_set('America/Bogota');

// ── Fechas ───────────────────────────────────────────────────────────────────
$hoy           = date('Y-m-d');
$inicio_mes    = date('Y-m-01');
$inicio_semana = date('Y-m-d', strtotime('monday this week'));

$fecha_inicio  = (isset($_GET['fecha_inicio']) && strtotime($_GET['fecha_inicio'])) ? $_GET['fecha_inicio'] : $inicio_mes;
$fecha_fin     = (isset($_GET['fecha_fin'])    && strtotime($_GET['fecha_fin']))    ? $_GET['fecha_fin']    : $hoy;
$fecha_fin_sql = $fecha_fin . ' 23:59:59';

// ── PAGINACIÓN ───────────────────────────────────────────────────────────────
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registros_por_pagina = 10;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// ── Obtener TOTAL de ventas para paginación ─────────────────────────────────
$stmt_total = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM ventas 
    WHERE fecha_venta BETWEEN ? AND ?
");
$stmt_total->bind_param("ss", $fecha_inicio, $fecha_fin_sql);
$stmt_total->execute();
$total_registros = $stmt_total->get_result()->fetch_assoc()['total'];
$stmt_total->close();

$total_paginas = ceil($total_registros / $registros_por_pagina);
if ($pagina_actual > $total_paginas && $total_paginas > 0) {
    $pagina_actual = $total_paginas;
    $offset = ($pagina_actual - 1) * $registros_por_pagina;
}

// ── Ventas del período CON PAGINACIÓN ────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, total, cliente_nombre, fecha_venta, estado, cliente_id
    FROM ventas
    WHERE fecha_venta BETWEEN ? AND ?
    ORDER BY fecha_venta DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("ssii", $fecha_inicio, $fecha_fin_sql, $registros_por_pagina, $offset);
$stmt->execute();
$res = $stmt->get_result();

$ventas = [];
$total_ingresos = 0;
$ventas_completadas = 0;

while ($v = $res->fetch_assoc()) {
    $ventas[] = $v;
    if (in_array(strtolower($v['estado']), ['completada','pagada'])) {
        $total_ingresos += (float)$v['total'];
        $ventas_completadas++;
    }
}
$stmt->close();

// ── GASTOS (EGRESOS de movimientos_caja) ─────────────────────────────────────
$stmt_g = $conn->prepare("
    SELECT COALESCE(SUM(monto),0) AS total 
    FROM movimientos_caja 
    WHERE tipo = 'egreso' AND DATE(fecha) BETWEEN ? AND ?
");
$stmt_g->bind_param("ss", $fecha_inicio, $fecha_fin);
$stmt_g->execute();
$total_gastos = (float)($stmt_g->get_result()->fetch_assoc()['total'] ?? 0);
$stmt_g->close();

$balance = $total_ingresos - $total_gastos;
$avg     = $ventas_completadas > 0 ? $total_ingresos / $ventas_completadas : 0;

// ── Ventas por día (para gráfico de barras) con nombres de días ────────────────
$stmt_dias = $conn->prepare("
    SELECT DATE(fecha_venta) AS dia, 
           COUNT(*) AS cantidad, 
           SUM(total) AS total_dia,
           DAYOFWEEK(fecha_venta) AS numero_dia
    FROM ventas
    WHERE fecha_venta BETWEEN ? AND ?
      AND LOWER(estado) IN ('completada','pagada')
    GROUP BY DATE(fecha_venta)
    ORDER BY dia ASC
");
$stmt_dias->bind_param("ss", $fecha_inicio, $fecha_fin_sql);
$stmt_dias->execute();
$ventas_por_dia_raw = $stmt_dias->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_dias->close();

// Array de días de la semana en español
$dias_semana_es = [
    1 => 'Domingo',
    2 => 'Lunes',
    3 => 'Martes',
    4 => 'Miércoles',
    5 => 'Jueves',
    6 => 'Viernes',
    7 => 'Sábado'
];

// Preparar datos para gráfico con nombres de días
$ventas_por_dia = [];
$chart_labels = [];
$chart_totales = [];
$chart_cantidades = [];

foreach ($ventas_por_dia_raw as $row) {
    $fecha_obj = new DateTime($row['dia']);
    $dia_semana_num = (int)$row['numero_dia'];
    $nombre_dia = $dias_semana_es[$dia_semana_num];
    $dia_mes = $fecha_obj->format('d');
    
    // Formato: "Lunes 15"
    $label = $nombre_dia . ' ' . $dia_mes;
    
    $ventas_por_dia[] = [
        'dia' => $row['dia'],
        'cantidad' => (int)$row['cantidad'],
        'total_dia' => (float)$row['total_dia'],
        'nombre_dia' => $nombre_dia
    ];
    
    $chart_labels[] = $label;
    $chart_totales[] = (float)$row['total_dia'];
    $chart_cantidades[] = (int)$row['cantidad'];
}

// ── Ventas por método de pago (de movimientos_caja) ──────────────────────────
$stmt_met = $conn->prepare("
    SELECT metodo_pago, COUNT(*) AS cantidad, SUM(monto) AS total
    FROM movimientos_caja
    WHERE tipo = 'venta' AND DATE(fecha) BETWEEN ? AND ?
    GROUP BY metodo_pago
    ORDER BY total DESC
");
$stmt_met->bind_param("ss", $fecha_inicio, $fecha_fin);
$stmt_met->execute();
$por_metodo = $stmt_met->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_met->close();

// ── Top clientes ─────────────────────────────────────────────────────────────
$stmt_t = $conn->prepare("
    SELECT cliente_nombre, COUNT(*) AS num_compras, SUM(total) AS total_c
    FROM ventas
    WHERE fecha_venta BETWEEN ? AND ?
      AND LOWER(estado) IN ('completada','pagada')
    GROUP BY cliente_id, cliente_nombre
    ORDER BY total_c DESC
    LIMIT 5
");
$stmt_t->bind_param("ss", $fecha_inicio, $fecha_fin_sql);
$stmt_t->execute();
$top_clientes = $stmt_t->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_t->close();

// ── Comparación vs período anterior ─────────────────────────────────────────
$dias_periodo  = max(1, (strtotime($fecha_fin) - strtotime($fecha_inicio)) / 86400 + 1);
$inicio_ant    = date('Y-m-d', strtotime($fecha_inicio) - $dias_periodo * 86400);
$fin_ant       = date('Y-m-d', strtotime($fecha_inicio) - 86400);
$fin_ant_sql   = $fin_ant . ' 23:59:59';

$stmt_ant = $conn->prepare("
    SELECT COALESCE(SUM(total),0) AS total
    FROM ventas
    WHERE fecha_venta BETWEEN ? AND ?
      AND LOWER(estado) IN ('completada','pagada')
");
$stmt_ant->bind_param("ss", $inicio_ant, $fin_ant_sql);
$stmt_ant->execute();
$total_anterior = (float)($stmt_ant->get_result()->fetch_assoc()['total'] ?? 0);
$stmt_ant->close();

$variacion_pct = $total_anterior > 0
    ? round((($total_ingresos - $total_anterior) / $total_anterior) * 100, 1)
    : ($total_ingresos > 0 ? 100 : 0);

// ── Distribución estados ─────────────────────────────────────────────────────
$stmt_est = $conn->prepare("
    SELECT estado, COUNT(*) AS cantidad
    FROM ventas
    WHERE fecha_venta BETWEEN ? AND ?
    GROUP BY estado
");
$stmt_est->bind_param("ss", $fecha_inicio, $fecha_fin_sql);
$stmt_est->execute();
$por_estado = $stmt_est->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_est->close();

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmtV($n) { return '$' . number_format((float)$n, 0, ',', '.'); }

function getAvatarUrlVentas($imagen, $nombre) {
    if (!empty($imagen)) {
        $candidatas = [
            [ 'file' => __DIR__ . "/../uploads/avatars/" . $imagen, 'url' => "../uploads/avatars/" . rawurlencode($imagen) ],
            [ 'file' => __DIR__ . "/uploads/avatars/" . $imagen, 'url' => "../uploads/avatars/" . rawurlencode($imagen) ],
            [ 'file' => $_SERVER['DOCUMENT_ROOT'] . "/luxuryv3/uploads/avatars/" . $imagen, 'url' => "../uploads/avatars/" . rawurlencode($imagen) ],
        ];
        foreach ($candidatas as $candidata) {
            if (file_exists($candidata['file'])) {
                return $candidata['url'];
            }
        }
    }
    return "../assets/img/avatars/default.svg";
}

// JSON para gráficos
$chart_labels_json = json_encode($chart_labels, JSON_UNESCAPED_UNICODE);
$chart_totales_json = json_encode($chart_totales);
$chart_cantidades_json = json_encode($chart_cantidades);

$metodos_labels = array_map(fn($r) => $r['metodo_pago'] ?: 'Otro', $por_metodo);
$metodos_totales = array_map(fn($r) => (float)$r['total'], $por_metodo);

$metodos_labels_json = json_encode($metodos_labels, JSON_UNESCAPED_UNICODE);
$metodos_totales_json = json_encode($metodos_totales);

// Función para construir URLs de paginación
function buildPaginationUrl($pagina, $fecha_inicio, $fecha_fin) {
    return "?pagina=$pagina&fecha_inicio=$fecha_inicio&fecha_fin=$fecha_fin";
}

$avatar_url = getAvatarUrlVentas($user_avatar, $user_nombre);
$avatar_mobile = $avatar_url;
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Luxury Store - Ventas</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
  <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
  <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
  <script src="../assets/vendor/chartjs/chart.umd.min.js"></script>
  <style>
    * { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 to-gray-100 min-h-screen">
<?php luxury_render_nav_start('ventas'); ?>
    <div class="max-w-[1600px] mx-auto space-y-5">

<!-- ── HEADER ────────────────────────────────────────────────────────────── -->
<div class="flex items-start justify-between flex-wrap gap-4">
  <div>
    <h1 class="text-2xl font-extrabold text-gray-800 flex items-center gap-2">
      <i class="fas fa-chart-line text-emerald-500"></i> Ventas
    </h1>
    <p class="text-xs text-gray-400 mt-0.5">
      <?= date('d/m/Y', strtotime($fecha_inicio)) ?> — <?= date('d/m/Y', strtotime($fecha_fin)) ?>
      · <?= $total_registros ?> registros
    </p>
  </div>

  <!-- Filtros rápidos -->
  <div class="flex flex-wrap gap-2 items-center">
    <?php foreach ([
      'Hoy'         => [$hoy, $hoy],
      'Esta semana' => [$inicio_semana, $hoy],
      'Este mes'    => [$inicio_mes, $hoy],
    ] as $lbl => [$fi, $ff]):
      $active = ($fecha_inicio === $fi && $fecha_fin === $ff);
    ?>
    <a href="?fecha_inicio=<?= $fi ?>&fecha_fin=<?= $ff ?>&pagina=1"
       class="px-3 py-1.5 rounded-lg text-xs font-bold border transition-all
              <?= $active ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-500 border-gray-200 hover:border-gray-400' ?>">
      <?= $lbl ?>
    </a>
    <?php endforeach; ?>

    <!-- Rango personalizado -->
    <form method="GET" class="flex items-center gap-2">
      <input type="date" name="fecha_inicio" value="<?= $fecha_inicio ?>"
             class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-emerald-400 outline-none">
      <span class="text-gray-300 text-xs">→</span>
      <input type="date" name="fecha_fin" value="<?= $fecha_fin ?>"
             class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-emerald-400 outline-none">
      <input type="hidden" name="pagina" value="1">
      <button type="submit"
              class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold transition-colors">
        <i class="fas fa-search"></i>
      </button>
    </form>
  </div>
</div>

<!-- ── KPIs ───────────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3">

  <!-- Ingresos -->
  <div class="lg:col-span-2 bg-gradient-to-br from-gray-900 to-gray-800 rounded-2xl p-5 text-white relative overflow-hidden">
    <div class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-500/10 rounded-full"></div>
    <div class="absolute -right-2 -bottom-6 w-32 h-32 bg-emerald-500/5 rounded-full"></div>
    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Ingresos del período</p>
    <p class="text-3xl font-extrabold text-emerald-400"><?= fmtV($total_ingresos) ?></p>
    <p class="text-xs text-gray-500 mt-1"><?= $ventas_completadas ?> ventas completadas</p>
    <?php if ($total_anterior > 0): ?>
    <div class="mt-3 flex items-center gap-2">
      <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $variacion_pct >= 0 ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400' ?>">
        <?= $variacion_pct >= 0 ? '▲' : '▼' ?> <?= abs($variacion_pct) ?>% vs período anterior
      </span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Gastos (EGRESOS) -->
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-2">
      <div class="w-7 h-7 bg-red-50 rounded-lg flex items-center justify-center">
        <i class="fas fa-arrow-down text-red-400 text-xs"></i>
      </div>
      <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Gastos</p>
    </div>
    <p class="text-2xl font-extrabold text-red-500"><?= fmtV($total_gastos) ?></p>
    <p class="text-[10px] text-gray-400 mt-1">Egresos de caja</p>
  </div>

  <!-- Balance -->
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-2">
      <div class="w-7 h-7 <?= $balance >= 0 ? 'bg-blue-50' : 'bg-red-50' ?> rounded-lg flex items-center justify-center">
        <i class="fas fa-scale-balanced <?= $balance >= 0 ? 'text-blue-400' : 'text-red-400' ?> text-xs"></i>
      </div>
      <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Balance</p>
    </div>
    <p class="text-2xl font-extrabold <?= $balance >= 0 ? 'text-blue-600' : 'text-red-500' ?>">
      <?= fmtV($balance) ?>
    </p>
  </div>

  <!-- Ticket promedio -->
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-2">
      <div class="w-7 h-7 bg-amber-50 rounded-lg flex items-center justify-center">
        <i class="fas fa-ticket text-amber-400 text-xs"></i>
      </div>
      <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Ticket prom.</p>
    </div>
    <p class="text-2xl font-extrabold text-amber-600"><?= fmtV($avg) ?></p>
  </div>

</div>

<!-- ── GRÁFICOS FILA 1 ────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

  <!-- Gráfico de barras: ventas por día -->
  <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h3 class="font-bold text-gray-800 text-sm">Ventas por día</h3>
        <p class="text-[10px] text-gray-400">Solo ventas completadas/pagadas</p>
      </div>
      <div class="flex gap-3 text-[10px] text-gray-400">
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-emerald-400 inline-block"></span> Monto</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-indigo-300 inline-block"></span> Cantidad</span>
      </div>
    </div>
    <?php if (!empty($ventas_por_dia)): ?>
    <div class="relative h-48">
      <canvas id="chartDias"></canvas>
    </div>
    <?php else: ?>
    <div class="h-48 flex items-center justify-center text-gray-300 text-sm">
      <div class="text-center"><i class="fas fa-chart-bar text-4xl block mb-2 opacity-30"></i>Sin datos en este período</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Gráfico donut: métodos de pago -->
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <div class="mb-4">
      <h3 class="font-bold text-gray-800 text-sm">Métodos de pago</h3>
      <p class="text-[10px] text-gray-400">Distribución por método</p>
    </div>
    <?php if (!empty($por_metodo)): ?>
    <div class="relative h-36">
      <canvas id="chartMetodos"></canvas>
    </div>
    <div class="mt-3 space-y-1.5">
      <?php
      $colores_met = ['#10b981','#6366f1','#f59e0b','#ef4444','#8b5cf6'];
      $total_met = array_sum($metodos_totales);
      foreach ($por_metodo as $i => $met):
        $pct = $total_met > 0 ? round($met['total'] / $total_met * 100) : 0;
        $color = $colores_met[$i % count($colores_met)];
      ?>
      <div class="flex items-center justify-between text-xs">
        <div class="flex items-center gap-1.5">
          <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:<?= $color ?>"></span>
          <span class="text-gray-600"><?= htmlspecialchars($met['metodo_pago'] ?: 'Otro') ?></span>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-gray-400"><?= $met['cantidad'] ?> ventas</span>
          <span class="font-bold text-gray-700"><?= $pct ?>%</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="h-36 flex items-center justify-center text-gray-300 text-sm">
      <div class="text-center"><i class="fas fa-circle-half-stroke text-3xl block mb-2 opacity-30"></i>Sin datos</div>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ── FILA 2: Top clientes + Estados ────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

  <!-- Top clientes -->
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <h3 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
      <i class="fas fa-trophy text-amber-400 text-xs"></i> Top Clientes
    </h3>
    <?php if (!empty($top_clientes)):
      $max_tc = max(array_column($top_clientes, 'total_c'));
      foreach ($top_clientes as $i => $tc):
        $pct_tc = $max_tc > 0 ? ($tc['total_c'] / $max_tc * 100) : 0;
        $medallas = ['🥇','🥈','🥉','4°','5°'];
    ?>
    <div class="mb-3 last:mb-0">
      <div class="flex items-center justify-between mb-1">
        <div class="flex items-center gap-2">
          <span class="text-sm"><?= $medallas[$i] ?></span>
          <span class="text-xs font-semibold text-gray-700 truncate max-w-[150px]">
            <?= htmlspecialchars($tc['cliente_nombre'] ?: 'Cliente General') ?>
          </span>
        </div>
        <div class="text-right flex-shrink-0">
          <span class="text-xs font-extrabold text-gray-800"><?= fmtV($tc['total_c']) ?></span>
          <span class="text-[10px] text-gray-400 block"><?= $tc['num_compras'] ?> compra<?= $tc['num_compras'] != 1 ? 's' : '' ?></span>
        </div>
      </div>
      <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
        <div class="h-full rounded-full transition-all"
             style="width:<?= round($pct_tc) ?>%; background: <?= ['#10b981','#6366f1','#f59e0b','#94a3b8','#cbd5e1'][$i] ?>"></div>
      </div>
    </div>
    <?php endforeach; else: ?>
    <div class="py-8 text-center text-gray-300 text-sm">
      <i class="fas fa-users text-3xl block mb-2 opacity-30"></i>Sin datos de clientes
    </div>
    <?php endif; ?>
  </div>

  <!-- Distribución por estado -->
  <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
    <h3 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
      <i class="fas fa-circle-dot text-indigo-400 text-xs"></i> Estado de Ventas
    </h3>
    <?php if (!empty($por_estado)):
      $total_est = array_sum(array_column($por_estado, 'cantidad'));
      $colores_est = [
        'completada' => ['bg-emerald-100 text-emerald-700', '#10b981'],
        'pagada'     => ['bg-emerald-100 text-emerald-700', '#059669'],
        'pendiente'  => ['bg-amber-100 text-amber-700',    '#f59e0b'],
        'cancelada'  => ['bg-red-100 text-red-600',        '#ef4444'],
        'anulada'    => ['bg-red-100 text-red-600',        '#dc2626'],
      ];
      foreach ($por_estado as $est):
        $key = strtolower($est['estado']);
        [$badge_cls, $bar_color] = $colores_est[$key] ?? ['bg-gray-100 text-gray-500', '#94a3b8'];
        $pct_est = $total_est > 0 ? round($est['cantidad'] / $total_est * 100) : 0;
    ?>
    <div class="flex items-center gap-3 mb-3 last:mb-0">
      <span class="<?= $badge_cls ?> text-[10px] font-bold px-2 py-0.5 rounded-full w-24 text-center flex-shrink-0 uppercase">
        <?= htmlspecialchars($est['estado']) ?>
      </span>
      <div class="flex-1">
        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full" style="width:<?= $pct_est ?>%; background:<?= $bar_color ?>"></div>
        </div>
      </div>
      <div class="text-right flex-shrink-0 w-16">
        <span class="text-xs font-bold text-gray-700"><?= $est['cantidad'] ?></span>
        <span class="text-[10px] text-gray-400"> (<?= $pct_est ?>%)</span>
      </div>
    </div>
    <?php endforeach; else: ?>
    <div class="py-8 text-center text-gray-300 text-sm">
      <i class="fas fa-chart-pie text-3xl block mb-2 opacity-30"></i>Sin datos
    </div>
    <?php endif; ?>

    <!-- Mini resumen numérico -->
    <?php if (!empty($ventas)): ?>
    <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-3 gap-2 text-center">
      <div>
        <p class="text-xs font-extrabold text-gray-800"><?= $total_registros ?></p>
        <p class="text-[10px] text-gray-400">Total</p>
      </div>
      <div>
        <p class="text-xs font-extrabold text-emerald-600"><?= $ventas_completadas ?></p>
        <p class="text-[10px] text-gray-400">Completadas</p>
      </div>
      <div>
        <p class="text-xs font-extrabold text-red-500">
          <?= $total_registros - $ventas_completadas ?>
        </p>
        <p class="text-[10px] text-gray-400">Otras</p>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ── TABLA HISTORIAL CON PAGINACIÓN ──────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3">
    <h3 class="font-bold text-gray-800 text-sm flex items-center gap-2">
      <i class="fas fa-list text-gray-400 text-xs"></i> Historial de Ventas
      <span class="bg-gray-100 text-gray-500 text-xs font-bold px-2 py-0.5 rounded-full">
        Página <?= $pagina_actual ?> de <?= max(1, $total_paginas) ?>
      </span>
    </h3>
    <div class="flex items-center gap-3 flex-wrap">
      <!-- Buscador inline -->
      <input type="text" id="buscarVenta" placeholder="Buscar cliente, estado…"
             class="border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:ring-2 focus:ring-indigo-400 outline-none w-48"
             oninput="filtrarTabla(this.value)">
      <span class="bg-gray-100 text-gray-500 text-xs font-bold px-2 py-0.5 rounded-full" id="contadorTabla">
        <?= count($ventas) ?> registros
      </span>
      <button onclick="descargarExcel()"
              class="flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition-all shadow-sm">
        <i class="fas fa-file-excel"></i> Exportar Excel
      </button>
    </div>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full text-sm text-left" id="tablaVentas">
      <thead class="bg-gray-50 text-[10px] text-gray-400 uppercase tracking-wider">
          <tr>
          <th class="px-5 py-3 cursor-pointer hover:text-gray-600" onclick="sortTabla(0)">Ref <i class="fas fa-sort text-gray-300 ml-1"></i></th>
          <th class="px-5 py-3 cursor-pointer hover:text-gray-600" onclick="sortTabla(1)">Cliente <i class="fas fa-sort text-gray-300 ml-1"></i></th>
          <th class="px-5 py-3 text-right cursor-pointer hover:text-gray-600" onclick="sortTabla(2)">Total <i class="fas fa-sort text-gray-300 ml-1"></i></th>
          <th class="px-5 py-3 cursor-pointer hover:text-gray-600" onclick="sortTabla(3)">Fecha <i class="fas fa-sort text-gray-300 ml-1"></i></th>
          <th class="px-5 py-3 text-center">Estado</th>
          </tr>
      </thead>
      <tbody id="tbodyVentas" class="divide-y divide-gray-50">
        <?php if ($ventas): foreach ($ventas as $v):
          $est = strtolower($v['estado']);
          $badge = match(true) {
            in_array($est, ['completada','pagada']) => 'bg-emerald-100 text-emerald-700',
            $est === 'pendiente'                    => 'bg-amber-100 text-amber-700',
            in_array($est, ['cancelada','anulada']) => 'bg-red-100 text-red-600',
            default                                 => 'bg-gray-100 text-gray-500',
          };
        ?>
        <tr class="hover:bg-gray-50 transition-colors fila-venta">
          <td class="px-5 py-3 font-mono text-xs text-gray-400">#<?= $v['id'] ?> </td>
          <td class="px-5 py-3 font-semibold text-gray-800 text-xs">
            <?= htmlspecialchars($v['cliente_nombre'] ?: 'Público General') ?>
          </td>
          <td class="px-5 py-3 text-right font-extrabold text-emerald-600 font-mono text-sm">
            <?= fmtV($v['total']) ?>
          </td>
          <td class="px-5 py-3 text-xs text-gray-500 font-mono">
            <?= date('d/m/Y', strtotime($v['fecha_venta'])) ?>
            <span class="text-gray-300"><?= date('H:i', strtotime($v['fecha_venta'])) ?></span>
          </td>
          <td class="px-5 py-3 text-center">
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= $badge ?>">
              <?= htmlspecialchars($v['estado']) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr>
          <td colspan="5" class="px-5 py-14 text-center text-gray-400 text-sm">
            <i class="fas fa-inbox text-4xl block mb-3 opacity-20"></i>
            No hay ventas en este período.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- PAGINACIÓN -->
  <?php if ($total_paginas > 1): ?>
  <div class="px-5 py-4 bg-gray-50 border-t border-gray-200">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <p class="text-xs text-gray-500">
        Mostrando <span class="font-semibold text-gray-700"><?= count($ventas) ?></span> de 
        <span class="font-semibold text-gray-700"><?= $total_registros ?></span> registros
      </p>
      
      <div class="flex gap-1">
        <!-- Botón Primera página -->
        <?php if ($pagina_actual > 1): ?>
        <a href="<?= buildPaginationUrl(1, $fecha_inicio, $fecha_fin) ?>" 
           class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-gray-600 text-xs hover:bg-gray-50 transition">
          <i class="fas fa-angle-double-left"></i>
        </a>
        <?php endif; ?>
        
        <!-- Botón Anterior -->
        <?php if ($pagina_actual > 1): ?>
        <a href="<?= buildPaginationUrl($pagina_actual - 1, $fecha_inicio, $fecha_fin) ?>" 
           class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-gray-600 text-xs hover:bg-gray-50 transition">
          <i class="fas fa-chevron-left"></i> Anterior
        </a>
        <?php endif; ?>
        
        <!-- Números de página -->
        <div class="flex gap-1">
          <?php
          $start_page = max(1, $pagina_actual - 2);
          $end_page = min($total_paginas, $pagina_actual + 2);
          
          if ($start_page > 1) {
              echo '<span class="px-3 py-1.5 text-gray-400 text-xs">...</span>';
          }
          
          for ($i = $start_page; $i <= $end_page; $i++):
            $active = ($i == $pagina_actual) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white border-gray-300 text-gray-600 hover:bg-gray-50';
          ?>
          <a href="<?= buildPaginationUrl($i, $fecha_inicio, $fecha_fin) ?>" 
             class="px-3 py-1.5 rounded-lg border text-xs font-medium transition <?= $active ?>">
            <?= $i ?>
          </a>
          <?php endfor;
          
          if ($end_page < $total_paginas) {
              echo '<span class="px-3 py-1.5 text-gray-400 text-xs">...</span>';
          }
          ?>
        </div>
        
        <!-- Botón Siguiente -->
        <?php if ($pagina_actual < $total_paginas): ?>
        <a href="<?= buildPaginationUrl($pagina_actual + 1, $fecha_inicio, $fecha_fin) ?>" 
           class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-gray-600 text-xs hover:bg-gray-50 transition">
          Siguiente <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
        
        <!-- Botón Última página -->
        <?php if ($pagina_actual < $total_paginas): ?>
        <a href="<?= buildPaginationUrl($total_paginas, $fecha_inicio, $fecha_fin) ?>" 
           class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-gray-600 text-xs hover:bg-gray-50 transition">
          <i class="fas fa-angle-double-right"></i>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Totales pie de tabla -->
  <?php if (!empty($ventas)): ?>
  <div class="px-5 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between flex-wrap gap-2">
    <p class="text-xs text-gray-400">
      <strong class="text-gray-600"><?= $total_registros ?></strong> ventas en el período
    </p>
    <div class="flex gap-4 text-xs font-bold">
      <span class="text-emerald-600">Ingresos <?= fmtV($total_ingresos) ?></span>
      <?php if ($total_gastos > 0): ?>
        <span class="text-red-500">Gastos <?= fmtV($total_gastos) ?></span>
      <?php endif; ?>
      <span class="<?= $balance >= 0 ? 'text-indigo-600' : 'text-red-600' ?>">Balance <?= fmtV($balance) ?></span>
    </div>
  </div>
  <?php endif; ?>
</div>

</div><!-- /max-w-7xl -->

<!-- ── SCRIPTS ────────────────────────────────────────────────────────────── -->
<script>
// ── Datos desde PHP ──────────────────────────────────────────────────────────
const CHART_LABELS    = <?= $chart_labels_json ?>;
const CHART_TOTALES   = <?= $chart_totales_json ?>;
const CHART_CANTIDADES = <?= $chart_cantidades_json ?>;
const MET_LABELS      = <?= $metodos_labels_json ?>;
const MET_TOTALES     = <?= $metodos_totales_json ?>;

// ── Paleta ───────────────────────────────────────────────────────────────────
const COLORES_MET = ['#10b981','#6366f1','#f59e0b','#ef4444','#8b5cf6','#06b6d4'];

// ── Gráfico barras: ventas por día con tooltip mejorado ───────────────────────
const ctxDias = document.getElementById('chartDias');
if (ctxDias && CHART_LABELS.length > 0) {
    new Chart(ctxDias, {
        data: {
            labels: CHART_LABELS,
            datasets: [
                {
                    type: 'bar',
                    label: 'Monto ($)',
                    data: CHART_TOTALES,
                    backgroundColor: 'rgba(16,185,129,0.15)',
                    borderColor: '#10b981',
                    borderWidth: 2,
                    borderRadius: 6,
                    yAxisID: 'yMonto',
                    order: 2,
                },
                {
                    type: 'line',
                    label: 'Cantidad',
                    data: CHART_CANTIDADES,
                    borderColor: '#a5b4fc',
                    backgroundColor: 'rgba(165,180,252,0.1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#6366f1',
                    pointRadius: 3,
                    tension: 0.4,
                    yAxisID: 'yCant',
                    order: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1f2937',
                    titleColor: '#9ca3af',
                    bodyColor: '#f9fafb',
                    padding: 10,
                    callbacks: {
                        title: (tooltipItems) => {
                            const label = tooltipItems[0].label;
                            return '📅 ' + label;
                        },
                        label: (ctx) => {
                            if (ctx.datasetIndex === 0) {
                                return '💰 Total: $' + ctx.raw.toLocaleString('es-CO');
                            }
                            return '📦 Ventas: ' + ctx.raw;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { 
                        font: { size: 10 }, 
                        color: '#9ca3af',
                        maxRotation: CHART_LABELS.length > 15 ? 45 : 0,
                        autoSkip: true,
                        maxTicksLimit: 10
                    }
                },
                yMonto: {
                    position: 'left',
                    grid: { color: '#f3f4f6' },
                    ticks: {
                        font: { size: 10 }, 
                        color: '#9ca3af',
                        callback: v => '$' + (v/1000 >= 1 ? (v/1000).toFixed(0)+'K' : v.toLocaleString('es-CO'))
                    },
                    title: {
                        display: true,
                        text: 'Monto ($)',
                        color: '#10b981',
                        font: { size: 9 }
                    }
                },
                yCant: {
                    position: 'right',
                    grid: { display: false },
                    ticks: { 
                        font: { size: 10 }, 
                        color: '#a5b4fc', 
                        stepSize: 1 
                    },
                    title: {
                        display: true,
                        text: 'Cantidad de ventas',
                        color: '#6366f1',
                        font: { size: 9 }
                    }
                }
            }
        }
    });
}

// ── Gráfico donut: métodos de pago ───────────────────────────────────────────
const ctxMet = document.getElementById('chartMetodos');
if (ctxMet && MET_LABELS.length > 0) {
    new Chart(ctxMet, {
        type: 'doughnut',
        data: {
            labels: MET_LABELS,
            datasets: [{
                data: MET_TOTALES,
                backgroundColor: COLORES_MET.slice(0, MET_LABELS.length),
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1f2937',
                    titleColor: '#9ca3af',
                    bodyColor: '#f9fafb',
                    callbacks: {
                        label: ctx => ' $' + ctx.raw.toLocaleString('es-CO')
                    }
                }
            }
        }
    });
}

// ── Búsqueda en tabla ────────────────────────────────────────────────────────
function filtrarTabla(q) {
    const rows = document.querySelectorAll('#tbodyVentas .fila-venta');
    const term = q.toLowerCase().trim();
    let visible = 0;
    rows.forEach(row => {
        const txt = row.textContent.toLowerCase();
        const show = !term || txt.includes(term);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('contadorTabla').textContent = visible + ' registros';
}

// ── Ordenar tabla ────────────────────────────────────────────────────────────
const _sortDir = {};
function sortTabla(col) {
    const tbody = document.getElementById('tbodyVentas');
    const rows  = Array.from(tbody.querySelectorAll('.fila-venta'));
    const dir   = _sortDir[col] = !_sortDir[col];

    rows.sort((a, b) => {
        const va = a.cells[col]?.textContent.trim() ?? '';
        const vb = b.cells[col]?.textContent.trim() ?? '';
        const na = parseFloat(va.replace(/[^0-9.-]/g,''));
        const nb = parseFloat(vb.replace(/[^0-9.-]/g,''));
        if (!isNaN(na) && !isNaN(nb)) return dir ? na - nb : nb - na;
        return dir ? va.localeCompare(vb,'es') : vb.localeCompare(va,'es');
    });

    rows.forEach(r => tbody.appendChild(r));
}

// ── Exportar Excel ───────────────────────────────────────────────────────────
function descargarExcel() {
    if (typeof XLSX === 'undefined') {
        const btn = document.querySelector('[onclick="descargarExcel()"]');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cargando...';
        btn.disabled = true;
        const s = document.createElement('script');
        s.src = '../assets/vendor/sheetjs/xlsx.full.min.js';
        s.onload  = () => { btn.innerHTML = '<i class="fas fa-file-excel"></i> Exportar Excel'; btn.disabled = false; _generarExcel(); };
        s.onerror = () => { btn.innerHTML = '<i class="fas fa-file-excel"></i> Exportar Excel'; btn.disabled = false; alert('No se pudo cargar la librería.'); };
        document.head.appendChild(s);
    } else {
        _generarExcel();
    }
}

function _generarExcel() {
    // Obtener todas las ventas del período (sin paginación) para exportar
    const wb = XLSX.utils.book_new();
    
    <?php
    // Obtener todas las ventas del período para exportar
    $stmt_export = $conn->prepare("
        SELECT id, total, cliente_nombre, fecha_venta, estado
        FROM ventas
        WHERE fecha_venta BETWEEN ? AND ?
        ORDER BY fecha_venta DESC
    ");
    $stmt_export->bind_param("ss", $fecha_inicio, $fecha_fin_sql);
    $stmt_export->execute();
    $export_ventas = $stmt_export->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_export->close();
    ?>
    
    const todasVentas = <?= json_encode(array_map(function($v) {
        return [
            'Ref' => '#' . $v['id'],
            'Cliente' => $v['cliente_nombre'] ?: 'Público General',
            'Total ($)' => (float)$v['total'],
            'Fecha' => date('d/m/Y H:i', strtotime($v['fecha_venta'])),
            'Estado' => $v['estado']
        ];
    }, $export_ventas)) ?>;
    
    const ws1 = XLSX.utils.json_to_sheet(todasVentas);
    ws1['!cols'] = [{ wch:8 },{ wch:28 },{ wch:16 },{ wch:18 },{ wch:14 }];
    XLSX.utils.book_append_sheet(wb, ws1, 'Ventas');

    // Hoja 2: Resumen
    const resumen = [
        ['RESUMEN DEL PERÍODO', ''],
        ['', ''],
        ['Período',             '<?= date("d/m/Y", strtotime($fecha_inicio)) ?> — <?= date("d/m/Y", strtotime($fecha_fin)) ?>'],
        ['Ingresos',            <?= $total_ingresos ?>],
        ['Gastos',              <?= $total_gastos ?>],
        ['Balance',             <?= $balance ?>],
        ['Ventas completadas',  <?= $ventas_completadas ?>],
        ['Ticket promedio',     <?= round($avg) ?>],
        ['', ''],
        ['MÉTODOS DE PAGO', ''],
        ['Método', 'Total ($)', 'Cantidad'],
        <?php foreach ($por_metodo as $met): ?>
        ['<?= addslashes($met['metodo_pago'] ?: 'Otro') ?>', <?= (float)$met['total'] ?>, <?= (int)$met['cantidad'] ?>],
        <?php endforeach; ?>
    ];
    const ws2 = XLSX.utils.aoa_to_sheet(resumen);
    XLSX.utils.book_append_sheet(wb, ws2, 'Resumen');

    const nombre = 'ventas_<?= $fecha_inicio ?>_al_<?= $fecha_fin ?>.xlsx';
    XLSX.writeFile(wb, nombre);
}

function updateClockVentas() {
    const el = document.getElementById('relojVentas');
    if (el) el.textContent = new Date().toLocaleTimeString('es-CO');
}
setInterval(updateClockVentas, 1000);
updateClockVentas();

</script>
</div>

<?php luxury_render_nav_end(); ?>
</body>
</html>