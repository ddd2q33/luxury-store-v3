<?php
// pages/proveedores.php - GESTIÓN DE PROVEEDORES MEJORADA
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

require_admin();

function buildUrl($pagina) {
    return "?pagina=$pagina";
}

// ====================================================================
// MÉTRICAS DEL MÓDULO
// ====================================================================
$total_proveedores = $conn->query("SELECT COUNT(*) as total FROM proveedores")->fetch_assoc()['total'] ?? 0;
$total_deuda = $conn->query("SELECT COALESCE(SUM(saldo_deuda), 0) as total FROM proveedores")->fetch_assoc()['total'] ?? 0;
$proveedores_con_deuda = $conn->query("SELECT COUNT(*) as total FROM proveedores WHERE saldo_deuda > 0")->fetch_assoc()['total'] ?? 0;
$total_abonos_mes = $conn->query("SELECT COALESCE(SUM(monto), 0) as total FROM abonos_proveedores WHERE MONTH(fecha_abono) = MONTH(CURDATE()) AND YEAR(fecha_abono) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;
$total_compras_mes = $conn->query("SELECT COALESCE(SUM(total_general), 0) as total FROM compras WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;

// ====================================================================
// PAGINACIÓN
// ====================================================================
$elementos_por_pagina = 5;
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina_actual - 1) * $elementos_por_pagina;

$total_elementos = $total_proveedores;
$total_paginas = max(1, ceil($total_elementos / $elementos_por_pagina));

// ====================================================================
// VER DETALLE DEL PROVEEDOR
// ====================================================================
$ver_detalle_id = isset($_GET['ver_detalle']) ? intval($_GET['ver_detalle']) : 0;
$detalle_proveedor = null;
$historial_compras = [];
$historial_compras_detalle = [];
$historial_abonos = [];
$total_compras = 0;
$total_abonos = 0;

if ($ver_detalle_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM proveedores WHERE id = ?");
    $stmt->bind_param("i", $ver_detalle_id);
    $stmt->execute();
    $detalle_proveedor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT c.id, DATE_FORMAT(c.fecha, '%d/%m/%Y %H:%i') as fecha, c.total_general, (SELECT COUNT(*) FROM ingresos_mercancia WHERE compra_id = c.id) as num_productos FROM compras c WHERE c.proveedor_id = ? ORDER BY c.fecha DESC LIMIT 20");
    $stmt->bind_param("i", $ver_detalle_id);
    $stmt->execute();
    $historial_compras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($historial_compras)) {
        $stmt_det = $conn->prepare("SELECT compra_id, producto_nombre, cantidad, precio_unitario, total, descripcion, serial FROM ingresos_mercancia WHERE compra_id = ? ORDER BY id ASC");
        foreach ($historial_compras as $compra_item) {
            $compra_hist_id = (int)$compra_item['id'];
            $stmt_det->bind_param("i", $compra_hist_id);
            $stmt_det->execute();
            $historial_compras_detalle[$compra_hist_id] = $stmt_det->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $stmt_det->close();
    }
    
    $stmt = $conn->prepare("SELECT SUM(total_general) as total FROM compras WHERE proveedor_id = ?");
    $stmt->bind_param("i", $ver_detalle_id);
    $stmt->execute();
    $total_compras = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT a.id, a.monto, DATE_FORMAT(a.fecha_abono, '%d/%m/%Y %H:%i') as fecha_abono, a.referencia FROM abonos_proveedores a WHERE a.proveedor_id = ? ORDER BY a.fecha_abono DESC LIMIT 20");
    $stmt->bind_param("i", $ver_detalle_id);
    $stmt->execute();
    $historial_abonos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT SUM(monto) as total FROM abonos_proveedores WHERE proveedor_id = ?");
    $stmt->bind_param("i", $ver_detalle_id);
    $stmt->execute();
    $total_abonos = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

// ====================================================================
// PROCESAMIENTO DE FORMULARIOS
// ====================================================================
if (isset($_POST['guardar'])) {
    $saldo_inicial = floatval(str_replace(',', '', $_POST['saldo_inicial']));
    $descripcion_deuda = trim($_POST['descripcion_deuda_inicial']);
    $stmt = $conn->prepare("INSERT INTO proveedores (nombre, contacto, telefono, correo, direccion, saldo_deuda, descripcion_deuda_inicial) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssds", $_POST['nombre'], $_POST['contacto'], $_POST['telefono'], $_POST['correo'], $_POST['direccion'], $saldo_inicial, $descripcion_deuda);
    if ($stmt->execute()) echo "<script>alert('✅ Proveedor agregado'); window.location='./';</script>";
    else echo "<script>alert('❌ Error: " . addslashes($conn->error) . "'); window.location='./';</script>";
    $stmt->close(); exit;
}

if (isset($_POST['editar'])) {
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("UPDATE proveedores SET nombre=?, contacto=?, telefono=?, correo=?, direccion=? WHERE id=?");
    $stmt->bind_param("sssssi", $_POST['nombre'], $_POST['contacto'], $_POST['telefono'], $_POST['correo'], $_POST['direccion'], $id);
    if ($stmt->execute()) echo "<script>alert('✏️ Proveedor actualizado'); window.location='./';</script>";
    else echo "<script>alert('❌ Error: " . addslashes($conn->error) . "'); window.location='./';</script>";
    $stmt->close(); exit;
}

if (isset($_POST['registrar_abono'])) {
    $proveedor_id = intval($_POST['abono_proveedor_id']);
    $monto = floatval(str_replace(',', '', $_POST['monto_abono']));
    $referencia = trim($_POST['referencia_abono']);
    $usuario_id = $_SESSION['user_id'] ?? 1;

    if ($monto > 0) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO abonos_proveedores (proveedor_id, monto, fecha_abono, referencia, usuario_id) VALUES (?, ?, NOW(), ?, ?)");
            $stmt->bind_param("idsi", $proveedor_id, $monto, $referencia, $usuario_id);
            if (!$stmt->execute()) throw new Exception("Error al registrar abono");
            $stmt->close();

            $stmt = $conn->prepare("UPDATE proveedores SET saldo_deuda = saldo_deuda - ? WHERE id = ?");
            $stmt->bind_param("di", $monto, $proveedor_id);
            if (!$stmt->execute()) throw new Exception("Error al actualizar saldo");
            $stmt->close();

            $conn->commit();
            echo "<script>alert('💰 Abono de " . format_currency($monto) . " registrado'); window.location='./';</script>";
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('❌ Error: " . addslashes($e->getMessage()) . "'); window.location='./';</script>";
        }
        exit;
    } else {
        echo "<script>alert('❌ El monto debe ser positivo'); window.location='./';</script>";
        exit;
    }
}

