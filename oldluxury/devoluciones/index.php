<?php
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('devoluciones');

$usuario_id = $_SESSION['user_id'] ?? 1;

function fmtCOPDev($monto) {
    return '$ ' . number_format((float)$monto, 0, ',', '.');
}

function obtenerCajaActivaDev($conn) {
    $stmt = $conn->prepare("SELECT * FROM cajas WHERE estado='abierta' ORDER BY fecha_apertura DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function obtenerDevolucionDev($conn, $devolucion_id) {
    $stmt = $conn->prepare("
        SELECT d.*, v.metodo_pago, v.fecha_venta
        FROM devoluciones d
        LEFT JOIN ventas v ON v.id = d.venta_id
        WHERE d.id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $devolucion_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function obtenerDetallesDevolucionDev($conn, $devolucion_id) {
    $stmt = $conn->prepare("
        SELECT *
        FROM devolucion_detalles
        WHERE devolucion_id = ?
        ORDER BY id ASC
    ");
    if (!$stmt) return [];
    $stmt->bind_param("i", $devolucion_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function columnasTablaDev($conn, $tabla) {
    static $cache = [];
    if (isset($cache[$tabla])) return $cache[$tabla];
    $cols = [];
    $tabla_segura = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $res = $conn->query("SHOW COLUMNS FROM `$tabla_segura`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    $cache[$tabla] = $cols;
    return $cols;
}

function tablaExisteDev($conn, $tabla) {
    $tabla_segura = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    if ($stmt) {
        $stmt->bind_param("s", $tabla_segura);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($res['total'] ?? 0) > 0) {
            return true;
        }
    }

    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tabla_segura) . "'");
    if ($res && $res->num_rows > 0) {
        return true;
    }

    return !empty(columnasTablaDev($conn, $tabla_segura));
}

$mensaje = '';
$error = '';
$venta_id = isset($_GET['venta_id']) ? intval($_GET['venta_id']) : 0;
$devolucion_id = isset($_GET['devolucion_id']) ? intval($_GET['devolucion_id']) : 0;

if (!tablaExisteDev($conn, 'devoluciones') || !tablaExisteDev($conn, 'devolucion_detalles')) {
    $error = 'Faltan las tablas de devoluciones en la base de datos.';
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_devolucion'])) {
    $venta_id_post = intval($_POST['venta_id'] ?? 0);
    $motivo = trim($_POST['motivo'] ?? '');
    $cantidades = $_POST['devolver'] ?? [];
    $manual_total = isset($_POST['manual_total']) && intval($_POST['manual_total']) === 1;

    if ($venta_id_post <= 0) {
        $error = 'Venta inválida.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
        $stmt->bind_param("i", $venta_id_post);
        $stmt->execute();
        $venta = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$venta) {
            $error = 'La venta no existe.';
        } else {
            $stmt = $conn->prepare("
                SELECT vd.*,
                COALESCE((
                    SELECT SUM(dd.cantidad)
                    FROM devolucion_detalles dd
                    INNER JOIN devoluciones d ON d.id = dd.devolucion_id
                    WHERE dd.venta_detalle_id = vd.id
                ), 0) AS cantidad_devuelta
                FROM venta_detalles vd
                WHERE vd.venta_id = ?
            ");
            $stmt->bind_param("i", $venta_id_post);
            $stmt->execute();
            $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $items_devolver = [];
            $total_devuelto = 0;
            $devolucion_manual = false;

            foreach ($detalles as $detalle) {
                $detalle_id = (int)$detalle['id'];
                $cantidad_solicitada = isset($cantidades[$detalle_id]) ? intval($cantidades[$detalle_id]) : 0;
                $cantidad_disponible = max(0, (int)$detalle['cantidad'] - (int)$detalle['cantidad_devuelta']);

                if ($cantidad_solicitada < 0 || $cantidad_solicitada > $cantidad_disponible) {
                    $error = 'La cantidad a devolver excede lo disponible en uno de los productos.';
                    break;
                }

                if ($cantidad_solicitada > 0) {
                    $subtotal = $cantidad_solicitada * (float)$detalle['precio_unitario'];
                    $items_devolver[] = [
                        'venta_detalle_id' => $detalle_id,
                        'producto_id' => (int)$detalle['producto_id'],
                        'producto_nombre' => $detalle['producto_nombre'],
                        'cantidad' => $cantidad_solicitada,
                        'precio_unitario' => (float)$detalle['precio_unitario'],
                        'subtotal' => $subtotal,
                    ];
                    $total_devuelto += $subtotal;
                }
            }

            if (!$error && empty($items_devolver)) {
                if ($manual_total || empty($detalles)) {
                    $devolucion_manual = true;
                    $total_devuelto = (float)($venta['total'] ?? 0);
                    if ($total_devuelto <= 0) {
                        $error = 'No hay total válido para devolver.';
                    }
                } else {
                    $error = 'Debes indicar al menos un producto para devolver.';
                }
            }

            if (!$error) {
                $stmt = $conn->prepare(
                    "SELECT COALESCE(SUM(dd.cantidad * vd.precio_unitario), 0) AS total_devuelto_previo\n" .
                    "FROM devolucion_detalles dd\n" .
                    "INNER JOIN venta_detalles vd ON vd.id = dd.venta_detalle_id\n" .
                    "WHERE vd.venta_id = ?"
                );
                $stmt->bind_param("i", $venta_id_post);
                $stmt->execute();
                $prev_data = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $total_devuelto_previo = (float)($prev_data['total_devuelto_previo'] ?? 0);
                $devolucion_completa = ($total_devuelto_previo + $total_devuelto) >= ((float)$venta['total'] - 0.01);

                $caja_activa = obtenerCajaActivaDev($conn);
                $caja_id = $caja_activa['id'] ?? null;

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO devoluciones (venta_id, cliente_nombre, motivo, total_devuelto, usuario_id, caja_id, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $cliente_nombre = $venta['cliente_nombre'] ?? 'Cliente';
                    $stmt->bind_param("issdii", $venta_id_post, $cliente_nombre, $motivo, $total_devuelto, $usuario_id, $caja_id);
                    $stmt->execute();
                    $devolucion_id = $conn->insert_id;
                    $stmt->close();

                    if (!empty($items_devolver)) {
                        $stmt_det = $conn->prepare("INSERT INTO devolucion_detalles (devolucion_id, venta_detalle_id, producto_id, producto_nombre, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt_stock = $conn->prepare("UPDATE productos SET stock = stock + ? WHERE id = ?");

                        foreach ($items_devolver as $item) {
                            $stmt_det->bind_param(
                                "iiisidd",
                                $devolucion_id,
                                $item['venta_detalle_id'],
                                $item['producto_id'],
                                $item['producto_nombre'],
                                $item['cantidad'],
                                $item['precio_unitario'],
                                $item['subtotal']
                            );
                            $stmt_det->execute();

                            $stmt_stock->bind_param("ii", $item['cantidad'], $item['producto_id']);
                            $stmt_stock->execute();
                        }
                        $stmt_det->close();
                        $stmt_stock->close();
                    }

                    if ($caja_id) {
                        // Usar solo el motivo como descripción del movimiento
                        $descripcion = !empty($motivo) ? $motivo : 'Devolución';
                        $metodo_pago = $venta['metodo_pago'] ?? 'Efectivo';
                        $stmt = $conn->prepare("INSERT INTO movimientos_caja (caja_id, tipo, metodo_pago, monto, descripcion, fecha) VALUES (?, 'devolucion', ?, ?, ?, NOW())");
                        $stmt->bind_param("isds", $caja_id, $metodo_pago, $total_devuelto, $descripcion);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // Actualizar estado de la venta sin eliminarla (evita problemas con FK)
                    $ventas_cols = columnasTablaDev($conn, 'ventas');
                    if (in_array('estado', $ventas_cols, true)) {
                        if ($devolucion_manual || $devolucion_completa) {
                            $stmt = $conn->prepare("UPDATE ventas SET estado = ? WHERE id = ?");
                            $estado_nuevo = 'Devuelta';
                            $stmt->bind_param("si", $estado_nuevo, $venta_id_post);
                            $stmt->execute();
                            $stmt->close();
                        } else {
                            $stmt = $conn->prepare("
                                SELECT 
                                    COALESCE(SUM(vd.cantidad), 0) AS vendido,
                                    COALESCE((
                                        SELECT SUM(dd.cantidad)
                                        FROM devolucion_detalles dd
                                        INNER JOIN venta_detalles vd2 ON vd2.id = dd.venta_detalle_id
                                        WHERE vd2.venta_id = ?
                                    ), 0) AS devuelto
                                FROM venta_detalles vd
                                WHERE vd.venta_id = ?
                            ");
                            $stmt->bind_param("ii", $venta_id_post, $venta_id_post);
                            $stmt->execute();
                            $estado_data = $stmt->get_result()->fetch_assoc();
                            $stmt->close();

                            $estado_nuevo = ((int)$estado_data['devuelto'] >= (int)$estado_data['vendido']) ? 'Devuelta' : 'Devuelta parcial';
                            $stmt = $conn->prepare("UPDATE ventas SET estado = ? WHERE id = ?");
                            $stmt->bind_param("si", $estado_nuevo, $venta_id_post);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    $conn->commit();
                    $mensaje = "Devolución registrada correctamente por " . fmtCOPDev($total_devuelto);
                    if ($devolucion_completa) {
                        $mensaje .= " La venta ha sido marcada como completamente devuelta.";
                    }
                    $venta_id = $venta_id_post;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Error al registrar la devolución: " . $e->getMessage();
                }
            }
        }
    }
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_devolucion'])) {
    $devolucion_id_post = intval($_POST['devolucion_id'] ?? 0);
    $motivo = trim($_POST['motivo'] ?? '');

    if ($devolucion_id_post <= 0) {
        $error = 'Devolución inválida.';
    } else {
        $devolucion_actual = obtenerDevolucionDev($conn, $devolucion_id_post);
        if (!$devolucion_actual) {
            $error = 'La devolución no existe.';
        } elseif (($devolucion_actual['estado'] ?? 'procesada') === 'anulada') {
            $error = 'No puedes editar una devolución anulada.';
        } else {
            $stmt = $conn->prepare("UPDATE devoluciones SET motivo = ? WHERE id = ?");
            $stmt->bind_param("si", $motivo, $devolucion_id_post);
            if ($stmt->execute()) {
                $mensaje = 'Devolución actualizada correctamente.';
                $devolucion_id = $devolucion_id_post;
            } else {
                $error = 'No se pudo actualizar la devolución.';
            }
            $stmt->close();
        }
    }
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_devolucion'])) {
    $devolucion_id_post = intval($_POST['devolucion_id'] ?? 0);

    if ($devolucion_id_post <= 0) {
        $error = 'Devolución inválida.';
    } else {
        $devolucion_actual = obtenerDevolucionDev($conn, $devolucion_id_post);
        $detalles_actuales = obtenerDetallesDevolucionDev($conn, $devolucion_id_post);

        if (!$devolucion_actual) {
            $error = 'La devolución no existe.';
        } elseif (($devolucion_actual['estado'] ?? 'procesada') === 'anulada') {
            $error = 'La devolución ya fue anulada.';
        } elseif (empty($detalles_actuales)) {
            $error = 'La devolución no tiene detalles para revertir.';
        } else {
            $devoluciones_cols = columnasTablaDev($conn, 'devoluciones');
            $tiene_estado_devolucion = in_array('estado', $devoluciones_cols, true);
            $conn->begin_transaction();
            try {
                $stmt_stock = $conn->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
                if (!$stmt_stock) {
                    throw new Exception('No se pudo preparar la reversión de stock.');
                }
                foreach ($detalles_actuales as $item) {
                    $cantidad = (int)$item['cantidad'];
                    $producto_id = (int)$item['producto_id'];
                    $stmt_stock->bind_param("ii", $cantidad, $producto_id);
                    $stmt_stock->execute();
                }
                $stmt_stock->close();

                if ($tiene_estado_devolucion) {
                    $stmt = $conn->prepare("UPDATE devoluciones SET estado = 'anulada' WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception('No se pudo marcar la devolución como anulada.');
                    }
                    $stmt->bind_param("i", $devolucion_id_post);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("DELETE FROM devolucion_detalles WHERE devolucion_id = ?");
                    if (!$stmt) {
                        throw new Exception('No se pudieron limpiar los detalles de la devolución.');
                    }
                    $stmt->bind_param("i", $devolucion_id_post);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM devoluciones WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception('No se pudo eliminar la devolución.');
                    }
                    $stmt->bind_param("i", $devolucion_id_post);
                    $stmt->execute();
                    $stmt->close();
                }

                if (!empty($devolucion_actual['caja_id'])) {
                    $descripcion = "Reverso devolución #{$devolucion_id_post} de venta #{$devolucion_actual['venta_id']}";
                    $metodo_pago = $devolucion_actual['metodo_pago'] ?? 'Efectivo';
                    $monto = (float)$devolucion_actual['total_devuelto'];
                    $stmt = $conn->prepare("INSERT INTO movimientos_caja (caja_id, tipo, metodo_pago, monto, descripcion, fecha) VALUES (?, 'ingreso', ?, ?, ?, NOW())");
                    if (!$stmt) {
                        throw new Exception('No se pudo registrar el reverso en caja.');
                    }
                    $stmt->bind_param("isds", $devolucion_actual['caja_id'], $metodo_pago, $monto, $descripcion);
                    $stmt->execute();
                    $stmt->close();
                }

                $ventas_cols = columnasTablaDev($conn, 'ventas');
                if (in_array('estado', $ventas_cols, true)) {
                    if ($tiene_estado_devolucion) {
                        $stmt = $conn->prepare("
                            SELECT 
                                COALESCE(SUM(vd.cantidad), 0) AS vendido,
                                COALESCE((
                                    SELECT SUM(dd.cantidad)
                                    FROM devolucion_detalles dd
                                    INNER JOIN devoluciones d ON d.id = dd.devolucion_id
                                    INNER JOIN venta_detalles vd2 ON vd2.id = dd.venta_detalle_id
                                    WHERE vd2.venta_id = ? AND COALESCE(d.estado, 'procesada') <> 'anulada'
                                ), 0) AS devuelto
                            FROM venta_detalles vd
                            WHERE vd.venta_id = ?
                        ");
                    } else {
                        $stmt = $conn->prepare("
                            SELECT 
                                COALESCE(SUM(vd.cantidad), 0) AS vendido,
                                COALESCE((
                                    SELECT SUM(dd.cantidad)
                                    FROM devolucion_detalles dd
                                    INNER JOIN venta_detalles vd2 ON vd2.id = dd.venta_detalle_id
                                    WHERE vd2.venta_id = ?
                                ), 0) AS devuelto
                            FROM venta_detalles vd
                            WHERE vd.venta_id = ?
                        ");
                    }
                    if (!$stmt) {
                        throw new Exception('No se pudo recalcular el estado de la venta.');
                    }
                    $stmt->bind_param("ii", $devolucion_actual['venta_id'], $devolucion_actual['venta_id']);
                    $stmt->execute();
                    $estado_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $devuelto = (int)($estado_data['devuelto'] ?? 0);
                    $vendido = (int)($estado_data['vendido'] ?? 0);
                    $estado_venta = $devuelto <= 0 ? 'Completada' : (($devuelto >= $vendido) ? 'Devuelta' : 'Devuelta parcial');

                    $stmt = $conn->prepare("UPDATE ventas SET estado = ? WHERE id = ?");
                    $stmt->bind_param("si", $estado_venta, $devolucion_actual['venta_id']);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();
                $mensaje = 'Devolución anulada correctamente.';
                $devolucion_id = $tiene_estado_devolucion ? $devolucion_id_post : 0;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error al anular la devolución: ' . $e->getMessage();
            }
        }
    }
}

$venta_seleccionada = null;
$venta_detalles = [];
$devolucion_seleccionada = null;
$devolucion_detalles = [];

if (!$error && $venta_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $venta_seleccionada = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($venta_seleccionada) {
        $stmt = $conn->prepare("
            SELECT vd.*,
            COALESCE((
                SELECT SUM(dd.cantidad)
                FROM devolucion_detalles dd
                INNER JOIN devoluciones d ON d.id = dd.devolucion_id
                WHERE dd.venta_detalle_id = vd.id
            ), 0) AS cantidad_devuelta
            FROM venta_detalles vd
            WHERE vd.venta_id = ?
            ORDER BY vd.id ASC
        ");
        $stmt->bind_param("i", $venta_id);
        $stmt->execute();
        $venta_detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

if (!$error && $devolucion_id > 0) {
    $devolucion_seleccionada = obtenerDevolucionDev($conn, $devolucion_id);
    if ($devolucion_seleccionada) {
        $devolucion_detalles = obtenerDetallesDevolucionDev($conn, $devolucion_id);
    }
}

$devoluciones_recientes = [];
$res = null;
if (!$error) {
    $res = $conn->query("
        SELECT d.*, v.metodo_pago
        FROM devoluciones d
        LEFT JOIN ventas v ON v.id = d.venta_id
        ORDER BY d.fecha DESC
        LIMIT 20
    ");
}
if ($res) {
    $devoluciones_recientes = $res->fetch_all(MYSQLI_ASSOC);
}

$ventas_recientes = [];
$res = $conn->query("SELECT id, cliente_nombre, fecha_venta, total, metodo_pago FROM ventas ORDER BY id DESC LIMIT 20");
if ($res) {
    $ventas_recientes = $res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury POS | Devoluciones</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="bg-gray-100">
<?php luxury_render_nav_start('devoluciones'); ?>
<div class="">
    <?php if ($mensaje): ?>
        <div class="bg-emerald-100 border border-emerald-300 text-emerald-700 px-4 py-3 rounded-xl"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-xl"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="bg-gradient-to-r from-rose-500 to-red-500 text-white p-5">
                    <h1 class="text-2xl font-black flex items-center gap-3">
                        <i class="fas fa-rotate-left"></i> Devoluciones
                    </h1>
                    <p class="text-sm text-rose-100 mt-1">Procesa devoluciones parciales o totales y repone stock automáticamente.</p>
                </div>
                <div class="p-5">
                    <form method="GET" class="flex gap-3 mb-5">
                        <input type="number" name="venta_id" value="<?= $venta_id > 0 ? $venta_id : '' ?>" placeholder="Número de venta" class="flex-1 border rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-rose-400">
                        <button type="submit" class="bg-rose-500 hover:bg-rose-600 text-white px-5 rounded-xl font-bold">
                            <i class="fas fa-search mr-2"></i> Buscar venta
                        </button>
                    </form>

                    <?php if ($venta_seleccionada): ?>
                        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 mb-5">
                            <div class="grid md:grid-cols-4 gap-3 text-sm">
                                <div><p class="text-slate-400 text-xs uppercase font-bold">Venta</p><p class="font-bold">#<?= $venta_seleccionada['id'] ?></p></div>
                                <div><p class="text-slate-400 text-xs uppercase font-bold">Cliente</p><p class="font-bold"><?= htmlspecialchars($venta_seleccionada['cliente_nombre'] ?? 'Cliente') ?></p></div>
                                <div><p class="text-slate-400 text-xs uppercase font-bold">Fecha</p><p class="font-bold"><?= date('d/m/Y H:i', strtotime($venta_seleccionada['fecha_venta'])) ?></p></div>
                                <div><p class="text-slate-400 text-xs uppercase font-bold">Total</p><p class="font-bold text-emerald-600"><?= fmtCOPDev($venta_seleccionada['total'] ?? 0) ?></p></div>
                            </div>
                        </div>

                        <form method="POST" class="space-y-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="venta_id" value="<?= $venta_seleccionada['id'] ?>">
                            <?php if (!empty($venta_detalles)): ?>
                                <div class="overflow-x-auto border rounded-2xl">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                                            <tr>
                                                <th class="p-3 text-left">Producto</th>
                                                <th class="p-3 text-center">Vendidos</th>
                                                <th class="p-3 text-center">Devueltos</th>
                                                <th class="p-3 text-center">Disponibles</th>
                                                <th class="p-3 text-right">Precio</th>
                                                <th class="p-3 text-center">A devolver</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                        <?php foreach ($venta_detalles as $detalle): ?>
                                            <?php $disponible = max(0, (int)$detalle['cantidad'] - (int)$detalle['cantidad_devuelta']); ?>
                                            <tr>
                                                <td class="p-3 font-semibold"><?= htmlspecialchars($detalle['producto_nombre']) ?></td>
                                                <td class="p-3 text-center"><?= (int)$detalle['cantidad'] ?></td>
                                                <td class="p-3 text-center"><?= (int)$detalle['cantidad_devuelta'] ?></td>
                                                <td class="p-3 text-center font-bold <?= $disponible > 0 ? 'text-emerald-600' : 'text-gray-400' ?>"><?= $disponible ?></td>
                                                <td class="p-3 text-right"><?= fmtCOPDev($detalle['precio_unitario']) ?></td>
                                                <td class="p-3 text-center">
                                                    <input type="number" name="devolver[<?= $detalle['id'] ?>]" min="0" max="<?= $disponible ?>" value="0" class="w-20 border rounded-lg px-2 py-1 text-center">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="rounded-2xl border border-dashed border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
                                    Esta venta es de cobro manual (sin productos). Se devolverá el total completo.
                                </div>
                                <input type="hidden" name="manual_total" value="1">
                            <?php endif; ?>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Motivo de la devolución</label>
                                <textarea name="motivo" rows="3" class="w-full border rounded-2xl px-4 py-3 outline-none focus:ring-2 focus:ring-rose-400" placeholder="Ej: producto defectuoso, cambio por referencia, error de facturación..."></textarea>
                            </div>
                            <button type="submit" name="registrar_devolucion" class="w-full bg-rose-500 hover:bg-rose-600 text-white font-bold py-3 rounded-2xl">
                                <i class="fas fa-rotate-left mr-2"></i> Registrar devolución
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="text-center text-gray-400 py-12">
                            <i class="fas fa-receipt text-4xl mb-3"></i>
                            <p>Busca una venta para iniciar la devolución.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($devolucion_seleccionada): ?>
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-slate-800 to-slate-900 text-white p-5 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-black flex items-center gap-2">
                                <i class="fas fa-file-lines"></i> Devolución #<?= $devolucion_seleccionada['id'] ?>
                            </h2>
                            <p class="text-sm text-slate-300 mt-1">
                                Venta #<?= $devolucion_seleccionada['venta_id'] ?> · <?= htmlspecialchars($devolucion_seleccionada['cliente_nombre'] ?? 'Cliente') ?>
                            </p>
                        </div>
                        <span class="px-3 py-1 rounded-full text-xs font-black uppercase <?= ($devolucion_seleccionada['estado'] ?? 'procesada') === 'anulada' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' ?>">
                            <?= htmlspecialchars($devolucion_seleccionada['estado'] ?? 'procesada') ?>
                        </span>
                    </div>
                    <div class="p-5 space-y-5">
                        <div class="grid md:grid-cols-4 gap-3 text-sm">
                            <div><p class="text-slate-400 text-xs uppercase font-bold">Fecha</p><p class="font-bold"><?= date('d/m/Y H:i', strtotime($devolucion_seleccionada['fecha'])) ?></p></div>
                            <div><p class="text-slate-400 text-xs uppercase font-bold">Método</p><p class="font-bold"><?= htmlspecialchars($devolucion_seleccionada['metodo_pago'] ?? 'Pago') ?></p></div>
                            <div><p class="text-slate-400 text-xs uppercase font-bold">Total devuelto</p><p class="font-bold text-red-600"><?= fmtCOPDev($devolucion_seleccionada['total_devuelto']) ?></p></div>
                            <div><p class="text-slate-400 text-xs uppercase font-bold">Caja</p><p class="font-bold"><?= $devolucion_seleccionada['caja_id'] ? ('#' . (int)$devolucion_seleccionada['caja_id']) : 'Sin caja' ?></p></div>
                        </div>

                        <div class="overflow-x-auto border rounded-2xl">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                                    <tr>
                                        <th class="p-3 text-left">Producto</th>
                                        <th class="p-3 text-center">Cantidad</th>
                                        <th class="p-3 text-right">Precio</th>
                                        <th class="p-3 text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <?php foreach ($devolucion_detalles as $item): ?>
                                        <tr>
                                            <td class="p-3 font-semibold"><?= htmlspecialchars($item['producto_nombre']) ?></td>
                                            <td class="p-3 text-center"><?= (int)$item['cantidad'] ?></td>
                                            <td class="p-3 text-right"><?= fmtCOPDev($item['precio_unitario']) ?></td>
                                            <td class="p-3 text-right font-bold"><?= fmtCOPDev($item['subtotal']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <form method="POST" class="space-y-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="devolucion_id" value="<?= $devolucion_seleccionada['id'] ?>">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Motivo / observación</label>
                                <textarea name="motivo" rows="3" class="w-full border rounded-2xl px-4 py-3 outline-none focus:ring-2 focus:ring-slate-400" <?= ($devolucion_seleccionada['estado'] ?? 'procesada') === 'anulada' ? 'disabled' : '' ?>><?= htmlspecialchars($devolucion_seleccionada['motivo'] ?? '') ?></textarea>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <?php if (($devolucion_seleccionada['estado'] ?? 'procesada') !== 'anulada'): ?>
                                    <button type="submit" name="actualizar_devolucion" class="bg-slate-900 hover:bg-slate-800 text-white px-5 py-3 rounded-2xl font-bold">
                                        <i class="fas fa-save mr-2"></i> Guardar cambios
                                    </button>
                                    <button type="submit" name="eliminar_devolucion" onclick="return confirm('¿Anular esta devolución? Se revertirá el stock y se compensará caja.')" class="bg-red-500 hover:bg-red-600 text-white px-5 py-3 rounded-2xl font-bold">
                                        <i class="fas fa-trash-alt mr-2"></i> Anular devolución
                                    </button>
                                <?php endif; ?>
                                <a href="./" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-3 rounded-2xl font-bold">
                                    <i class="fas fa-times mr-2"></i> Cerrar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="bg-slate-900 text-white p-4">
                    <h2 class="font-bold">Ventas recientes</h2>
                </div>
                <div class="max-h-[340px] overflow-y-auto divide-y">
                    <?php foreach ($ventas_recientes as $venta): ?>
                        <a href="?venta_id=<?= $venta['id'] ?>" class="block p-4 hover:bg-gray-50 transition">
                            <div class="flex justify-between items-start gap-3">
                                <div>
                                    <p class="font-bold text-slate-800">#<?= str_pad($venta['id'], 8, '0', STR_PAD_LEFT) ?></p>
                                    <p class="text-sm text-gray-600"><?= htmlspecialchars($venta['cliente_nombre']) ?></p>
                                    <p class="text-xs text-gray-400"><?= date('d/m/Y H:i', strtotime($venta['fecha_venta'])) ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-emerald-600"><?= fmtCOPDev($venta['total']) ?></p>
                                    <p class="text-xs text-gray-400"><?= htmlspecialchars($venta['metodo_pago'] ?? 'Pago') ?></p>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="bg-amber-500 text-white p-4">
                    <h2 class="font-bold">Devoluciones recientes</h2>
                </div>
                <div class="max-h-[340px] overflow-y-auto divide-y">
                    <?php if (empty($devoluciones_recientes)): ?>
                        <div class="p-6 text-center text-gray-400">Sin devoluciones registradas.</div>
                    <?php else: ?>
                        <?php foreach ($devoluciones_recientes as $dev): ?>
                            <div class="p-4">
                                <div class="flex justify-between gap-3">
                                    <div>
                                        <p class="font-bold text-slate-800">Dev #<?= $dev['id'] ?> · Venta #<?= $dev['venta_id'] ?></p>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($dev['cliente_nombre'] ?? 'Cliente') ?></p>
                                        <p class="text-xs text-gray-400"><?= date('d/m/Y H:i', strtotime($dev['fecha'])) ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold text-red-600"><?= fmtCOPDev($dev['total_devuelto']) ?></p>
                                        <p class="text-xs text-gray-400"><?= htmlspecialchars($dev['metodo_pago'] ?? 'Pago') ?></p>
                                        <p class="text-[11px] font-bold <?= ($dev['estado'] ?? 'procesada') === 'anulada' ? 'text-red-500' : 'text-emerald-600' ?>">
                                            <?= htmlspecialchars($dev['estado'] ?? 'procesada') ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex gap-2 mt-3">
                                    <a href="?devolucion_id=<?= $dev['id'] ?>" class="bg-slate-900 hover:bg-slate-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold">
                                        <i class="fas fa-eye mr-1"></i> Ver
                                    </a>
                                    <?php if (($dev['estado'] ?? 'procesada') !== 'anulada'): ?>
                                        <a href="?devolucion_id=<?= $dev['id'] ?>" class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold">
                                            <i class="fas fa-edit mr-1"></i> Editar
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php luxury_render_nav_end(); ?>
</body>
</html>
