<?php
// dashboard/index.php
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('dashboard');

$is_admin = ($user_rol === 'admin');

function tablaTieneColumna($conn, $tabla, $columna)
{
    $tabla = mysqli_real_escape_string($conn, $tabla);
    $columna = mysqli_real_escape_string($conn, $columna);
    $sql = "SHOW COLUMNS FROM `{$tabla}` LIKE '{$columna}'";
    $result = mysqli_query($conn, $sql);
    return $result && mysqli_num_rows($result) > 0;
}

// ========== MÉTRICAS DE VENTAS ==========
$ventas_hoy = 0;
$sql = "SELECT COALESCE(SUM(total), 0) as total FROM ventas WHERE DATE(fecha_venta) = CURDATE() AND estado = 'Completada'";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $ventas_hoy = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$ventas_ayer = 0;
$sql = "SELECT COALESCE(SUM(total), 0) as total FROM ventas WHERE DATE(fecha_venta) = CURDATE() - INTERVAL 1 DAY AND estado = 'Completada'";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $ventas_ayer = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$ventas_mes = 0;
$sql = "SELECT COALESCE(SUM(total), 0) as total FROM ventas WHERE MONTH(fecha_venta) = MONTH(CURDATE()) AND YEAR(fecha_venta) = YEAR(CURDATE()) AND estado = 'Completada'";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $ventas_mes = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$ventas_mes_anterior = 0;
$sql = "SELECT COALESCE(SUM(total), 0) as total FROM ventas WHERE MONTH(fecha_venta) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(fecha_venta) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND estado = 'Completada'";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $ventas_mes_anterior = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$ventas_total = 0;
$sql = "SELECT COALESCE(SUM(total), 0) as total FROM ventas WHERE estado = 'Completada'";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $ventas_total = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$num_ventas_hoy = 0;
$sql = "SELECT COUNT(*) as total FROM ventas WHERE DATE(fecha_venta) = CURDATE() AND estado = 'Completada'";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $num_ventas_hoy = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$ticket_promedio = $num_ventas_hoy > 0 ? $ventas_hoy / $num_ventas_hoy : 0;

// ========== GASTOS ==========
$gastos_hoy = 0;
$sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo = 'egreso' AND DATE(fecha) = CURDATE()";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $gastos_hoy = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$gastos_semana = 0;
$sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo = 'egreso' AND WEEK(fecha) = WEEK(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $gastos_semana = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$gastos_mes = 0;
$sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo = 'egreso' AND MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $gastos_mes = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$gastos_ano = 0;
$sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo = 'egreso' AND YEAR(fecha) = YEAR(CURDATE())";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $gastos_ano = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

// ========== CAJA ==========
$caja_activa = false;
$caja_data = null;
$sql = "SELECT * FROM cajas WHERE estado = 'abierta' ORDER BY fecha_apertura DESC LIMIT 1";
$result = mysqli_query($conn, $sql);
if ($result) {
    $caja_data = mysqli_fetch_assoc($result);
    $caja_activa = $caja_data ? true : false;
    mysqli_free_result($result);
}

$saldo_caja = 0;
if ($caja_activa && $caja_data) {
    $saldo_caja = floatval($caja_data['saldo_inicial'] ?? 0);
    $sql = "SELECT COALESCE(SUM(CASE WHEN tipo IN ('venta', 'ingreso') THEN monto ELSE 0 END), 0) as ingresos,
                   COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) as egresos
            FROM movimientos_caja WHERE caja_id = " . intval($caja_data['id']);
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $mov = mysqli_fetch_assoc($result);
        $saldo_caja += ($mov['ingresos'] ?? 0) - ($mov['egresos'] ?? 0);
        mysqli_free_result($result);
    }
}

// ========== PRODUCTOS ==========
$total_productos = 0;
$sql = "SELECT COUNT(*) as total FROM productos";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_productos = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$productos_bajo_stock = 0;
$sql = "SELECT COUNT(*) as total FROM productos WHERE stock < 5 AND stock > 0";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $productos_bajo_stock = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$productos_sin_stock = 0;
$sql = "SELECT COUNT(*) as total FROM productos WHERE stock = 0";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $productos_sin_stock = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$productos_stock_normal = $total_productos - $productos_bajo_stock - $productos_sin_stock;

$valor_inventario = 0;
$sql = "SELECT COALESCE(SUM(stock * precio), 0) as total FROM productos";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $valor_inventario = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

// ========== CLIENTES ==========
$total_clientes = 0;
$sql = "SELECT COUNT(*) as total FROM clientes";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_clientes = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$clientes_nuevos_mes = 0;
if (tablaTieneColumna($conn, 'clientes', 'fecha_registro')) {
    $sql = "SELECT COUNT(*) as total FROM clientes WHERE MONTH(fecha_registro) = MONTH(CURDATE()) AND YEAR(fecha_registro) = YEAR(CURDATE())";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $clientes_nuevos_mes = intval($row['total'] ?? 0);
        mysqli_free_result($result);
    }
}

// ========== PEDIDOS ==========
$pedidos_estados = [];
$sql = "SELECT estado, COUNT(*) as total FROM pedidos GROUP BY estado";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $pedidos_estados[$row['estado']] = $row['total'];
    }
    mysqli_free_result($result);
}

// ========== PROVEEDORES ==========
$total_proveedores = $conn->query("SELECT COUNT(*) as total FROM proveedores")->fetch_assoc()['total'] ?? 0;
$total_deuda = $conn->query("SELECT COALESCE(SUM(saldo_deuda), 0) as total FROM proveedores")->fetch_assoc()['total'] ?? 0;
$proveedores_con_deuda = $conn->query("SELECT COUNT(*) as total FROM proveedores WHERE saldo_deuda > 0")->fetch_assoc()['total'] ?? 0;
$total_abonos_mes = $conn->query("SELECT COALESCE(SUM(monto), 0) as total FROM abonos_proveedores WHERE MONTH(fecha_abono) = MONTH(CURDATE()) AND YEAR(fecha_abono) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;
$total_compras_mes = $conn->query("SELECT COALESCE(SUM(total_general), 0) as total FROM compras WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;

$total_proveedores = 0;
$sql = "SELECT COUNT(*) as total FROM proveedores";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_proveedores = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}





// ========== COMPRAS ==========
$total_compras = 0;
$sql = "SELECT COUNT(*) as total FROM compras";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_compras = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$total_invertido = 0;
$sql = "SELECT COALESCE(SUM(total_general), 0) as total FROM compras";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_invertido = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$compras_mes = 0;
$sql = "SELECT COALESCE(SUM(total_general), 0) as total FROM compras WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $compras_mes = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

// ========== FACTURACIÓN ==========
$total_facturas = 0;
$sql = "SELECT COUNT(*) as total FROM ventas";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_facturas = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}

