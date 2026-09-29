<?php
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('ingresos');

// ====================================================================
// 🔹 CONSULTA INICIAL: Obtener la lista de productos para el Select
// ====================================================================
$productos_existentes = [];
if (isset($conn)) {
    $result_prods = $conn->query("SELECT id, nombre FROM productos ORDER BY nombre ASC");
    if ($result_prods) {
        $productos_existentes = $result_prods->fetch_all(MYSQLI_ASSOC);
    }
}

// ====================================================================
// 🔹 OBTENER LISTA DE PROVEEDORES PARA EL SELECT (NUEVO)
// ====================================================================
$proveedores = [];
if (isset($conn)) {
    $result_prov = $conn->query("SELECT id, nombre FROM proveedores ORDER BY nombre ASC");
    if ($result_prov) {
        $proveedores = $result_prov->fetch_all(MYSQLI_ASSOC);
    }
}

// ====================================================================
// 🔹 CONSULTA PARA OBTENER EL TOTAL GENERAL DE TODAS LAS COMPRAS (NUEVO)
// ====================================================================
$total_general_todos_tiempos = 0;
$total_compras = 0;
$total_proveedores = 0;
$mes_actual = date('Y-m');
$total_mes_actual = 0;

if (isset($conn)) {
    // Total general de todas las compras
    $total_query = "SELECT SUM(total_general) AS total FROM compras";
    $result_total = $conn->query($total_query);
    if ($result_total) {
        $total_general_todos_tiempos = $result_total->fetch_assoc()['total'] ?? 0;
    }

    // Total de compras registradas
    $compras_query = "SELECT COUNT(id) AS total FROM compras";
    $result_compras = $conn->query($compras_query);
    if ($result_compras) {
        $total_compras = $result_compras->fetch_assoc()['total'] ?? 0;
    }

    // Total de proveedores activos
    $proveedores_query = "SELECT COUNT(id) AS total FROM proveedores";
    $result_proveedores = $conn->query($proveedores_query);
    if ($result_proveedores) {
        $total_proveedores = $result_proveedores->fetch_assoc()['total'] ?? 0;
    }

    // Total del mes actual
    $mes_query = "SELECT SUM(total_general) AS total FROM compras WHERE DATE_FORMAT(fecha, '%Y-%m') = '$mes_actual'";
    $result_mes = $conn->query($mes_query);
    if ($result_mes) {
        $total_mes_actual = $result_mes->fetch_assoc()['total'] ?? 0;
    }
}

// ====================================================================
// 🔹 LÓGICA DE ELIMINACIÓN DE COMPRA CON REVERSIÓN DE STOCK Y DEUDA (Modificada)
// ====================================================================
if (isset($_POST['eliminar']) && isset($conn)) {
    $compra_id_a_eliminar = intval($_POST['eliminar']);

    if ($compra_id_a_eliminar > 0) {
        $conn->begin_transaction();

        try {
            // 1. Obtener el proveedor_id y total_general de la compra a eliminar
            $stmt_get_compra = $conn->prepare("SELECT proveedor_id, total_general FROM compras WHERE id = ?");
            $stmt_get_compra->bind_param("i", $compra_id_a_eliminar);
            $stmt_get_compra->execute();
            $compra_info = $stmt_get_compra->get_result()->fetch_assoc();
            $stmt_get_compra->close();

            if (!$compra_info) {
                throw new Exception("Compra no encontrada.");
            }

            $proveedor_id = $compra_info['proveedor_id'];
            $total_compra = $compra_info['total_general'];

            // 2. Obtener la lista de productos y cantidades de la compra a eliminar
            $stmt_select_detalle = $conn->prepare("SELECT producto_id, cantidad FROM ingresos_mercancia WHERE compra_id = ?");
            $stmt_select_detalle->bind_param("i", $compra_id_a_eliminar);
            $stmt_select_detalle->execute();
            $detalle_result = $stmt_select_detalle->get_result();
            $items_a_revertir = $detalle_result->fetch_all(MYSQLI_ASSOC);
            $stmt_select_detalle->close();

            // 3. Revertir el stock de cada producto
            $stmt_update_stock = $conn->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
            foreach ($items_a_revertir as $item) {
                $stmt_update_stock->bind_param("ii", $item['cantidad'], $item['producto_id']);
                if (!$stmt_update_stock->execute()) {
                    throw new Exception("Error al revertir stock para el producto ID {$item['producto_id']}.");
                }
            }
            $stmt_update_stock->close();

            // 4. Eliminar el detalle de la compra
            $stmt_delete_detalle = $conn->prepare("DELETE FROM ingresos_mercancia WHERE compra_id = ?");
            $stmt_delete_detalle->bind_param("i", $compra_id_a_eliminar);
            if (!$stmt_delete_detalle->execute()) {
                throw new Exception("Error al eliminar el detalle de la compra.");
            }
            $stmt_delete_detalle->close();

            // 5. Eliminar el encabezado de la compra
            $stmt_delete_compra = $conn->prepare("DELETE FROM compras WHERE id = ?");
            $stmt_delete_compra->bind_param("i", $compra_id_a_eliminar);
            if (!$stmt_delete_compra->execute()) {
                throw new Exception("Error al eliminar el encabezado de la compra.");
            }
            $stmt_delete_compra->close();

            // 🔄 MODIFICACIÓN PARA PROVEEDORES: Si la compra tenía proveedor, restar la deuda
            if ($proveedor_id && $proveedor_id > 0) {
                $stmt_update_deuda = $conn->prepare("UPDATE proveedores SET saldo_deuda = saldo_deuda - ? WHERE id = ?");
                $stmt_update_deuda->bind_param("di", $total_compra, $proveedor_id);
                if (!$stmt_update_deuda->execute()) {
                    throw new Exception("Error al actualizar la deuda del proveedor.");
                }
                $stmt_update_deuda->close();
            }

            $conn->commit();

            echo "<script>alert('🗑️ Compra N°{$compra_id_a_eliminar} eliminada, stock y deuda revertidos correctamente.'); window.location='./';</script>";
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error de eliminación de ingresos: " . $e->getMessage());
            echo "<script>alert('❌ Error al eliminar la compra: " . $e->getMessage() . "'); window.location='./';</script>";
            exit;
        }
    }
}


