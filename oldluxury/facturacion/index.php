<?php
// facturacion.php - SISTEMA COMPLETO DE FACTURACIÓN (PDF, WhatsApp, Historial) - SIN IVA
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('facturacion');

// ====================================================================
// DATOS DE LA EMPRESA
// ====================================================================
$empresa = [
    'nombre'    => 'LUXURY STORE S.A.S.',
    'nit'       => '901.234.567-8',
    'direccion' => 'Cra 6 #19-29, Santa Marta, Magdalena',
    'telefono'  => '+57 304 357 9108',
    'email'     => 'info@luxurystore.com',
    'web'       => 'www.luxurystore.com',
];

// ====================================================================
// FUNCIONES AUXILIARES
// ====================================================================
function formatearCOP($monto) {
    return '$ ' . number_format($monto, 0, ',', '.');
}

function metodoPagoLegible($metodo) {
    $metodo = is_string($metodo) ? trim($metodo) : (string)$metodo;
    return ($metodo === '' || $metodo === '0') ? 'Efectivo' : $metodo;
}

// ====================================================================
// HELPER: descripción dinámica desde movimientos_caja
// ====================================================================
function obtenerDescripcionVenta($conn, $venta_id, $venta_motivo = '') {
    $descripcion = '';
    $stmt = $conn->prepare("
        SELECT descripcion FROM movimientos_caja
        WHERE venta_id = ? AND tipo = 'venta'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $venta_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $descripcion = trim((string)($row['descripcion'] ?? ''));
        $stmt->close();
    }
    if ($descripcion === '' || $descripcion === 'Venta general') {
        $descripcion = trim((string)$venta_motivo) ?: 'Venta general';
    }
    return $descripcion;
}

// ====================================================================
// AJAX: OBTENER DATOS DE FACTURA (incluye descripcion_movimiento)
// ====================================================================
if (isset($_GET['action']) && $_GET['action'] == 'get_factura_data') {
    header('Content-Type: application/json');
    $venta_id = intval($_GET['id']);

    $stmt = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $venta = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$venta) {
        echo json_encode(['ok' => false, 'error' => 'Venta no encontrada']);
        exit;
    }

    $stmt = $conn->prepare("SELECT vd.*, p.descripcion FROM venta_detalles vd LEFT JOIN productos p ON vd.producto_id = p.id WHERE vd.venta_id = ?");
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ✅ Descripción dinámica incluida en JSON
    $descripcion_movimiento = obtenerDescripcionVenta($conn, $venta_id, $venta['motivo'] ?? '');

    echo json_encode([
        'ok'          => true,
        'venta'       => $venta,
        'productos'   => $productos,
        'descripcion' => $descripcion_movimiento,
    ]);
    exit;
}

// ====================================================================
// AJAX: SUBIR PDF AL SERVIDOR
// ====================================================================
if (isset($_GET['action']) && $_GET['action'] === 'upload_pdf') {
    header('Content-Type: application/json');
    $venta_id = intval($_POST['id'] ?? 0);
    if ($venta_id <= 0 || !isset($_FILES['pdf'])) {
        echo json_encode(['ok' => false, 'error' => 'Datos incompletos para subir PDF']);
        exit;
    }

    $upload_dir = dirname(__DIR__) . '/uploads/facturas';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo crear la carpeta de facturas']);
        exit;
    }

    // Validar que el archivo subido sea realmente un PDF
    $pdf_tmp = $_FILES['pdf']['tmp_name'];
    $pdf_fin = fopen($pdf_tmp, 'rb');
    $pdf_head = $pdf_fin ? fread($pdf_fin, 5) : '';
    if ($pdf_fin) fclose($pdf_fin);
    if ($pdf_head !== '%PDF-') {
        echo json_encode(['ok' => false, 'error' => 'Solo se permiten archivos PDF']);
        exit;
    }

    $filename    = 'factura_' . str_pad($venta_id, 8, '0', STR_PAD_LEFT) . '.pdf';
    $target_path = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $target_path)) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo PDF']);
        exit;
    }

    $base_url = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    $file_url = $base_url . '/uploads/facturas/' . rawurlencode($filename);
    echo json_encode(['ok' => true, 'url' => $file_url]);
    exit;
}