// ========== MOVIMIENTOS ==========
$total_ventas_mov = 0;
$sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo = 'venta' AND DATE(fecha) = CURDATE()";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_ventas_mov = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$total_ingresos_mov = 0;
$sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo = 'ingreso' AND DATE(fecha) = CURDATE()";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_ingresos_mov = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$total_egresos_mov = 0;
$sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo = 'egreso' AND DATE(fecha) = CURDATE()";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_egresos_mov = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

$balance_mov = $total_ventas_mov + $total_ingresos_mov - $total_egresos_mov;

// ========== CATEGORÍAS ==========
$total_categorias = 0;
$sql = "SELECT COUNT(*) as total FROM categorias";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_categorias = intval($row['total'] ?? 0);
    mysqli_free_result($result);
}

// ========== GRÁFICOS ==========
$ventas_7dias = [];
$labels_7dias = [];
$dias_semana = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
for ($i = 6; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-$i days"));
    $labels_7dias[] = $dias_semana[date('w', strtotime($fecha))] . ' ' . date('d/m', strtotime($fecha));
    $sql = "SELECT COALESCE(SUM(total), 0) as total FROM ventas WHERE DATE(fecha_venta) = '$fecha' AND estado = 'Completada'";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $ventas_7dias[] = floatval($row['total'] ?? 0);
        mysqli_free_result($result);
    } else {
        $ventas_7dias[] = 0;
    }
}

$gastos_7dias = [];
for ($i = 6; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-$i days"));
    $sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo = 'egreso' AND DATE(fecha) = '$fecha'";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $gastos_7dias[] = floatval($row['total'] ?? 0);
        mysqli_free_result($result);
    } else {
        $gastos_7dias[] = 0;
    }
}

$ventas_12meses = [];
$labels_12meses = [];
$meses_es = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
for ($i = 11; $i >= 0; $i--) {
    $mes = date('Y-m', strtotime("-$i months"));
    $mes_num = intval(date('m', strtotime($mes . '-01')));
    $labels_12meses[] = $meses_es[$mes_num - 1] . ' ' . date('Y', strtotime($mes . '-01'));
    $sql = "SELECT COALESCE(SUM(total), 0) as total FROM ventas WHERE DATE_FORMAT(fecha_venta, '%Y-%m') = '$mes' AND estado = 'Completada'";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $ventas_12meses[] = floatval($row['total'] ?? 0);
        mysqli_free_result($result);
    } else {
        $ventas_12meses[] = 0;
    }
}

$top_productos = [];
$sql = "SELECT p.nombre, COALESCE(SUM(dv.cantidad), 0) as total_vendido 
        FROM venta_detalles dv 
        JOIN productos p ON dv.producto_id = p.id 
        GROUP BY p.id 
        ORDER BY total_vendido DESC 
        LIMIT 5";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $top_productos[] = $row;
    }
    mysqli_free_result($result);
}

$top_clientes = [];
$sql = "SELECT cliente_nombre, COUNT(*) as compras, COALESCE(SUM(total), 0) as total_gastado 
        FROM ventas 
        WHERE estado = 'Completada' AND cliente_nombre IS NOT NULL AND cliente_nombre != '' AND cliente_nombre != 'Consumidor Final'
        GROUP BY cliente_nombre 
        ORDER BY total_gastado DESC 
        LIMIT 5";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $top_clientes[] = $row;
    }
    mysqli_free_result($result);
}

$devoluciones_data = [];
$sql = "SELECT DATE(fecha) as fecha, COUNT(*) as cantidad, COALESCE(SUM(total_devuelto), 0) as total 
        FROM devoluciones 
        WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
        GROUP BY DATE(fecha) 
        ORDER BY fecha ASC";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $devoluciones_data[] = $row;
    }
    mysqli_free_result($result);
}

$metodos_pago = [];
$sql = "SELECT metodo_pago, COUNT(*) as cantidad, COALESCE(SUM(monto), 0) as total 
        FROM movimientos_caja 
        WHERE tipo = 'venta' AND metodo_pago IS NOT NULL
        GROUP BY metodo_pago 
        ORDER BY total DESC";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $metodos_pago[] = $row;
    }
    mysqli_free_result($result);
}

$ventas_por_hora = array_fill(0, 24, 0);
$desde_horas = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
$hasta_horas = date('Y-m-d') . ' 23:59:59';
$stmt_horas = $conn->prepare("SELECT HOUR(fecha_venta) as hora, COUNT(*) as total 
                              FROM ventas 
                              WHERE fecha_venta BETWEEN ? AND ? AND estado = 'Completada' 
                              GROUP BY HOUR(fecha_venta)");
if ($stmt_horas) {
    $stmt_horas->bind_param("ss", $desde_horas, $hasta_horas);
    $stmt_horas->execute();
    $result = $stmt_horas->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ventas_por_hora[intval($row['hora'])] = intval($row['total']);
        }
        $result->free();
    }
    $stmt_horas->close();
}

$productos_stock_bajo = [];
$sql = "SELECT id, nombre, stock, precio FROM productos WHERE stock < 5 ORDER BY stock ASC LIMIT 10";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $productos_stock_bajo[] = $row;
    }
    mysqli_free_result($result);
}

$ultimas_ventas = [];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$where_clause = "WHERE estado = 'Completada'";
if (!empty($search)) {
    $where_clause .= " AND (cliente_nombre LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR id LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
}

$sql_total = "SELECT COUNT(*) as total FROM ventas $where_clause";
$result_total = mysqli_query($conn, $sql_total);
$total_records = mysqli_fetch_assoc($result_total)['total'];
$total_pages = ceil($total_records / $per_page);