if (isset($_POST['actualizar_deuda'])) {
    $id = intval($_POST['deuda_proveedor_id']);
    $nuevo_saldo = floatval(str_replace(',', '', $_POST['nuevo_saldo_deuda']));
    $stmt = $conn->prepare("UPDATE proveedores SET saldo_deuda = ? WHERE id = ?");
    $stmt->bind_param("di", $nuevo_saldo, $id);
    if ($stmt->execute()) echo "<script>alert('✅ Saldo actualizado a " . format_currency($nuevo_saldo) . "'); window.location='./';</script>";
    else echo "<script>alert('❌ Error: " . addslashes($conn->error) . "'); window.location='./';</script>";
    $stmt->close(); exit;
}

if (isset($_POST['eliminar'])) {
    $id = intval($_POST['eliminar']);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM abonos_proveedores WHERE proveedor_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare("DELETE FROM proveedores WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        echo "<script>alert('🗑️ Proveedor eliminado'); window.location='./';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('❌ No se puede eliminar: Tiene compras asociadas'); window.location='./';</script>";
    }
    exit;
}

// ====================================================================
// CONSULTA PRINCIPAL
// ====================================================================
$query = "SELECT id, nombre, contacto, telefono, correo, direccion, saldo_deuda, descripcion_deuda_inicial FROM proveedores ORDER BY id DESC LIMIT $elementos_por_pagina OFFSET $offset";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury POS | Proveedores</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <script src="../assets/vendor/fontawesome/js/all.min.js"></script>
    <style>
        @import url('../assets/vendor/inter/inter.css');
        * { font-family: 'Inter', sans-serif; }
        
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 32px; max-width: 550px; width: 90%; max-height: 85vh; overflow-y: auto; padding: 32px; animation: modalFade 0.2s ease; }
        @keyframes modalFade { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        
        .stats-card { transition: all 0.3s; }
        .stats-card:hover { transform: translateY(-3px); box-shadow: 0 12px 25px -8px rgba(0,0,0,0.15); }
        
        .proveedor-row { transition: all 0.2s; }
        .proveedor-row:hover { background-color: #f8fafc; transform: translateX(2px); }
        
        .pagination a { transition: all 0.2s; }
        .pagination a:hover { transform: translateY(-2px); }
        
        .badge-deuda { transition: all 0.2s; }
        .badge-deuda:hover { transform: scale(1.05); }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
<?php luxury_render_nav_start('proveedores'); ?>

<div class="">

    <!-- Header -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6 border border-gray-100">
        <div class="flex justify-between items-center flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-black text-gray-800 flex items-center gap-3">
                    <span class="bg-gradient-to-r from-blue-500 to-indigo-600 p-2 rounded-xl">
                        <i class="fas fa-truck text-white text-lg"></i>
                    </span>
                    Gestión de Proveedores
                </h1>
                <p class="text-gray-500 text-sm mt-1 flex items-center gap-2">
                    <i class="fas fa-chart-line text-blue-500"></i>
                    Administra tus proveedores y control de deudas
                </p>
            </div>
            <button onclick="abrirModalNuevoProveedor()" class="bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white px-6 py-3 rounded-xl font-bold transition-all shadow-md flex items-center gap-2">
                <i class="fas fa-plus"></i> Nuevo Proveedor
            </button>
        </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-4 mb-6">
        <div class="stats-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-truck mr-1"></i> Total Proveedores</p>
            <p class="text-2xl font-bold"><?= format_number($total_proveedores) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-red-500 to-red-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-dollar-sign mr-1"></i> Deuda Total</p>
            <p class="text-2xl font-bold"><?= format_currency($total_deuda) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-exclamation-triangle mr-1"></i> Con Deuda</p>
            <p class="text-2xl font-bold"><?= format_number($proveedores_con_deuda) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-hand-holding-usd mr-1"></i> Abonos (Mes)</p>
            <p class="text-2xl font-bold"><?= format_currency($total_abonos_mes) ?></p>
        </div>
        <div class="stats-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-5 text-white shadow-lg">
            <p class="text-xs opacity-80"><i class="fas fa-shopping-cart mr-1"></i> Compras (Mes)</p>
            <p class="text-2xl font-bold"><?= format_currency($total_compras_mes) ?></p>
        </div>
    </div>

    <!-- Tabla de Proveedores -->
    <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
        <div class="bg-gradient-to-r from-gray-800 to-gray-900 text-white p-5">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-lg font-bold flex items-center gap-2">
                        <i class="fas fa-list"></i> Lista de Proveedores
                    </h2>
                    <p class="text-xs opacity-70">Página <?= $pagina_actual ?> de <?= $total_paginas ?> · Total: <?= format_number($total_proveedores) ?> registros</p>
                </div>
                <div class="bg-white/20 px-3 py-1 rounded-full text-xs font-bold">
                    <?= $elementos_por_pagina ?> por página
                </div>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-bold border-b">
                     
                        <th class="p-4 text-left">ID</th>
                        <th class="p-4 text-left">Nombre</th>
                        <th class="p-4 text-left">Contacto</th>
                        <th class="p-4 text-left">Teléfono</th>
                        <th class="p-4 text-left hidden md:table-cell">Email</th>
                        <th class="p-4 text-right">Deuda Actual</th>
                        <th class="p-4 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if ($result && mysqli_num_rows($result) > 0):
                        $i = $offset + 1;
                        while ($row = mysqli_fetch_assoc($result)):
                            $deuda_clase = $row['saldo_deuda'] > 0 ? 'text-red-600 font-bold' : 'text-gray-500';
                    ?>
                        <form method="POST" class="proveedor-row">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 font-mono text-xs text-gray-400">#<?= $i++ ?>    </td>
                                <td class="p-4"><input name="nombre" value="<?= htmlspecialchars($row['nombre']) ?>" class="border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-blue-500 outline-none" required></td>
                                <td class="p-4"><input name="contacto" value="<?= htmlspecialchars($row['contacto']) ?>" class="border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-blue-500 outline-none"></td>
                                <td class="p-4"><input name="telefono" value="<?= htmlspecialchars($row['telefono']) ?>" class="border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-blue-500 outline-none"></td>
                                <td class="p-4 hidden md:table-cell"><input name="correo" value="<?= htmlspecialchars($row['correo']) ?>" class="border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-blue-500 outline-none"></td>
                                <td class="p-4 text-right <?= $deuda_clase ?>"><?= format_currency($row['saldo_deuda']) ?></td>
                                <td class="p-4 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="?ver_detalle=<?= $row['id'] ?>" class="bg-blue-500 hover:bg-blue-600 text-white w-8 h-8 rounded-lg flex items-center justify-center transition" title="Ver Detalle"><i class="fas fa-eye text-xs"></i></a>
                                        <button type="button" onclick="abrirModalAbono(<?= $row['id'] ?>, '<?= addslashes($row['nombre']) ?>', <?= $row['saldo_deuda'] ?>)" class="bg-green-500 hover:bg-green-600 text-white w-8 h-8 rounded-lg flex items-center justify-center transition" title="Registrar Abono"><i class="fas fa-dollar-sign text-xs"></i></button>
                                        <button type="button" onclick="abrirModalAjusteDeuda(<?= $row['id'] ?>, '<?= addslashes($row['nombre']) ?>', <?= $row['saldo_deuda'] ?>)" class="bg-amber-500 hover:bg-amber-600 text-white w-8 h-8 rounded-lg flex items-center justify-center transition" title="Ajustar Saldo"><i class="fas fa-calculator text-xs"></i></button>
                                        <button type="submit" name="editar" class="bg-indigo-500 hover:bg-indigo-600 text-white w-8 h-8 rounded-lg flex items-center justify-center transition" title="Guardar"><i class="fas fa-save text-xs"></i></button>
                                        <button type="button" onclick="eliminarProveedor(<?= $row['id'] ?>, '<?= addslashes($row['nombre']) ?>')" class="bg-gray-400 hover:bg-gray-500 text-white w-8 h-8 rounded-lg flex items-center justify-center transition" title="Eliminar"><i class="fas fa-trash text-xs"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </form>
                    <?php endwhile; else: ?>
                        <tr><td colspan="7" class="p-12 text-center text-gray-400"><i class="fas fa-truck text-5xl mb-3 opacity-30"></i><br>No hay proveedores registrados</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Paginación -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-between items-center flex-wrap gap-3">
            <p class="text-xs text-gray-500">Mostrando <?= mysqli_num_rows($result) ?> de <?= format_number($total_proveedores) ?> proveedores</p>
            <div class="flex gap-2">
                <?php if ($pagina_actual > 1): ?>
                    <a href="<?= buildUrl(1) ?>" class="w-9 h-9 flex items-center justify-center bg-white border rounded-xl text-xs hover:bg-gray-100"><i class="fas fa-angle-double-left"></i></a>
                    <a href="<?= buildUrl($pagina_actual - 1) ?>" class="px-3 py-2 bg-white border rounded-xl text-xs hover:bg-gray-100"><i class="fas fa-chevron-left"></i> Anterior</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $pagina_actual - 2);
                $end = min($total_paginas, $pagina_actual + 2);
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="<?= buildUrl($i) ?>" class="w-9 h-9 flex items-center justify-center rounded-xl text-sm font-medium transition <?= $i == $pagina_actual ? 'bg-blue-600 text-white shadow-md' : 'bg-white border text-gray-600 hover:bg-gray-100' ?>"><?= $i ?></a>
                <?php endfor; ?>
                
                <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="<?= buildUrl($pagina_actual + 1) ?>" class="px-3 py-2 bg-white border rounded-xl text-xs hover:bg-gray-100">Siguiente <i class="fas fa-chevron-right"></i></a>
                    <a href="<?= buildUrl($total_paginas) ?>" class="w-9 h-9 flex items-center justify-center bg-white border rounded-xl text-xs hover:bg-gray-100"><i class="fas fa-angle-double-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL NUEVO PROVEEDOR -->
<div id="modalNuevoProveedor" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-2xl font-black text-gray-800 flex items-center gap-2">
                <i class="fas fa-truck text-blue-500"></i> Nuevo Proveedor
            </h3>
            <button onclick="cerrarModalNuevoProveedor()" class="text-gray-400 hover:text-red-500 text-3xl">&times;</button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nombre *</label><input type="text" name="nombre" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none"></div>
                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Contacto</label><input type="text" name="contacto" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none"></div>
                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Teléfono</label><input type="text" name="telefono" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none"></div>
                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email</label><input type="email" name="correo" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none"></div>
                <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Dirección</label><textarea name="direccion" rows="2" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none"></textarea></div>
                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Descripción Deuda</label><input type="text" name="descripcion_deuda_inicial" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none"></div>
                <div><label class="block text-xs font-bold text-red-500 uppercase mb-1">Deuda Inicial</label><input type="number" name="saldo_inicial" step="any" min="0" value="0" class="w-full p-3 bg-red-50 border border-red-200 rounded-xl focus:ring-2 focus:ring-red-500 outline-none"></div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" name="guardar" class="flex-1 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white py-3 rounded-xl font-bold transition-all">Guardar Proveedor</button>
                <button type="button" onclick="cerrarModalNuevoProveedor()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-600 py-3 rounded-xl font-bold transition-all">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL REGISTRAR ABONO -->
<div id="modalRegistrarAbono" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-2xl font-black text-gray-800 flex items-center gap-2">
                <i class="fas fa-hand-holding-usd text-green-500"></i> Registrar Abono
            </h3>
            <button onclick="cerrarModalAbono()" class="text-gray-400 hover:text-red-500 text-3xl">&times;</button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="abono_proveedor_id" id="abono_proveedor_id">
            <p class="mb-3 text-gray-600">Proveedor: <strong id="abono_proveedor_nombre" class="text-blue-600"></strong></p>
            <div class="bg-amber-50 p-4 rounded-xl mb-4">
                <p class="text-sm text-gray-600">Deuda Actual</p>
                <p class="text-2xl font-bold text-red-600" id="abono_deuda_actual">$0</p>
            </div>
            <div class="mb-3"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Monto del Abono *</label><input type="number" name="monto_abono" step="any" min="1" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 outline-none"></div>
            <div class="mb-4"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Referencia</label><input type="text" name="referencia_abono" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 outline-none"></div>
            <div class="flex gap-3 mt-4">
                <button type="submit" name="registrar_abono" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-3 rounded-xl font-bold transition-all">Confirmar Abono</button>
                <button type="button" onclick="cerrarModalAbono()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-600 py-3 rounded-xl font-bold transition-all">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL AJUSTAR DEUDA -->
<div id="modalAjustarDeuda" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-2xl font-black text-gray-800 flex items-center gap-2">
                <i class="fas fa-calculator text-amber-500"></i> Ajuste de Saldo
            </h3>
            <button onclick="cerrarModalAjuste()" class="text-gray-400 hover:text-red-500 text-3xl">&times;</button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="deuda_proveedor_id" id="ajuste_proveedor_id">
            <p class="mb-3 text-gray-600">Proveedor: <strong id="ajuste_proveedor_nombre" class="text-blue-600"></strong></p>
            <div class="bg-amber-50 p-4 rounded-xl mb-4">
                <p class="text-sm text-gray-600">Saldo Actual</p>
                <p class="text-2xl font-bold text-red-600" id="ajuste_deuda_actual">$0</p>
            </div>
            <div class="mb-3"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nuevo Saldo *</label><input type="number" name="nuevo_saldo_deuda" id="nuevo_saldo_deuda_input" step="any" min="0" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 outline-none"></div>
            <div class="mb-4"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Razón del Ajuste *</label><input type="text" name="razon_ajuste" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 outline-none"></div>
            <div class="flex gap-3 mt-4">
                <button type="submit" name="actualizar_deuda" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white py-3 rounded-xl font-bold transition-all">Aplicar Ajuste</button>
                <button type="button" onclick="cerrarModalAjuste()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-600 py-3 rounded-xl font-bold transition-all">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DETALLE PROVEEDOR -->
<?php if ($ver_detalle_id > 0 && $detalle_proveedor): ?>
<div id="modalDetalleProveedor" class="modal active">
    <div class="modal-content" style="max-width: 900px;">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-2xl font-black text-gray-800 flex items-center gap-2">
                <i class="fas fa-truck text-blue-500"></i> <?= htmlspecialchars($detalle_proveedor['nombre']) ?>
            </h3>
            <a href="./" class="text-gray-400 hover:text-red-500 text-3xl">&times;</a>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 p-4 bg-gray-50 rounded-xl">
            <div><p class="text-xs text-gray-500">Contacto</p><p class="font-semibold"><?= htmlspecialchars($detalle_proveedor['contacto'] ?? 'N/A') ?></p></div>
            <div><p class="text-xs text-gray-500">Teléfono</p><p class="font-semibold"><?= htmlspecialchars($detalle_proveedor['telefono'] ?? 'N/A') ?></p></div>
            <div><p class="text-xs text-gray-500">Email</p><p class="font-semibold"><?= htmlspecialchars($detalle_proveedor['correo'] ?? 'N/A') ?></p></div>
            <div><p class="text-xs text-gray-500">Dirección</p><p class="font-semibold"><?= htmlspecialchars($detalle_proveedor['direccion'] ?? 'N/A') ?></p></div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-blue-50 p-4 rounded-xl text-center"><i class="fas fa-shopping-cart text-2xl text-blue-600 mb-2"></i><p class="text-sm text-gray-600">Total Compras</p><p class="text-xl font-bold text-blue-700"><?= format_currency($total_compras) ?></p></div>
            <div class="bg-green-50 p-4 rounded-xl text-center"><i class="fas fa-hand-holding-usd text-2xl text-green-600 mb-2"></i><p class="text-sm text-gray-600">Total Abonos</p><p class="text-xl font-bold text-green-700"><?= format_currency($total_abonos) ?></p></div>
            <div class="bg-red-50 p-4 rounded-xl text-center"><i class="fas fa-chart-line text-2xl text-red-600 mb-2"></i><p class="text-sm text-gray-600">Saldo Pendiente</p><p class="text-2xl font-bold text-red-700"><?= format_currency($detalle_proveedor['saldo_deuda']) ?></p></div>
        </div>
        
        <div class="mb-6"><h4 class="font-bold text-gray-800 mb-3 border-b pb-2">📦 Historial de Compras</h4>
            <?php if (count($historial_compras) > 0): ?>
            <table class="w-full text-sm"><thead class="bg-gray-100"><tr><th class="p-2 text-left">ID</th><th class="p-2 text-left">Fecha</th><th class="p-2 text-right">Total</th><th class="p-2 text-center">Productos</th><th class="p-2 text-center">Acción</th></tr></thead>
            <tbody><?php foreach($historial_compras as $compra): ?><tr class="hover:bg-gray-50"><td class="p-2 font-mono">#<?= $compra['id'] ?></td><td class="p-2"><?= $compra['fecha'] ?></td><td class="p-2 text-right font-bold text-green-600"><?= format_currency($compra['total_general']) ?></td><td class="p-2 text-center"><?= $compra['num_productos'] ?></td><td class="p-2 text-center"><a href="../ingresos/?detalle=<?= $compra['id'] ?>" class="text-blue-500"><i class="fas fa-eye"></i></a></td></tr><?php endforeach; ?></tbody></table>
            <?php else: ?><p class="text-gray-500 text-center py-4">No hay compras registradas</p><?php endif; ?>
        </div>
        
        <div><h4 class="font-bold text-gray-800 mb-3 border-b pb-2">💰 Historial de Abonos</h4>
        <?php if (count($historial_compras) > 0): ?>
        <div class="mb-6">
            <h4 class="font-bold text-gray-800 mb-3 border-b pb-2">Detalle de Productos por Compra</h4>
            <div class="space-y-3">
                <?php foreach($historial_compras as $compra): ?>
                    <?php $detalle_items = $historial_compras_detalle[$compra['id']] ?? []; ?>
                    <div class="border border-gray-200 rounded-2xl overflow-hidden bg-white">
                        <button type="button" onclick="toggleCompraDetalle(<?= $compra['id'] ?>)" class="w-full flex items-center justify-between gap-4 px-4 py-3 text-left hover:bg-gray-50 transition">
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="font-mono text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">Compra #<?= $compra['id'] ?></span>
                                <span class="text-sm text-gray-600"><?= $compra['fecha'] ?></span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-sm md:text-base font-bold text-green-600"><?= format_currency($compra['total_general']) ?></span>
                                <i id="icon-compra-<?= $compra['id'] ?>" class="fas fa-chevron-down text-gray-400"></i>
                            </div>
                        </button>
                        <div id="detalle-compra-<?= $compra['id'] ?>" class="hidden border-t bg-gray-50 p-4">
                            <?php if (!empty($detalle_items)): ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs md:text-sm">
                                        <thead class="bg-white text-gray-500 uppercase">
                                            <tr>
                                                <th class="p-2 text-left">Producto</th>
                                                <th class="p-2 text-center">Cant.</th>
                                                <th class="p-2 text-right">Precio</th>
                                                <th class="p-2 text-right">Total</th>
                                                <th class="p-2 text-left">Detalle</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php foreach ($detalle_items as $item): ?>
                                                <tr class="bg-white">
                                                    <td class="p-2">
                                                        <div class="font-semibold text-gray-800"><?= htmlspecialchars($item['producto_nombre'] ?? 'Producto') ?></div>
                                                        <?php if (!empty($item['serial'])): ?>
                                                            <div class="text-[11px] text-gray-400">Serial: <?= htmlspecialchars($item['serial']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="p-2 text-center font-semibold"><?= format_number($item['cantidad'] ?? 0) ?></td>
                                                    <td class="p-2 text-right"><?= format_currency($item['precio_unitario'] ?? 0) ?></td>
                                                    <td class="p-2 text-right font-bold text-green-600"><?= format_currency($item['total'] ?? 0) ?></td>
                                                    <td class="p-2 text-gray-500"><?= htmlspecialchars($item['descripcion'] ?: 'Sin descripción') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-500 text-center py-3">Esta compra no tiene subdetalles registrados.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

            <?php if (count($historial_abonos) > 0): ?>
            <table class="w-full text-sm"><thead class="bg-gray-100"><tr><th class="p-2 text-left">Fecha</th><th class="p-2 text-right">Monto</th><th class="p-2 text-left">Referencia</th></tr></thead>
            <tbody><?php foreach($historial_abonos as $abono): ?><tr class="hover:bg-gray-50"><td class="p-2"><?= $abono['fecha_abono'] ?></td><td class="p-2 text-right font-bold text-green-600"><?= format_currency($abono['monto']) ?></td><td class="p-2"><?= htmlspecialchars($abono['referencia'] ?? 'Sin referencia') ?></td></tr><?php endforeach; ?></tbody></table>
            <?php else: ?><p class="text-gray-500 text-center py-4">No hay abonos registrados</p><?php endif; ?>
        </div>
        
        <div class="flex justify-end gap-3 mt-6">
            <a href="../ingresos/?proveedor=<?= $ver_detalle_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl font-bold"><i class="fas fa-plus mr-1"></i> Registrar Compra</a>
            <a href="./" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-xl font-bold"><i class="fas fa-times mr-1"></i> Cerrar</a>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const PROVEEDORES_CSRF = '<?= csrf_token() ?>';

function eliminarProveedor(id, nombre) {
    if (!confirm('⚠️ ¿Eliminar el proveedor "' + nombre + '"? Se eliminarán sus abonos registrados.')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="_csrf" value="' + PROVEEDORES_CSRF + '">' +
        '<input type="hidden" name="eliminar" value="' + id + '">';
    document.body.appendChild(form);
    form.submit();
}

function abrirModalNuevoProveedor() { document.getElementById('modalNuevoProveedor').classList.add('active'); }
function cerrarModalNuevoProveedor() { document.getElementById('modalNuevoProveedor').classList.remove('active'); }

function formatCurrencyJS(number) { return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(number || 0); }

function abrirModalAbono(id, nombre, deuda) {
    document.getElementById('abono_proveedor_id').value = id;
    document.getElementById('abono_proveedor_nombre').innerHTML = nombre;
    document.getElementById('abono_deuda_actual').innerHTML = formatCurrencyJS(deuda);
    document.getElementById('modalRegistrarAbono').classList.add('active');
}
function cerrarModalAbono() { document.getElementById('modalRegistrarAbono').classList.remove('active'); }

function abrirModalAjusteDeuda(id, nombre, deuda) {
    document.getElementById('ajuste_proveedor_id').value = id;
    document.getElementById('ajuste_proveedor_nombre').innerHTML = nombre;
    document.getElementById('ajuste_deuda_actual').innerHTML = formatCurrencyJS(deuda);
    document.getElementById('nuevo_saldo_deuda_input').value = deuda;
    document.getElementById('modalAjustarDeuda').classList.add('active');
}
function cerrarModalAjuste() { document.getElementById('modalAjustarDeuda').classList.remove('active'); }

function toggleCompraDetalle(compraId) {
    const detalle = document.getElementById(`detalle-compra-${compraId}`);
    const icono = document.getElementById(`icon-compra-${compraId}`);
    if (!detalle || !icono) return;
    detalle.classList.toggle('hidden');
    icono.classList.toggle('fa-chevron-down');
    icono.classList.toggle('fa-chevron-up');
}

window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => { if (event.target === modal) modal.classList.remove('active'); });
}
</script>

<?php luxury_render_nav_end(); ?>
</body>
</html>