// ====================================================================
// GENERAR PDF HTML
// ====================================================================
if (isset($_GET['action']) && $_GET['action'] == 'generar_pdf') {
    $venta_id = intval($_GET['id']);

    $stmt = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $venta = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$venta) die("Venta no encontrada");

    $stmt = $conn->prepare("
        SELECT vd.*, p.descripcion
        FROM venta_detalles vd
        LEFT JOIN productos p ON vd.producto_id = p.id
        WHERE vd.venta_id = ?
    ");
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ✅ Descripción dinámica desde movimientos_caja
    $descripcion_movimiento = obtenerDescripcionVenta($conn, $venta_id, $venta['motivo'] ?? '');

    $detalles_extras = [];
    $stmt = $conn->prepare("SELECT concepto, monto FROM venta_extras WHERE venta_id = ? ORDER BY id ASC");
    if ($stmt) {
        $stmt->bind_param("i", $venta_id);
        $stmt->execute();
        $detalles_extras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $subtotal = 0;
    foreach ($productos as $p) {
        $lineSubtotal = (float)($p['subtotal'] ?? 0);
        if ($lineSubtotal <= 0) $lineSubtotal = (float)$p['precio_unitario'] * (int)$p['cantidad'];
        $subtotal += $lineSubtotal;
    }
    $total = $subtotal;
    if ($total <= 0 && isset($venta['total'])) {
        $total = $subtotal = (float)$venta['total'];
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"><title>Factura #<?= str_pad($venta['id'], 8, '0', STR_PAD_LEFT) ?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',Arial,sans-serif;background:#0a0a0a;padding:40px 20px;display:flex;justify-content:center}
        .factura{max-width:500px;margin:0 auto;background:#000000;border-radius:24px;overflow:hidden;box-shadow:0 20px 40px -12px rgba(0,0,0,0.5);border:1px solid rgba(212,175,55,0.3)}
        .header{background:linear-gradient(135deg,#000000,#1a1a1a);color:white;padding:30px;text-align:center;border-bottom:2px solid #D4AF37}
        .logo-container{display:flex;align-items:center;justify-content:center;gap:15px;margin-bottom:8px}
        .logo-line{width:40px;height:2px;background:linear-gradient(90deg,transparent,#D4AF37,transparent)}
        .logo{font-size:28px;font-weight:800}
        .logo span{color:#D4AF37}
        .factura-tipo{color:#D4AF37;font-size:12px;margin-top:8px}
        .datos-factura{display:grid;grid-template-columns:1fr 1fr;gap:15px;padding:20px;background:#000000}
        .dato-label{font-size:10px;color:#D4AF37;text-transform:uppercase;letter-spacing:0.5px}
        .dato-value{font-weight:600;font-size:13px;color:#ffffff}
        table{width:100%;border-collapse:collapse}
        th{background:#0a0a0a;padding:12px;text-align:left;font-size:11px;color:#D4AF37;border-bottom:1px solid #D4AF37}
        td{padding:12px;font-size:12px;border-bottom:1px solid #1a1a1a;color:#ffffff}
        .totales{background:#0a0a0a;margin:20px;padding:20px;border-radius:16px;border:1px solid rgba(212,175,55,0.2)}
        .total-row td{font-size:20px;font-weight:800;color:#D4AF37;border-top:2px solid #D4AF37;padding-top:12px}
        .footer{background:#0a0a0a;color:#666;padding:20px;text-align:center;font-size:10px;border-top:1px solid #1a1a1a}
        .thanks{color:#D4AF37;font-weight:600;margin-bottom:10px;font-size:12px}
    </style>
    </head>
    <body>
        <div class="factura">
            <div class="header">
                <div class="logo-container">
                    <div class="logo-line"></div>
                    <div class="logo">LUXURY <span>PREMIUM</span></div>
                    <div class="logo-line"></div>
                </div>
                <div class="factura-tipo">FACTURA ELECTRÓNICA</div>
            </div>
            <div class="datos-factura">
                <div><div class="dato-label">FACTURA N°</div><div class="dato-value"><?= str_pad($venta['id'], 8, '0', STR_PAD_LEFT) ?></div></div>
                <div><div class="dato-label">FECHA</div><div class="dato-value"><?= date('d/m/Y H:i:s', strtotime($venta['fecha_venta'])) ?></div></div>
                <div><div class="dato-label">CLIENTE</div><div class="dato-value"><?= htmlspecialchars($venta['cliente_nombre']) ?></div></div>
                <div><div class="dato-label">MÉTODO</div><div class="dato-value"><?= htmlspecialchars(metodoPagoLegible($venta['metodo_pago'] ?? 'Efectivo')) ?></div></div>
                <div style="grid-column:span 2">
                    <div class="dato-label">DESCRIPCIÓN</div>
                    <div class="dato-value"><?= htmlspecialchars($descripcion_movimiento) ?></div>
                </div>
            </div>
            <?php if (!empty($detalles_extras)): ?>
            <div style="padding:20px;background:#000000;border-top:1px solid rgba(212,175,55,0.2)">
                <div style="color:#D4AF37;font-size:13px;font-weight:600;margin-bottom:12px;text-transform:uppercase">DETALLES DE LA VENTA</div>
                <table>
                    <tbody>
                        <?php foreach($detalles_extras as $det): ?>
                        <tr style="border-bottom:1px solid #1a1a1a">
                            <td style="padding:8px 12px;font-size:11px;color:#ffffff"><?= htmlspecialchars($det['concepto']) ?></td>
                            <td style="padding:8px 12px;text-align:right;font-size:11px;color:#D4AF37"><?= formatearCOP($det['monto']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <div class="totales">
                <table>
                    <tr><td style="color:#ffffff">SUBTOTAL</td><td style="text-align:right;color:#ffffff"><?= formatearCOP($subtotal) ?></td></tr>
                    <tr class="total-row"><td style="color:#D4AF37;font-weight:800">TOTAL</td><td style="text-align:right;color:#D4AF37;font-weight:800"><?= formatearCOP($total) ?></td></tr>
                </table>
            </div>
            <div class="footer">
                <p class="thanks">✨ ¡GRACIAS POR SU COMPRA! ✨</p>
                <p>Factura generada por LUXURY PREMIUM</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ====================================================================
// CONSULTAS PARA LA VISTA
// ====================================================================
$limit  = 10;
$pagina = isset($_GET['p']) ? intval($_GET['p']) : 1;
$offset = ($pagina - 1) * $limit;

$q            = trim($_GET['q'] ?? '');
$fecha_inicio = trim($_GET['fecha_inicio'] ?? '');
$fecha_fin    = trim($_GET['fecha_fin'] ?? '');

$ventas_cols = [];
$cols_res = $conn->query("SHOW COLUMNS FROM ventas");
if ($cols_res) {
    while ($c = $cols_res->fetch_assoc()) $ventas_cols[] = $c['Field'];
}
$has_metodo_pago = in_array('metodo_pago', $ventas_cols, true);

$where = "1=1";
if ($fecha_inicio !== '' && $fecha_fin !== '') {
    $fi = $conn->real_escape_string($fecha_inicio . ' 00:00:00');
    $ff = $conn->real_escape_string($fecha_fin . ' 23:59:59');
    $where .= " AND v.fecha_venta BETWEEN '$fi' AND '$ff'";
} elseif ($fecha_inicio !== '') {
    $fi = $conn->real_escape_string($fecha_inicio . ' 00:00:00');
    $where .= " AND v.fecha_venta >= '$fi'";
} elseif ($fecha_fin !== '') {
    $ff = $conn->real_escape_string($fecha_fin . ' 23:59:59');
    $where .= " AND v.fecha_venta <= '$ff'";
}

if ($q !== '') {
    $q_esc = $conn->real_escape_string($q);
    $like  = "%" . $q_esc . "%";
    $cond  = [];
    if (is_numeric($q)) $cond[] = "v.id = " . intval($q);
    $cond[] = "v.cliente_nombre LIKE '$like'";
    if ($has_metodo_pago) $cond[] = "v.metodo_pago LIKE '$like'";
    $cond[] = "mc.descripcion LIKE '$like'";
    $where .= " AND (" . implode(" OR ", $cond) . ")";
}

$total_facturas = $conn->query("
    SELECT COUNT(DISTINCT v.id) as total
    FROM ventas v
    LEFT JOIN movimientos_caja mc ON mc.venta_id = v.id AND mc.tipo = 'venta'
    WHERE $where
")->fetch_assoc()['total'];

$paginas_totales = max(1, ceil($total_facturas / $limit));

$facturas = $conn->query("
    SELECT v.*, mc.descripcion AS mc_descripcion
    FROM ventas v
    LEFT JOIN movimientos_caja mc ON mc.venta_id = v.id AND mc.tipo = 'venta'
    WHERE $where
    GROUP BY v.id
    ORDER BY v.fecha_venta DESC
    LIMIT $limit OFFSET $offset
");

$hoy         = date('Y-m-d');
$totales_hoy = $conn->query("SELECT COALESCE(SUM(total),0) as total_hoy, COUNT(*) as cantidad_hoy FROM ventas WHERE DATE(fecha_venta)='$hoy' AND estado='Completada'")->fetch_assoc();
$total_mes   = $conn->query("SELECT COALESCE(SUM(total),0) as total_mes FROM ventas WHERE MONTH(fecha_venta)=MONTH(CURDATE()) AND YEAR(fecha_venta)=YEAR(CURDATE()) AND estado='Completada'")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury POS | Facturación Electrónica</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <script src="../assets/vendor/fontawesome/js/all.min.js"></script>
    <script src="../assets/vendor/html2pdf/html2pdf.bundle.min.js"></script>
    <style>
        @import url('../assets/vendor/inter/inter.css');
        * { font-family: 'Inter', sans-serif; }
        .toast { position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 12px 24px; border-radius: 12px; z-index: 1000; animation: slideIn 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .toast.error { background: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 32px; max-width: 700px; width: 92%; max-height: 85vh; overflow-y: auto; padding: 32px; animation: modalFade 0.2s ease; }
        #factura-iframe { width: 100%; height: 520px; border: 0; background: #0a0a0a; border-radius: 16px; }
        @keyframes modalFade { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .factura-row { transition: all 0.2s; }
        .factura-row:hover { background-color: #f8fafc; transform: translateX(2px); }
        .stats-card { transition: all 0.3s; }
        .stats-card:hover { transform: translateY(-3px); box-shadow: 0 12px 25px -8px rgba(0,0,0,0.15); }
        .desc-badge {
            display: inline-block; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;
            border-radius: 6px; padding: 2px 8px; font-size: 11px; font-weight: 600; vertical-align: middle;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 to-gray-100 min-h-screen">
<?php luxury_render_nav_start('facturacion'); ?>

<div class="">

    <div class="flex justify-between items-end mb-8 flex-wrap gap-4">
        <div>
            <h1 class="text-4xl font-black text-slate-800 tracking-tighter flex items-center gap-3">
                <i class="fas fa-file-invoice text-emerald-500"></i> Facturación
            </h1>
            <p class="text-slate-400 font-bold text-xs mt-1">Gestión de facturas y documentos electrónicos</p>
        </div>
        <a href="../caja/" class="bg-emerald-500 hover:bg-emerald-600 text-white px-6 py-3 rounded-2xl font-black text-sm shadow-lg transition-all flex items-center gap-2">
            <i class="fas fa-cash-register"></i> NUEVA VENTA
        </a>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-8">
        <div class="stats-card bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-chart-line mr-1"></i> Total Facturas</p>
            <p class="text-3xl font-bold"><?= number_format($total_facturas) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-calendar-day mr-1"></i> Ventas Hoy</p>
            <p class="text-3xl font-bold"><?= number_format($totales_hoy['cantidad_hoy']) ?></p>
            <p class="text-xs opacity-70 mt-1">Total: <?= formatearCOP($totales_hoy['total_hoy']) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-calendar-alt mr-1"></i> Ventas del Mes</p>
            <p class="text-3xl font-bold"><?= formatearCOP($total_mes['total_mes']) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-dollar-sign mr-1"></i> Ticket Promedio</p>
            <p class="text-3xl font-bold"><?= $total_facturas > 0 ? formatearCOP($total_mes['total_mes'] / $total_facturas) : formatearCOP(0) ?></p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white rounded-3xl shadow-xl border border-slate-100 p-5 mb-8">
        <form method="GET" class="grid grid-cols-1 lg:grid-cols-4 gap-4 items-end">
            <div class="lg:col-span-2">
                <label class="text-xs font-black uppercase tracking-widest text-slate-400">Buscar</label>
                <div class="relative mt-2">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Factura, cliente, método o descripción"
                           class="w-full pl-11 pr-4 py-3 bg-slate-50 border-2 border-slate-200 rounded-2xl focus:border-emerald-500 outline-none transition-all">
                </div>
            </div>
            <div>
                <label class="text-xs font-black uppercase tracking-widest text-slate-400">Desde</label>
                <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>"
                       class="mt-2 w-full px-3 py-3 bg-slate-50 border-2 border-slate-200 rounded-2xl focus:border-emerald-500 outline-none transition-all">
            </div>
            <div>
                <label class="text-xs font-black uppercase tracking-widest text-slate-400">Hasta</label>
                <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>"
                       class="mt-2 w-full px-3 py-3 bg-slate-50 border-2 border-slate-200 rounded-2xl focus:border-emerald-500 outline-none transition-all">
            </div>
            <div class="lg:col-span-4 flex flex-wrap gap-3">
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-3 rounded-2xl font-black text-sm transition-all flex items-center gap-2">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <a href="./" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-6 py-3 rounded-2xl font-black text-sm transition-all flex items-center gap-2">
                    <i class="fas fa-rotate-right"></i> Limpiar
                </a>
            </div>
        </form>
    </div>

    <!-- Tabla -->
    <div class="bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden">
        <div class="bg-gradient-to-r from-gray-800 to-gray-900 text-white p-5 flex justify-between items-center">
            <div>
                <h2 class="text-lg font-bold flex items-center gap-2"><i class="fas fa-receipt"></i> Historial de Facturas</h2>
                <p class="text-xs opacity-70">Documentos electrónicos generados</p>
            </div>
            <div class="bg-white/20 px-3 py-1 rounded-full text-xs font-bold">
                Página <?= $pagina ?> de <?= $paginas_totales ?>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b">
                    <tr>
                        <th class="p-5 text-left">N° Factura</th>
                        <th class="p-5 text-left">Fecha</th>
                        <th class="p-5 text-left">Cliente</th>
                        <th class="p-5 text-left">Descripción</th>
                        <th class="p-5 text-right">Total</th>
                        <th class="p-5 text-center">Método</th>
                        <th class="p-5 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if($facturas && $facturas->num_rows > 0): ?>
                        <?php while($f = $facturas->fetch_assoc()):
                            $desc_tabla = trim((string)($f['mc_descripcion'] ?? ''));
                            if ($desc_tabla === '' || $desc_tabla === 'Venta general') {
                                $desc_tabla = trim((string)($f['motivo'] ?? '')) ?: 'Venta general';
                            }
                        ?>
                        <tr class="factura-row transition-colors">
                            <td class="p-5 font-mono font-bold text-slate-700"><?= str_pad($f['id'], 8, '0', STR_PAD_LEFT) ?></td>
                            <td class="p-5 text-sm text-slate-600"><?= date('d/m/Y H:i', strtotime($f['fecha_venta'])) ?></td>
                            <td class="p-5 text-sm font-medium text-slate-700 max-w-[180px] truncate"><?= htmlspecialchars($f['cliente_nombre']) ?></td>
                            <td class="p-5">
                                <span class="desc-badge" title="<?= htmlspecialchars($desc_tabla) ?>">
                                    <?= htmlspecialchars($desc_tabla) ?>
                                </span>
                            </td>
                            <td class="p-5 text-right font-black text-emerald-600"><?= formatearCOP($f['total']) ?></td>
                            <td class="p-5 text-center text-sm">
                                <span class="px-2 py-1 font-bold rounded-full text-xs bg-slate-100"><?= htmlspecialchars($f['metodo_pago'] ?? 'Efectivo') ?></span>
                            </td>
                            <td class="p-5 text-center space-x-1">
                                <button onclick="verFactura(<?= $f['id'] ?>)" class="text-blue-500 hover:text-blue-700 p-2 rounded-lg hover:bg-blue-50 transition" title="Ver Factura">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="generarPDF(<?= $f['id'] ?>)" class="text-red-500 hover:text-red-700 p-2 rounded-lg hover:bg-red-50 transition" title="Descargar PDF">
                                    <i class="fas fa-file-pdf"></i>
                                </button>
                                <button onclick="enviarWhatsApp(<?= $f['id'] ?>)" class="text-green-500 hover:text-green-700 p-2 rounded-lg hover:bg-green-50 transition" title="Enviar por WhatsApp">
                                    <i class="fab fa-whatsapp"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="p-12 text-center text-slate-400">
                                <i class="fas fa-receipt text-5xl mb-3 opacity-30 block"></i>
                                <p>No hay facturas registradas</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if($paginas_totales > 1): ?>
        <div class="p-6 bg-slate-50 flex justify-center gap-2 flex-wrap border-t border-slate-100">
            <?php if($pagina > 1): ?>
                <a href="?<?= http_build_query(['p'=>$pagina-1,'q'=>$q,'fecha_inicio'=>$fecha_inicio,'fecha_fin'=>$fecha_fin]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-sm bg-white text-slate-600 hover:bg-slate-100 transition shadow-sm">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php endif; ?>
            <?php for($i=max(1,$pagina-2); $i<=min($paginas_totales,$pagina+2); $i++): ?>
                <a href="?<?= http_build_query(['p'=>$i,'q'=>$q,'fecha_inicio'=>$fecha_inicio,'fecha_fin'=>$fecha_fin]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-sm transition-all <?= $i==$pagina?'bg-slate-900 text-white shadow-md':'bg-white text-slate-400 hover:bg-slate-100' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            <?php if($pagina < $paginas_totales): ?>
                <a href="?<?= http_build_query(['p'=>$pagina+1,'q'=>$q,'fecha_inicio'=>$fecha_inicio,'fecha_fin'=>$fecha_fin]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-sm bg-white text-slate-600 hover:bg-slate-100 transition shadow-sm">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL VER FACTURA -->
<div id="modal-factura" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-2xl font-black text-slate-800 flex items-center gap-2">
                <i class="fas fa-file-invoice text-emerald-500"></i> Factura Electrónica
            </h3>
            <button onclick="cerrarModalFactura()" class="text-slate-400 hover:text-red-500 text-3xl">&times;</button>
        </div>
        <div id="factura-preview" class="max-h-[500px] overflow-y-auto">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-2xl text-emerald-500"></i>
                <p class="mt-2 text-slate-500">Cargando factura...</p>
            </div>
        </div>
        <div class="flex gap-3 mt-6">
            <button id="btn-descargar-pdf" class="flex-1 bg-red-500 hover:bg-red-600 text-white py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-2">
                <i class="fas fa-file-pdf"></i> Descargar PDF
            </button>
            <button id="btn-compartir-wa" class="flex-1 bg-green-500 hover:bg-green-600 text-white py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-2">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </button>
            <button onclick="cerrarModalFactura()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-500 py-3 rounded-xl font-bold transition-all">
                CERRAR
            </button>
        </div>
    </div>
</div>

<script>
let facturaActualId = null;

function mostrarToast(msg, error = false) {
    const t = document.createElement('div');
    t.className = 'toast' + (error ? ' error' : '');
    t.innerHTML = `<i class="fas ${error ? 'fa-exclamation-triangle' : 'fa-check-circle'} mr-2"></i> ${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

function formatearCOP(valor) {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(valor || 0);
}

// ====================================================================
// VER FACTURA EN MODAL
// ====================================================================
function verFactura(ventaId) {
    facturaActualId = ventaId;
    const modal   = document.getElementById('modal-factura');
    const preview = document.getElementById('factura-preview');
    modal.classList.add('active');
    preview.innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-emerald-500"></i><p class="mt-2">Cargando...</p></div>';
    const mc = document.querySelector('#modal-factura .modal-content');
    if (mc) mc.style.background = '#0a0a0a';
    fetch(`?action=generar_pdf&id=${ventaId}`)
        .then(r => r.text())
        .then(html => {
            preview.innerHTML = '<iframe id="factura-iframe" title="Factura"></iframe>';
            const frame = document.getElementById('factura-iframe');
            if (frame) frame.srcdoc = html;
        })
        .catch(() => {
            preview.innerHTML = '<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl"></i><p>Error al cargar</p></div>';
        });
    document.getElementById('btn-descargar-pdf').onclick = () => generarPDF(ventaId);
    document.getElementById('btn-compartir-wa').onclick  = () => enviarWhatsApp(ventaId);
}

function cerrarModalFactura() {
    document.getElementById('modal-factura').classList.remove('active');
    facturaActualId = null;
}

// ====================================================================
// CONSTRUIR PDF BLOB (reutilizable)
// ====================================================================
async function buildFacturaPdfBlob(ventaId) {
    const html = await fetch(`?action=generar_pdf&id=${ventaId}`).then(r => r.text());
    const div  = document.createElement('div');
    div.innerHTML = html;

    // Intentar usar el iframe si está abierto (mejor calidad)
    const preview = document.getElementById('factura-preview');
    const iframe  = preview ? preview.querySelector('#factura-iframe') : null;
    let facturaEl, stylesText = '';

    if (iframe && iframe.contentDocument && iframe.contentDocument.querySelector('.factura')) {
        facturaEl  = iframe.contentDocument.querySelector('.factura');
        stylesText = Array.from(iframe.contentDocument.querySelectorAll('style')).map(s => s.textContent || '').join('\n');
    } else {
        facturaEl  = div.querySelector('.factura') || div;
        stylesText = Array.from(div.querySelectorAll('style')).map(s => s.textContent || '').join('\n');
    }

    const container = document.createElement('div');
    if (stylesText) {
        const st = document.createElement('style');
        st.textContent = stylesText;
        container.appendChild(st);
    }
    const clone = facturaEl.cloneNode(true);
    clone.style.margin = '0'; clone.style.width = '100%'; clone.style.maxWidth = 'none';
    container.appendChild(clone);

    const width  = Math.max(clone.scrollWidth || clone.offsetWidth || 500, 1);
    const height = Math.max(clone.scrollHeight || clone.offsetHeight || 700, 1);
    const opt = {
        margin: 0,
        filename: `factura_${String(ventaId).padStart(8,'0')}.pdf`,
        image: { type: 'jpeg', quality: 1 },
        html2canvas: { scale: 1, letterRendering: true, backgroundColor: '#000000', useCORS: true, logging: false, scrollX: 0, scrollY: 0 },
        jsPDF: { unit: 'px', format: [width, height], orientation: 'portrait' }
    };
    return html2pdf().set(opt).from(container).outputPdf('blob');
}

// ====================================================================
// GENERAR PDF (descarga directa)
// ====================================================================
async function generarPDF(ventaId) {
    mostrarToast('Generando PDF...');
    try {
        const blob = await buildFacturaPdfBlob(ventaId);
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = `factura_${String(ventaId).padStart(8,'0')}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
        mostrarToast('PDF descargado correctamente');
    } catch(e) {
        console.error(e);
        mostrarToast('Error al generar PDF', true);
    }
}

// ====================================================================
// ENVIAR WHATSAPP
// - Abre WhatsApp con el mensaje
// - Descarga el PDF en el dispositivo en segundo plano
// ====================================================================
async function enviarWhatsApp(ventaId) {
    const telefono = prompt('Numero WhatsApp con codigo de pais (ej: 573001234567):', '57');
    if (!telefono || telefono.trim() === '' || telefono.trim() === '57') {
        mostrarToast('Numero cancelado o invalido', true);
        return;
    }

    mostrarToast('Preparando mensaje...');

    try {
        // 1. Obtener datos de la venta (descripcion viene del backend via movimientos_caja)
        const r    = await fetch(`?action=get_factura_data&id=${ventaId}`);
        const data = await r.json();
        if (!data.ok) throw new Error('No se pudieron obtener los datos de la venta');

        const total = data.productos.reduce((s, p) => {
            return s + (Number(p.subtotal) > 0 ? Number(p.subtotal) : Number(p.precio_unitario) * Number(p.cantidad));
        }, 0) || Number(data.venta.total) || 0;

        const fecha = new Date(data.venta.fecha_venta).toLocaleString('es-CO', {
            day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });

        // ✅ Descripción dinámica desde movimientos_caja (ya resuelta en el backend)
        const descripcion = data.descripcion || data.venta.motivo || 'Venta general';

        // 2. Mensaje limpio sin emojis
        const mensaje =
`*LUXURY PREMIUM STORE*
━━━━━━━━━━━━━━━━━━━━
*FACTURA ELECTRONICA*
━━━━━━━━━━━━━━━━━━━━
*N Factura:* ${String(data.venta.id).padStart(8, '0')}
*Fecha:* ${fecha}
*Cliente:* ${data.venta.cliente_nombre}
*Pago:* ${data.venta.metodo_pago || 'Efectivo'}
*Descripcion:* ${descripcion}
━━━━━━━━━━━━━━━━━━━━
*TOTAL: ${formatearCOP(total)}*
━━━━━━━━━━━━━━━━━━━━
Gracias por su compra en LUXURY PREMIUM STORE
${<?= json_encode($empresa['direccion']) ?>}
${<?= json_encode($empresa['telefono']) ?>}`;

        // 3. Abrir WhatsApp
        window.open(`https://wa.me/${telefono.trim()}?text=${encodeURIComponent(mensaje)}`, '_blank');
        mostrarToast('WhatsApp abierto correctamente');

        // 4. Descargar PDF en segundo plano
        buildFacturaPdfBlob(ventaId).then(blob => {
            const url = URL.createObjectURL(blob);
            const a   = document.createElement('a');
            a.href    = url;
            a.download = `factura_${String(ventaId).padStart(8,'0')}.pdf`;
            a.click();
            URL.revokeObjectURL(url);
            mostrarToast('PDF descargado tambien en tu dispositivo');
        }).catch(() => {});

    } catch (error) {
        console.error('Error WhatsApp:', error);
        mostrarToast('Error al preparar el mensaje: ' + error.message, true);
    }
}

window.onclick = function(e) {
    const modal = document.getElementById('modal-factura');
    if (e.target === modal) cerrarModalFactura();
};
</script>

<?php luxury_render_nav_end(); ?>
</body>
</html>