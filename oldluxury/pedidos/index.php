<?php
// pedidos.php - Sistema de Gestión de Pedidos COMPLETO (SIN IVA)
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('pedidos');

function generarNumeroPedido($conn) {
    $year = date('Y');
    $month = date('m');
    $prefix = "PED-$year$month-";
    
    $result = $conn->query("SELECT MAX(CAST(SUBSTRING_INDEX(numero_pedido, '-', -1) AS UNSIGNED)) as ultimo 
                            FROM pedidos WHERE numero_pedido LIKE '$prefix%'");
    $row = $result->fetch_assoc();
    $nuevo_num = ($row['ultimo'] ?? 0) + 1;
    
    return $prefix . str_pad($nuevo_num, 4, '0', STR_PAD_LEFT);
}

// ===== HANDLERS AJAX =====
$is_ajax = isset($_GET['ajax']) || isset($_POST['ajax']) || 
           (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest');

if ($is_ajax || isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // ===== LISTAR PEDIDOS CON BÚSQUEDA =====
    if (isset($_GET['action']) && $_GET['action'] == 'listar_pedidos') {
        $estado = isset($_GET['estado']) ? $_GET['estado'] : '';
        $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
        
        $sql = "SELECT p.*, 
                (SELECT COUNT(*) FROM pedido_detalles WHERE pedido_id = p.id) as total_productos
                FROM pedidos p WHERE 1=1";
        
        if ($estado && $estado != 'todos') {
            $sql .= " AND p.estado = '" . $conn->real_escape_string($estado) . "'";
        }
        
        if ($buscar) {
            $buscar = $conn->real_escape_string($buscar);
            $sql .= " AND (p.numero_pedido LIKE '%$buscar%' 
                        OR p.cliente_nombre LIKE '%$buscar%' 
                        OR p.cliente_telefono LIKE '%$buscar%'
                        OR p.cliente_email LIKE '%$buscar%')";
        }
        
        $sql .= " ORDER BY p.id DESC LIMIT 100";
        
        $result = $conn->query($sql);
        $pedidos = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['fecha_entrega']) {
                $row['fecha_entrega_formateada'] = date('d/m/Y', strtotime($row['fecha_entrega']));
            } else {
                $row['fecha_entrega_formateada'] = '-';
            }
            $pedidos[] = $row;
        }
        echo json_encode(['ok' => true, 'pedidos' => $pedidos]);
        exit;
    }
    
    // ===== OBTENER DETALLE DE PEDIDO =====
    if (isset($_GET['action']) && $_GET['action'] == 'detalle_pedido') {
        $id = intval($_GET['id']);
        
        $stmt = $conn->prepare("SELECT * FROM pedidos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $pedido = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$pedido) {
            echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT * FROM pedido_detalles WHERE pedido_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode(['ok' => true, 'pedido' => $pedido, 'detalles' => $detalles]);
        exit;
    }
    
    // ===== BUSCAR CLIENTES =====
    if (isset($_GET['action']) && $_GET['action'] == 'buscar_clientes') {
        $q = trim($_GET['query']);
        if (strlen($q) < 2) { echo json_encode([]); exit; }
        $like = "%$q%";
        
        $checkTable = $conn->query("SHOW TABLES LIKE 'clientes'");
        if ($checkTable->num_rows == 0) {
            echo json_encode([]);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT id, nombre, telefono, correo, direccion FROM clientes WHERE nombre LIKE ? OR telefono LIKE ? OR correo LIKE ? LIMIT 10");
        $stmt->bind_param("sss", $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($res);
        exit;
    }
    
    // ===== BUSCAR PRODUCTO =====
    if (isset($_GET['action']) && $_GET['action'] == 'buscar_producto') {
        $q = trim($_GET['query']);
        $like = "%$q%";
        
        $checkTable = $conn->query("SHOW TABLES LIKE 'productos'");
        if ($checkTable->num_rows == 0) {
            echo json_encode(['ok' => false, 'error' => 'Tabla productos no existe']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT id, nombre, precio, stock, codigo_barras FROM productos WHERE codigo_barras = ? OR nombre LIKE ? LIMIT 1");
        $stmt->bind_param("ss", $q, $like);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        echo json_encode($res ? ['ok' => true, 'p' => $res] : ['ok' => false]);
        exit;
    }
    
    // ===== CREAR PEDIDO =====
    if (isset($_POST['action']) && $_POST['action'] == 'crear_pedido') {
        $cliente_id = intval($_POST['cliente_id']);
        $cliente_nombre = trim($_POST['cliente_nombre']);
        $cliente_telefono = trim($_POST['cliente_telefono'] ?? '');
        $cliente_email = trim($_POST['cliente_email'] ?? '');
        $cliente_direccion = trim($_POST['cliente_direccion'] ?? '');
        $fecha_entrega = !empty($_POST['fecha_entrega']) ? $_POST['fecha_entrega'] : null;
        $metodo_pago = $_POST['metodo_pago'] ?? 'Pendiente';
        $observaciones = trim($_POST['observaciones'] ?? '');
        $prioridad = $_POST['prioridad'] ?? 'normal';
        $items = json_decode($_POST['items'], true);
        
        if (!$cliente_nombre) {
            echo json_encode(['ok' => false, 'error' => 'El nombre del cliente es requerido']);
            exit;
        }
        
        if (!$items || count($items) == 0) {
            echo json_encode(['ok' => false, 'error' => 'Debe agregar al menos un producto']);
            exit;
        }
        
        // Validar stock
        foreach ($items as $item) {
            $stmt = $conn->prepare("SELECT stock FROM productos WHERE id = ?");
            $stmt->bind_param("i", $item['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $producto = $result->fetch_assoc();
            $stmt->close();
            
            if (!$producto) {
                echo json_encode(['ok' => false, 'error' => "Producto '{$item['nombre']}' no existe"]);
                exit;
            }
            
            if ($producto['stock'] < $item['cantidad']) {
                echo json_encode(['ok' => false, 'error' => "Stock insuficiente para '{$item['nombre']}'. Disponible: {$producto['stock']}"]);
                exit;
            }
        }
        
        // Calcular total (sin IVA)
        $total = 0;
        foreach ($items as $item) {
            $total += $item['precio'] * $item['cantidad'];
        }
        
        $numero_pedido = generarNumeroPedido($conn);
        $fecha_pedido = date('Y-m-d H:i:s');
        $created_by = $_SESSION['usuario_id'] ?? null;
        
        $conn->begin_transaction();
        try {
            // Insertar pedido
            $stmt = $conn->prepare("INSERT INTO pedidos (
                numero_pedido, cliente_id, cliente_nombre, cliente_telefono, cliente_email, cliente_direccion,
                fecha_pedido, fecha_entrega, total, metodo_pago, observaciones, estado, prioridad, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?)");
            
            $stmt->bind_param("sissssssdsssi", 
                $numero_pedido, $cliente_id, $cliente_nombre, $cliente_telefono, 
                $cliente_email, $cliente_direccion, $fecha_pedido, $fecha_entrega,
                $total, $metodo_pago, $observaciones, $prioridad, $created_by
            );
            $stmt->execute();
            $pedido_id = $conn->insert_id;
            $stmt->close();
            
            // Insertar detalles y actualizar stock
            $stmt = $conn->prepare("INSERT INTO pedido_detalles (
                pedido_id, producto_id, producto_nombre, producto_codigo, 
                cantidad, precio_unitario, subtotal
            ) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $updateStock = $conn->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
            
            foreach ($items as $item) {
                $subtotal = $item['precio'] * $item['cantidad'];
                $codigo = $item['codigo'] ?? '';
                $stmt->bind_param("iissidd", 
                    $pedido_id, $item['id'], $item['nombre'], $codigo,
                    $item['cantidad'], $item['precio'], $subtotal
                );
                $stmt->execute();
                
                // Actualizar stock
                $updateStock->bind_param("ii", $item['cantidad'], $item['id']);
                $updateStock->execute();
            }
            $stmt->close();
            $updateStock->close();
            
            // Registrar en historial
            $stmt = $conn->prepare("INSERT INTO pedido_historial (
                pedido_id, estado_anterior, estado_nuevo, usuario_id, fecha_cambio
            ) VALUES (?, NULL, 'pendiente', ?, NOW())");
            $stmt->bind_param("ii", $pedido_id, $created_by);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            echo json_encode(['ok' => true, 'pedido_id' => $pedido_id, 'numero_pedido' => $numero_pedido]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // ===== ACTUALIZAR PEDIDO =====
    if (isset($_POST['action']) && $_POST['action'] == 'actualizar_pedido') {
        $id = intval($_POST['id']);
        $cliente_nombre = trim($_POST['cliente_nombre']);
        $cliente_telefono = trim($_POST['cliente_telefono'] ?? '');
        $cliente_email = trim($_POST['cliente_email'] ?? '');
        $cliente_direccion = trim($_POST['cliente_direccion'] ?? '');
        $fecha_entrega = !empty($_POST['fecha_entrega']) ? $_POST['fecha_entrega'] : null;
        $metodo_pago = $_POST['metodo_pago'] ?? 'Pendiente';
        $observaciones = trim($_POST['observaciones'] ?? '');
        $prioridad = $_POST['prioridad'] ?? 'normal';
        
        $stmt = $conn->prepare("UPDATE pedidos SET 
            cliente_nombre = ?, cliente_telefono = ?, cliente_email = ?, cliente_direccion = ?,
            fecha_entrega = ?, metodo_pago = ?, observaciones = ?, prioridad = ?
            WHERE id = ?");
        
        $stmt->bind_param("ssssssssi", 
            $cliente_nombre, $cliente_telefono, $cliente_email, $cliente_direccion,
            $fecha_entrega, $metodo_pago, $observaciones, $prioridad, $id
        );
        
        $result = $stmt->execute();
        echo json_encode(['ok' => $result, 'error' => $stmt->error]);
        $stmt->close();
        exit;
    }
    
    // ===== ACTUALIZAR ESTADO DEL PEDIDO =====
    if (isset($_POST['action']) && $_POST['action'] == 'actualizar_estado') {
        $id = intval($_POST['id']);
        $estado = $_POST['estado'];
        $observacion = $_POST['observacion'] ?? '';
        
        $estados_validos = ['pendiente', 'confirmado', 'preparando', 'listo', 'entregado', 'cancelado'];
        if (!in_array($estado, $estados_validos)) {
            echo json_encode(['ok' => false, 'error' => 'Estado no válido']);
            exit;
        }
        
        // Obtener estado anterior
        $stmt = $conn->prepare("SELECT estado FROM pedidos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pedido = $result->fetch_assoc();
        $estado_anterior = $pedido['estado'];
        $stmt->close();
        
        $conn->begin_transaction();
        try {
            // Actualizar estado
            $stmt = $conn->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
            $stmt->bind_param("si", $estado, $id);
            $stmt->execute();
            $stmt->close();
            
            // Registrar en historial
            $usuario_id = $_SESSION['usuario_id'] ?? null;
            $stmt = $conn->prepare("INSERT INTO pedido_historial (
                pedido_id, estado_anterior, estado_nuevo, observacion, usuario_id, fecha_cambio
            ) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("isssi", $id, $estado_anterior, $estado, $observacion, $usuario_id);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // ===== ELIMINAR PEDIDO =====
    if (isset($_POST['action']) && $_POST['action'] == 'eliminar_pedido') {
        $id = intval($_POST['id']);
        
        $stmt = $conn->prepare("DELETE FROM pedidos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $result = $stmt->execute();
        
        echo json_encode(['ok' => $result, 'error' => $stmt->error]);
        $stmt->close();
        exit;
    }
    
    echo json_encode(['ok' => false, 'error' => 'Acción no válida']);
    exit;
}

// ===== DATOS PARA LA VISTA =====
$clientes = [];
$checkClientes = $conn->query("SHOW TABLES LIKE 'clientes'");
if ($checkClientes->num_rows > 0) {
    $clientes = $conn->query("SELECT id, nombre, telefono, correo, direccion FROM clientes ORDER BY nombre LIMIT 50")->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - Luxury POS</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <script src="../assets/vendor/fontawesome/js/all.min.js"></script>
    <style>
        .toast { position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 12px 20px; border-radius: 12px; z-index: 1000; animation: slideIn 0.3s; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .toast.error { background: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 24px; max-width: 700px; width: 90%; max-height: 85vh; overflow-y: auto; padding: 24px; }
        .estado-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; display: inline-flex; align-items: center; gap: 4px; }
        .estado-pendiente { background: #fef3c7; color: #d97706; }
        .estado-confirmado { background: #dbeafe; color: #2563eb; }
        .estado-preparando { background: #fed7aa; color: #ea580c; }
        .estado-listo { background: #d1fae5; color: #059669; }
        .estado-entregado { background: #a7f3d0; color: #047857; }
        .estado-cancelado { background: #fee2e2; color: #dc2626; }
        .priority-badge { padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; }
        .priority-alta { background: #fee2e2; color: #dc2626; }
        .priority-media { background: #fef3c7; color: #d97706; }
        .priority-normal { background: #d1fae5; color: #059669; }
        .quantity-btn { width: 28px; height: 28px; border-radius: 50%; background: #e5e7eb; border: none; font-weight: bold; cursor: pointer; transition: all 0.2s; }
        .quantity-btn:hover { background: #d1d5db; transform: scale(1.05); }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e5e7eb; border-radius: 12px; max-height: 200px; overflow-y: auto; z-index: 10; display: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .search-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background 0.2s; }
        .search-item:hover { background: #f0fdf4; }
        .relative { position: relative; }
        .action-btn { padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: bold; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .action-btn-warning { background: #f59e0b; color: white; }
        .action-btn-warning:hover { background: #d97706; transform: scale(1.05); }
        .action-btn-danger { background: #ef4444; color: white; }
        .action-btn-danger:hover { background: #dc2626; transform: scale(1.05); }
        .action-btn-info { background: #3b82f6; color: white; }
        .action-btn-info:hover { background: #2563eb; transform: scale(1.05); }
        .action-btn-success { background: #10b981; color: white; }
        .action-btn-success:hover { background: #059669; transform: scale(1.05); }
        .filtro-btn.active { background: #1f2937; color: white; }
        .filtro-btn:not(.active) { background: #e5e7eb; color: #374151; }
        .filtro-btn:not(.active):hover { background: #d1d5db; }
        .filtro-btn { white-space: nowrap; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { scrollbar-width: none; }
    </style>
</head>
<body class="bg-gray-100">
<?php if (function_exists('luxury_render_nav_start')) luxury_render_nav_start('pedidos'); ?>

<div class="p-0 space-y-3 md:space-y-6">

    <!-- Header -->
    <div>
        <div class="bg-white rounded-2xl shadow p-4 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-clipboard-list text-emerald-500 mr-2"></i> Gestión de Pedidos
                </h1>
                <p class="text-xs text-gray-400">Administra pedidos de clientes</p>
            </div>
            <button onclick="abrirModalNuevoPedido()" class="hidden md:inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg font-bold transition">
                <i class="fas fa-plus"></i> Nuevo Pedido
            </button>
        </div>
    </div>

    <!-- Búsqueda y Filtros -->
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex flex-col sm:flex-row gap-2 sm:gap-3 mb-3">
            <div class="flex-1 relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="buscador-pedidos" placeholder="Buscar por #pedido, cliente, teléfono o email..." class="w-full pl-10 pr-4 py-2 border rounded-lg text-sm">
            </div>
            <button onclick="buscarPedidos()" class="bg-blue-500 text-white px-4 rounded-lg hover:bg-blue-600 transition">
                <i class="fas fa-search mr-1"></i> Buscar
            </button>
            <button onclick="limpiarBusqueda()" class="bg-gray-200 text-gray-700 px-4 rounded-lg hover:bg-gray-300 transition">
                <i class="fas fa-times mr-1"></i> Limpiar
            </button>
        </div>
        <div class="flex gap-2 pb-1 overflow-x-auto no-scrollbar md:flex-wrap md:overflow-visible">
            <button data-estado="todos" class="filtro-btn px-3 py-1.5 rounded-lg text-sm font-medium active">
                <i class="fas fa-list mr-1"></i> Todos
            </button>
            <button data-estado="pendiente" class="filtro-btn px-3 py-1.5 rounded-lg text-sm font-medium">
                <i class="fas fa-clock text-amber-500 mr-1"></i> Pendientes
            </button>
            <button data-estado="confirmado" class="filtro-btn px-3 py-1.5 rounded-lg text-sm font-medium">
                <i class="fas fa-check-circle text-blue-500 mr-1"></i> Confirmados
            </button>
            <button data-estado="preparando" class="filtro-btn px-3 py-1.5 rounded-lg text-sm font-medium">
                <i class="fas fa-boxes-packing text-orange-500 mr-1"></i> Empacando
            </button>
            <button data-estado="listo" class="filtro-btn px-3 py-1.5 rounded-lg text-sm font-medium">
                <i class="fas fa-box-open text-green-500 mr-1"></i> Listos
            </button>
            <button data-estado="entregado" class="filtro-btn px-3 py-1.5 rounded-lg text-sm font-medium">
                <i class="fas fa-truck text-emerald-500 mr-1"></i> Entregados
            </button>
            <button data-estado="cancelado" class="filtro-btn px-3 py-1.5 rounded-lg text-sm font-medium">
                <i class="fas fa-ban text-red-500 mr-1"></i> Cancelados
            </button>
        </div>
    </div>

    <!-- Lista de Pedidos (escritorio) -->
    <div class="hidden md:block bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="p-3 text-left">Pedido</th>
                        <th class="p-3 text-left">Cliente</th>
                        <th class="p-3 text-left">Fecha</th>
                        <th class="p-3 text-left">Fecha Entrega</th>
                        <th class="p-3 text-center">Estado</th>
                        <th class="p-3 text-right">Total</th>
                        <th class="p-3 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody id="pedidos-body" class="divide-y divide-gray-100">
                    <tr><td colspan="7" class="p-8 text-center text-gray-400">Cargando pedidos...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Lista de Pedidos (móvil) -->
    <div id="pedidos-movil" class="md:hidden space-y-3"></div>
</div>

<!-- MODAL NUEVO/EDITAR PEDIDO -->
<div id="modal-pedido" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold" id="modal-titulo"><i class="fas fa-shopping-cart text-emerald-500 mr-2"></i> Nuevo Pedido</h2>
            <button onclick="cerrarModalPedido()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <input type="hidden" id="edit-id" value="0">
        
        <!-- Datos del Cliente -->
        <div class="bg-gray-50 p-4 rounded-xl mb-4">
            <h3 class="font-bold text-sm mb-3">Datos del Cliente</h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="text-xs font-bold text-gray-500">Buscar Cliente</label>
                    <div class="relative">
                        <input type="text" id="buscar-cliente" placeholder="Nombre, teléfono o email..." class="w-full border rounded-lg px-3 py-2 text-sm">
                        <div id="sugerencias-clientes" class="search-results"></div>
                    </div>
                </div>
                <input type="hidden" id="cliente-id" value="0">
                <div>
                    <label class="text-xs font-bold text-gray-500">Nombre *</label>
                    <input type="text" id="cliente-nombre" class="w-full border rounded-lg px-3 py-2 text-sm" required>
                </div>
                <div>
                    <label class="text-xs font-bold text-gray-500">Teléfono</label>
                    <input type="text" id="cliente-telefono" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-bold text-gray-500">Email</label>
                    <input type="email" id="cliente-email" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-bold text-gray-500">Dirección</label>
                    <input type="text" id="cliente-direccion" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
        </div>
        
        <!-- Productos (solo para nuevo pedido) -->
        <div id="productos-section" class="bg-gray-50 p-4 rounded-xl mb-4">
            <h3 class="font-bold text-sm mb-3">Agregar Productos</h3>
            <div class="flex gap-2 mb-3">
                <input type="text" id="buscar-producto" placeholder="Buscar producto (código o nombre)..." class="flex-1 border rounded-lg px-3 py-2 text-sm">
                <button onclick="buscarProductoParaPedido()" class="bg-emerald-500 text-white px-4 rounded-lg text-sm hover:bg-emerald-600 transition">
                    <i class="fas fa-plus mr-1"></i> Agregar
                </button>
            </div>
            <div id="productos-seleccionados" class="max-h-48 overflow-y-auto">
                <div class="text-center text-gray-400 py-4 text-sm">No hay productos agregados</div>
            </div>
        </div>
        
        <!-- Información adicional -->
        <div class="grid grid-cols-2 gap-3 mb-4">
            <div>
                <label class="text-xs font-bold text-gray-500">Fecha Entrega</label>
                <input type="date" id="fecha-entrega" class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="text-xs font-bold text-gray-500">Prioridad</label>
                <select id="prioridad" class="w-full border rounded-lg px-3 py-2 text-sm">
                    <option value="normal">🟢 Normal</option>
                    <option value="media">🟡 Media</option>
                    <option value="alta">🔴 Alta</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-gray-500">Método de Pago</label>
                <select id="metodo-pago" class="w-full border rounded-lg px-3 py-2 text-sm">
                    <option value="Pendiente">Pendiente</option>
                    <option value="Efectivo">💵 Efectivo</option>
                    <option value="Nequi">📱 Nequi</option>
                    <option value="Transferencia">📲 Transferencia</option>
                    <option value="Datafono">💳 Datáfono</option>
                </select>
            </div>
        </div>
        
        <div>
            <label class="text-xs font-bold text-gray-500">Observaciones</label>
            <textarea id="observaciones" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Notas del pedido (talla, color, forma de entrega...)"></textarea>
        </div>
        
        <!-- Total -->
        <div id="total-section" class="bg-gray-100 p-3 rounded-xl mt-4 flex justify-between items-center">
            <span class="font-bold text-lg">TOTAL</span>
            <span id="total-pedido" class="text-2xl font-bold text-emerald-600">$0</span>
        </div>
        
        <div class="flex gap-3 mt-4">
            <button onclick="guardarPedido()" class="flex-1 bg-emerald-500 text-white py-2 rounded-lg font-bold hover:bg-emerald-600 transition">
                <i class="fas fa-save mr-1"></i> Guardar Pedido
            </button>
            <button onclick="cerrarModalPedido()" class="flex-1 bg-gray-200 text-gray-700 py-2 rounded-lg font-bold hover:bg-gray-300 transition">
                Cancelar
            </button>
        </div>
    </div>
</div>

<!-- MODAL DETALLE PEDIDO -->
<div id="modal-detalle" class="modal">
    <div class="modal-content max-w-2xl">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold"><i class="fas fa-receipt text-indigo-500 mr-2"></i> Detalle del Pedido</h2>
            <button onclick="cerrarModalDetalle()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <div id="detalle-content" class="space-y-4"></div>
        <div class="flex gap-2 mt-4 justify-end">
            <button onclick="abrirEditarPedido()" id="btn-editar-pedido" class="bg-emerald-500 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-emerald-600 transition">
                <i class="fas fa-edit mr-1"></i> Editar Pedido
            </button>
            <button onclick="abrirModalCambiarEstadoDesdeDetalle()" class="bg-blue-500 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-600 transition">
                <i class="fas fa-exchange-alt mr-1"></i> Cambiar Estado
            </button>
            <button onclick="cerrarModalDetalle()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-bold hover:bg-gray-300 transition">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- MODAL CAMBIAR ESTADO -->
<div id="modal-cambiar-estado" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold"><i class="fas fa-exchange-alt text-blue-500 mr-2"></i> Cambiar Estado</h2>
            <button onclick="cerrarModalCambiarEstado()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <div class="mb-4">
            <p class="text-sm text-gray-600 mb-3">Pedido: <strong id="estado-pedido-numero"></strong></p>
            <label class="block text-xs font-bold text-gray-500 mb-2">Seleccionar Estado</label>
            <select id="select-estado" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                <option value="pendiente">⏱️ Pendiente</option>
                <option value="confirmado">✅ Confirmado</option>
                <option value="preparando">🛍️ Empacando</option>
                <option value="listo">📦 Listo</option>
                <option value="entregado">🚚 Entregado</option>
                <option value="cancelado">❌ Cancelado</option>
            </select>
            <label class="block text-xs font-bold text-gray-500 mt-3 mb-2">Observación (opcional)</label>
            <textarea id="estado-observacion" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Motivo del cambio..."></textarea>
        </div>
        <div class="flex gap-3 mt-4">
            <button onclick="confirmarCambiarEstado()" class="flex-1 bg-blue-500 text-white py-2 rounded-lg font-bold hover:bg-blue-600 transition">
                <i class="fas fa-save mr-1"></i> Actualizar Estado
            </button>
            <button onclick="cerrarModalCambiarEstado()" class="flex-1 bg-gray-200 text-gray-700 py-2 rounded-lg font-bold hover:bg-gray-300 transition">
                Cancelar
            </button>
        </div>
    </div>
</div>

<!-- FAB nuevo pedido (móvil) -->
<button onclick="abrirModalNuevoPedido()" class="md:hidden fixed bottom-5 right-5 z-50 w-14 h-14 rounded-full bg-emerald-500 hover:bg-emerald-600 text-white shadow-xl shadow-emerald-400/40 flex items-center justify-center active:scale-90 transition" aria-label="Nuevo Pedido">
    <i class="fas fa-plus text-xl"></i>
</button>

<script>
// ===== CONFIGURACIÓN =====
const BASE_URL = window.location.pathname;
const CSRF_TOKEN = '<?= csrf_token() ?>';

// ===== VARIABLES =====
let productosSeleccionados = [];
let pedidoActualId = null;
let pedidoActualNumero = null;
let pedidoActualEstado = null;
let timeoutBusqueda;
let terminoBusqueda = '';

// ===== UTILIDADES =====
function mostrarToast(msg, error = false) {
    const t = document.createElement('div');
    t.className = 'toast' + (error ? ' error' : '');
    t.innerHTML = `<i class="fas ${error ? 'fa-exclamation-triangle' : 'fa-check-circle'} mr-2"></i> ${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}

function formatearDinero(valor) {
    return '$' + parseFloat(valor || 0).toLocaleString('es-CO');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ===== CARGAR PEDIDOS =====
let filtroActual = 'todos';

function cargarPedidos() {
    let url = `${BASE_URL}?ajax=1&action=listar_pedidos&estado=${filtroActual}`;
    if (terminoBusqueda) {
        url += `&buscar=${encodeURIComponent(terminoBusqueda)}`;
    }
    
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const tbody = document.getElementById('pedidos-body');
                const tarjetas = document.getElementById('pedidos-movil');

                if (data.pedidos.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-gray-400">No hay pedidos registrados</td><\/tr>';
                    if (tarjetas) tarjetas.innerHTML = '<div class="bg-white rounded-2xl border border-dashed border-gray-200 p-8 text-center text-gray-400 text-sm">No hay pedidos registrados</div>';
                    return;
                }

                const iconos = { 'pendiente': '⏱️', 'confirmado': '✅', 'preparando': '🛍️', 'listo': '📦', 'entregado': '🚚', 'cancelado': '❌' };
                const textos = { 'pendiente': 'Pendiente', 'confirmado': 'Confirmado', 'preparando': 'Empacando', 'listo': 'Listo', 'entregado': 'Entregado', 'cancelado': 'Cancelado' };

                const filas = [];
                const cards = [];

                data.pedidos.forEach(p => {
                    const estadoClass = `estado-${p.estado}`;
                    const estadoIcono = iconos[p.estado] || '';
                    const estadoTexto = textos[p.estado] || p.estado;
                    const fechaEntrega = p.fecha_entrega_formateada || (p.fecha_entrega ? new Date(p.fecha_entrega).toLocaleDateString() : '-');
                    const telefono = p.cliente_telefono || '';

                    filas.push(`<tr class="hover:bg-gray-50">
                        <td class="p-3"><span class="font-mono text-sm font-bold">${p.numero_pedido}</span><br><small class="text-xs text-gray-400">#${p.id}</small></td>
                        <td class="p-3"><div class="font-medium">${escapeHtml(p.cliente_nombre)}</div><div class="text-xs text-gray-400">${telefono}</div></td>
                        <td class="p-3 text-sm">${new Date(p.fecha_pedido).toLocaleDateString()}</td>
                        <td class="p-3 text-sm font-mono">${fechaEntrega}</td>
                        <td class="p-3 text-center"><span class="estado-badge ${estadoClass}">${estadoIcono} ${estadoTexto}</span></td>
                        <td class="p-3 text-right font-bold">${formatearDinero(p.total)}</td>
                        <td class="p-3 text-center">
                            <button onclick="verDetallePedido(${p.id})" class="action-btn-info action-btn mr-1" title="Ver Detalle">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button onclick="abrirModalCambiarEstado(${p.id}, '${p.numero_pedido}', '${p.estado}')" class="action-btn-warning action-btn mr-1" title="Cambiar Estado">
                                <i class="fas fa-exchange-alt"></i>
                            </button>
                            <button onclick="eliminarPedido(${p.id})" class="action-btn-danger action-btn" title="Eliminar">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                     </tr>`);

                    cards.push(`<div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden active:scale-[0.99] transition" onclick="verDetallePedido(${p.id})">
                        <div class="p-4 cursor-pointer">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <span class="font-mono text-sm font-bold text-gray-800">${p.numero_pedido}</span>
                                    <div class="text-[11px] text-gray-400">#${p.id}</div>
                                </div>
                                <span class="estado-badge ${estadoClass} flex-shrink-0">${estadoIcono} ${estadoTexto}</span>
                            </div>
                            <div class="mt-3 flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-full bg-emerald-50 text-emerald-500 flex items-center justify-center flex-shrink-0"><i class="fas fa-user text-sm"></i></div>
                                <div class="min-w-0 flex-1">
                                    <div class="font-semibold text-sm text-gray-800 truncate">${escapeHtml(p.cliente_nombre)}</div>
                                    <div class="text-xs text-gray-400 truncate">
                                        ${telefono ? `<i class="fas fa-phone mr-1"></i>` + telefono : ''}${telefono && fechaEntrega !== '-' ? ' · ' : ''}
                                        ${fechaEntrega !== '-' ? `<i class="far fa-calendar-alt mr-1"></i>` + fechaEntrega : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between">
                                <div>
                                    <div class="text-[10px] text-gray-400 uppercase font-bold tracking-wide">Total</div>
                                    <div class="text-lg font-black text-emerald-600 leading-tight">${formatearDinero(p.total)}</div>
                                </div>
                                <div class="flex gap-2">
                                    <button onclick="event.stopPropagation(); abrirModalCambiarEstado(${p.id}, '${p.numero_pedido}', '${p.estado}')" class="action-btn-warning action-btn" title="Cambiar Estado">
                                        <i class="fas fa-exchange-alt"></i><span class="hidden sm:inline"> Estado</span>
                                    </button>
                                    <button onclick="event.stopPropagation(); eliminarPedido(${p.id})" class="action-btn-danger action-btn" title="Eliminar">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>`);
                });

                tbody.innerHTML = filas.join('');
                if (tarjetas) tarjetas.innerHTML = cards.join('');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarToast('Error de conexión', true);
        });
}

function buscarPedidos() {
    terminoBusqueda = document.getElementById('buscador-pedidos').value.trim();
    cargarPedidos();
}

function limpiarBusqueda() {
    terminoBusqueda = '';
    document.getElementById('buscador-pedidos').value = '';
    cargarPedidos();
}

function filtrarPedidos(estado) {
    filtroActual = estado;
    document.querySelectorAll('.filtro-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    const activeBtn = document.querySelector(`.filtro-btn[data-estado="${estado}"]`);
    if (activeBtn) activeBtn.classList.add('active');
    cargarPedidos();
}

// ===== VER DETALLE =====
function verDetallePedido(id) {
    pedidoActualId = id;
    fetch(`${BASE_URL}?ajax=1&action=detalle_pedido&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const p = data.pedido;
                const detalles = data.detalles;
                pedidoActualNumero = p.numero_pedido;
                pedidoActualEstado = p.estado;
                
                let estadoIcono = {
                    'pendiente': '⏱️', 'confirmado': '✅',
                    'preparando': '🛍️', 'listo': '📦',
                    'entregado': '🚚', 'cancelado': '❌'
                }[p.estado] || '';
                
                let estadoTexto = {
                    'pendiente': 'Pendiente', 'confirmado': 'Confirmado',
                    'preparando': 'Empacando', 'listo': 'Listo',
                    'entregado': 'Entregado', 'cancelado': 'Cancelado'
                }[p.estado] || p.estado;
                let estadoClass = `estado-${p.estado}`;
                
                let prioridadClass = `priority-${p.prioridad || 'normal'}`;
                let prioridadTexto = { 'alta': 'Alta', 'media': 'Media', 'normal': 'Normal' }[p.prioridad] || 'Normal';
                
                const productosHtml = detalles.map(d => `
                    <tr class="border-b">
                        <td class="py-2">${escapeHtml(d.producto_nombre)}</td>
                        <td class="py-2 text-center">${d.cantidad}</td>
                        <td class="py-2 text-right">${formatearDinero(d.precio_unitario)}</td>
                        <td class="py-2 text-right font-bold">${formatearDinero(d.subtotal)}</td>
                    </tr>
                `).join('');
                
                document.getElementById('detalle-content').innerHTML = `
                    <div class="bg-gray-50 p-3 rounded-lg">
                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <div><span class="text-gray-500"><i class="fas fa-hashtag"></i> Pedido:</span> <span class="font-bold">${p.numero_pedido}</span></div>
                            <div><span class="text-gray-500"><i class="far fa-calendar-alt"></i> Fecha:</span> ${new Date(p.fecha_pedido).toLocaleString()}</div>
                            <div><span class="text-gray-500"><i class="fas fa-calendar-check"></i> Entrega:</span> ${p.fecha_entrega ? new Date(p.fecha_entrega).toLocaleDateString() : '-'}</div>
                            <div><span class="text-gray-500"><i class="fas fa-flag"></i> Prioridad:</span> <span class="priority-badge ${prioridadClass}">${prioridadTexto}</span></div>
                            <div class="col-span-2"><span class="text-gray-500"><i class="fas fa-user"></i> Cliente:</span> ${escapeHtml(p.cliente_nombre)}</div>
                            <div><span class="text-gray-500"><i class="fas fa-phone"></i> Teléfono:</span> ${p.cliente_telefono || '-'}</div>
                            <div><span class="text-gray-500"><i class="fas fa-envelope"></i> Email:</span> ${p.cliente_email || '-'}</div>
                            <div class="col-span-2"><span class="text-gray-500"><i class="fas fa-map-marker-alt"></i> Dirección:</span> ${p.cliente_direccion || '-'}</div>
                            <div><span class="text-gray-500"><i class="fas fa-credit-card"></i> Pago:</span> ${p.metodo_pago || 'Pendiente'}</div>
                            <div><span class="text-gray-500"><i class="fas fa-tag"></i> Estado:</span> <span class="estado-badge ${estadoClass}">${estadoIcono} ${estadoTexto}</span></div>
                        </div>
                    </div>
                    <h4 class="font-bold text-sm mt-3 mb-2"><i class="fas fa-boxes"></i> Productos</h4>
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100">
                            <tr><th class="p-2 text-left">Producto</th><th class="p-2 text-center">Cant</th><th class="p-2 text-right">Precio</th><th class="p-2 text-right">Subtotal</th></tr>
                        </thead>
                        <tbody>${productosHtml}</tbody>
                        <tfoot>
                            <tr class="border-t font-bold">
                                <td colspan="3" class="p-2 text-right">TOTAL:</td>
                                <td class="p-2 text-right text-emerald-600">${formatearDinero(p.total)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    ${p.observaciones ? `<div class="bg-yellow-50 p-2 rounded-lg mt-3"><span class="text-xs font-bold"><i class="fas fa-comment"></i> Observaciones:</span><p class="text-sm">${escapeHtml(p.observaciones)}</p></div>` : ''}
                `;
                document.getElementById('modal-detalle').classList.add('active');
                document.getElementById('modal-detalle').style.display = 'flex';
            }
        });
}

function cerrarModalDetalle() {
    document.getElementById('modal-detalle').classList.remove('active');
    document.getElementById('modal-detalle').style.display = 'none';
}

// ===== EDITAR PEDIDO =====
function abrirEditarPedido() {
    if (!pedidoActualId) return;
    
    fetch(`${BASE_URL}?ajax=1&action=detalle_pedido&id=${pedidoActualId}`)
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const p = data.pedido;
                
                document.getElementById('modal-titulo').innerHTML = '<i class="fas fa-edit text-emerald-500 mr-2"></i> Editar Pedido';
                document.getElementById('edit-id').value = p.id;
                document.getElementById('cliente-id').value = p.cliente_id;
                document.getElementById('cliente-nombre').value = p.cliente_nombre;
                document.getElementById('cliente-telefono').value = p.cliente_telefono || '';
                document.getElementById('cliente-email').value = p.cliente_email || '';
                document.getElementById('cliente-direccion').value = p.cliente_direccion || '';
                document.getElementById('fecha-entrega').value = p.fecha_entrega || '';
                document.getElementById('prioridad').value = p.prioridad || 'normal';
                document.getElementById('metodo-pago').value = p.metodo_pago || 'Pendiente';
                document.getElementById('observaciones').value = p.observaciones || '';
                
                // Ocultar sección de productos en edición
                document.getElementById('productos-section').style.display = 'none';
                document.getElementById('total-section').style.display = 'none';
                
                cerrarModalDetalle();
                document.getElementById('modal-pedido').classList.add('active');
                document.getElementById('modal-pedido').style.display = 'flex';
            }
        });
}

function cerrarModalPedido() {
    document.getElementById('modal-pedido').classList.remove('active');
    document.getElementById('modal-pedido').style.display = 'none';
    // Resetear modo
    document.getElementById('modal-titulo').innerHTML = '<i class="fas fa-shopping-cart text-emerald-500 mr-2"></i> Nuevo Pedido';
    document.getElementById('edit-id').value = '0';
    document.getElementById('productos-section').style.display = 'block';
    document.getElementById('total-section').style.display = 'flex';
    productosSeleccionados = [];
    actualizarListaProductos();
}

// ===== CAMBIAR ESTADO =====
function abrirModalCambiarEstado(id, numero, estadoActual) {
    pedidoActualId = id;
    pedidoActualNumero = numero;
    document.getElementById('estado-pedido-numero').innerText = numero;
    document.getElementById('select-estado').value = estadoActual;
    document.getElementById('estado-observacion').value = '';
    document.getElementById('modal-cambiar-estado').classList.add('active');
    document.getElementById('modal-cambiar-estado').style.display = 'flex';
}

function abrirModalCambiarEstadoDesdeDetalle() {
    if (pedidoActualId && pedidoActualNumero) {
        abrirModalCambiarEstado(pedidoActualId, pedidoActualNumero, pedidoActualEstado);
        cerrarModalDetalle();
    }
}

function cerrarModalCambiarEstado() {
    document.getElementById('modal-cambiar-estado').classList.remove('active');
    document.getElementById('modal-cambiar-estado').style.display = 'none';
}

function confirmarCambiarEstado() {
    const nuevoEstado = document.getElementById('select-estado').value;
    const observacion = document.getElementById('estado-observacion').value;
    
    const fd = new URLSearchParams();
    fd.append('_csrf', CSRF_TOKEN);
    fd.append('ajax', '1');
    fd.append('action', 'actualizar_estado');
    fd.append('id', pedidoActualId);
    fd.append('estado', nuevoEstado);
    fd.append('observacion', observacion);
    
    fetch(BASE_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const estadoTexto = {
                    'pendiente': 'Pendiente', 'confirmado': 'Confirmado',
                    'preparando': 'Empacando', 'listo': 'Listo',
                    'entregado': 'Entregado', 'cancelado': 'Cancelado'
                }[nuevoEstado] || nuevoEstado;
                mostrarToast(`✅ Estado actualizado a: ${estadoTexto}`);
                cerrarModalCambiarEstado();
                cargarPedidos();
            } else {
                mostrarToast(data.error || "Error al actualizar", true);
            }
        });
}

// ===== NUEVO PEDIDO =====
function abrirModalNuevoPedido() {
    productosSeleccionados = [];
    document.getElementById('edit-id').value = '0';
    document.getElementById('cliente-id').value = '0';
    document.getElementById('cliente-nombre').value = '';
    document.getElementById('cliente-telefono').value = '';
    document.getElementById('cliente-email').value = '';
    document.getElementById('cliente-direccion').value = '';
    document.getElementById('buscar-cliente').value = '';
    document.getElementById('fecha-entrega').value = '';
    document.getElementById('prioridad').value = 'normal';
    document.getElementById('metodo-pago').value = 'Pendiente';
    document.getElementById('observaciones').value = '';
    document.getElementById('productos-section').style.display = 'block';
    document.getElementById('total-section').style.display = 'flex';
    actualizarListaProductos();
    document.getElementById('modal-pedido').classList.add('active');
    document.getElementById('modal-pedido').style.display = 'flex';
}

// Buscar cliente
document.getElementById('buscar-cliente')?.addEventListener('input', function() {
    clearTimeout(timeoutBusqueda);
    const q = this.value.trim();
    if (q.length < 2) {
        document.getElementById('sugerencias-clientes').style.display = 'none';
        return;
    }
    timeoutBusqueda = setTimeout(() => {
        fetch(`${BASE_URL}?ajax=1&action=buscar_clientes&query=${encodeURIComponent(q)}`)
            .then(r => r.json())
            .then(data => {
                const sugerencias = document.getElementById('sugerencias-clientes');
                if (data.length) {
                    sugerencias.innerHTML = data.map(c => `
                        <div onclick="seleccionarClientePedido(${c.id}, '${escapeHtml(c.nombre)}', '${c.telefono || ''}', '${c.correo || ''}', '${escapeHtml(c.direccion || '')}')" class="search-item">
                            <div class="font-medium">${escapeHtml(c.nombre)}</div>
                            <div class="text-xs text-gray-500"><i class="fas fa-phone mr-1"></i> ${c.telefono || 'Sin teléfono'} | <i class="fas fa-envelope mr-1"></i> ${c.correo || ''}</div>
                        </div>
                    `).join('');
                    sugerencias.style.display = 'block';
                } else {
                    sugerencias.style.display = 'none';
                }
            });
    }, 300);
});

function seleccionarClientePedido(id, nombre, telefono, email, direccion) {
    document.getElementById('cliente-id').value = id;
    document.getElementById('cliente-nombre').value = nombre;
    document.getElementById('cliente-telefono').value = telefono;
    document.getElementById('cliente-email').value = email;
    document.getElementById('cliente-direccion').value = direccion;
    document.getElementById('buscar-cliente').value = nombre;
    document.getElementById('sugerencias-clientes').style.display = 'none';
}

// Buscar producto
function buscarProductoParaPedido() {
    const q = document.getElementById('buscar-producto').value.trim();
    if (!q) {
        mostrarToast("Ingrese código o nombre del producto", true);
        return;
    }
    
    fetch(`${BASE_URL}?ajax=1&action=buscar_producto&query=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.p) {
                const existente = productosSeleccionados.find(p => p.id === data.p.id);
                if (existente) {
                    if (existente.cantidad + 1 <= existente.stock) {
                        existente.cantidad++;
                    } else {
                        mostrarToast(`Stock máximo: ${existente.stock}`, true);
                    }
                } else {
                    if (data.p.stock >= 1) {
                        productosSeleccionados.push({
                            id: data.p.id,
                            nombre: data.p.nombre,
                            precio: parseFloat(data.p.precio),
                            cantidad: 1,
                            stock: data.p.stock,
                            codigo: data.p.codigo_barras || ''
                        });
                    } else {
                        mostrarToast("Producto sin stock", true);
                        return;
                    }
                }
                document.getElementById('buscar-producto').value = '';
                actualizarListaProductos();
                mostrarToast(`✅ ${data.p.nombre} agregado`);
            } else {
                mostrarToast("Producto no encontrado", true);
            }
        });
}

function actualizarListaProductos() {
    const container = document.getElementById('productos-seleccionados');
    let total = 0;
    
    if (productosSeleccionados.length === 0) {
        container.innerHTML = '<div class="text-center text-gray-400 py-4 text-sm">No hay productos agregados</div>';
        document.getElementById('total-pedido').innerHTML = formatearDinero(0);
        return;
    }
    
    container.innerHTML = productosSeleccionados.map((p, i) => {
        const itemTotal = p.precio * p.cantidad;
        total += itemTotal;
        return `<div class="flex justify-between items-center p-2 border-b">
            <div class="flex-1">
                <span class="font-medium">${escapeHtml(p.nombre)}</span>
                <br><span class="text-xs text-gray-400">${formatearDinero(p.precio)} c/u | Stock: ${p.stock}</span>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="cambiarCantidadProducto(${i}, -1)" class="quantity-btn"><i class="fas fa-minus"></i></button>
                <span class="w-8 text-center">${p.cantidad}</span>
                <button onclick="cambiarCantidadProducto(${i}, 1)" class="quantity-btn"><i class="fas fa-plus"></i></button>
            </div>
            <div class="w-24 text-right font-bold">${formatearDinero(itemTotal)}</div>
            <button onclick="eliminarProductoPedido(${i})" class="text-red-500 ml-2 hover:text-red-700"><i class="fas fa-trash"></i></button>
        </div>`;
    }).join('');
    
    document.getElementById('total-pedido').innerHTML = formatearDinero(total);
}

function cambiarCantidadProducto(index, delta) {
    const p = productosSeleccionados[index];
    const nuevaCant = p.cantidad + delta;
    if (nuevaCant < 1) {
        productosSeleccionados.splice(index, 1);
    } else if (nuevaCant <= p.stock) {
        p.cantidad = nuevaCant;
    } else {
        mostrarToast(`Stock máximo: ${p.stock}`, true);
        return;
    }
    actualizarListaProductos();
}

function eliminarProductoPedido(index) {
    productosSeleccionados.splice(index, 1);
    actualizarListaProductos();
}

// Guardar/Actualizar pedido
function guardarPedido() {
    const editId = document.getElementById('edit-id').value;
    const cliente_id = document.getElementById('cliente-id').value;
    const cliente_nombre = document.getElementById('cliente-nombre').value;
    const cliente_telefono = document.getElementById('cliente-telefono').value;
    const cliente_email = document.getElementById('cliente-email').value;
    const cliente_direccion = document.getElementById('cliente-direccion').value;
    const fecha_entrega = document.getElementById('fecha-entrega').value;
    const metodo_pago = document.getElementById('metodo-pago').value;
    const prioridad = document.getElementById('prioridad').value;
    const observaciones = document.getElementById('observaciones').value;
    
    if (!cliente_nombre) {
        mostrarToast("El nombre del cliente es requerido", true);
        return;
    }
    
    const fd = new URLSearchParams();
    fd.append('_csrf', CSRF_TOKEN);
    
    if (editId > 0) {
        // Actualizar pedido existente
        fd.append('ajax', '1');
        fd.append('action', 'actualizar_pedido');
        fd.append('id', editId);
        fd.append('cliente_nombre', cliente_nombre);
        fd.append('cliente_telefono', cliente_telefono);
        fd.append('cliente_email', cliente_email);
        fd.append('cliente_direccion', cliente_direccion);
        fd.append('fecha_entrega', fecha_entrega);
        fd.append('metodo_pago', metodo_pago);
        fd.append('prioridad', prioridad);
        fd.append('observaciones', observaciones);
        
        fetch(BASE_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    mostrarToast("✅ Pedido actualizado correctamente");
                    cerrarModalPedido();
                    cargarPedidos();
                } else {
                    mostrarToast(data.error || "Error al actualizar", true);
                }
            });
    } else {
        // Crear nuevo pedido
        if (productosSeleccionados.length === 0) {
            mostrarToast("Agregue al menos un producto", true);
            return;
        }
        
        fd.append('ajax', '1');
        fd.append('action', 'crear_pedido');
        fd.append('cliente_id', cliente_id);
        fd.append('cliente_nombre', cliente_nombre);
        fd.append('cliente_telefono', cliente_telefono);
        fd.append('cliente_email', cliente_email);
        fd.append('cliente_direccion', cliente_direccion);
        fd.append('fecha_entrega', fecha_entrega);
        fd.append('metodo_pago', metodo_pago);
        fd.append('prioridad', prioridad);
        fd.append('observaciones', observaciones);
        fd.append('items', JSON.stringify(productosSeleccionados.map(p => ({
            id: p.id, nombre: p.nombre, precio: p.precio, cantidad: p.cantidad, codigo: p.codigo
        }))));
        
        fetch(BASE_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    mostrarToast(`✅ Pedido ${data.numero_pedido} creado`);
                    cerrarModalPedido();
                    cargarPedidos();
                } else {
                    mostrarToast(data.error || "Error al crear pedido", true);
                }
            });
    }
}

// Eliminar pedido
function eliminarPedido(id) {
    if (!confirm("¿Eliminar este pedido? Esta acción no se puede deshacer.")) return;
    
    const fd = new URLSearchParams();
    fd.append('_csrf', CSRF_TOKEN);
    fd.append('ajax', '1');
    fd.append('action', 'eliminar_pedido');
    fd.append('id', id);
    
    fetch(BASE_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                mostrarToast("✅ Pedido eliminado");
                cargarPedidos();
            } else {
                mostrarToast("Error al eliminar", true);
            }
        });
}

// ===== INICIALIZACIÓN =====
document.querySelectorAll('.filtro-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        filtrarPedidos(this.getAttribute('data-estado'));
    });
});

// Enter para buscar
document.getElementById('buscador-pedidos')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        buscarPedidos();
    }
});

document.getElementById('buscar-producto')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        buscarProductoParaPedido();
    }
});

cargarPedidos();

window.onclick = function(event) {
    const modalPedido = document.getElementById('modal-pedido');
    const modalDetalle = document.getElementById('modal-detalle');
    const modalEstado = document.getElementById('modal-cambiar-estado');
    if (event.target === modalPedido) cerrarModalPedido();
    if (event.target === modalDetalle) cerrarModalDetalle();
    if (event.target === modalEstado) cerrarModalCambiarEstado();
}

document.addEventListener('click', function(e) {
    const buscarCliente = document.getElementById('buscar-cliente');
    const sugerencias = document.getElementById('sugerencias-clientes');
    if (buscarCliente && sugerencias && !buscarCliente.contains(e.target) && !sugerencias.contains(e.target)) {
        sugerencias.style.display = 'none';
    }
});
</script>

<?php if (function_exists('luxury_render_nav_end')) luxury_render_nav_end(); ?>
</body>
</html>