$sql = "SELECT id, cliente_nombre, fecha_venta, total FROM ventas $where_clause ORDER BY fecha_venta DESC LIMIT $per_page OFFSET $offset";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $ultimas_ventas[] = $row;
    }
    mysqli_free_result($result);
}

$avatar_url = getAvatarUrl($user_avatar, $user_nombre);
$avatar_mobile = !empty($user_avatar) ? getAvatarUrl($user_avatar, $user_nombre) : "../assets/img/avatars/default.svg";
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Luxury Store - Home</title>
    <!-- PWA: instalable como app -->
    <meta name="theme-color" content="#111827">
    <link rel="manifest" href="../manifest.json">
    <link rel="apple-touch-icon" href="../assets/img/icons/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Luxury">
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <script src="../assets/vendor/chartjs/chart.umd.min.js"></script>
    <style>
        * {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* Sensación de app nativa */
        body {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            -webkit-tap-highlight-color: transparent;
            overscroll-behavior-y: none;
        }

        button, a, input, select {
            touch-action: manipulation;
        }

        .avatar-img {
            width: 36px;
            height: 36px;
            border-radius: 0.5rem;
            object-fit: cover;
        }

        .status-dot {
            position: absolute;
            bottom: -4px;
            right: -4px;
            width: 12px;
            height: 12px;
            background-color: #22c55e;
            border: 2px solid #000;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            }

            70% {
                transform: scale(1);
                box-shadow: 0 0 0 4px rgba(34, 197, 94, 0);
            }

            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
            }
        }

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        /* Estilos modernos para el dashboard */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(0px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.3s ease;
        }

        .glass-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.1);
            border-color: #e2e8f0;
        }

        .stat-icon {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 1rem;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .progress-bar {
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 10px;
            height: 6px;
        }

        .badge-pedido {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-pendiente {
            background: #fef3c7;
            color: #d97706;
        }

        .badge-confirmado {
            background: #dbeafe;
            color: #2563eb;
        }

        .badge-preparando {
            background: #fed7aa;
            color: #ea580c;
        }

        .badge-listo {
            background: #d1fae5;
            color: #059669;
        }

        .badge-entregado {
            background: #a7f3d0;
            color: #047857;
        }

        .badge-cancelado {
            background: #fee2e2;
            color: #dc2626;
        }

        .metric-card {
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            border: 1px solid #eef2ff;
        }

        .metric-title {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f172a;
        }

        /* Espejo desktop de las utilidades lg: usadas en modo app (debe ir ANTES de las reglas de paneles) */
        .luxury-app-header { display: block; }
        .luxury-app-hide { display: none; }
        .luxury-app-pad { padding-bottom: 0; }

        /* Modo app móvil: SOLO dispositivos reales (html.luxury-is-mobile), nunca por ancho de ventana */
        html.luxury-is-mobile .luxury-app-header { display: none; }
        html.luxury-is-mobile .luxury-app-hide { display: block; }
        html.luxury-is-mobile .luxury-app-pad { padding-bottom: calc(5.5rem + env(safe-area-inset-bottom)); }
        html.luxury-is-mobile .tab-panel-alt { display: none; }
        html.luxury-is-mobile .tab-panel-alt.active { display: block; }
        /* Los paneles que son grids deben seguir siendo grid al activarse */
        html.luxury-is-mobile .tab-panel-alt.active.grid { display: grid; }
        html.luxury-is-mobile .tab-btn { -webkit-tap-highlight-color: transparent; }
        html.luxury-is-mobile #seccion-ventas, html.luxury-is-mobile #seccion-graficas { scroll-margin-top: 70px; }
    </style>
<body class="bg-gradient-to-br from-slate-50 to-gray-100 min-h-screen">

    <?php luxury_render_nav_start('dashboard'); ?>


    <!-- MAIN CONTENT - DASHBOARD LIMPIO -->
    <div class="p-0 luxury-app-pad">
            <header class="luxury-app-header sticky top-0 z-50 w-full bg-white/80 backdrop-blur-md border-b border-slate-200/60 shadow-sm px-4 py-3 rounded-xl">
                <div class="max-w-[1600px] mx-auto flex flex-col gap-4">

                    <div class="flex items-center justify-between">

                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 flex items-center justify-center rounded-2xl bg-gradient-to-tr from-emerald-500 to-emerald-400 shadow-lg shadow-emerald-200/50 group hover:rotate-6 transition-all">
                                <i class="fas fa-chart-line text-white text-base"></i>
                            </div>

                            <div class="hidden sm:block h-8 w-[1px] bg-slate-200"></div>

                            <div class="leading-tight">
                                <div class="flex items-center gap-2 mb-0.5">
                                    <i class="far fa-clock text-indigo-500 text-[10px]"></i>
                                    <span id="reloj" class="text-slate-700 text-xs font-black tracking-widest leading-none"></span>
                                </div>
                                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-tight">
                                    <i class="far fa-calendar-alt mr-1"></i>
                                    <?php
                                    $dias = ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"];
                                    $meses = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
                                    echo $dias[date('w')] . ", " . date('d') . " " . $meses[date('n') - 1];
                                    ?>
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">

                            <a href="../pedidos" class="flex items-center gap-2 px-4 py-2 bg-slate-900 text-white rounded-xl hover:bg-slate-800 transition-all shadow-md shadow-slate-200 group active:scale-95">
                                <i class="fas fa-list-ul text-[10px] group-hover:text-emerald-400 transition-colors"></i>
                                <span class="text-xs font-bold tracking-tight">Ver Pedidos</span>
                            </a>

                            <div class="h-8 w-[1px] bg-slate-200 mx-1"></div>

                            <div class="relative">
                                <button id="userMenuBtn" class="relative group active:scale-90 transition-transform">
                                    <img src="<?= $avatar_url ?>" class="w-10 h-10 rounded-2xl object-cover border-2 border-white shadow-md ring-1 ring-slate-200">
                                    <span class="absolute -top-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full shadow-sm animate-pulse"></span>
                                </button>

                                <!-- Dropdown Menu -->
                                <div id="userMenu" class="absolute right-0 top-12 w-48 bg-white rounded-xl shadow-lg border border-slate-200 py-2 z-50 hidden">
                                    <div class="px-4 py-2 border-b border-slate-100">
                                        <p class="text-sm font-bold text-slate-800"><?= htmlspecialchars($user_nombre) ?></p>
                                        <p class="text-xs text-slate-500">Administrador</p>
                                    </div>
                                    <a href="../perfil" class="flex items-center gap-3 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors">
                                        <i class="fas fa-user text-slate-400"></i>
                                        Perfil
                                    </a>
                                    <a href="../logout.php" class="flex items-center gap-3 px-4 py-2 text-sm text-rose-600 hover:bg-rose-50 transition-colors">
                                        <i class="fas fa-power-off text-rose-400"></i>
                                        Cerrar Sesión
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-4 pt-1">
                        <div class="flex-1 overflow-x-auto no-scrollbar">
                            <div class="flex items-center gap-2 min-w-max pb-1">

                                <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-100 border border-slate-200 mr-2">
                                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Total</span>
                                    <span class="bg-white px-2 py-0.5 rounded-lg text-xs font-black text-slate-800 shadow-sm border border-slate-200"><?= array_sum($pedidos_estados) ?></span>
                                </div>

                                <?php
                                $estados_config = [
                                    'pendiente'   => ['bg' => 'bg-amber-50', 'text' => 'text-amber-600', 'border' => 'border-amber-100', 'label' => 'Pendiente'],
                                    'confirmado'  => ['bg' => 'bg-blue-50', 'text' => 'text-blue-600', 'border' => 'border-blue-100', 'label' => 'Confirmado'],
                                    'preparando'  => ['bg' => 'bg-orange-50', 'text' => 'text-orange-600', 'border' => 'border-orange-100', 'label' => 'Empacando'],
                                    'listo'       => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'border' => 'border-emerald-100', 'label' => 'Listo'],
                                    'entregado'   => ['bg' => 'bg-slate-50', 'text' => 'text-slate-600', 'border' => 'border-slate-200', 'label' => 'Entregado'],
                                    'cancelado'   => ['bg' => 'bg-rose-50', 'text' => 'text-rose-600', 'border' => 'border-rose-100', 'label' => 'Cancelado'],
                                ];

                                foreach ($estados_config as $key => $conf):
                                    $count = $pedidos_estados[$key] ?? 0;
                                ?>
                                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl <?= $conf['bg'] ?> border <?= $conf['border'] ?> transition-transform hover:scale-105">
                                        <span class="text-[10px] font-black uppercase tracking-tighter <?= $conf['text'] ?>"><?= $conf['label'] ?></span>
                                        <span class="text-xs font-black <?= $conf['text'] ?>"><?= $count ?></span>
                                    </div>
                                <?php endforeach; ?>

                            </div>
                        </div>
                    </div>

                </div>
            </header>

            <!-- ========== PANEL PEDIDOS (tab móvil) ========== -->
            <div data-panel="pedidos" class="tab-panel-alt luxury-app-hide">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                            <i class="fas fa-route text-emerald-500 text-sm"></i> Pedidos por estado
                        </h3>
                        <span class="bg-emerald-100 text-emerald-700 text-xs font-black px-2.5 py-1 rounded-full flex-shrink-0">Total: <?= array_sum($pedidos_estados) ?></span>
                    </div>
                    <div class="space-y-1.5">
                        <?php
                        $total_estados_movil = array_sum($pedidos_estados);
                        $iconos_movil = [
                            'pendiente' => 'fa-hourglass-half',
                            'confirmado' => 'fa-check',
                            'preparando' => 'fa-boxes-packing',
                            'listo' => 'fa-box-open',
                            'entregado' => 'fa-truck-fast',
                            'cancelado' => 'fa-ban',
                        ];
                        foreach ($estados_config as $key => $conf):
                            $count = $pedidos_estados[$key] ?? 0;
                            $icono = $iconos_movil[$key] ?? 'fa-circle';
                            $pct = $total_estados_movil > 0 ? round($count / $total_estados_movil * 100) : 0;
                        ?>
                            <div class="flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl <?= $conf['bg'] ?> border <?= $conf['border'] ?>">
                                <span class="flex items-center gap-2.5 min-w-0">
                                    <i class="fas <?= $icono ?> <?= $conf['text'] ?> text-xs w-4 text-center flex-shrink-0"></i>
                                    <span class="text-xs font-bold <?= $conf['text'] ?> truncate"><?= $conf['label'] ?></span>
                                </span>
                                <span class="flex items-center gap-2 flex-shrink-0">
                                    <span class="text-sm font-black <?= $conf['text'] ?> tabular-nums"><?= $count ?></span>
                                    <span class="w-6 h-1 rounded-full bg-black/10 overflow-hidden">
                                        <span class="block h-full rounded-full bg-current <?= $conf['text'] ?>" style="width: <?= max($pct, 2) ?>%"></span>
                                    </span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="../pedidos" class="mt-4 w-full flex items-center justify-center gap-2 bg-slate-900 text-white rounded-xl px-4 py-2.5 text-xs font-bold active:scale-[0.99] transition hover:bg-slate-800">
                        <i class="fas fa-arrow-right text-emerald-400"></i> Ver todos los pedidos
                    </a>
                </div>
            </div>

            <!-- ========== FILA 1: KPIs PRINCIPALES ========== -->
            <div id="seccion-ventas" data-panel="resumen" class="tab-panel-alt grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6 mt-6">

                <!-- VENTAS HOY -->
                <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="metric-title">Ventas Hoy</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1"><?= format_currency($ventas_hoy) ?></p>

                            <div class="flex items-center gap-2 mt-2">
                                <span class="metric-title"><?= format_number($num_ventas_hoy) ?> ventas</span>

                                <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold
                        <?= $ventas_hoy >= $ventas_ayer ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-500' ?>">
                                    <i class="fas fa-<?= $ventas_hoy >= $ventas_ayer ? 'arrow-up' : 'arrow-down' ?>"></i>
                                    <?= abs(porcentaje_cambio($ventas_hoy, $ventas_ayer)) ?>%
                                </span>
                            </div>
                        </div>

                        <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-green-50">
                            <i class="fas fa-shopping-cart text-green-500"></i>
                        </div>
                    </div>

                    <div class="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-green-500 rounded-full"
                            style="width: <?= ($ventas_hoy / ($ventas_mes ?: 1)) * 100 ?>%">
                        </div>
                    </div>
                </div>

                <!-- VENTAS MES -->
                <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="metric-title">Ventas Mes</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1"><?= format_currency($ventas_mes) ?></p>
                            <p class="text-xs text-gray-400 mt-1">Total: <?= format_currency($ventas_total) ?></p>
                        </div>

                        <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-blue-50">
                            <i class="fas fa-calendar-alt text-blue-500"></i>
                        </div>
                    </div>

                    <div class="mt-3 flex justify-between items-center text-xs">
                        <span class="text-gray-400">vs anterior</span>
                        <span class="font-semibold <?= $ventas_mes >= $ventas_mes_anterior ? 'text-green-500' : 'text-red-500' ?>">
                            <?= porcentaje_cambio($ventas_mes, $ventas_mes_anterior) ?>%
                        </span>
                    </div>
                </div>

                <!-- GASTOS -->
                <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="metric-title">Gastos</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1"><?= format_currency($gastos_mes) ?></p>
                            <p class="text-xs text-gray-400 mt-1">Hoy: <?= format_currency($gastos_hoy) ?></p>
                        </div>

                        <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-red-50">
                            <i class="fas fa-arrow-down text-red-500"></i>
                        </div>
                    </div>

                    <div class="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-red-500 rounded-full"
                            style="width: <?= ($gastos_mes / ($ventas_mes ?: 1)) * 100 ?>%">
                        </div>
                    </div>
                </div>

                <!-- BALANCE -->
                <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-all relative overflow-hidden">

                    <!-- Glow decor -->
                    <div class="absolute -top-6 -right-6 w-20 h-20 bg-purple-100 rounded-full blur-2xl opacity-50"></div>

                    <div class="flex justify-between items-start relative">
                        <div>
                            <p class="metric-title">Balance</p>

                            <p class="text-2xl font-extrabold mt-1
                    <?= ($ventas_mes - $gastos_mes) >= 0 ? 'text-green-600' : 'text-red-500' ?>">
                                <?= format_currency($ventas_mes - $gastos_mes) ?>
                            </p>

                            <p class="text-xs text-gray-500 mt-1">
                                Margen: <?= $ventas_mes > 0 ? round(($ventas_mes - $gastos_mes) / $ventas_mes * 100, 1) : 0 ?>%
                            </p>
                        </div>

                        <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-purple-50">
                            <i class="fas fa-chart-line text-purple-500"></i>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t flex justify-between text-xs">
                        <span class="text-gray-500">
                            <i class="fas fa-wallet mr-1"></i>
                            <?= format_currency($saldo_caja) ?>
                        </span>

                        <span class="font-semibold <?= $caja_activa ? 'text-green-500' : 'text-red-500' ?>">
                            <i class="fas <?= $caja_activa ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                            <?= $caja_activa ? 'Activa' : 'Cerrada' ?>
                        </span>
                    </div>
                </div>

            </div>

            <!-- ========== FILA 2: MÉTRICAS SECUNDARIAS ========== -->
            <div data-panel="resumen" class="tab-panel-alt grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <div class="metric-card">
                    <p class="metric-title">Clientes</p>
                    <p class="metric-value"><?= format_number($total_clientes) ?></p>
                    <p class="text-xs text-emerald-500 mt-1"><i class="fas fa-user-plus"></i> +<?= format_number($clientes_nuevos_mes) ?></p>
                </div>
                <div class="metric-card">
                    <p class="metric-title">Productos</p>
                    <p class="metric-value"><?= format_number($total_productos) ?></p>
                    <p class="text-xs text-amber-500 mt-1">Stock: <?= format_number($valor_inventario) ?></p>
                </div>
                <div class="metric-card">
                    <p class="metric-title">Stock Bajo</p>
                    <p class="metric-value text-yellow-600"><?= format_number($productos_bajo_stock) ?></p>
                    <p class="text-xs text-slate-500 mt-1">Sin stock: <?= $productos_sin_stock ?></p>
                </div>
                <div class="metric-card">
                    <p class="metric-title">Ticket Promedio</p>
                    <p class="metric-value text-purple-600"><?= format_currency($ticket_promedio) ?></p>
                    <p class="text-xs text-slate-500 mt-1">Por venta</p>
                </div>
                <div class="metric-card">
                    <p class="metric-title">Ventas Hoy</p>
                    <p class="metric-value text-indigo-600"><?= format_number($num_ventas_hoy) ?></p>
                    <p class="text-xs text-slate-500 mt-1">Transacciones</p>
                </div>
                <div class="metric-card">
                    <p class="metric-title">Estado Caja</p>
                    <p class="metric-value <?= $caja_activa ? 'text-emerald-600' : 'text-red-500' ?>"><?= $caja_activa ? 'Abierta' : 'Cerrada' ?></p>
                    <p class="text-xs text-slate-500 mt-1"><?= $caja_activa ? 'Sesión activa' : 'Iniciar turno' ?></p>
                </div>
            </div>

            <!-- ========== FILA 3: GRÁFICOS PRINCIPALES ========== -->
            <div id="seccion-graficas" data-panel="graficas" class="tab-panel-alt grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <div class="glass-card rounded-2xl p-5 mb-5">
                        <div class="flex justify-between items-center">
                            <h3 class="font-semibold text-slate-700"><i class="fas fa-chart-line text-emerald-500 mr-2"></i>Ventas Últimos 7 Días</h3><span class="text-xs text-slate-400 bg-slate-100 px-2 py-1 rounded-full">Tendencia</span>
                        </div>
                        <div class="h-64"><canvas id="chartVentas7Dias"></canvas></div>
                    </div>
                    <div class="glass-card rounded-2xl p-5">
                        <div class="flex justify-between items-center">
                            <h3 class="font-semibold text-slate-700"><i class="fas fa-chart-bar text-blue-500 mr-2"></i>Ventas vs Gastos (7 días)</h3><span class="text-xs text-slate-400 bg-slate-100 px-2 py-1 rounded-full">Comparativa</span>
                        </div>
                        <div class="h-64"><canvas id="chartVsGastos"></canvas></div>
                    </div>
                </div>

                <div>
                    <div class="lg:col-span-2 glass-card rounded-2xl p-5 mb-5">
                        <h3 class="font-semibold text-slate-700"><i class="fas fa-chart-line text-purple-500 mr-2"></i>Evolución Mensual de Ventas</h3>
                        <div class="h-64"><canvas id="chartMensual"></canvas></div>
                    </div>
                    <div class="glass-card rounded-2xl p-5">
                        <h3 class="font-semibold text-slate-700"><i class="fas fa-clock text-amber-500 mr-2"></i>Horas con Más Ventas</h3>
                        <div class="h-64"><canvas id="chartHoras"></canvas></div>
                    </div>
                </div>
            </div>

            <!-- ========== FILA 8: PROVEEDORES Y GASTOS ========== -->
            <div data-panel="graficas" class="tab-panel-alt grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
                <div class="glass-card rounded-2xl p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-semibold text-slate-700"><i class="fas fa-truck text-cyan-500 mr-2"></i>Gestión de Proveedores</h3><a href="../proveedores" class="text-xs text-cyan-500 hover:underline">Gestionar</a>
                    </div>
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div class="text-center p-3 rounded-xl bg-slate-50">
                            <p class="text-2xl font-bold text-slate-800"><?= format_number($total_proveedores) ?></p>
                            <p class="text-xs text-slate-500">Total Proveedores</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-red-50">
                            <p class="text-2xl font-bold text-red-600"><?= format_currency($total_deuda) ?></p>
                            <p class="text-xs text-red-500">Deuda Total</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-yellow-50">
                            <p class="text-2xl font-bold text-yellow-600"><?= $proveedores_con_deuda ?></p>
                            <p class="text-xs text-yellow-500">Con Deuda</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-emerald-50">
                            <p class="text-lg font-bold text-emerald-600"><?= format_currency($total_compras_mes) ?></p>
                            <p class="text-xs text-emerald-500">Compras (Mes)</p>
                        </div>
                    </div>
                    <div class="pt-3 border-t">
                        <div class="flex justify-between text-sm"><span class="text-slate-500">Total Invertido:</span><span class="font-bold"><?= format_currency($total_invertido) ?></span></div>
                        <div class="flex justify-between text-sm mt-1"><span class="text-slate-500">Compras Registradas:</span><span class="font-bold"><?= format_number($total_compras) ?></span></div>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-semibold text-slate-700"><i class="fas fa-receipt text-amber-500 mr-2"></i>Control de Gastos</h3><a href="../gastos" class="text-xs text-amber-500 hover:underline">Ver todos</a>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="text-center p-3 rounded-xl bg-red-50">
                            <p class="text-xl font-bold text-red-600"><?= format_currency($gastos_hoy) ?></p>
                            <p class="text-xs text-red-500">Gastos Hoy</p>
                            <p class="text-xs text-slate-400"><?= porcentaje_cambio($gastos_hoy, 0) ?>% vs ayer</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-orange-50">
                            <p class="text-xl font-bold text-orange-600"><?= format_currency($gastos_semana) ?></p>
                            <p class="text-xs text-orange-500">Gastos Semana</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-amber-50">
                            <p class="text-xl font-bold text-amber-600"><?= format_currency($gastos_mes) ?></p>
                            <p class="text-xs text-amber-500">Gastos Mes</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-rose-50">
                            <p class="text-xl font-bold text-rose-600"><?= format_currency($gastos_ano) ?></p>
                            <p class="text-xs text-rose-500">Gastos Año</p>
                        </div>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-5">
                    <h3 class="font-semibold text-slate-700 mb-4"><i class="fas fa-boxes text-emerald-500 mr-2"></i>Control de Stock</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="text-center p-3 rounded-xl bg-slate-50">
                            <p class="text-2xl font-bold text-slate-800"><?= format_number($total_productos) ?></p>
                            <p class="text-xs text-slate-500">Total Productos</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-red-50">
                            <p class="text-2xl font-bold text-red-600"><?= format_number($productos_sin_stock) ?></p>
                            <p class="text-xs text-red-500">Sin Stock</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-yellow-50">
                            <p class="text-2xl font-bold text-yellow-600"><?= format_number($productos_bajo_stock) ?></p>
                            <p class="text-xs text-yellow-500">Stock Bajo</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-green-50">
                            <p class="text-2xl font-bold text-green-600"><?= format_number($productos_stock_normal) ?></p>
                            <p class="text-xs text-green-500">Stock Normal</p>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t">
                        <div class="flex justify-between text-sm"><span class="text-slate-500 text-bold">Valor del inventario:</span><span class="font-bold"><?= format_currency($valor_inventario) ?></span></div>
                    </div>
                    <div class="mt-2">
                        <div class="flex justify-between text-xs text-slate-500 mb-1"><span>Stock disponible</span><span><?= round(($productos_stock_normal / $total_productos) * 100) ?>%</span></div>
                        <div class="h-2 bg-slate-100 rounded-full">
                            <div class="h-full bg-emerald-500 rounded-full" style="width: <?= ($productos_stock_normal / $total_productos) * 100 ?>%"></div>
                        </div>
                    </div>
                </div>


            </div>


            <!-- ========== FILA 5: DEVOLUCIONES, TOP CLIENTES Y MÉTODOS DE PAGO ========== -->
            <div data-panel="graficas" class="tab-panel-alt grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
                <div class="glass-card rounded-2xl p-5">
                    <h3 class="font-semibold text-slate-700 mb-4"><i class="fas fa-undo text-rose-500 mr-2"></i>Devoluciones (Últimos 30 días)</h3>
                    <div class="h-40"><canvas id="chartDevoluciones"></canvas></div>
                    <div class="mt-3 space-y-1">
                        <div class="flex justify-between text-sm"><span>Total devoluciones:</span><span class="font-bold text-rose-600"><?= array_sum(array_column($devoluciones_data, 'cantidad')) ?></span></div>
                        <div class="flex justify-between text-sm"><span>Monto total:</span><span class="font-bold text-rose-600"><?= format_currency(array_sum(array_column($devoluciones_data, 'total'))) ?></span></div>
                    </div>
                </div>
                <div class="glass-card rounded-2xl p-5">
                    <h3 class="font-semibold text-slate-700 mb-4"><i class="fas fa-crown text-indigo-500 mr-2"></i>Top 5 Clientes</h3>
                    <div class="space-y-3">
                        <?php foreach ($top_clientes as $i => $c): $porc = ($c['total_gastado'] / ($top_clientes[0]['total_gastado'] ?? 1)) * 100; ?>
                            <div>
                                <div class="flex justify-between text-sm mb-1"><span><?= $i == 0 ? '👑' : ($i == 1 ? '⭐' : '🏆') ?> <?= htmlspecialchars($c['cliente_nombre']) ?></span><span class="font-bold text-indigo-600"><?= format_currency($c['total_gastado']) ?></span></div>
                                <div class="h-1.5 bg-slate-100 rounded-full">
                                    <div class="h-full bg-indigo-500 rounded-full" style="width: <?= $porc ?>%"></div>
                                </div>
                                <p class="text-xs text-slate-400 mt-0.5">📦 <?= format_number($c['compras']) ?> compras</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="glass-card rounded-2xl p-5">
                    <h3 class="font-semibold text-slate-700 mb-4"><i class="fas fa-credit-card text-emerald-500 mr-2"></i>Métodos de Pago</h3>
                    <div class="h-40"><canvas id="chartMetodosPago"></canvas></div>
                    <div class="mt-3 space-y-1"><?php foreach ($metodos_pago as $m): ?><div class="flex justify-between text-sm"><span><?= htmlspecialchars($m['metodo_pago'] ?? 'Otro') ?></span><span class="font-bold"><?= format_currency($m['total']) ?></span></div><?php endforeach; ?></div>
                </div>
            </div>


            <!-- ========== FILA 7: PEDIDOS Y FACTURAS ========== -->
            <div data-panel="ventas" class="tab-panel-alt">
                <div class="glass-card rounded-2xl p-5">
                    <h3 class="font-semibold text-slate-700 mb-4"><i class="fas fa-file-invoice-dollar text-indigo-500 mr-2"></i>Facturación</h3>
                    <div class="grid grid-cols-4 gap-3 mb-4">
                        <div class="text-center p-3 rounded-xl bg-slate-50">
                            <p class="text-2xl font-bold text-slate-800"><?= format_number($total_facturas) ?></p>
                            <p class="text-xs text-slate-500">Total Facturas</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-emerald-50">
                            <p class="text-2xl font-bold text-emerald-600"><?= format_number($num_ventas_hoy) ?></p>
                            <p class="text-xs text-emerald-500">Ventas Hoy</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-blue-50">
                            <p class="text-lg font-bold text-blue-600"><?= format_currency($ventas_mes) ?></p>
                            <p class="text-xs text-blue-500">Ventas del Mes</p>
                        </div>
                        <div class="text-center p-3 rounded-xl bg-purple-50">
                            <p class="text-lg font-bold text-purple-600"><?= format_currency($ticket_promedio) ?></p>
                            <p class="text-xs text-purple-500">Ticket Promedio</p>
                        </div>
                    </div>
                    <!-- Search and Pagination -->
                    <div class="mb-4 flex flex-col sm:flex-row gap-3 items-center justify-between">
                        <div class="flex items-center gap-2">
                            <input type="text" id="searchFacturas" placeholder="Buscar por cliente o factura..." 
                                   value="<?= htmlspecialchars($search) ?>" 
                                   class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <button onclick="buscarFacturas()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition-colors">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                        <div class="text-sm text-slate-600">
                            Mostrando <?= count($ultimas_ventas) ?> de <?= $total_records ?> facturas
                        </div>
                    </div>
                    <div class="overflow-x-auto max-h-64">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="p-2 text-left">Factura</th>
                                    <th class="p-2 text-left">Cliente</th>
                                    <th class="p-2 text-left">Fecha</th>
                                    <th class="p-2 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($ultimas_ventas as $v): ?><tr class="border-b hover:bg-slate-50">
                                        <td class="p-2 font-mono">#<?= str_pad($v['id'], 8, '0', STR_PAD_LEFT) ?></td>
                                        <td class="p-2"><?= htmlspecialchars($v['cliente_nombre']) ?></td>
                                        <td class="p-2 text-slate-600"><?= date('d/m/Y', strtotime($v['fecha_venta'])) ?></td>
                                        <td class="p-2 text-right font-bold text-emerald-600"><?= format_currency($v['total']) ?></td>
                                    </tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="mt-4 flex items-center justify-between">
                        <div class="text-sm text-slate-600">
                            Página <?= $page ?> de <?= $total_pages ?>
                        </div>
                        <div class="flex gap-1">
                            <?php if ($page > 1): ?>
                                <button onclick="cambiarPagina(<?= $page - 1 ?>)" class="px-3 py-1 bg-slate-200 text-slate-700 rounded text-sm hover:bg-slate-300">Anterior</button>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <button onclick="cambiarPagina(<?= $i ?>)" class="px-3 py-1 rounded text-sm <?= $i == $page ? 'bg-indigo-600 text-white' : 'bg-slate-200 text-slate-700 hover:bg-slate-300' ?>"><?= $i ?></button>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <button onclick="cambiarPagina(<?= $page + 1 ?>)" class="px-3 py-1 bg-slate-200 text-slate-700 rounded text-sm hover:bg-slate-300">Siguiente</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ========== BARRA NAVEGACIÓN INFERIOR (móvil) ========== -->
            <nav id="tabBarInferior" class="luxury-app-hide fixed bottom-0 inset-x-0 z-[90] bg-white/95 backdrop-blur-md border-t border-slate-200 shadow-[0_-4px_20px_rgba(0,0,0,0.06)]" style="padding-bottom: env(safe-area-inset-bottom)" aria-label="Navegación rápida">
                <div class="grid grid-cols-4 max-w-lg mx-auto">
                    <button data-tab="resumen" class="tab-btn flex flex-col items-center justify-center gap-1 pt-2.5 pb-2 text-slate-400 active:scale-95 transition">
                        <span class="text-lg leading-none"><i class="fas fa-gauge-high"></i></span>
                        <span class="text-[10px] font-bold">Resumen</span>
                        <span class="tab-dot w-1 h-1 rounded-full bg-current opacity-0 mt-0.5"></span>
                    </button>
                    <button data-tab="ventas" class="tab-btn flex flex-col items-center justify-center gap-1 pt-2.5 pb-2 text-slate-400 active:scale-95 transition">
                        <span class="text-lg leading-none"><i class="fas fa-chart-line"></i></span>
                        <span class="text-[10px] font-bold">Ventas</span>
                        <span class="tab-dot w-1 h-1 rounded-full bg-current opacity-0 mt-0.5"></span>
                    </button>
                    <button id="tabPedidos" class="tab-btn flex flex-col items-center justify-center gap-1 pt-2.5 pb-2 text-slate-400 active:scale-95 transition">
                        <span class="text-lg leading-none"><i class="fas fa-boxes-packing"></i></span>
                        <span class="text-[10px] font-bold">Pedidos</span>
                        <span class="tab-dot w-1 h-1 rounded-full bg-current opacity-0 mt-0.5"></span>
                    </button>
                    <button data-tab="graficas" class="tab-btn flex flex-col items-center justify-center gap-1 pt-2.5 pb-2 text-slate-400 active:scale-95 transition">
                        <span class="text-lg leading-none"><i class="fas fa-chart-column"></i></span>
                        <span class="text-[10px] font-bold">Gráficas</span>
                        <span class="tab-dot w-1 h-1 rounded-full bg-current opacity-0 mt-0.5"></span>
                    </button>
                </div>
            </nav>
    </div>

    <?php luxury_render_nav_end(); ?>

    <script>
        // Reloj
        function updateClock() {
            let el = document.getElementById('reloj');
            if (el) el.innerHTML = new Date().toLocaleTimeString('es-CO');
        }
        setInterval(updateClock, 1000);
        updateClock();


        // ===== BARRA INFERIOR: secciones por tab (móvil) =====
        function activarTab(nombre) {
            document.querySelectorAll('.tab-btn').forEach(b => {
                const activo = (b.dataset.tab === nombre) || (b.id === 'tabPedidos' && nombre === 'pedidos');
                b.classList.toggle('text-emerald-500', activo);
                b.classList.toggle('text-slate-400', !activo);
                const dot = b.querySelector('.tab-dot');
                if (dot) dot.classList.toggle('opacity-100', activo);
            });
            if (document.documentElement.classList.contains('luxury-is-mobile')) {
                document.querySelectorAll('[data-panel]').forEach(p => {
                    p.classList.toggle('active', p.getAttribute('data-panel') === nombre);
                });
                // Los gráficos creados dentro de paneles ocultos necesitan re-medirse
                setTimeout(() => {
                    try {
                        if (window.Chart && Chart.instances) {
                            Object.values(Chart.instances).forEach(c => c.resize());
                        }
                    } catch (e) { /* noop */ }
                }, 60);
            }
        }

        document.querySelectorAll('.tab-btn').forEach(b => {
            b.addEventListener('click', () => {
                const tab = b.id === 'tabPedidos' ? 'pedidos' : b.dataset.tab;
                if (tab) activarTab(tab);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        activarTab('resumen');

        // Gráficos
        new Chart(document.getElementById('chartVentas7Dias'), {
            type: 'line',
            data: {
                labels: <?= json_encode($labels_7dias) ?>,
                datasets: [{
                    label: 'Ventas ($)',
                    data: <?= json_encode($ventas_7dias) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
        new Chart(document.getElementById('chartVsGastos'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels_7dias) ?>,
                datasets: [{
                    label: 'Ventas',
                    data: <?= json_encode($ventas_7dias) ?>,
                    backgroundColor: '#10b981'
                }, {
                    label: 'Gastos',
                    data: <?= json_encode($gastos_7dias) ?>,
                    backgroundColor: '#ef4444'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
        new Chart(document.getElementById('chartMensual'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels_12meses) ?>,
                datasets: [{
                    label: 'Ventas ($)',
                    data: <?= json_encode($ventas_12meses) ?>,
                    backgroundColor: '#6366f1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
        new Chart(document.getElementById('chartHoras'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function ($h) {
                            return str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
                        }, range(0, 23))) ?>,
                datasets: [{
                    label: 'Ventas por hora',
                    data: <?= json_encode(array_values($ventas_por_hora)) ?>,
                    borderColor: '#f59e0b',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
        <?php if (count($metodos_pago) > 0): ?>
            new Chart(document.getElementById('chartMetodosPago'), {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_column($metodos_pago, 'metodo_pago')) ?>,
                    datasets: [{
                        data: <?= json_encode(array_column($metodos_pago, 'total')) ?>,
                        backgroundColor: ['#10b981', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%'
                }
            });
        <?php endif; ?>

        // Chart Devoluciones
        new Chart(document.getElementById('chartDevoluciones'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($devoluciones_data, 'fecha')) ?>,
                datasets: [{
                    label: 'Devoluciones ($)',
                    data: <?= json_encode(array_column($devoluciones_data, 'total')) ?>,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        // Funciones de búsqueda y paginación
        function buscarFacturas() {
            const search = document.getElementById('searchFacturas').value;
            const url = new URL(window.location);
            if (search) {
                url.searchParams.set('search', search);
            } else {
                url.searchParams.delete('search');
            }
            url.searchParams.delete('page'); // Reset to page 1
            window.location.href = url.toString();
        }

        function cambiarPagina(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        // Enter key en búsqueda
        document.getElementById('searchFacturas').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                buscarFacturas();
            }
        });

        // User Menu Dropdown
        const userMenuBtn = document.getElementById('userMenuBtn');
        const userMenu = document.getElementById('userMenu');

        if (userMenuBtn && userMenu) {
            userMenuBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                userMenu.classList.toggle('hidden');
            });

            // Close menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!userMenuBtn.contains(e.target) && !userMenu.contains(e.target)) {
                    userMenu.classList.add('hidden');
                }
            });
        }
    </script>
</body>

</html>