// ====================================================================
// 🔹 LÓGICA DE EDICIÓN DE COMPRA (Modificada para proveedores)
// ====================================================================
if (isset($_POST['registrar_edicion']) && isset($conn)) {
    $compra_id_editar    = intval($_POST['compra_id_editar'] ?? 0);
    $nuevo_proveedor_id  = intval($_POST['edit_proveedor_id'] ?? 0);
    $productos_ids       = $_POST['edit_producto_id']  ?? [];
    $cantidades          = $_POST['edit_cantidad']      ?? [];
    $precios_unitarios   = $_POST['edit_precio']        ?? [];
    $descripciones       = $_POST['edit_descripcion']   ?? [];
    $serials             = $_POST['edit_serial']        ?? [];

    // Mapa ID → nombre
    $productos_map = [];
    foreach ($productos_existentes as $prod) {
        $productos_map[$prod['id']] = $prod['nombre'];
    }

    $productos_a_registrar = [];
    $total_general_calculado = 0;

    for ($i = 0; $i < count($productos_ids); $i++) {
        $id       = intval($productos_ids[$i]     ?? 0);
        $cantidad = intval($cantidades[$i]         ?? 0);
        $precio   = floatval($precios_unitarios[$i] ?? 0);
        $nombre   = $productos_map[$id]            ?? '';
        $descripcion = trim($descripciones[$i] ?? '');
        $serial   = trim($serials[$i] ?? '');

        if ($id > 0 && $cantidad > 0 && $precio >= 0 && !empty($nombre)) {
            $total = $cantidad * $precio;
            $productos_a_registrar[] = [
                'id'          => $id,
                'nombre'      => $nombre,
                'cantidad'    => $cantidad,
                'precio'      => $precio,
                'total'       => $total,
                'descripcion' => $descripcion,
                'serial'      => $serial
            ];
            $total_general_calculado += $total;
        }
    }

    if ($compra_id_editar > 0 && count($productos_a_registrar) > 0 && $nuevo_proveedor_id > 0) {
        $conn->begin_transaction();
        try {
            // 1. Obtener información ANTIGUA de la compra (proveedor_id y total)
            $stmt_old_compra = $conn->prepare("SELECT proveedor_id, total_general FROM compras WHERE id = ?");
            $stmt_old_compra->bind_param("i", $compra_id_editar);
            $stmt_old_compra->execute();
            $old_compra_data = $stmt_old_compra->get_result()->fetch_assoc();
            $stmt_old_compra->close();

            $old_proveedor_id = $old_compra_data['proveedor_id'] ?? 0;
            $old_total = $old_compra_data['total_general'] ?? 0;

            // 2. Revertir stock de los ítems ANTERIORES
            $stmt_old = $conn->prepare("SELECT producto_id, cantidad FROM ingresos_mercancia WHERE compra_id = ?");
            $stmt_old->bind_param("i", $compra_id_editar);
            $stmt_old->execute();
            $old_items = $stmt_old->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_old->close();

            $stmt_revert = $conn->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
            foreach ($old_items as $old) {
                $stmt_revert->bind_param("ii", $old['cantidad'], $old['producto_id']);
                if (!$stmt_revert->execute()) {
                    throw new Exception("Error al revertir stock antiguo del producto ID {$old['producto_id']}.");
                }
            }
            $stmt_revert->close();

            // 3. Borrar detalle antiguo
            $stmt_del = $conn->prepare("DELETE FROM ingresos_mercancia WHERE compra_id = ?");
            $stmt_del->bind_param("i", $compra_id_editar);
            if (!$stmt_del->execute()) {
                throw new Exception("Error al borrar detalle antiguo.");
            }
            $stmt_del->close();

            // 4. Insertar nuevo detalle y actualizar stock
            $stmt_ins = $conn->prepare(
                "INSERT INTO ingresos_mercancia (compra_id, producto_id, producto_nombre, cantidad, precio_unitario, total, descripcion, serial, fecha)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt_upd = $conn->prepare("UPDATE productos SET stock = stock + ? WHERE id = ?");

            foreach ($productos_a_registrar as $item) {
                $stmt_ins->bind_param(
                    "iisdddss",
                    $compra_id_editar,
                    $item['id'],
                    $item['nombre'],
                    $item['cantidad'],
                    $item['precio'],
                    $item['total'],
                    $item['descripcion'],
                    $item['serial']
                );
                if (!$stmt_ins->execute()) {
                    throw new Exception("Error al insertar detalle editado: " . $stmt_ins->error);
                }

                $stmt_upd->bind_param("ii", $item['cantidad'], $item['id']);
                if (!$stmt_upd->execute()) {
                    throw new Exception("Error al actualizar stock para ID {$item['id']}.");
                }
            }
            $stmt_ins->close();
            $stmt_upd->close();

            // 5. Actualizar encabezado de compra (proveedor_id y total_general)
            $stmt_head = $conn->prepare("UPDATE compras SET proveedor_id = ?, total_general = ? WHERE id = ?");
            $stmt_head->bind_param("idi", $nuevo_proveedor_id, $total_general_calculado, $compra_id_editar);
            if (!$stmt_head->execute()) {
                throw new Exception("Error al actualizar el encabezado de la compra.");
            }
            $stmt_head->close();

            // 🔄 MODIFICACIÓN PARA PROVEEDORES: Ajustar saldos de deuda
            if ($old_proveedor_id != $nuevo_proveedor_id) {
                if ($old_proveedor_id > 0) {
                    $stmt_ajuste_old = $conn->prepare("UPDATE proveedores SET saldo_deuda = saldo_deuda - ? WHERE id = ?");
                    $stmt_ajuste_old->bind_param("di", $old_total, $old_proveedor_id);
                    if (!$stmt_ajuste_old->execute()) {
                        throw new Exception("Error al ajustar deuda del proveedor antiguo.");
                    }
                    $stmt_ajuste_old->close();
                }
                $stmt_ajuste_new = $conn->prepare("UPDATE proveedores SET saldo_deuda = saldo_deuda + ? WHERE id = ?");
                $stmt_ajuste_new->bind_param("di", $total_general_calculado, $nuevo_proveedor_id);
                if (!$stmt_ajuste_new->execute()) {
                    throw new Exception("Error al ajustar deuda del proveedor nuevo.");
                }
                $stmt_ajuste_new->close();
            } else {
                $diferencia = $total_general_calculado - $old_total;
                if ($diferencia != 0) {
                    $stmt_ajuste = $conn->prepare("UPDATE proveedores SET saldo_deuda = saldo_deuda + ? WHERE id = ?");
                    $stmt_ajuste->bind_param("di", $diferencia, $nuevo_proveedor_id);
                    if (!$stmt_ajuste->execute()) {
                        throw new Exception("Error al ajustar deuda del proveedor.");
                    }
                    $stmt_ajuste->close();
                }
            }

            $conn->commit();

            echo "<script>alert('✅ Compra N°{$compra_id_editar} actualizada. Nuevo total: " . format_currency($total_general_calculado) . "'); window.location='./';</script>";
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error de edición de ingresos: " . $e->getMessage());
            echo "<script>alert('❌ Error al editar la compra: " . $e->getMessage() . "'); window.location='./';</script>";
            exit;
        }
    } else {
        echo "<script>alert('⚠️ Datos inválidos para la edición.'); window.location='./';</script>";
        exit;
    }
}


// ====================================================================
// 🔹 LÓGICA DE DETALLE SIMPLE (MODIFICADO PARA MOSTRAR PROVEEDOR, DESCRIPCIÓN Y SERIAL)
// ====================================================================
$detalle_compra = null;
$detalle_proveedor_info = null;
$compra_id_a_mostrar = isset($_GET['detalle']) ? intval($_GET['detalle']) : 0;

if ($compra_id_a_mostrar > 0 && isset($conn)) {
    $proveedor_query = "
        SELECT 
            c.proveedor_id,
            p.nombre AS proveedor_nombre,
            p.contacto AS proveedor_contacto,
            p.telefono AS proveedor_telefono,
            p.correo AS proveedor_correo,
            c.total_general,
            c.fecha
        FROM compras c
        LEFT JOIN proveedores p ON c.proveedor_id = p.id
        WHERE c.id = ?
    ";

    $stmt_proveedor = $conn->prepare($proveedor_query);
    $stmt_proveedor->bind_param("i", $compra_id_a_mostrar);
    $stmt_proveedor->execute();
    $detalle_proveedor_info = $stmt_proveedor->get_result()->fetch_assoc();
    $stmt_proveedor->close();

    $detalle_query = "
        SELECT producto_nombre, cantidad, precio_unitario, total, descripcion, serial
        FROM ingresos_mercancia
        WHERE compra_id = ?
        ORDER BY id ASC
    ";

    if ($stmt_detalle = $conn->prepare($detalle_query)) {
        $stmt_detalle->bind_param("i", $compra_id_a_mostrar);
        $stmt_detalle->execute();
        $detalle_result = $stmt_detalle->get_result();

        if ($detalle_result->num_rows > 0) {
            $detalle_compra = $detalle_result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt_detalle->close();
    }
}

// ====================================================================
// 🔹 LÓGICA DE PROCESAMIENTO: Registrar Ingreso de Mercancía
// ====================================================================
if (isset($_POST['registrar_ingreso']) && isset($conn)) {

    $proveedor_id = intval($_POST['proveedor_id'] ?? 0);
    $productos_ids = $_POST['producto_id'] ?? [];
    $cantidades = $_POST['cantidad'] ?? [];
    $precios_unitarios = $_POST['precio'] ?? [];
    $descripciones = $_POST['descripcion'] ?? [];
    $serials = $_POST['serial'] ?? [];
    $total_general_calculado = 0;

    $productos_map = [];
    foreach ($productos_existentes as $prod) {
        $productos_map[$prod['id']] = $prod['nombre'];
    }

    $productos_a_registrar = [];
    for ($i = 0; $i < count($productos_ids); $i++) {
        $id = intval($productos_ids[$i] ?? 0);
        $cantidad = intval($cantidades[$i] ?? 0);
        $precio = floatval($precios_unitarios[$i] ?? 0);
        $nombre = $productos_map[$id] ?? '';
        $descripcion = trim($descripciones[$i] ?? '');
        $serial = trim($serials[$i] ?? '');

        if ($id > 0 && $cantidad > 0 && $precio >= 0 && !empty($nombre)) {
            $total = $cantidad * $precio;
            $productos_a_registrar[] = [
                'id' => $id,
                'nombre' => $nombre,
                'cantidad' => $cantidad,
                'precio' => $precio,
                'total' => $total,
                'descripcion' => $descripcion,
                'serial' => $serial
            ];
            $total_general_calculado += $total;
        }
    }

    if (count($productos_a_registrar) > 0 && $proveedor_id > 0) {

        $conn->begin_transaction();

        try {

            $stmt_insert_compra = $conn->prepare("INSERT INTO compras (proveedor_id, total_general, fecha) VALUES (?, ?, NOW())");
            $stmt_insert_compra->bind_param("id", $proveedor_id, $total_general_calculado);
            if (!$stmt_insert_compra->execute()) {
                throw new Exception("Error al crear encabezado de compra: " . $stmt_insert_compra->error);
            }
            $compra_id = $conn->insert_id;
            $stmt_insert_compra->close();

            $stmt_insert_ingreso = $conn->prepare("INSERT INTO ingresos_mercancia (compra_id, producto_id, producto_nombre, cantidad, precio_unitario, total, descripcion, serial, fecha)
                                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt_update_stock = $conn->prepare("UPDATE productos SET stock = stock + ? WHERE id = ?");

            foreach ($productos_a_registrar as $item) {

                $producto_id = $item['id'];
                $nombre = $item['nombre'];
                $cantidad = $item['cantidad'];
                $precio = $item['precio'];
                $total = $item['total'];
                $descripcion = $item['descripcion'];
                $serial = $item['serial'];

                $stmt_insert_ingreso->bind_param("iisdddss", $compra_id, $producto_id, $nombre, $cantidad, $precio, $total, $descripcion, $serial);
                if (!$stmt_insert_ingreso->execute()) {
                    throw new Exception("Error al insertar detalle de ingreso: " . $stmt_insert_ingreso->error);
                }

                $stmt_update_stock->bind_param("ii", $cantidad, $producto_id);
                if (!$stmt_update_stock->execute()) {
                    throw new Exception("Error al actualizar stock para ID {$producto_id}.");
                }
            }

            $stmt_insert_ingreso->close();
            $stmt_update_stock->close();

            $stmt_update_deuda = $conn->prepare("UPDATE proveedores SET saldo_deuda = saldo_deuda + ? WHERE id = ?");
            $stmt_update_deuda->bind_param("di", $total_general_calculado, $proveedor_id);
            if (!$stmt_update_deuda->execute()) {
                throw new Exception("Error al actualizar la deuda del proveedor.");
            }
            $stmt_update_deuda->close();

            $conn->commit();

            echo "<script>alert('✅ Ingreso N°{$compra_id} registrado. Total: " . format_currency($total_general_calculado) . "'); window.location='./';</script>";
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error de registro de ingresos: " . $e->getMessage());
            echo "<script>alert('❌ Error al registrar los ingresos: " . $e->getMessage() . "'); window.location='./';</script>";
            exit;
        }
    } else {
        echo "<script>alert('⚠️ No hay productos válidos para registrar o no seleccionaste un proveedor.'); window.location='./';</script>";
        exit;
    }
}

// ====================================================================
// 🔹 CONSULTA DE DATOS CON PAGINACIÓN Y FILTRO
// ====================================================================
$limit = 10;
$current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$where_clause = "";
$bind_types = "";
$bind_params = [];

if (!empty($search_query)) {
    $where_clause = " WHERE c.id = ? OR c.fecha LIKE ? OR p.nombre LIKE ?";
    $bind_types = "iss";
    $bind_params = [$search_query, "%{$search_query}%", "%{$search_query}%"];
}

$total_records = 0;
$total_pages = 0;
$historial_result = null;

if (isset($conn)) {
    $count_query = "SELECT COUNT(c.id) AS total FROM compras c LEFT JOIN proveedores p ON c.proveedor_id = p.id" . $where_clause;

    $stmt_count = $conn->prepare($count_query);
    if (!empty($bind_params)) {
        $stmt_count->bind_param($bind_types, ...$bind_params);
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt_count->close();

    $total_pages = ceil($total_records / $limit);

    if ($current_page < 1) {
        $current_page = 1;
    } elseif ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
    }
    $offset = ($current_page - 1) * $limit;

    $historial_query = "
    SELECT 
        c.id, 
        c.fecha, 
        c.total_general, 
        c.proveedor_id,
        p.nombre AS proveedor_nombre,
        p.contacto AS proveedor_contacto,
        p.telefono AS proveedor_telefono,
        (SELECT GROUP_CONCAT(DISTINCT descripcion SEPARATOR ' | ') 
         FROM ingresos_mercancia 
         WHERE compra_id = c.id 
         LIMIT 1) AS primera_descripcion,
        (SELECT GROUP_CONCAT(DISTINCT serial SEPARATOR ' | ') 
         FROM ingresos_mercancia 
         WHERE compra_id = c.id 
         LIMIT 1) AS primer_serial
    FROM compras c
    LEFT JOIN proveedores p ON c.proveedor_id = p.id
    " . $where_clause . "
    ORDER BY c.fecha DESC
    LIMIT ? OFFSET ?
";

    $stmt_historial = $conn->prepare($historial_query);

    $final_bind_types = $bind_types . "ii";
    $final_bind_params = array_merge($bind_params, [$limit, $offset]);

    if (!empty($final_bind_params)) {
        $stmt_historial->bind_param($final_bind_types, ...$final_bind_params);
    }

    $stmt_historial->execute();
    $historial_result = $stmt_historial->get_result();
    $stmt_historial->close();
}

// Función auxiliar para construir enlaces de paginación/detalle
function build_url($page = null, $detail = null, $search = null)
{
    $params = $_GET;
    unset($params['eliminar']);

    if ($page !== null) $params['p'] = $page;
    else unset($params['p']);
    if ($detail !== null) $params['detalle'] = $detail;
    else unset($params['detalle']);
    if ($search !== null) $params['q'] = $search;
    else unset($params['q']);

    $params['page'] = 'ingresos';

    return '?' . http_build_query($params);
}

// ====================================================================
// 🔹 FUNCIÓN AUXILIAR PARA GENERAR EL SELECT DE PRODUCTOS (PHP)
// ====================================================================
function generate_product_select($products)
{
    $options = '<select name="producto_id[]" class="w-full p-2 border border-gray-300 rounded producto-select" required>';
    $options .= '<option value="">-- Seleccione un Producto --</option>';
    foreach ($products as $prod) {
        $options .= '<option value="' . $prod['id'] . '">' . htmlspecialchars($prod['nombre']) . '</option>';
    }
    $options .= '</select>';
    return $options;
}

?>

<script src="../assets/vendor/tailwind/tailwind.min.js"></script>
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
<script src="../assets/vendor/chartjs/chart.umd.min.js"></script>

<?php luxury_render_nav_start('ingresos'); ?>
<div class="bg-white rounded-lg shadow p-6 space-y-6">
    <h2 class="text-xl font-bold text-blue-700 border-b pb-2 mb-4">Registro Rápido de Entrada</h2>
    <?php if (empty($productos_existentes)): ?>
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4" role="alert">
            <p class="font-bold">⚠️ Atención: No hay productos registrados</p>
            <p>Para ingresar mercancía, debes tener productos existentes en la tabla `productos`.</p>
        </div>
    <?php endif; ?>

    <form method="POST" id="formIngresos">
        <?= csrf_field() ?>
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-700 mb-2">Proveedor de esta compra:</label>
            <select name="proveedor_id" class="w-full p-2 border rounded border-blue-300" required>
                <option value="">-- Seleccione Proveedor --</option>
                <?php
                foreach ($proveedores as $p) {
                    echo "<option value='{$p['id']}'>" . htmlspecialchars($p['nombre']) . "</option>";
                }
                ?>
            </select>
        </div>

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-cubes text-blue-500"></i>
                <span>Detalle de Productos a Ingresar</span>
            </h3>
            <button type="button" id="btnAgregarFila" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded flex items-center space-x-2"
                <?= empty($productos_existentes) ? 'disabled title="Debe haber productos para agregar"' : '' ?>>
                <i class="fas fa-plus"></i>
                <span>Agregar producto</span>
            </button>
        </div>

        <div class="overflow-x-auto mb-6">
            <table id="tablaIngresos" class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100 text-gray-700 text-left">
                        <th class="px-4 py-2 border-b w-10">#</th>
                        <th class="px-4 py-2 border-b">Producto</th>
                        <th class="px-4 py-2 border-b text-center w-32">Cantidad</th>
                        <th class="px-4 py-2 border-b text-center w-32">Precio Unitario</th>
                        <th class="px-4 py-2 border-b">Descripción</th>
                        <th class="px-4 py-2 border-b">Serial / Código</th>
                        <th class="px-4 py-2 border-b text-center w-32">Total</th>
                        <th class="px-4 py-2 border-b text-center w-20">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <tr data-row-id="1">
                        <td class="px-4 py-2 border-b text-gray-500">1</td>
                        <td class="px-4 py-2 border-b">
                            <?= generate_product_select($productos_existentes) ?>
                        </td>
                        <td class="px-4 py-2 border-b text-center">
                            <input type="number" name="cantidad[]" value="1" min="1" class="w-20 p-2 border border-gray-300 rounded text-right cantidad" required>
                        </td>
                        <td class="px-4 py-2 border-b text-center">
                            <input type="number" name="precio[]" value="0" min="0" step="any" class="w-28 p-2 border border-gray-300 rounded text-right precio" required>
                        </td>
                        <td class="px-4 py-2 border-b">
                            <input type="text" name="descripcion[]" placeholder="Descripción del producto" class="w-full p-2 border border-gray-300 rounded">
                        </td>
                        <td class="px-4 py-2 border-b">
                            <input type="text" name="serial[]" placeholder="Serial / Código único" class="w-full p-2 border border-gray-300 rounded">
                        </td>
                        <td class="px-4 py-2 border-b text-center font-semibold text-gray-700 total"><?= format_currency(0) ?></td>
                        <td class="px-4 py-2 border-b text-center">
                            <button type="button" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded eliminarFila" title="Eliminar fila">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex justify-end">
            <div class="text-right">
                <p class="text-lg font-semibold text-gray-700">
                    Total General: <span id="totalGeneral" class="text-blue-600"><?= format_currency(0) ?></span>
                </p>
                <button type="submit" name="registrar_ingreso" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 mt-3 rounded flex items-center space-x-2"
                    <?= empty($productos_existentes) ? 'disabled' : '' ?>>
                    <i class="fas fa-save"></i>
                    <span>Registrar ingreso</span>
                </button>
            </div>
        </div>
    </form>
</div>
<hr>

<div class="bg-white rounded-lg shadow p-6 space-y-6 mt-6">
    <h2 class="text-xl font-bold text-gray-800 flex items-center space-x-2 border-b pb-2 mb-4">
        <i class="fas fa-chart-line text-purple-500"></i>
        <span>Dashboard de Compras</span>
    </h2>

    <!-- Tarjeta de resumen general -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-700 rounded-lg shadow-lg p-6 mb-6 text-white">
        <div class="flex justify-between items-start mb-4">
            <h3 class="text-xl font-bold flex items-center">
                <i class="fas fa-chart-line mr-2"></i>
                Resumen General de Compras
            </h3>
            <div class="bg-white/20 rounded-lg px-3 py-1 text-sm">
                <i class="fas fa-sync-alt mr-1"></i> Actualizado en tiempo real
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">Total Invertido</p>
                        <p class="text-2xl font-bold"><?= format_currency($total_general_todos_tiempos) ?></p>
                        <p class="text-xs opacity-75 mt-1">En todos los tiempos</p>
                    </div>
                    <i class="fas fa-dollar-sign text-3xl opacity-50"></i>
                </div>
            </div>

            <div class="bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">Compras Registradas</p>
                        <p class="text-2xl font-bold"><?= number_format($total_compras, 0) ?></p>
                        <p class="text-xs opacity-75 mt-1">Total de órdenes de compra</p>
                    </div>
                    <i class="fas fa-shopping-cart text-3xl opacity-50"></i>
                </div>
            </div>

            <div class="bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">Proveedores Activos</p>
                        <p class="text-2xl font-bold"><?= number_format($total_proveedores, 0) ?></p>
                        <p class="text-xs opacity-75 mt-1">En el sistema</p>
                    </div>
                    <i class="fas fa-truck text-3xl opacity-50"></i>
                </div>
            </div>

            <div class="bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">Compras Este Mes</p>
                        <p class="text-2xl font-bold"><?= format_currency($total_mes_actual) ?></p>
                        <p class="text-xs opacity-75 mt-1"><?= strftime('%B %Y') ?></p>
                    </div>
                    <i class="fas fa-calendar-alt text-3xl opacity-50"></i>
                </div>
            </div>
        </div>

        <?php if ($total_general_todos_tiempos > 0): ?>
            <div class="mt-4 pt-3 border-t border-white/20">
                <div class="flex justify-between text-sm mb-1">
                    <span>Meta anual estimada</span>
                    <span><?= format_currency($total_general_todos_tiempos) ?> / <?= format_currency($total_general_todos_tiempos * 2) ?></span>
                </div>
                <div class="w-full bg-white/20 rounded-full h-2">
                    <div class="bg-yellow-400 h-2 rounded-full" style="width: <?= min(100, ($total_general_todos_tiempos / ($total_general_todos_tiempos * 2)) * 100) ?>%"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-clipboard-list text-purple-500"></i>
            <span>Historial de Compras (Límite: <?= $limit ?> por página)</span>
        </h2>
        <?php if ($total_general_todos_tiempos > 0): ?>
            <div class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm">
                <i class="fas fa-chart-simple"></i> Total acumulado: <?= format_currency($total_general_todos_tiempos) ?>
            </div>
        <?php endif; ?>
    </div>

    <form method="GET" class="mb-4 flex space-x-3 items-center">
        <input type="hidden" name="page" value="ingresos">
        <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>"
            placeholder="Buscar por ID, Fecha o Proveedor..."
            class="p-2 border border-gray-300 rounded-md w-full max-w-xs focus:ring-purple-500 focus:border-purple-500">
        <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md flex items-center space-x-1">
            <i class="fas fa-search"></i>
            <span>Buscar</span>
        </button>
        <?php if (!empty($search_query)): ?>
            <a href="<?= build_url(1, null, '') ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-md flex items-center space-x-1">
                <i class="fas fa-times"></i>
                <span>Limpiar</span>
            </a>
        <?php endif; ?>
    </form>

    <div class="overflow-x-auto">
        <?php if (!isset($conn)): ?>
            <div class="text-center py-4 text-red-600 bg-red-100 border border-red-400 rounded-md">
                ❌ Error: Conexión a la base de datos no definida.
            </div>
        <?php elseif ($historial_result && $historial_result->num_rows > 0): ?>
            <table class="w-full table-auto border-collapse">
                <thead>
                    <tr class="bg-gray-100 text-gray-700 text-left">
                        <th class="px-4 py-2 border-b w-20">ID Compra</th>
                        <th class="px-4 py-2 border-b">Fecha</th>
                        <th class="px-4 py-2 border-b">Proveedor</th>
                        <th class="px-4 py-2 border-b">Descripción</th> <!-- NUEVA -->
                        <th class="px-4 py-2 border-b">Serial</th> <!-- NUEVA -->
                        <th class="px-4 py-2 border-b text-right w-40">Total General</th>
                        <th class="px-4 py-2 border-b text-center w-40">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($h = $historial_result->fetch_assoc()):
                        $is_open = ($compra_id_a_mostrar == $h['id']);
                    ?>
                        <tr class="hover:bg-gray-50 <?= $is_open ? 'bg-indigo-50 font-bold' : '' ?>">
                            <td class="px-4 py-2 border-b text-gray-500 font-semibold"><?= $h['id'] ?></td>
                            <td class="px-4 py-2 border-b"><?= date('Y-m-d H:i', strtotime($h['fecha'])) ?></td>
                            <td class="px-4 py-2 border-b">
                                <?php if ($h['proveedor_nombre']): ?>
                                    <div class="flex items-center space-x-2">
                                        <i class="fas fa-truck text-purple-500"></i>
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($h['proveedor_nombre']) ?></span>
                                        <?php if ($h['proveedor_contacto']): ?>
                                            <span class="text-xs text-gray-500">(<?= htmlspecialchars($h['proveedor_contacto']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm">Sin proveedor asignado</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-2 border-b">
                                <?php if (!empty($h['primera_descripcion'])): ?>
                                    <span class="text-sm text-gray-700"><?= htmlspecialchars(substr($h['primera_descripcion'], 0, 50)) . (strlen($h['primera_descripcion']) > 50 ? '...' : '') ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">Sin descripción</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 border-b">
                                <?php if (!empty($h['primer_serial'])): ?>
                                    <span class="text-xs font-mono text-gray-600"><?= htmlspecialchars($h['primer_serial']) ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-2 border-b text-right font-bold text-green-700"><?= format_currency($h['total_general']) ?></td>
                            <td class="px-4 py-2 border-b text-center space-x-2 flex justify-center items-center">
                                <?php if ($is_open): ?>
                                    <a href="<?= build_url($current_page, null, $search_query) ?>"
                                        class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded text-sm" title="Ocultar Detalle">
                                        <i class="fas fa-eye-slash"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= build_url($current_page, $h['id'], $search_query) ?>"
                                        class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded text-sm" title="Ver Detalle de Productos">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                <?php endif; ?>

                                <button onclick="openEditModal(<?= $h['id'] ?>, <?= $h['proveedor_id'] ?? 0 ?>)"
                                    class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded text-sm" title="Editar Compra">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>

                                <button onclick="confirmDeletion(<?= $h['id'] ?>)"
                                    class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm" title="Eliminar Compra">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>

                        <?php if ($is_open): ?>
                            <tr class="bg-indigo-50">
                                <td colspan="5" class="p-0 border-b">
                                    <div class="p-4 bg-indigo-100/50 border-l-4 border-indigo-400">
                                        <?php if ($detalle_proveedor_info && $detalle_proveedor_info['proveedor_nombre']): ?>
                                            <div class="mb-4 pb-3 border-b border-indigo-200">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center space-x-3">
                                                        <i class="fas fa-truck text-indigo-600 text-xl"></i>
                                                        <div>
                                                            <h5 class="font-bold text-indigo-800">Proveedor: <?= htmlspecialchars($detalle_proveedor_info['proveedor_nombre']) ?></h5>
                                                            <?php if ($detalle_proveedor_info['proveedor_contacto']): ?>
                                                                <p class="text-sm text-indigo-600">Contacto: <?= htmlspecialchars($detalle_proveedor_info['proveedor_contacto']) ?></p>
                                                            <?php endif; ?>
                                                            <div class="flex space-x-3 mt-1">
                                                                <?php if ($detalle_proveedor_info['proveedor_telefono']): ?>
                                                                    <a href="tel:<?= htmlspecialchars($detalle_proveedor_info['proveedor_telefono']) ?>"
                                                                        class="text-xs text-indigo-500 hover:text-indigo-700">
                                                                        <i class="fas fa-phone"></i> <?= htmlspecialchars($detalle_proveedor_info['proveedor_telefono']) ?>
                                                                    </a>
                                                                <?php endif; ?>
                                                                <?php if ($detalle_proveedor_info['proveedor_correo']): ?>
                                                                    <a href="mailto:<?= htmlspecialchars($detalle_proveedor_info['proveedor_correo']) ?>"
                                                                        class="text-xs text-indigo-500 hover:text-indigo-700">
                                                                        <i class="fas fa-envelope"></i> <?= htmlspecialchars($detalle_proveedor_info['proveedor_correo']) ?>
                                                                    </a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <p class="text-sm text-gray-600">Fecha de ingreso</p>
                                                        <p class="font-semibold text-indigo-800"><?= date('d/m/Y H:i', strtotime($detalle_proveedor_info['fecha'])) ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="mb-4 pb-3 border-b border-indigo-200 text-yellow-700">
                                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                                Esta compra no tiene un proveedor asignado.
                                            </div>
                                        <?php endif; ?>

                                        <h4 class="font-bold text-indigo-700 mb-2">Productos Ingresados (Detalle de Compra #<?= $h['id'] ?>)</h4>
                                        <?php if ($detalle_compra): ?>
                                            <div class="overflow-x-auto">
                                                <table class="min-w-full divide-y divide-indigo-200">
                                                    <thead>
                                                        <tr class="text-xs text-indigo-700">
                                                            <th class="px-2 py-1 text-left">Producto</th>
                                                            <th class="px-2 py-1 text-center">Cantidad</th>
                                                            <th class="px-2 py-1 text-right">P/Unitario</th>
                                                            <th class="px-2 py-1 text-left">Descripción</th>
                                                            <th class="px-2 py-1 text-left">Serial</th>
                                                            <th class="px-2 py-1 text-right">Subtotal</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-indigo-200 text-sm">
                                                        <?php foreach ($detalle_compra as $item): ?>
                                                            <tr>
                                                                <td class="px-2 py-1"><?= htmlspecialchars($item['producto_nombre']) ?></td>
                                                                <td class="px-2 py-1 text-center"><?= $item['cantidad'] ?></td>
                                                                <td class="px-2 py-1 text-right"><?= format_currency($item['precio_unitario']) ?></td>
                                                                <td class="px-2 py-1"><?= htmlspecialchars($item['descripcion'] ?? '-') ?></td>
                                                                <td class="px-2 py-1"><?= htmlspecialchars($item['serial'] ?? '-') ?></td>
                                                                <td class="px-2 py-1 text-right font-semibold"><?= format_currency($item['total']) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="text-right mt-2 pt-2 border-t border-indigo-200">
                                                <span class="font-bold text-lg text-indigo-800">Total General: <?= format_currency($h['total_general']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div class="p-4 bg-red-100/50 border-l-4 border-red-400 text-red-700 text-center">
                                                Error: No se encontró el detalle de los productos para la compra #<?= $h['id'] ?>.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="text-center py-4 text-gray-500">
                <?php if (!empty($search_query)): ?>
                    No se encontraron compras que coincidan con "<?= htmlspecialchars($search_query) ?>".
                <?php else: ?>
                    No hay compras registradas todavía.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row justify-between items-center mt-4 pt-4 border-t border-gray-200 gap-3">
            <p class="text-xs text-gray-400">
                Página <strong class="text-gray-600"><?= $current_page ?></strong> de
                <strong class="text-gray-600"><?= $total_pages ?></strong>
                · <?= $total_records ?> compras en total
            </p>
            <div class="flex items-center gap-1 flex-wrap justify-center">
                <?php if ($current_page > 1): ?>
                    <a href="<?= build_url(1, null, $search_query) ?>"
                        class="px-2 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-500 hover:bg-gray-100 transition-colors" title="Primera">
                        <i class="fas fa-angles-left"></i>
                    </a>
                    <a href="<?= build_url($current_page - 1, null, $search_query) ?>"
                        class="px-2 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-500 hover:bg-gray-100 transition-colors" title="Anterior">
                        <i class="fas fa-angle-left"></i>
                    </a>
                <?php else: ?>
                    <span class="px-2 py-1.5 rounded-lg border border-gray-100 text-xs text-gray-300 cursor-not-allowed"><i class="fas fa-angles-left"></i></span>
                    <span class="px-2 py-1.5 rounded-lg border border-gray-100 text-xs text-gray-300 cursor-not-allowed"><i class="fas fa-angle-left"></i></span>
                <?php endif; ?>

                <?php
                $rango = 2;
                $inicio = max(1, $current_page - $rango);
                $fin    = min($total_pages, $current_page + $rango);
                if ($inicio > 1): ?>
                    <a href="<?= build_url(1, null, $search_query) ?>"
                        class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-100 transition-colors">1</a>
                    <?php if ($inicio > 2): ?>
                        <span class="px-2 py-1.5 text-xs text-gray-400">…</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $inicio; $p <= $fin; $p++): ?>
                    <?php if ($p === $current_page): ?>
                        <span class="px-3 py-1.5 rounded-lg bg-gray-900 text-white text-xs font-bold border border-gray-900"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= build_url($p, null, $search_query) ?>"
                            class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-100 transition-colors"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($fin < $total_pages): ?>
                    <?php if ($fin < $total_pages - 1): ?>
                        <span class="px-2 py-1.5 text-xs text-gray-400">…</span>
                    <?php endif; ?>
                    <a href="<?= build_url($total_pages, null, $search_query) ?>"
                        class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-100 transition-colors"><?= $total_pages ?></a>
                <?php endif; ?>

                <?php if ($current_page < $total_pages): ?>
                    <a href="<?= build_url($current_page + 1, null, $search_query) ?>"
                        class="px-2 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-500 hover:bg-gray-100 transition-colors" title="Siguiente">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="<?= build_url($total_pages, null, $search_query) ?>"
                        class="px-2 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-500 hover:bg-gray-100 transition-colors" title="Última">
                        <i class="fas fa-angles-right"></i>
                    </a>
                <?php else: ?>
                    <span class="px-2 py-1.5 rounded-lg border border-gray-100 text-xs text-gray-300 cursor-not-allowed"><i class="fas fa-angle-right"></i></span>
                    <span class="px-2 py-1.5 rounded-lg border border-gray-100 text-xs text-gray-300 cursor-not-allowed"><i class="fas fa-angles-right"></i></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL DE EDICIÓN DE COMPRA -->
<div id="editModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl mx-4 max-h-screen overflow-y-auto">
        <div class="flex justify-between items-center px-6 py-4 border-b sticky top-0 bg-white z-10">
            <h3 class="text-lg font-bold text-yellow-700 flex items-center space-x-2">
                <i class="fas fa-pencil-alt text-yellow-500"></i>
                <span>Editar Compra N°<span id="editModalTitle">-</span></span>
            </h3>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-4">
            <div id="editLoadingMsg" class="text-center py-6 text-gray-500">
                <i class="fas fa-spinner fa-spin mr-2"></i> Cargando detalle...
            </div>
            <form method="POST" id="formEdicion" class="hidden">
                <?= csrf_field() ?>
                <input type="hidden" name="compra_id_editar" id="editCompraId">
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">Proveedor de esta compra:</label>
                    <select name="edit_proveedor_id" id="editProveedorId" class="w-full p-2 border rounded border-yellow-300" required>
                        <option value="">-- Seleccione Proveedor --</option>
                        <?php foreach ($proveedores as $p) {
                            echo "<option value='{$p['id']}'>" . htmlspecialchars($p['nombre']) . "</option>";
                        } ?>
                    </select>
                </div>
                <div class="flex justify-between items-center mb-3">
                    <span class="text-sm font-semibold text-gray-700">Productos de la compra</span>
                    <button type="button" id="btnAgregarFilaEdit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm flex items-center space-x-1">
                        <i class="fas fa-plus"></i> <span>Agregar fila</span>
                    </button>
                </div>
                <div class="overflow-x-auto mb-4">
                    <table class="w-full border-collapse" id="tablaEdicion">
                        <thead>
                            <tr class="bg-gray-100 text-gray-700 text-left text-sm">
                                <th class="px-3 py-2 border-b w-8">#</th>
                                <th class="px-3 py-2 border-b">Producto</th>
                                <th class="px-3 py-2 border-b text-center w-24">Cantidad</th>
                                <th class="px-3 py-2 border-b text-center w-28">Precio Unit.</th>
                                <th class="px-3 py-2 border-b">Descripción</th>
                                <th class="px-3 py-2 border-b">Serial</th>
                                <th class="px-3 py-2 border-b text-center w-28">Total</th>
                                <th class="px-3 py-2 border-b text-center w-16">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="tablaEdicionBody"></tbody>
                    </table>
                </div>
                <div class="flex justify-between items-center pt-3 border-t">
                    <p class="text-base font-semibold text-gray-700">Total: <span id="editTotalGeneral" class="text-yellow-600 font-bold">$0,00</span></p>
                    <div class="flex space-x-2">
                        <button type="button" onclick="closeEditModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded text-sm">Cancelar</button>
                        <button type="submit" name="registrar_edicion" class="bg-yellow-500 hover:bg-yellow-600 text-white px-5 py-2 rounded text-sm flex items-center space-x-1">
                            <i class="fas fa-save"></i> <span>Guardar cambios</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const PRODUCTOS_DISPONIBLES = <?= json_encode($productos_existentes); ?>;
    const PROVEEDORES_DISPONIBLES = <?= json_encode($proveedores); ?>;

    function generateProductSelectHtml() {
        let options = '<select name="producto_id[]" class="w-full p-2 border border-gray-300 rounded producto-select" required>';
        options += '<option value="">-- Seleccione un Producto --</option>';
        PRODUCTOS_DISPONIBLES.forEach(prod => {
            options += `<option value="${prod.id}">${prod.nombre}</option>`;
        });
        options += '</select>';
        return options;
    }

    const tablaIngresos = document.getElementById('tablaIngresos').querySelector('tbody');
    const btnAgregarFila = document.getElementById('btnAgregarFila');

    const formatCurrency = (number) => {
        const num = parseFloat(number) || 0;
        return num.toLocaleString('es-CO', {
            style: 'currency',
            currency: 'COP',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    function actualizarTotales() {
        let totalGeneral = 0;
        let filaNum = 1;
        tablaIngresos.querySelectorAll('tr').forEach(fila => {
            fila.querySelector('td:first-child').textContent = filaNum++;
            const cantidad = parseFloat(fila.querySelector('.cantidad').value) || 0;
            const precio = parseFloat(fila.querySelector('.precio').value) || 0;
            const total = cantidad * precio;
            fila.querySelector('.total').textContent = formatCurrency(total);
            totalGeneral += total;
        });
        document.getElementById('totalGeneral').textContent = formatCurrency(totalGeneral);
    }

    btnAgregarFila.addEventListener('click', () => {
        if (PRODUCTOS_DISPONIBLES.length === 0) {
            alert('No hay productos disponibles para agregar.');
            return;
        }
        const filas = tablaIngresos.querySelectorAll('tr').length;
        const nuevaFila = document.createElement('tr');
        nuevaFila.innerHTML = `
            <td class="px-4 py-2 border-b text-gray-500">${filas + 1}</td>
            <td class="px-4 py-2 border-b">${generateProductSelectHtml()}</td>
            <td class="px-4 py-2 border-b text-center"><input type="number" name="cantidad[]" value="1" min="1" class="w-20 p-2 border border-gray-300 rounded text-right cantidad" required></td>
            <td class="px-4 py-2 border-b text-center"><input type="number" name="precio[]" value="0" min="0" step="any" class="w-28 p-2 border border-gray-300 rounded text-right precio" required></td>
            <td class="px-4 py-2 border-b"><input type="text" name="descripcion[]" placeholder="Descripción" class="w-full p-2 border border-gray-300 rounded"></td>
            <td class="px-4 py-2 border-b"><input type="text" name="serial[]" placeholder="Serial" class="w-full p-2 border border-gray-300 rounded"></td>
            <td class="px-4 py-2 border-b text-center font-semibold text-gray-700 total">${formatCurrency(0)}</td>
            <td class="px-4 py-2 border-b text-center"><button type="button" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded eliminarFila"><i class="fas fa-trash"></i></button></td>
        `;
        tablaIngresos.appendChild(nuevaFila);
        actualizarTotales();
    });

    document.addEventListener('click', e => {
        if (e.target.closest('.eliminarFila')) {
            const fila = e.target.closest('tr');
            const tbody = fila.closest('tbody');
            if (tbody.querySelectorAll('tr').length > 1) {
                fila.remove();
                actualizarTotales();
            } else {
                alert('Debe haber al menos una fila de producto.');
            }
        }
    });

    document.addEventListener('input', e => {
        if (e.target.classList.contains('cantidad') || e.target.classList.contains('precio')) {
            if (e.target.closest('#formEdicion')) {
                actualizarTotalesEdicion();
            } else {
                actualizarTotales();
            }
        }
    });

    function confirmDeletion(compraId) {
        if (confirm(`¿Estás seguro de que deseas ELIMINAR la Compra N°${compraId}? Esta acción es irreversible y revertirá el stock de los productos y la deuda del proveedor.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="_csrf" value="' + '<?= csrf_token() ?>' + '">' +
                '<input type="hidden" name="eliminar" value="' + compraId + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }

    actualizarTotales();

    // Funciones para el modal de edición
    function generateEditProductSelectHtml(selectedId = '') {
        let options = `<select name="edit_producto_id[]" class="w-full p-2 border border-gray-300 rounded producto-select-edit text-sm" required>`;
        options += '<option value="">-- Seleccione --</option>';
        PRODUCTOS_DISPONIBLES.forEach(prod => {
            const sel = (prod.id == selectedId) ? 'selected' : '';
            options += `<option value="${prod.id}" ${sel}>${prod.nombre}</option>`;
        });
        options += '</select>';
        return options;
    }

    function actualizarTotalesEdicion() {
        const tbody = document.getElementById('tablaEdicionBody');
        let totalGeneral = 0;
        let num = 1;
        tbody.querySelectorAll('tr').forEach(fila => {
            fila.querySelector('td:first-child').textContent = num++;
            const cantidad = parseFloat(fila.querySelector('.edit-cantidad').value) || 0;
            const precio = parseFloat(fila.querySelector('.edit-precio').value) || 0;
            const total = cantidad * precio;
            fila.querySelector('.edit-total').textContent = formatCurrency(total);
            totalGeneral += total;
        });
        document.getElementById('editTotalGeneral').textContent = formatCurrency(totalGeneral);
    }

    document.getElementById('btnAgregarFilaEdit').addEventListener('click', () => {
        const tbody = document.getElementById('tablaEdicionBody');
        const num = tbody.querySelectorAll('tr').length + 1;
        const fila = document.createElement('tr');
        fila.innerHTML = `
            <td class="px-3 py-2 border-b text-gray-500 text-sm">${num}</td>
            <td class="px-3 py-2 border-b">${generateEditProductSelectHtml()}</td>
            <td class="px-3 py-2 border-b text-center"><input type="number" name="edit_cantidad[]" value="1" min="1" step="1" class="w-20 p-1.5 border border-gray-300 rounded text-right text-sm edit-cantidad" required></td>
            <td class="px-3 py-2 border-b text-center"><input type="number" name="edit_precio[]" value="0" min="0" step="any" class="w-24 p-1.5 border border-gray-300 rounded text-right text-sm edit-precio" required></td>
            <td class="px-3 py-2 border-b"><input type="text" name="edit_descripcion[]" placeholder="Descripción" class="w-full p-1.5 border border-gray-300 rounded text-sm"></td>
            <td class="px-3 py-2 border-b"><input type="text" name="edit_serial[]" placeholder="Serial" class="w-full p-1.5 border border-gray-300 rounded text-sm"></td>
            <td class="px-3 py-2 border-b text-center text-sm font-semibold text-gray-700 edit-total">${formatCurrency(0)}</td>
            <td class="px-3 py-2 border-b text-center"><button type="button" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs eliminarFila"><i class="fas fa-trash"></i></button></td>
        `;
        tbody.appendChild(fila);
        actualizarTotalesEdicion();
    });

    function openEditModal(compraId, proveedorId = 0) {
        document.getElementById('editModal').classList.remove('hidden');
        document.getElementById('editModalTitle').textContent = compraId;
        document.getElementById('editCompraId').value = compraId;
        const editProveedorSelect = document.getElementById('editProveedorId');
        if (editProveedorSelect) editProveedorSelect.value = proveedorId;
        document.getElementById('editLoadingMsg').classList.remove('hidden');
        document.getElementById('formEdicion').classList.add('hidden');
        document.getElementById('tablaEdicionBody').innerHTML = '';
        const params = new URLSearchParams(window.location.search);
        params.set('detalle', compraId);
        params.set('page', 'ingresos');
        fetch('?' + params.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const detailRows = doc.querySelectorAll(`.bg-indigo-100\\/50 tbody tr`);
                const tbody = document.getElementById('tablaEdicionBody');
                if (detailRows.length === 0) {
                    fetchDetalleJSON(compraId);
                    return;
                }
                detailRows.forEach((row, idx) => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length < 6) return;
                    const nombreTexto = cells[0].textContent.trim();
                    const cantidad = cells[1].textContent.trim();
                    const precioTexto = cells[2].textContent.trim();
                    const descripcion = cells[3]?.textContent.trim() || '';
                    const serial = cells[4]?.textContent.trim() || '';
                    const prod = PRODUCTOS_DISPONIBLES.find(p => p.nombre === nombreTexto) || {};
                    const prodId = prod.id || '';
                    const precioLimpio = precioTexto.replace(/[^0-9,\.]/g, '').replace(/\./g, '').replace(',', '.');
                    const fila = document.createElement('tr');
                    fila.innerHTML = `
                        <td class="px-3 py-2 border-b text-gray-500 text-sm">${idx + 1}</td>
                        <td class="px-3 py-2 border-b">${generateEditProductSelectHtml(prodId)}</td>
                        <td class="px-3 py-2 border-b text-center"><input type="number" name="edit_cantidad[]" value="${cantidad}" min="1" step="1" class="w-20 p-1.5 border border-gray-300 rounded text-right text-sm edit-cantidad" required></td>
                        <td class="px-3 py-2 border-b text-center"><input type="number" name="edit_precio[]" value="${precioLimpio}" min="0" step="any" class="w-24 p-1.5 border border-gray-300 rounded text-right text-sm edit-precio" required></td>
                        <td class="px-3 py-2 border-b"><input type="text" name="edit_descripcion[]" value="${descripcion.replace(/'/g, "\\'")}" placeholder="Descripción" class="w-full p-1.5 border border-gray-300 rounded text-sm"></td>
                        <td class="px-3 py-2 border-b"><input type="text" name="edit_serial[]" value="${serial.replace(/'/g, "\\'")}" placeholder="Serial" class="w-full p-1.5 border border-gray-300 rounded text-sm"></td>
                        <td class="px-3 py-2 border-b text-center text-sm font-semibold text-gray-700 edit-total">${formatCurrency(0)}</td>
                        <td class="px-3 py-2 border-b text-center"><button type="button" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs eliminarFila"><i class="fas fa-trash"></i></button></td>
                    `;
                    tbody.appendChild(fila);
                });
                document.getElementById('editLoadingMsg').classList.add('hidden');
                document.getElementById('formEdicion').classList.remove('hidden');
                actualizarTotalesEdicion();
            })
            .catch(() => {
                document.getElementById('editLoadingMsg').innerHTML = '<span class="text-red-600">❌ Error al cargar el detalle.</span>';
            });
    }

    function fetchDetalleJSON(compraId) {
        const url = `?get_detalle_json=${compraId}`;
        fetch(url).then(res => res.json()).then(items => {
            const tbody = document.getElementById('tablaEdicionBody');
            items.forEach((item, idx) => {
                const fila = document.createElement('tr');
                fila.innerHTML = `
                    <td class="px-3 py-2 border-b text-gray-500 text-sm">${idx + 1}</td>
                    <td class="px-3 py-2 border-b">${generateEditProductSelectHtml(item.producto_id)}</td>
                    <td class="px-3 py-2 border-b text-center"><input type="number" name="edit_cantidad[]" value="${item.cantidad}" min="1" step="1" class="w-20 p-1.5 border border-gray-300 rounded text-right text-sm edit-cantidad" required></td>
                    <td class="px-3 py-2 border-b text-center"><input type="number" name="edit_precio[]" value="${item.precio_unitario}" min="0" step="any" class="w-24 p-1.5 border border-gray-300 rounded text-right text-sm edit-precio" required></td>
                    <td class="px-3 py-2 border-b"><input type="text" name="edit_descripcion[]" value="${item.descripcion || ''}" placeholder="Descripción" class="w-full p-1.5 border border-gray-300 rounded text-sm"></td>
                    <td class="px-3 py-2 border-b"><input type="text" name="edit_serial[]" value="${item.serial || ''}" placeholder="Serial" class="w-full p-1.5 border border-gray-300 rounded text-sm"></td>
                    <td class="px-3 py-2 border-b text-center text-sm font-semibold text-gray-700 edit-total">${formatCurrency(0)}</td>
                    <td class="px-3 py-2 border-b text-center"><button type="button" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs eliminarFila"><i class="fas fa-trash"></i></button></td>
                `;
                tbody.appendChild(fila);
            });
            document.getElementById('editLoadingMsg').classList.add('hidden');
            document.getElementById('formEdicion').classList.remove('hidden');
            actualizarTotalesEdicion();
        }).catch(() => {
            document.getElementById('editLoadingMsg').innerHTML = '<span class="text-red-600">❌ Error al cargar detalle.</span>';
        });
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.add('hidden');
        document.getElementById('tablaEdicionBody').innerHTML = '';
    }
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
</script>
<?php luxury_render_nav_end(); ?>
