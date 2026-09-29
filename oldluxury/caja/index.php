<?php
// caja.php - Punto de Venta COMPLETO con Gestión de Caja, Ingresos/Egresos y Facturación
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('caja');

$usuario_id = $_SESSION['user_id'] ?? 1;

// ====================================================================
// DATOS DE LA EMPRESA
// ====================================================================
$empresa = [
    'nombre'    => 'LUXURY PREMIUM STORE',
    'nit'       => '901.234.567-8',
    'direccion' => 'Cra 6 #19-29, Santa Marta, Magdalena',
    'telefono'  => '+57 304 357 9108',
    'email'     => 'info@luxurystore.com',
    'web'       => 'www.luxurystore.com',
];

// ====================================================================
// FUNCIONES
// ====================================================================

// Ventas del día actual
$ventas_hoy = 0;
$sql = "SELECT COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo='venta' AND DATE(fecha) = CURDATE()";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $ventas_hoy = floatval($row['total'] ?? 0);
    mysqli_free_result($result);
}

// Ticket promedio
$ticket_promedio = 0;
$sql = "SELECT COUNT(*) as cantidad, COALESCE(SUM(monto), 0) as total FROM movimientos_caja WHERE tipo='venta' AND DATE(fecha) = CURDATE()";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    if ($row['cantidad'] > 0) {
        $ticket_promedio = $row['total'] / $row['cantidad'];
    }
    mysqli_free_result($result);
}

function obtenerCajaActiva($conn) {
    $stmt = $conn->prepare("SELECT * FROM cajas WHERE estado='abierta' ORDER BY fecha_apertura DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->execute();
    $caja = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $caja;
}

function formatearCOP($monto) {
    return '$ ' . number_format($monto, 0, ',', '.');
}

function obtenerColumnasTabla($conn, $tabla, $forzar = false) {
    static $cache = [];
    if (!$forzar && isset($cache[$tabla])) {
        return $cache[$tabla];
    }
    $columnas = [];
    $tabla_segura = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $res = $conn->query("SHOW COLUMNS FROM `$tabla_segura`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columnas[] = $row['Field'];
        }
    }
    $cache[$tabla] = $columnas;
    return $columnas;
}

function asegurarColumnas($conn, $tabla, $defs) {
    $tabla_segura = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $columnas = obtenerColumnasTabla($conn, $tabla_segura, true);
    foreach ($defs as $columna => $definicion) {
        if (!in_array($columna, $columnas, true)) {
            $col_segura = preg_replace('/[^a-zA-Z0-9_]/', '', $columna);
            $conn->query("ALTER TABLE `$tabla_segura` ADD COLUMN `$col_segura` $definicion");
        }
    }
    obtenerColumnasTabla($conn, $tabla_segura, true);
}

function asegurarTipoMovimientoVenta($conn) {
    $res = $conn->query("SHOW COLUMNS FROM `movimientos_caja` LIKE 'tipo'");
    if (!$res) return;
    $row = $res->fetch_assoc();
    if (!$row || !isset($row['Type'])) return;
    $tipo = strtolower($row['Type']);
    if (strpos($tipo, 'enum(') !== false && (strpos($tipo, 'venta') === false || strpos($tipo, 'devolucion') === false)) {
        $conn->query("ALTER TABLE `movimientos_caja` MODIFY COLUMN `tipo` ENUM('ingreso','egreso','venta','devolucion') NOT NULL");
    }
}

function columnaClienteCorreo($conn) {
    $columnas = obtenerColumnasTabla($conn, 'clientes');
    if (in_array('correo', $columnas, true)) return 'correo';
    if (in_array('email', $columnas, true)) return 'email';
    return null;
}

function clientesTieneFechaRegistro($conn) {
    return in_array('fecha_registro', obtenerColumnasTabla($conn, 'clientes'), true);
}

asegurarColumnas($conn, 'ventas', [
    'metodo_pago' => "varchar(50) NOT NULL DEFAULT 'Efectivo'",
    'monto_recibido' => "decimal(10,2) NOT NULL DEFAULT 0",
    'cambio' => "decimal(10,2) NOT NULL DEFAULT 0",
    'motivo' => "varchar(255) NOT NULL DEFAULT 'Venta general'"
]);
asegurarColumnas($conn, 'movimientos_caja', [
    'metodo_pago' => "varchar(50) NOT NULL DEFAULT 'Efectivo'"
]);
asegurarColumnas($conn, 'clientes', [
    'direccion' => "varchar(255) NOT NULL DEFAULT ''",
    'tipo' => "enum('particular','empresa','vip') NOT NULL DEFAULT 'particular'"
]);

asegurarTipoMovimientoVenta($conn);

$ventas_cols = obtenerColumnasTabla($conn, 'ventas', true);
$ventas_tiene_metodo = in_array('metodo_pago', $ventas_cols, true);
$ventas_tiene_monto_recibido = in_array('monto_recibido', $ventas_cols, true);
$ventas_tiene_cambio = in_array('cambio', $ventas_cols, true);

$mov_cols = obtenerColumnasTabla($conn, 'movimientos_caja', true);
$mov_tiene_metodo = in_array('metodo_pago', $mov_cols, true);

// ====================================================================
// HANDLERS AJAX
// ====================================================================
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$ajax_get = isset($_GET['buscar']) || isset($_GET['buscar_productos']) || isset($_GET['scan']) || isset($_GET['poll']) || isset($_GET['buscar_clientes']) || isset($_GET['get_factura_data']) || isset($_GET['editar_movimiento']) || isset($_GET['listar_facturas']) || isset($_GET['listar_movimientos']);
$ajax_post = isset($_POST['registrar_venta']) || isset($_POST['registrar_movimiento_manual']) || isset($_POST['crear_cliente']) || isset($_POST['actualizar_movimiento']) || isset($_POST['eliminar_venta']) || isset($_POST['eliminar_movimiento']);

if ($is_ajax || $ajax_get || $ajax_post) {
    
    // BUSCAR PRODUCTO
    if (isset($_GET['buscar'])) {
        header('Content-Type: application/json');
        $query = trim($_GET['buscar']);
        $like = "%$query%";
        $stmt = $conn->prepare("SELECT id, nombre, precio, stock, codigo_barras FROM productos WHERE codigo_barras = ? OR nombre LIKE ? LIMIT 1");
        $stmt->bind_param("ss", $query, $like);
        $stmt->execute();
        $producto = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode($producto ? ['ok' => true, 'producto' => $producto] : ['ok' => false, 'error' => 'Producto no encontrado']);
        exit;
    }

    if (isset($_GET['buscar_productos'])) {
        header('Content-Type: application/json');
        $query = trim($_GET['buscar_productos']);
        if (mb_strlen($query) < 1) {
            echo json_encode(['ok' => true, 'productos' => []]);
            exit;
        }
        $like = "%$query%";
        $stmt = $conn->prepare("SELECT id, nombre, precio, stock, codigo_barras FROM productos WHERE codigo_barras LIKE ? OR nombre LIKE ? ORDER BY nombre ASC LIMIT 8");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['ok' => true, 'productos' => $productos]);
        exit;
    }
    
    // ESCÁNER
    if (isset($_GET['scan'])) {
        header('Content-Type: application/json');
        $codigo = trim($_GET['codigo'] ?? '');
        if (empty($codigo)) { echo json_encode(['ok' => false, 'error' => 'Código vacío']); exit; }
        $stmt = $conn->prepare("SELECT id, nombre, precio, stock, codigo_barras FROM productos WHERE codigo_barras = ? LIMIT 1");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $producto = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($producto) {
            $_SESSION['ultimo_scan'] = array_merge($producto, ['timestamp' => time()]);
            echo json_encode(['ok' => true, 'producto' => $producto]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);
        }
        exit;
    }
    
    // POLLING
    if (isset($_GET['poll'])) {
        header('Content-Type: application/json');
        $scan = $_SESSION['ultimo_scan'] ?? null;
        if ($scan && (time() - ($scan['timestamp'] ?? 0)) < 10) {
            echo json_encode(['ok' => true, 'producto' => $scan]);
            unset($_SESSION['ultimo_scan']);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    
    // BUSCAR CLIENTES
    if (isset($_GET['buscar_clientes'])) {
        header('Content-Type: application/json');
        $query = trim($_GET['buscar_clientes'] ?? '');
        if (mb_strlen($query) < 2) {
            echo json_encode([]);
            exit;
        }
        $like = "%$query%";
        $correo_col = columnaClienteCorreo($conn);
        $select_correo = $correo_col ? "$correo_col AS email" : "'' AS email";
        $stmt = $conn->prepare("SELECT id, nombre, telefono, $select_correo FROM clientes WHERE nombre LIKE ? OR telefono LIKE ? LIMIT 10");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode($clientes);
        exit;
    }
    
    // LISTAR FACTURAS
    if (isset($_GET['listar_facturas'])) {
        header('Content-Type: application/json');
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $select_metodo = $ventas_tiene_metodo ? 'metodo_pago' : "'' AS metodo_pago";
        $stmt = $conn->prepare("SELECT id, cliente_nombre, fecha_venta, total, $select_metodo FROM ventas ORDER BY id DESC LIMIT ?");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $facturas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['ok' => true, 'facturas' => $facturas]);
        exit;
    }

    // LISTAR MOVIMIENTOS DE CAJA ACTIVA
    if (isset($_GET['listar_movimientos'])) {
        header('Content-Type: application/json');
        $caja_activa = obtenerCajaActiva($conn);
        if (!$caja_activa) {
            echo json_encode(['ok' => false, 'error' => 'No hay caja activa']);
            exit;
        }

        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $stmt = $conn->prepare("SELECT m.*, v.motivo AS venta_motivo FROM movimientos_caja m LEFT JOIN ventas v ON m.venta_id = v.id WHERE m.caja_id = ? ORDER BY m.fecha DESC LIMIT ?");
        $stmt->bind_param("ii", $caja_activa['id'], $limit);
        $stmt->execute();
        $movimientos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("SELECT 
            COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) as ventas,
            COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) as ingresos,
            COALESCE(SUM(CASE WHEN tipo IN ('egreso','devolucion') THEN monto ELSE 0 END),0) as egresos,
            COUNT(*) as movimientos
            FROM movimientos_caja WHERE caja_id = ?");
        $stmt->bind_param("i", $caja_activa['id']);
        $stmt->execute();
        $totales = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $saldo_actual_ajax = $caja_activa['saldo_inicial'] + ($totales['ventas'] ?? 0) + ($totales['ingresos'] ?? 0) - ($totales['egresos'] ?? 0);

        echo json_encode([
            'ok' => true,
            'movimientos' => $movimientos,
            'totales' => $totales,
            'saldo_inicial' => (float)$caja_activa['saldo_inicial'],
            'saldo_actual' => (float)$saldo_actual_ajax
        ]);
        exit;
    }
    
    // CREAR CLIENTE
    if (isset($_POST['crear_cliente'])) {
        header('Content-Type: application/json');
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $tipo = trim($_POST['tipo'] ?? 'particular');
        
        if (empty($nombre)) {
            echo json_encode(['ok' => false, 'error' => 'El nombre del cliente es requerido']);
            exit;
        }
        
        $correo_col = columnaClienteCorreo($conn);
        $usa_fecha = clientesTieneFechaRegistro($conn);
        $campos = ['nombre', 'telefono', 'direccion', 'tipo'];
        $placeholders = ['?', '?', '?', '?'];
        $tipos = 'ssss';
        $params = [$nombre, $telefono, $direccion, $tipo];

        if ($correo_col) {
            $campos[] = $correo_col;
            $placeholders[] = '?';
            $tipos .= 's';
            $params[] = $email;
        }

        if ($usa_fecha) {
            $campos[] = 'fecha_registro';
            $placeholders[] = 'NOW()';
        }

        $sql = "INSERT INTO clientes (" . implode(', ', $campos) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($tipos, ...$params);
        
        if ($stmt->execute()) {
            $nuevo_id = $conn->insert_id;
            echo json_encode([
                'ok' => true, 
                'cliente_id' => $nuevo_id, 
                'nombre' => $nombre,
                'telefono' => $telefono,
                'email' => $email
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Error al crear cliente: ' . $stmt->error]);
        }
        $stmt->close();
        exit;
    }
    
    // OBTENER MOVIMIENTO PARA EDITAR
    if (isset($_GET['editar_movimiento'])) {
        header('Content-Type: application/json');
        $mov_id = intval($_GET['editar_movimiento']);
        $stmt = $conn->prepare("SELECT * FROM movimientos_caja WHERE id = ?");
        $stmt->bind_param("i", $mov_id);
        $stmt->execute();
        $movimiento = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode($movimiento ? ['ok' => true, 'movimiento' => $movimiento] : ['ok' => false, 'error' => 'Movimiento no encontrado']);
        exit;
    }
    
    // ACTUALIZAR MOVIMIENTO
    if (isset($_POST['actualizar_movimiento'])) {
        header('Content-Type: application/json');
        $id = intval($_POST['id']);
        $tipo = $_POST['tipo'];
        $metodo_pago = $_POST['metodo_pago'];
        $monto = floatval($_POST['monto']);
        $descripcion = trim($_POST['descripcion']);

        if ($mov_tiene_metodo) {
            $stmt = $conn->prepare("UPDATE movimientos_caja SET tipo=?, metodo_pago=?, monto=?, descripcion=? WHERE id=?");
            $stmt->bind_param("ssdsi", $tipo, $metodo_pago, $monto, $descripcion, $id);
        } else {
            $stmt = $conn->prepare("UPDATE movimientos_caja SET tipo=?, monto=?, descripcion=? WHERE id=?");
            $stmt->bind_param("sdsi", $tipo, $monto, $descripcion, $id);
        }
        echo $stmt->execute() ? json_encode(['ok' => true, 'mensaje' => 'Movimiento actualizado']) : json_encode(['ok' => false, 'error' => $stmt->error]);
        $stmt->close();
        exit;
    }
    
    // OBTENER DATOS FACTURA
    if (isset($_GET['get_factura_data'])) {
        header('Content-Type: application/json');
        $venta_id = intval($_GET['get_factura_data']);
        $stmt = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
        $stmt->bind_param("i", $venta_id);
        $stmt->execute();
        $venta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$venta) {
            echo json_encode(['ok' => false, 'error' => 'Venta no encontrada']);
            exit;
        }
        $stmt = $conn->prepare("SELECT * FROM venta_detalles WHERE venta_id = ?");
        $stmt->bind_param("i", $venta_id);
        $stmt->execute();
        $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['ok' => true, 'venta' => $venta, 'productos' => $productos]);
        exit;
    }
    
    // REGISTRAR VENTA
    if (isset($_POST['registrar_venta'])) {
        header('Content-Type: application/json');
        while (ob_get_level()) ob_end_clean();
        
        $items_json = $_POST['items_json'] ?? '';
        $manual_total = isset($_POST['manual_total']) && intval($_POST['manual_total']) === 1;
        $metodo_pago = $_POST['metodo_pago'] ?? 'Efectivo';
        $cliente_nombre = trim($_POST['cliente_nombre'] ?? '') ?: 'Cliente general';
        $motivo = trim($_POST['motivo'] ?? '') ?: 'Venta general';
        $total = floatval($_POST['total'] ?? 0);
        $monto_recibido = isset($_POST['monto_recibido']) ? floatval($_POST['monto_recibido']) : $total;
        $cambio = $monto_recibido - $total;
        
        if ($total <= 0 || (empty($items_json) && !$manual_total)) {
            echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
            exit;
        }
        
        $items = [];
        if (!empty($items_json)) {
            $items = json_decode($items_json, true);
            if ($items === null) {
                echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
                exit;
            }
        } elseif ($manual_total) {
            $items = [];
        }

        if ($metodo_pago === 'Efectivo' && $monto_recibido < $total) {
            echo json_encode(['ok' => false, 'error' => 'El monto recibido es menor al total']);
            exit;
        }

        if ($metodo_pago !== 'Efectivo') {
            $monto_recibido = $total;
            $cambio = 0;
        }
        
        $caja_activa = obtenerCajaActiva($conn);
        if (!$caja_activa) {
            echo json_encode(['ok' => false, 'error' => 'No hay caja abierta']);
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $venta_campos = ['cliente_nombre', 'fecha_venta', 'total', 'motivo'];
            $venta_vals = ['?', 'NOW()', '?', '?'];
            $venta_tipos = 'ssd';
            $venta_params = [$cliente_nombre, $total, $motivo];

            if ($ventas_tiene_metodo) {
                $venta_campos[] = 'metodo_pago';
                $venta_vals[] = '?';
                $venta_tipos .= 's';
                $venta_params[] = $metodo_pago;
            }
            if ($ventas_tiene_monto_recibido) {
                $venta_campos[] = 'monto_recibido';
                $venta_vals[] = '?';
                $venta_tipos .= 'd';
                $venta_params[] = $monto_recibido;
            }
            if ($ventas_tiene_cambio) {
                $venta_campos[] = 'cambio';
                $venta_vals[] = '?';
                $venta_tipos .= 'd';
                $venta_params[] = $cambio;
            }

            $venta_sql = "INSERT INTO ventas (" . implode(', ', $venta_campos) . ") VALUES (" . implode(', ', $venta_vals) . ")";
            $stmt = $conn->prepare($venta_sql);
            $stmt->bind_param($venta_tipos, ...$venta_params);
            $stmt->execute();
            $venta_id = $conn->insert_id;
            $stmt->close();
            
            // Determinar descripción del movimiento en caja
            $descripcion = !empty($motivo) ? $motivo : 'Venta general';
            
            // Insertar detalles de productos si existen (para auditoría)
            if (!empty($items)) {
                $stmt_d = $conn->prepare("INSERT INTO venta_detalles (venta_id, producto_id, producto_nombre, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
                
                foreach ($items as $item) {
                    $pid = intval($item['id'] ?? 0);
                    $nombre = $item['nombre'] ?? 'Costo';
                    $cantidad = intval($item['cantidad'] ?? 1);
                    $precio = floatval($item['precio'] ?? 0);
                    $subtotal = $cantidad * $precio;
                    $stmt_d->bind_param("iisidd", $venta_id, $pid, $nombre, $cantidad, $precio, $subtotal);
                    $stmt_d->execute();
                    
                    if ($pid > 0) {
                        $upd = $conn->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
                        $upd->bind_param("ii", $cantidad, $pid);
                        $upd->execute();
                        $upd->close();
                    }
                }
                $stmt_d->close();
            }
            
            if ($mov_tiene_metodo) {
                $sc = $conn->prepare("INSERT INTO movimientos_caja (caja_id, tipo, metodo_pago, monto, descripcion, fecha, venta_id) VALUES (?, 'venta', ?, ?, ?, NOW(), ?)");
                $sc->bind_param("isdsi", $caja_activa['id'], $metodo_pago, $total, $descripcion, $venta_id);
            } else {
                $sc = $conn->prepare("INSERT INTO movimientos_caja (caja_id, tipo, monto, descripcion, fecha, venta_id) VALUES (?, 'venta', ?, ?, NOW(), ?)");
                $sc->bind_param("idsi", $caja_activa['id'], $total, $descripcion, $venta_id);
            }
            $sc->execute();
            $sc->close();
            
            $conn->commit();
            echo json_encode(['ok' => true, 'venta_id' => $venta_id, 'total' => $total]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // REGISTRAR OTROS MOVIMIENTOS
    if (isset($_POST['registrar_movimiento_manual'])) {
        header('Content-Type: application/json');
        $tipo = $_POST['tipo'] ?? 'ingreso';
        $metodo = trim($_POST['metodo_pago'] ?? 'Efectivo');
        $monto = floatval($_POST['monto'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        
        if ($monto <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Monto inválido']);
            exit;
        }
        
        $caja_activa = obtenerCajaActiva($conn);
        if (!$caja_activa) {
            echo json_encode(['ok' => false, 'error' => 'No hay caja abierta']);
            exit;
        }
        
        // Usar el motivo ingresado por el usuario o usar valores por defecto
        if ($motivo !== '') {
            $descripcion = $motivo;
        } else {
            $descripcion = $tipo === 'ingreso' ? 'Ingreso' : ($tipo === 'devolucion' ? 'Devolución' : 'Egreso');
        }
        
        if ($mov_tiene_metodo) {
            $sm = $conn->prepare("INSERT INTO movimientos_caja (caja_id, tipo, metodo_pago, monto, descripcion, fecha) VALUES (?, ?, ?, ?, ?, NOW())");
            $sm->bind_param("issds", $caja_activa['id'], $tipo, $metodo, $monto, $descripcion);
        } else {
            $sm = $conn->prepare("INSERT INTO movimientos_caja (caja_id, tipo, monto, descripcion, fecha) VALUES (?, ?, ?, ?, NOW())");
            $sm->bind_param("isds", $caja_activa['id'], $tipo, $monto, $descripcion);
        }
        $sm->execute();
        $sm->close();
        echo json_encode(['ok' => true, 'mensaje' => 'Movimiento registrado']);
        exit;
    }
    
    // ELIMINAR VENTA
    if (isset($_POST['eliminar_venta'])) {
        header('Content-Type: application/json');
        $venta_id = intval($_POST['venta_id']);
        
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT producto_id, cantidad FROM venta_detalles WHERE venta_id = ?");
            $stmt->bind_param("i", $venta_id);
            $stmt->execute();
            $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            foreach ($productos as $p) {
                $upd = $conn->prepare("UPDATE productos SET stock = stock + ? WHERE id = ?");
                $upd->bind_param("ii", $p['cantidad'], $p['producto_id']);
                $upd->execute();
                $upd->close();
            }
            
            $del1 = $conn->prepare("DELETE FROM venta_detalles WHERE venta_id = ?");
            $del1->bind_param("i", $venta_id);
            $del1->execute();
            $del1->close();

            $del2 = $conn->prepare("DELETE FROM movimientos_caja WHERE venta_id = ?");
            $del2->bind_param("i", $venta_id);
            $del2->execute();
            $del2->close();

            $del3 = $conn->prepare("DELETE FROM ventas WHERE id = ?");
            $del3->bind_param("i", $venta_id);
            $del3->execute();
            $del3->close();
            
            $conn->commit();
            echo json_encode(['ok' => true, 'mensaje' => 'Venta eliminada']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // ELIMINAR MOVIMIENTO
    if (isset($_POST['eliminar_movimiento'])) {
        header('Content-Type: application/json');
        $mov_id = intval($_POST['mov_id']);
        $stmt = $conn->prepare("DELETE FROM movimientos_caja WHERE id = ?");
        $stmt->bind_param("i", $mov_id);
        echo $stmt->execute() ? json_encode(['ok' => true, 'mensaje' => 'Movimiento eliminado']) : json_encode(['ok' => false, 'error' => $stmt->error]);
        $stmt->close();
        exit;
    }
    
    exit;
}

// --- SUBIR PDF GENERADO DESDE EL NAVEGADOR ---
if (isset($_GET['action']) && $_GET['action'] === 'upload_pdf') {
    header('Content-Type: application/json');
    $venta_id = intval($_POST['id'] ?? 0);
    if ($venta_id <= 0 || !isset($_FILES['pdf'])) {
        echo json_encode(['ok' => false, 'error' => 'Datos incompletos para subir PDF']);
        exit;
    }

    $upload_dir = dirname(__DIR__) . '/uploads/recibos';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo crear la carpeta de recibos']);
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

    $filename = 'recibo_' . str_pad($venta_id, 8, '0', STR_PAD_LEFT) . '.pdf';
    $target_path = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($pdf_tmp, $target_path)) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo PDF']);
        exit;
    }

    $base_url = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    $file_url = $base_url . '/uploads/recibos/' . rawurlencode($filename);
    echo json_encode(['ok' => true, 'url' => $file_url]);
    exit;
}

// --- ENVIAR WHATSAPP AUTOMÁTICO ---
if (isset($_GET['action']) && $_GET['action'] === 'enviar_whatsapp_auto') {
    header('Content-Type: application/json');
    $venta_id = intval($_POST['venta_id'] ?? 0);
    $numero = trim($_POST['numero'] ?? '');
    $pdf_url = trim($_POST['pdf_url'] ?? '');

    if ($venta_id <= 0 || empty($numero)) {
        echo json_encode(['ok' => false, 'error' => 'Datos incompletos']);
        exit;
    }

    // Obtener datos de la venta
    $stmt = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $venta = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$venta) {
        echo json_encode(['ok' => false, 'error' => 'Venta no encontrada']);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM venta_detalles WHERE venta_id = ?");
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Preparar mensaje
    $productos_texto = array_map(function($p) {
        $subtotal = number_format((float)($p['subtotal'] ?: ($p['precio_unitario'] * $p['cantidad'])), 0, ',', '.');
        return "✨ {$p['producto_nombre']} x{$p['cantidad']} = $$subtotal";
    }, $productos);

    $total = number_format((float)$venta['total'], 0, ',', '.');
    $fecha = date('d/m/Y H:i', strtotime($venta['fecha_venta']));

    $mensaje = "🛍️ *LUXURY STORE* 🛍️\n" .
               "━━━━━━━━━━━━━━━━━━━━\n" .
               "🧾 *RECIBO DE VENTA*\n" .
               "━━━━━━━━━━━━━━━━━━━━\n" .
               "📌 *N° Venta:* " . str_pad($venta['id'], 8, '0', STR_PAD_LEFT) . "\n" .
               "📅 *Fecha:* {$fecha}\n" .
               "👤 *Cliente:* {$venta['cliente_nombre']}\n" .
               "💳 *Pago:* " . ($venta['metodo_pago'] ?: 'Efectivo') . "\n\n" .
               "━━━━━━━━━━━━━━━━━━━━\n" .
               "*📦 DETALLE DE PRODUCTOS*\n" .
               "━━━━━━━━━━━━━━━━━━━━\n" .
               implode("\n", $productos_texto) . "\n\n" .
               "━━━━━━━━━━━━━━━━━━━━\n" .
               "*💰 TOTAL: $" . $total . "*\n\n" .
               "━━━━━━━━━━━━━━━━━━━━\n" .
               "✨ *¡GRACIAS POR SU COMPRA!* ✨\n" .
               "🏠 {$empresa['direccion']}\n" .
               "📞 {$empresa['telefono']}\n" .
               "🌐 {$empresa['web']}\n" .
               "━━━━━━━━━━━━━━━━━━━━\n" .
               "*Recibo válido como soporte contable*";

    // Enviar WhatsApp automático - requiere integración con API (Meta/WhatsApp Business)
    // No hay API configurada; se devuelve el mensaje preparado para compartir manualmente.
    $resultado = ['ok' => true, 'mensaje' => $mensaje, 'manual' => true, 'url_whatsapp' => 'https://wa.me/?text=' . rawurlencode($mensaje)];

    echo json_encode($resultado);
    exit;
}

// ====================================================================
// PROCESAR POST NORMALES (abrir/cerrar/reabrir caja)
// ====================================================================
if (isset($_POST['abrir_caja'])) {
    $si = floatval($_POST['saldo_inicial']);
    $fa = date('Y-m-d H:i:s');
    unset($_SESSION['resumen_ultima_caja']);
    $s = $conn->prepare("INSERT INTO cajas (fecha_apertura, saldo_inicial, estado, usuario_id) VALUES (?, ?, 'abierta', ?)");
    if ($s) {
        $s->bind_param("sdi", $fa, $si, $usuario_id);
        $s->execute();
        $s->close();
    }
    echo "<script>window.location='./';</script>";
    exit;
}

if (isset($_POST['reabrir_caja'])) {
    $check = $conn->query("SELECT id FROM cajas WHERE estado='abierta' LIMIT 1");
    if ($check && $check->num_rows > 0) {
        echo "<script>alert('⚠️ Ya existe una caja abierta.'); window.location='./';</script>";
        exit;
    }
    
    $res = $conn->query("SELECT * FROM cajas WHERE estado='cerrada' ORDER BY fecha_cierre DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        echo "<script>alert('❌ No hay caja cerrada para reabrir.'); window.location='./';</script>";
        exit;
    }
    
    $ultima_caja = $res->fetch_assoc();
    $cid = $ultima_caja['id'];
    
    $s = $conn->prepare("UPDATE cajas SET estado='abierta', fecha_cierre=NULL, saldo_final=NULL WHERE id=?");
    $s->bind_param("i", $cid);
    $s->execute();
    $s->close();
    
    echo "<script>alert('✅ Caja reabierta exitosamente. Saldo inicial: " . formatearCOP($ultima_caja['saldo_inicial']) . "'); window.location='./';</script>";
    exit;
}

if (isset($_POST['cerrar_caja'])) {
    $caja_activa = obtenerCajaActiva($conn);
    if ($caja_activa) {
        $conn->begin_transaction();
        try {
            $res = $conn->query("SELECT 
                COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) as ventas,
                COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) as ingresos,
                COALESCE(SUM(CASE WHEN tipo IN ('egreso','devolucion') THEN monto ELSE 0 END),0) as egresos
                FROM movimientos_caja WHERE caja_id=" . $caja_activa['id']);
            $totales = $res ? $res->fetch_assoc() : ['ventas'=>0,'ingresos'=>0,'egresos'=>0];
            $sf = $caja_activa['saldo_inicial'] + $totales['ventas'] + $totales['ingresos'] - $totales['egresos'];
            $fc = date('Y-m-d H:i:s');
            $s = $conn->prepare("UPDATE cajas SET saldo_final=?, fecha_cierre=?, estado='cerrada' WHERE id=?");
            $s->bind_param("dsi", $sf, $fc, $caja_activa['id']);
            $s->execute();
            $s->close();
            $res_mov = $conn->query("SELECT COUNT(*) AS total FROM movimientos_caja WHERE caja_id=" . (int)$caja_activa['id']);
            $movimientos_cerrados = $res_mov ? (int)($res_mov->fetch_assoc()['total'] ?? 0) : 0;
            $_SESSION['resumen_ultima_caja'] = [
                'id' => $caja_activa['id'],
                'fecha_apertura' => $caja_activa['fecha_apertura'],
                'fecha_cierre' => $fc,
                'saldo_inicial' => $caja_activa['saldo_inicial'],
                'saldo_final' => $sf,
                'ventas' => $totales['ventas'],
                'ingresos' => $totales['ingresos'],
                'egresos' => $totales['egresos'],
                'movimientos' => $movimientos_cerrados
            ];
            $conn->commit();
            echo "<script>alert('✅ Caja cerrada. Saldo final: " . formatearCOP($sf) . "'); window.location='./';</script>";
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('❌ Error: " . addslashes($e->getMessage()) . "'); window.location='./';</script>";
        }
        exit;
    }
}

// ====================================================================
// DATOS PARA LA VISTA
// ====================================================================
$caja = obtenerCajaActiva($conn);

$movimientos_paginados = [];
$total_ventas = 0;
$total_ingresos = 0;
$total_egresos = 0;
$saldo_actual = 0;
$pagina_actual = 1;
$total_paginas = 1;
$total_movimientos = 0;
$por_pagina = 10;

  if ($caja) {
    $count_res = $conn->query("SELECT COUNT(*) as total FROM movimientos_caja WHERE caja_id=" . (int)$caja['id']);
    $total_movimientos = $count_res ? (int)$count_res->fetch_assoc()['total'] : 0;
    $total_paginas = max(1, ceil($total_movimientos / $por_pagina));
    $pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $pagina_actual = min($pagina_actual, $total_paginas);
    $offset = ($pagina_actual - 1) * $por_pagina;
    
    $res = $conn->query("SELECT * FROM movimientos_caja WHERE caja_id=" . (int)$caja['id'] . " ORDER BY fecha DESC LIMIT $por_pagina OFFSET $offset");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $movimientos_paginados[] = $row;
            if ($row['tipo'] === 'venta') $total_ventas += $row['monto'];
            elseif ($row['tipo'] === 'ingreso') $total_ingresos += $row['monto'];
            elseif (in_array($row['tipo'], ['egreso', 'devolucion'], true)) $total_egresos += $row['monto'];
        }
    }
      $saldo_actual = $caja['saldo_inicial'] + $total_ventas + $total_ingresos - $total_egresos;
  }

  // ================= Resumen por periodo (hoy/semana/mes) =================
  $resumen_hoy = ['ventas' => 0, 'ingresos' => 0, 'egresos' => 0, 'saldo' => 0];
  $resumen_semana = ['ventas' => 0, 'ingresos' => 0, 'egresos' => 0, 'saldo' => 0];
  $resumen_mes = ['ventas' => 0, 'ingresos' => 0, 'egresos' => 0, 'saldo' => 0];
  $chart_labels = [];
  $chart_ventas = [];
  $chart_ingresos = [];
  $chart_egresos = [];
  $chart_saldo = [];

  if ($caja) {
      $hoy = date('Y-m-d');
      $inicio_semana = date('Y-m-d', strtotime('monday this week'));
      $inicio_mes = date('Y-m-01');

      $stmt_res = $conn->prepare("
          SELECT 
            COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) AS ventas,
            COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) AS ingresos,
            COALESCE(SUM(CASE WHEN tipo IN ('egreso','devolucion') THEN monto ELSE 0 END),0) AS egresos
          FROM movimientos_caja
          WHERE caja_id = ? AND fecha BETWEEN ? AND ?
      ");

      if ($stmt_res) {
          $f1 = $hoy . " 00:00:00";
          $f2 = $hoy . " 23:59:59";
          $stmt_res->bind_param("iss", $caja['id'], $f1, $f2);
          $stmt_res->execute();
          $resumen_hoy = $stmt_res->get_result()->fetch_assoc() ?: $resumen_hoy;

          $f1 = $inicio_semana . " 00:00:00";
          $f2 = $hoy . " 23:59:59";
          $stmt_res->bind_param("iss", $caja['id'], $f1, $f2);
          $stmt_res->execute();
          $resumen_semana = $stmt_res->get_result()->fetch_assoc() ?: $resumen_semana;

          $f1 = $inicio_mes . " 00:00:00";
          $f2 = $hoy . " 23:59:59";
          $stmt_res->bind_param("iss", $caja['id'], $f1, $f2);
          $stmt_res->execute();
          $resumen_mes = $stmt_res->get_result()->fetch_assoc() ?: $resumen_mes;

          $stmt_res->close();
      }

      $resumen_hoy['saldo'] = $resumen_hoy['ventas'] + $resumen_hoy['ingresos'] - $resumen_hoy['egresos'];
      $resumen_semana['saldo'] = $resumen_semana['ventas'] + $resumen_semana['ingresos'] - $resumen_semana['egresos'];
      $resumen_mes['saldo'] = $resumen_mes['ventas'] + $resumen_mes['ingresos'] - $resumen_mes['egresos'];

      // Datos para gráfico por día
      $chart_labels_dias = [];
      $chart_ventas_dias = [];
      $chart_ingresos_dias = [];
      $chart_egresos_dias = [];
      $chart_saldo_dias = [];
      $chart_labels_semanas = [];
      $chart_ventas_semanas = [];
      $chart_ingresos_semanas = [];
      $chart_egresos_semanas = [];
      $chart_saldo_semanas = [];
      $chart_labels_meses = [];
      $chart_ventas_meses = [];
      $chart_ingresos_meses = [];
      $chart_egresos_meses = [];
      $chart_saldo_meses = [];

      $map = [];
      for ($i = 6; $i >= 0; $i--) {
          $d = date('Y-m-d', strtotime("-$i days"));
          $map[$d] = ['ventas' => 0, 'ingresos' => 0, 'egresos' => 0];
      }
      $desde = date('Y-m-d', strtotime('-6 days')) . " 00:00:00";
      $hasta = $hoy . " 23:59:59";
      $stmt_g = $conn->prepare("
          SELECT DATE(fecha) as dia,
            COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) AS ventas,
            COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) AS ingresos,
            COALESCE(SUM(CASE WHEN tipo IN ('egreso','devolucion') THEN monto ELSE 0 END),0) AS egresos
          FROM movimientos_caja
          WHERE caja_id = ? AND fecha BETWEEN ? AND ?
          GROUP BY DATE(fecha)
          ORDER BY DATE(fecha) ASC
      ");
      if ($stmt_g) {
          $stmt_g->bind_param("iss", $caja['id'], $desde, $hasta);
          $stmt_g->execute();
          $resg = $stmt_g->get_result();
          while ($row = $resg->fetch_assoc()) {
              $dia = $row['dia'];
              if (isset($map[$dia])) {
                  $map[$dia] = $row;
              }
          }
          $stmt_g->close();
      }

      foreach ($map as $dia => $vals) {
          $chart_labels_dias[] = date('d M', strtotime($dia));
          $chart_ventas_dias[] = (float)$vals['ventas'];
          $chart_ingresos_dias[] = (float)$vals['ingresos'];
          $chart_egresos_dias[] = (float)$vals['egresos'];
          $chart_saldo_dias[] = (float)$vals['ventas'] + (float)$vals['ingresos'] - (float)$vals['egresos'];
      }

      // Datos para gráfico por semana (últimas 8 semanas)
      $week_map = [];
      for ($i = 7; $i >= 0; $i--) {
          $weekStart = date('Y-m-d', strtotime("monday this week -$i week"));
          $weekIndex = date('o-\WW', strtotime($weekStart));
          $week_map[$weekIndex] = ['ventas' => 0, 'ingresos' => 0, 'egresos' => 0, 'label' => date('d M', strtotime($weekStart))];
      }
      $desde = date('Y-m-d', strtotime('monday this week -7 week')) . " 00:00:00";
      $hasta = date('Y-m-d', strtotime('sunday this week')) . " 23:59:59";
      $stmt_w = $conn->prepare("
          SELECT YEARWEEK(fecha, 3) AS ano_semana,
                 MIN(DATE(fecha)) AS inicio_semana,
                 COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) AS ventas,
                 COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) AS ingresos,
                 COALESCE(SUM(CASE WHEN tipo IN ('egreso','devolucion') THEN monto ELSE 0 END),0) AS egresos
          FROM movimientos_caja
          WHERE caja_id = ? AND fecha BETWEEN ? AND ?
          GROUP BY ano_semana
          ORDER BY ano_semana ASC
      ");
      if ($stmt_w) {
          $stmt_w->bind_param("iss", $caja['id'], $desde, $hasta);
          $stmt_w->execute();
          $resw = $stmt_w->get_result();
          while ($row = $resw->fetch_assoc()) {
              $weekIndex = date('o-\WW', strtotime($row['inicio_semana']));
              if (isset($week_map[$weekIndex])) {
                  $week_map[$weekIndex] = array_merge($week_map[$weekIndex], $row);
              }
          }
          $stmt_w->close();
      }
      foreach ($week_map as $weekIndex => $vals) {
          $chart_labels_semanas[] = ($vals['label'] ?? date('d M', strtotime(substr($weekIndex, 0, 4) . '-01-01'))) . ' - ' . substr($weekIndex, -2);
          $chart_ventas_semanas[] = (float)$vals['ventas'];
          $chart_ingresos_semanas[] = (float)$vals['ingresos'];
          $chart_egresos_semanas[] = (float)$vals['egresos'];
          $chart_saldo_semanas[] = (float)$vals['ventas'] + (float)$vals['ingresos'] - (float)$vals['egresos'];
      }

      // Datos para gráfico por mes (últimos 6 meses)
      $month_map = [];
      for ($i = 5; $i >= 0; $i--) {
          $monthKey = date('Y-m', strtotime("-$i months"));
          $month_map[$monthKey] = ['ventas' => 0, 'ingresos' => 0, 'egresos' => 0, 'label' => date('M Y', strtotime("$monthKey-01"))];
      }
      $desde = date('Y-m-01', strtotime('-5 months')) . " 00:00:00";
      $hasta = date('Y-m-t') . " 23:59:59";
      $stmt_m = $conn->prepare("
          SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes,
                 COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) AS ventas,
                 COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) AS ingresos,
                 COALESCE(SUM(CASE WHEN tipo IN ('egreso','devolucion') THEN monto ELSE 0 END),0) AS egresos
          FROM movimientos_caja
          WHERE caja_id = ? AND fecha BETWEEN ? AND ?
          GROUP BY mes
          ORDER BY mes ASC
      ");
      if ($stmt_m) {
          $stmt_m->bind_param("iss", $caja['id'], $desde, $hasta);
          $stmt_m->execute();
          $resm = $stmt_m->get_result();
          while ($row = $resm->fetch_assoc()) {
              $mesKey = $row['mes'];
              if (isset($month_map[$mesKey])) {
                  $month_map[$mesKey] = array_merge($month_map[$mesKey], $row);
              }
          }
          $stmt_m->close();
      }
      foreach ($month_map as $monthKey => $vals) {
          $chart_labels_meses[] = $vals['label'] ?? date('M Y', strtotime("$monthKey-01"));
          $chart_ventas_meses[] = (float)$vals['ventas'];
          $chart_ingresos_meses[] = (float)$vals['ingresos'];
          $chart_egresos_meses[] = (float)$vals['egresos'];
          $chart_saldo_meses[] = (float)$vals['ventas'] + (float)$vals['ingresos'] - (float)$vals['egresos'];
      }
  }

  // Obtener facturas recientes
  $facturas_recientes = [];
  $select_metodo = $ventas_tiene_metodo ? 'metodo_pago' : "'' AS metodo_pago";
  $res_facturas = $conn->query("SELECT id, cliente_nombre, fecha_venta, total, motivo, $select_metodo FROM ventas ORDER BY id DESC LIMIT 10");
if ($res_facturas) {
    $facturas_recientes = $res_facturas->fetch_all(MYSQLI_ASSOC);
}

$ultima_caja = null;
$res_ultima = $conn->query("SELECT * FROM cajas WHERE estado='cerrada' ORDER BY fecha_cierre DESC LIMIT 1");
if ($res_ultima) {
    $ultima_caja = $res_ultima->fetch_assoc();
}
$resumen_ultima_caja = $_SESSION['resumen_ultima_caja'] ?? null;
if (!$resumen_ultima_caja && $ultima_caja) {
    $res = $conn->query("SELECT 
        COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) as ventas,
        COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) as ingresos,
        COALESCE(SUM(CASE WHEN tipo IN ('egreso','devolucion') THEN monto ELSE 0 END),0) as egresos,
        COUNT(*) as movimientos
        FROM movimientos_caja WHERE caja_id = " . (int)$ultima_caja['id']);
    $totales_ultima = $res ? $res->fetch_assoc() : ['ventas' => 0, 'ingresos' => 0, 'egresos' => 0, 'movimientos' => 0];
    $resumen_ultima_caja = [
        'id' => $ultima_caja['id'],
        'fecha_apertura' => $ultima_caja['fecha_apertura'],
        'fecha_cierre' => $ultima_caja['fecha_cierre'],
        'saldo_inicial' => $ultima_caja['saldo_inicial'],
        'saldo_final' => $ultima_caja['saldo_final'],
        'ventas' => $totales_ultima['ventas'],
        'ingresos' => $totales_ultima['ingresos'],
        'egresos' => $totales_ultima['egresos'],
        'movimientos' => $totales_ultima['movimientos']
    ];
}

$dias_semana = ["Domingo","Lunes","Martes","Miércoles","Jueves","Viernes","Sábado"];
$meses_año = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
$fecha_formateada = $dias_semana[date('w')] . " " . date('d') . " de " . $meses_año[date('n')-1] . ", " . date('Y');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury POS | Sistema de Ventas</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <script src="../assets/vendor/fontawesome/js/all.min.js"></script>
    <script src="../assets/vendor/html2pdf/html2pdf.bundle.min.js"></script>
    <script src="../assets/vendor/chartjs/chart.umd.min.js"></script>
    <style>
        .toast { position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 12px 20px; border-radius: 12px; z-index: 1000; animation: slideIn 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .toast.error { background: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .quantity-btn { width: 32px; height: 32px; border-radius: 50%; background: #e5e7eb; border: none; font-size: 18px; font-weight: bold; cursor: pointer; transition: all 0.2s; }
        .quantity-btn:active { transform: scale(0.92); }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center; }
        .modal-content { background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 100%); border-radius: 24px; max-width: 700px; width: 95%; max-height: 90vh; overflow-y: auto; padding: 0; box-shadow: 0 25px 80px rgba(0,0,0,0.5); border: 1px solid rgba(212,175,55,0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 24px 32px 16px; border-bottom: 1px solid rgba(212,175,55,0.2); margin-bottom: 0; background: linear-gradient(135deg, #000000, #1a1a1a); border-radius: 24px 24px 0 0; }
        .modal-header h3 { color: #ffffff; font-size: 20px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 12px; }
        .modal-header h3 i { color: #D4AF37; }
        .modal-header button { background: rgba(212,175,55,0.1); color: #D4AF37; border: 1px solid rgba(212,175,55,0.3); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 18px; transition: all 0.3s; }
        .modal-header button:hover { background: rgba(212,175,55,0.2); transform: scale(1.05); }
        .client-search-results { position: absolute; background: white; border: 1px solid #e5e7eb; border-radius: 12px; max-height: 280px; overflow-y: auto; z-index: 50; width: 100%; top: 100%; left: 0; display: none; box-shadow: 0 8px 25px rgba(0,0,0,0.15); margin-top: 4px; }
        .client-search-item { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background 0.2s; }
        .client-search-item:hover { background: #f0fdf4; }
        .product-search-results { display: none; }
        .product-search-item { display: none; }
        .scanner-toggle-btn { display: none; }
        .relative { position: relative; }
        .factura-panel { background: linear-gradient(135deg, #ecfdf5, #d1fae5); border: 1px solid #6ee7b7; border-radius: 16px; padding: 12px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .factura-panel.hidden { display: none; }
        .btn-panel-pdf { background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; border: none; padding: 10px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3); }
        .btn-panel-pdf:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4); }
        .btn-panel-wa { background: linear-gradient(135deg, #25D366, #128C7E); color: white; border: none; padding: 10px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3); }
        .btn-panel-wa:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4); }
        .btn-panel-close { background: #9ca3af; color: white; border: none; padding: 6px 12px; border-radius: 40px; font-size: 12px; cursor: pointer; }
        .action-btn { width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; background: transparent; border: none; font-size: 12px; transition: all 0.2s; }
        .action-btn.view { color: #3b82f6; } .action-btn.view:hover { background: #eff6ff; }
        .action-btn.edit { color: #f59e0b; } .action-btn.edit:hover { background: #fffbeb; }
        .action-btn.pdf { color: #dc2626; } .action-btn.pdf:hover { background: #fef2f2; }
        .action-btn.whatsapp { color: #25D366; } .action-btn.whatsapp:hover { background: #e8f5e9; }
        .action-btn.delete { color: #ef4444; } .action-btn.delete:hover { background: #fef2f2; }
        .pagination { display: flex; justify-content: center; gap: 6px; padding: 12px; flex-wrap: wrap; border-top: 1px solid #e5e7eb; }
        .pagination a { padding: 6px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: white; font-size: 12px; text-decoration: none; color: #374151; transition: all 0.2s; }
        .pagination a:hover { background: #f3f4f6; }
        .pagination .active { background: #4f46e5; color: white; border-color: #4f46e5; }
        .stats-card { transition: all 0.2s; cursor: default; }
        .stats-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
        .money-input { text-align: right; font-family: monospace; font-weight: bold; }
        .factura-item { transition: all 0.2s; cursor: pointer; }
        .factura-item:hover { background: #f0fdf4; }
        .tab-active { border-bottom: 2px solid #10b981; color: #10b981; }
        .modal-content::-webkit-scrollbar { width: 6px; }
        .modal-content::-webkit-scrollbar-track { background: rgba(212,175,55,0.1); border-radius: 3px; }
        .modal-content::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.3); border-radius: 3px; }
        .modal-content::-webkit-scrollbar-thumb:hover { background: rgba(212,175,55,0.5); }
        .ventas-resumen-card::before { content: ''; position: absolute; inset: 0; background: radial-gradient(circle at top left, rgba(129, 140, 248, 0.18), transparent 55%), radial-gradient(circle at 80% 10%, rgba(59, 130, 246, 0.12), transparent 50%); pointer-events: none; }
        .ventas-resumen-accent { height: 4px; background: linear-gradient(90deg, #6366f1, #a855f7, #f59e0b); }
        .ventas-resumen-header { position: relative; background: linear-gradient(90deg, rgba(99,102,241,0.08), rgba(168,85,247,0.08)); backdrop-filter: blur(6px); }
        .ventas-chip { background: rgba(15, 23, 42, 0.08); color: #334155; border: 1px solid rgba(148, 163, 184, 0.25); }
        .ventas-tabs { background: rgba(15, 23, 42, 0.06); border: 1px solid rgba(148, 163, 184, 0.25); }
        .ventas-tab { color: #475569; border: 1px solid transparent; }
        .ventas-tab:hover { background: rgba(255, 255, 255, 0.7); color: #1e293b; }
        .ventas-tab.active { background: #ffffff; color: #4338ca; border-color: rgba(99, 102, 241, 0.25); box-shadow: 0 6px 18px rgba(67, 56, 202, 0.15); }
        .ventas-panel { background: rgba(255, 255, 255, 0.85); }
        .ventas-rows { padding: 8px 12px 2px; }
        .ventas-panel.fade-enter { animation: ventasFade 0.25s ease; }
        @keyframes ventasFade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gray-100">
<?php luxury_render_nav_start('caja'); ?>

<div class="">

    <div id="connection-status" class="hidden"></div>

    <?php if (!$caja): ?>
    <!-- ================= CAJA CERRADA ================= -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Abrir Nueva Caja -->
        <div class="bg-white rounded-2xl shadow-xl p-8 text-center border border-gray-100">
            <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-lock text-red-500 text-3xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Caja Cerrada</h2>
            <p class="text-gray-500 mb-6">Para comenzar a vender, abre una nueva sesión de caja</p>
            <form method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fondo Inicial (COP)</label>
                    <input type="number" name="saldo_inicial" required min="0" placeholder="Ej: 100000" class="w-full border-2 rounded-xl px-4 py-3 text-lg text-center font-bold focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 outline-none transition-all">
                </div>
                <button type="submit" name="abrir_caja" class="w-full bg-gradient-to-r from-emerald-600 to-green-600 hover:from-emerald-700 hover:to-green-700 text-white font-bold py-3 rounded-xl transition-all shadow-md">
                    <i class="fas fa-play mr-2"></i> Abrir Caja
                </button>
            </form>
        </div>
        
        <!-- Reabrir Última Caja con Resumen -->
        <?php if ($ultima_caja): ?>
        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-2xl shadow-xl p-8 text-center border border-amber-200">
            <div class="w-20 h-20 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-history text-amber-600 text-3xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-amber-700 mb-2">Reabrir Última Sesión</h2>
            <div class="bg-amber-100 rounded-xl p-4 mb-6 text-left">
                <div class="flex justify-between mb-2">
                    <span class="text-gray-600">Sesión #<?= $ultima_caja['id'] ?></span>
                    <span class="font-bold text-emerald-600">Saldo final: <?= formatearCOP($ultima_caja['saldo_final'] ?? 0) ?></span>
                </div>
                <div class="flex justify-between text-sm text-gray-500">
                    <span>Apertura: <?= date('d/m/Y H:i', strtotime($ultima_caja['fecha_apertura'])) ?></span>
                    <span>Cierre: <?= date('d/m/Y H:i', strtotime($ultima_caja['fecha_cierre'])) ?></span>
                </div>
                <?php
                // Calcular resumen de la sesión anterior
                $resumen_anterior = [];
                if ($ultima_caja['id']) {
                    $res = $conn->query("SELECT 
                        COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) as ventas,
                        COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) as ingresos,
                        COALESCE(SUM(CASE WHEN tipo IN ('egreso','devolucion') THEN monto ELSE 0 END),0) as egresos,
                        COUNT(*) as movimientos
                        FROM movimientos_caja WHERE caja_id = " . $ultima_caja['id']);
                    if ($res) {
                        $resumen_anterior = $res->fetch_assoc();
                    }
                }
                ?>
                <div class="mt-3 pt-3 border-t border-amber-200 grid grid-cols-2 gap-2 text-xs">
                    <div><span class="text-gray-500">Ventas:</span> <span class="font-bold text-emerald-600"><?= formatearCOP($resumen_anterior['ventas'] ?? 0) ?></span></div>
                    <div><span class="text-gray-500">Ingresos:</span> <span class="font-bold text-blue-600"><?= formatearCOP($resumen_anterior['ingresos'] ?? 0) ?></span></div>
                    <div><span class="text-gray-500">Egresos:</span> <span class="font-bold text-red-600"><?= formatearCOP($resumen_anterior['egresos'] ?? 0) ?></span></div>
                    <div><span class="text-gray-500">Total movimientos:</span> <span class="font-bold"><?= number_format($resumen_anterior['movimientos'] ?? 0) ?></span></div>
                </div>
            </div>
            <form method="POST" onsubmit="return confirm('¿Reabrir esta sesión de caja?')">
                <?= csrf_field() ?>
                <button type="submit" name="reabrir_caja" class="w-full bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-bold py-3 rounded-xl transition-all shadow-md">
                    <i class="fas fa-undo mr-2"></i> Reabrir Sesión
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($resumen_ultima_caja): ?>
    <div class="mt-6 bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
        <div class="bg-gradient-to-r from-slate-900 to-slate-700 px-6 py-4 text-white">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h3 class="text-lg font-bold"><i class="fas fa-chart-line mr-2 text-emerald-400"></i> Resumen de la última caja cerrada</h3>
                    <p class="text-xs text-slate-200">Sesión #<?= $resumen_ultima_caja['id'] ?> cerrada el <?= date('d/m/Y H:i', strtotime($resumen_ultima_caja['fecha_cierre'])) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-slate-300">Saldo final</p>
                    <p class="text-2xl font-extrabold text-emerald-300"><?= formatearCOP($resumen_ultima_caja['saldo_final'] ?? 0) ?></p>
                </div>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6 bg-gradient-to-br from-white to-slate-50">
            <div class="bg-slate-100 rounded-xl p-4">
                <p class="text-xs text-slate-500 uppercase font-bold">Fondo inicial</p>
                <p class="text-xl font-bold text-slate-800"><?= formatearCOP($resumen_ultima_caja['saldo_inicial'] ?? 0) ?></p>
            </div>
            <div class="bg-emerald-50 rounded-xl p-4">
                <p class="text-xs text-emerald-600 uppercase font-bold">Ventas</p>
                <p class="text-xl font-bold text-emerald-700"><?= formatearCOP($resumen_ultima_caja['ventas'] ?? 0) ?></p>
            </div>
           
            <div class="bg-red-50 rounded-xl p-4">
                <p class="text-xs text-red-600 uppercase font-bold">Egresos</p>
                <p class="text-xl font-bold text-red-700"><?= formatearCOP($resumen_ultima_caja['egresos'] ?? 0) ?></p>
            </div>
            <div class="bg-amber-50 rounded-xl p-4">
                <p class="text-xs text-amber-600 uppercase font-bold">Movimientos</p>
                <p class="text-xl font-bold text-amber-700"><?= number_format($resumen_ultima_caja['movimientos'] ?? 0) ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- ================= CAJA ABIERTA ================= -->
    
    <!-- Resumen rápido - Estilo simplificado -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6 shadow-xl rounded-lg p-5">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Fondo Inicial</p>
        <p class="text-2xl font-extrabold text-indigo-600" id="stat-saldo-inicial">$<?= number_format($caja['saldo_inicial']) ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Ventas</p>
        <p class="text-2xl font-extrabold text-emerald-600" id="stat-total-ventas">$<?= number_format($total_ventas) ?></p>
        <?php if ($total_ingresos > 0): ?>
            <p class="text-xs text-blue-400 mt-0.5">+$<?= number_format($total_ingresos) ?> ingresos</p>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Egresos</p>
        <p class="text-2xl font-extrabold text-red-500" id="stat-total-egresos">$<?= number_format($total_egresos) ?></p>
    </div>
    <div class="bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-xl shadow-lg p-4 text-white">
        <p class="text-[10px] font-bold text-indigo-200 uppercase tracking-wider mb-1">Saldo Actual</p>
        <p class="text-3xl font-extrabold" id="stat-saldo-actual">$<?= number_format($saldo_actual) ?></p>
    </div>
</div>
  

    <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6 mb-6">
      <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-4">
        <div>
          <p class="text-xs uppercase tracking-widest text-gray-500">Gráfico</p>
          <h2 class="text-lg font-bold text-gray-800">Ventas por periodo</h2>
          <p class="text-sm text-gray-500">Ver ventas en días, semanas y meses.</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
          <button id="toggle-chart-btn" type="button" class="px-3 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200">Ocultar gráfico</button>
          <button type="button" class="timeframe-btn px-3 py-2 rounded-lg text-xs font-bold bg-indigo-600 text-white shadow-sm" data-period="dias">Días</button>
          <button type="button" class="timeframe-btn px-3 py-2 rounded-lg text-xs font-bold bg-gray-100 text-slate-700 hover:bg-slate-200" data-period="semanas">Semanas</button>
          <button type="button" class="timeframe-btn px-3 py-2 rounded-lg text-xs font-bold bg-gray-100 text-slate-700 hover:bg-slate-200" data-period="meses">Meses</button>
        </div>
      </div>
      <div id="chart-section" class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="rounded-2xl border border-gray-100 p-4 bg-slate-50">
          <div class="text-xs uppercase tracking-widest text-gray-500 mb-3">Resumen por período</div>
          <div class="h-80">
            <canvas id="chartResumenCaja"></canvas>
          </div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4 bg-slate-50">
          <div class="text-xs uppercase tracking-widest text-gray-500 mb-3">Saldo neto</div>
          <div class="h-80">
            <canvas id="chartSaldoCaja"></canvas>
          </div>
        </div>
      </div>
    </div>

   <?php if (!empty($ventas_dias) || !empty($ventas_meses) || !empty($ventas_anios)): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3">
      <div class="flex items-center gap-3">
        <div class="w-7 h-7 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0">
          <i class="fas fa-chart-line text-indigo-600 text-xs"></i>
        </div>
        <div>
          <h2 class="font-bold text-gray-800 text-sm">Resumen de Ventas</h2>
          <p class="text-xs text-gray-400">Clic en una fila para ver el detalle</p>
        </div>
      </div>
      <div class="flex bg-gray-100 rounded-lg p-1 gap-1">
        <button onclick="switchTab('dias')" id="tab-dias"
          class="tab-btn px-3 py-1.5 rounded-md text-xs font-bold transition-all bg-white text-indigo-700 shadow-sm">
          <i class="fas fa-calendar-day mr-1"></i> Días
        </button>
        <button onclick="switchTab('meses')" id="tab-meses"
          class="tab-btn px-3 py-1.5 rounded-md text-xs font-bold transition-all text-gray-500 hover:text-gray-700">
          <i class="fas fa-calendar-alt mr-1"></i> Meses
        </button>
        <button onclick="switchTab('anios')" id="tab-anios"
          class="tab-btn px-3 py-1.5 rounded-md text-xs font-bold transition-all text-gray-500 hover:text-gray-700">
          <i class="fas fa-calendar mr-1"></i> Años
        </button>
      </div>
    </div>

    <!-- TAB DÍAS -->
    <div id="panel-dias" class="tab-panel">
      <div id="rows-dias"></div>
      <div id="pag-dias" class="border-t border-gray-100"></div>
    </div>

    <!-- TAB MESES -->
    <div id="panel-meses" class="tab-panel hidden">
      <div id="rows-meses"></div>
      <div id="pag-meses" class="border-t border-gray-100"></div>
    </div>

    <!-- TAB AÑOS -->
    <div id="panel-anios" class="tab-panel hidden">
      <div id="rows-anios"></div>
      <div id="pag-anios" class="border-t border-gray-100"></div>
    </div>
  </div>
  <?php endif; ?>


    <!-- Panel Factura -->
      <div id="factura-panel" class="factura-panel hidden">
          <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-emerald-500 rounded-full flex items-center justify-center">
                  <i class="fas fa-receipt text-white text-sm"></i>
              </div>
              <div>
                  <p class="font-bold text-emerald-800">Factura #<span id="factura-venta-id"></span> generada</p>
                  <p class="text-emerald-600 text-xs">Descarga el PDF o comparte por WhatsApp</p>
              </div>
          </div>
          <div class="flex gap-2">
              <button id="btn-panel-pdf" class="btn-panel-pdf"><i class="fas fa-file-pdf mr-1"></i> PDF</button>
              <button id="btn-panel-wa" class="btn-panel-wa"><i class="fab fa-whatsapp mr-1"></i> WhatsApp</button>
              <button id="btn-panel-dev" class="btn-panel-close bg-amber-500 hover:bg-amber-600"><i class="fas fa-rotate-left mr-1"></i> Devolver</button>
              <button id="btn-panel-cerrar" class="btn-panel-close"><i class="fas fa-times"></i></button>
          </div>
      </div>

    <!-- Layout principal: 2 columnas -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        <!-- Columna izquierda: CARRITO DE VENTAS -->
          <div class="lg:col-span-7 bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="bg-gradient-to-r from-gray-800 to-gray-900 text-white p-5">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-bold"><i class="fas fa-cash-register mr-2"></i> Caja Registradora</h2>
                        <p class="text-xs text-gray-300 mt-1">Ingresa el costo directamente</p>
                    </div>
                    <button id="limpiar-btn" class="bg-white/10 hover:bg-white/20 text-white px-4 py-2 rounded-xl text-sm transition-all">
                        <i class="fas fa-eraser mr-1"></i> Limpiar
                    </button>
                </div>
            </div>
            
            <div class="p-5">
                <!-- Costo -->
                <div>
                        <!-- Costo -->
                        <div class="mb-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Costo</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">$</span>
                                <input type="text" id="total-costo" placeholder="0" class="w-full border-2 rounded-xl pl-10 pr-5 py-3 text-2xl money-input bg-white focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200 outline-none transition-all">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">$</span>
                            </div>
                            </div>

                           <div>
                             <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Descripción del Movimiento</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold"><i class="fas fa-comment"></i></span>
                                <input type="text" id="venta-motivo" placeholder="Descripción de la venta" class="w-full border-2 rounded-xl pl-10 pr-5 py-3 text-lg bg-white focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200 outline-none transition-all">
                            </div>
                           </div>
                        </div>
                    </div>
                
                <!-- Total -->
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-5 mb-5">
                    <div class="flex justify-between items-center">
                        <span class="text-xl font-bold text-gray-600">TOTAL A PAGAR</span>
                        <span id="cart-total" class="text-4xl font-extrabold text-emerald-600">$0</span>
                    </div>
                </div>
                
                <!-- Cliente y método de pago -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div class="relative">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cliente</label>
                        <div class="flex gap-2">
                            <input type="text" id="cliente-input" placeholder="Buscar por nombre o teléfono..." 
                                   class="flex-1 border rounded-xl px-3 py-2.5 text-sm focus:border-indigo-400 outline-none" autocomplete="off">
                            <button id="btn-nuevo-cliente" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2.5 rounded-xl text-sm font-bold transition-all" title="Nuevo cliente">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div id="client-search-results" class="client-search-results"></div>
                        <input type="hidden" id="cliente-id" value="">
                        <input type="hidden" id="cliente-nombre" value="">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Método de Pago</label>
                        <select id="metodo-pago" class="w-full border rounded-xl px-3 py-2.5 text-sm bg-white focus:border-indigo-400 outline-none">
                            <option value="Efectivo">💵 Efectivo</option>
                            <option value="Nequi">📱 Nequi</option>
                            <option value="Transferencia/QR">📲 Transferencia/QR</option>
                            <option value="Datafono">💳 Datáfono</option>
                        </select>
                    </div>
                    
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Monto Recibido</label>
                        <input type="text" id="monto-recibido" placeholder="0" class="w-full border rounded-xl px-4 py-3 text-lg money-input bg-white focus:border-emerald-400 outline-none">
                        <div class="flex gap-2 mt-2 flex-wrap">
                            <button type="button" class="quick-cash-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-full text-xs font-bold" data-multiple="exacto">Exacto</button>
                            <button type="button" class="quick-cash-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-full text-xs font-bold" data-value="10000">$10k</button>
                            <button type="button" class="quick-cash-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-full text-xs font-bold" data-value="20000">$20k</button>
                            <button type="button" class="quick-cash-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-full text-xs font-bold" data-value="50000">$50k</button>
                        </div>
                    </div>
                    <div class="bg-gradient-to-br from-emerald-50 to-white border border-emerald-100 rounded-xl p-4">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-gray-500 uppercase">Cambio</span>
                            <span id="estado-cambio" class="text-[11px] font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded-full">Listo</span>
                        </div>
                        <div id="cambio-valor" class="text-3xl font-extrabold text-emerald-600"><?= formatearCOP(0) ?></div>
                        <p id="cambio-ayuda" class="text-xs text-gray-500 mt-2">Ingresa el efectivo recibido para calcular el cambio.</p>
                    </div>
                </div>
                
                <!-- Botón Registrar Venta -->
                <button id="vender-btn" class="w-full bg-gradient-to-r from-emerald-600 to-green-600 hover:from-emerald-700 hover:to-green-700 text-white font-bold py-3 rounded-xl text-lg transition-all shadow-md">
                    <i class="fas fa-check-circle mr-2"></i> REGISTRAR VENTA
                </button>
            </div>
        </div>
        
        <!-- Columna derecha: MOVIMIENTOS + FACTURAS -->
          <div class="bg-white rounded-2xl shadow-xl flex flex-col overflow-hidden lg:col-span-5">
              <!-- Tabs -->
              <div class="flex border-b bg-gradient-to-r from-slate-50 to-white">
                <button onclick="mostrarTab('movimientos')" id="tab-movimientos-btn" class="tab-btn flex-1 px-4 py-3 font-bold text-sm uppercase tracking-wider text-emerald-600 border-b-2 border-emerald-600 transition-all">
                    <i class="fas fa-exchange-alt mr-1"></i> Movimientos
                </button>
                <button onclick="mostrarTab('facturas')" id="tab-facturas-btn" class="tab-btn flex-1 px-4 py-3 font-bold text-sm uppercase tracking-wider text-slate-400 hover:text-slate-600 transition-all">
                    <i class="fas fa-receipt mr-1"></i> Facturas
                </button>
            </div>
            
            <!-- Tab MOVIMIENTOS -->
              <div id="tab-movimientos" class="flex-1 flex-col bg-slate-50">
                <div class="bg-gradient-to-r from-amber-500 to-orange-500 text-white p-4">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-sm font-bold"><i class="fas fa-clock mr-2"></i> Últimos Movimientos</h2>
                            <p id="movimientos-count-label" class="text-xs text-amber-100 mt-1">Sesión actual • <?= $total_movimientos ?> registros</p>
                        </div>
                    </div>
                </div>
                
                <div id="movimientos-list" class="flex-1 max-h-[350px] overflow-y-auto divide-y">
                    <?php if (empty($movimientos_paginados)): ?>
                    <div class="text-center text-gray-400 py-12">
                        <i class="fas fa-inbox text-4xl mb-2 opacity-30"></i>
                        <p>Sin movimientos</p>
                    </div>
                    <?php else: foreach ($movimientos_paginados as $m): ?>
                    <div class="p-4 hover:bg-gray-50 transition-all movimiento-item" data-id="<?= $m['id'] ?>">
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">#<?= $m['id'] ?></span>
                            <span class="text-xs font-mono text-gray-400"><?= date('H:i:s', strtotime($m['fecha'])) ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2 flex-wrap">
                                <?php
                                $badgeClass = $m['tipo'] === 'venta' ? 'bg-emerald-100 text-emerald-700' : ($m['tipo'] === 'ingreso' ? 'bg-blue-100 text-blue-700' : ($m['tipo'] === 'devolucion' ? 'bg-rose-100 text-rose-700' : 'bg-red-100 text-red-700'));
                                $icono = $m['tipo'] === 'venta' ? '💰' : ($m['tipo'] === 'ingreso' ? '⬇️' : ($m['tipo'] === 'devolucion' ? '↩️' : '⬆️'));
                                $textoMovimiento = !empty($m['descripcion']) ? $m['descripcion'] : ($m['venta_motivo'] ?? '');
                                ?>
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold <?= $badgeClass ?>"><?= $icono ?> <?= strtoupper($m['tipo']) ?></span>
                                <span class="text-sm text-gray-600"><?= htmlspecialchars(substr($textoMovimiento,0,30)) ?></span>
                            </div>
                            <span class="text-base font-bold <?= in_array($m['tipo'], ['egreso','devolucion'], true)?'text-red-500':'text-emerald-600' ?>">
                                <?= in_array($m['tipo'], ['egreso','devolucion'], true)?'-':'+' ?> <?= formatearCOP($m['monto']) ?>
                            </span>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <?php if ($m['tipo'] === 'venta' && $m['venta_id']): ?>
                                <button onclick="verDetalleVenta(<?= $m['venta_id'] ?>)" class="action-btn view" title="Ver detalle"><i class="fas fa-eye"></i></button>
                                <button onclick="generarFacturaPDF(<?= $m['venta_id'] ?>)" class="action-btn pdf" title="PDF"><i class="fas fa-file-pdf"></i></button>
                                <button onclick="enviarWhatsApp(<?= $m['venta_id'] ?>)" class="action-btn whatsapp" title="WhatsApp"><i class="fab fa-whatsapp"></i></button>
                                <button onclick="abrirDevolucion(<?= $m['venta_id'] ?>)" class="action-btn edit" title="Devolver"><i class="fas fa-rotate-left"></i></button>
                                <button onclick="eliminarVenta(<?= $m['venta_id'] ?>)" class="action-btn delete" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                            <?php else: ?>
                                <button onclick="editarMovimiento(<?= $m['id'] ?>)" class="action-btn edit" title="Editar"><i class="fas fa-edit"></i></button>
                                <button onclick="eliminarMovimiento(<?= $m['id'] ?>)" class="action-btn delete" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                
                <!-- Paginación -->
                <?php if ($total_movimientos > $por_pagina): ?>
                <div class="pagination border-t" style="background: #f9fafb;">
                    <?php if ($pagina_actual > 1): ?>
                        <a href="?pagina=<?= $pagina_actual-1 ?>">←</a>
                    <?php endif; ?>
                    <?php
                    $inicio = max(1, $pagina_actual - 2);
                    $fin = min($total_paginas, $pagina_actual + 2);
                    for ($i = $inicio; $i <= $fin; $i++):
                    ?>
                        <a href="?pagina=<?= $i ?>" class="<?= $i == $pagina_actual ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($pagina_actual < $total_paginas): ?>
                        <a href="?pagina=<?= $pagina_actual+1 ?>">→</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Registrar movimiento manual -->
                <form id="formRegistrarMovimiento" class="border-t p-4 bg-gray-50">
                    <h3 class="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-exchange-alt text-amber-500"></i> Registrar Ingreso/Egreso/Devolución
                    </h3>
                    <div class="grid grid-cols-2 gap-2 mb-2">
                        <select id="mov-tipo" class="border rounded-lg px-3 py-2 text-sm bg-white text-gray-900">
                            <option value="ingreso">💰 Ingreso</option>
                            <option value="egreso">💸 Egreso</option>
                            <option value="devolucion">↩️ Devolución</option>
                        </select>
                        <select id="mov-metodo" class="border rounded-lg px-3 py-2 text-sm bg-white text-gray-900">
                            <option value="Efectivo">💵 Efectivo</option>
                            <option value="Nequi">📱 Nequi</option>
                            <option value="Transferencia/QR">📲 Transferencia/QR</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2 mb-3">
                        <input type="text" id="mov-monto" placeholder="Monto" class="border rounded-lg px-3 py-2 text-sm money-input bg-white text-gray-900">
                        <input type="text" id="mov-motivo" placeholder="Motivo" class="border rounded-lg px-3 py-2 text-sm bg-white text-gray-900">
                    </div>
                    <button id="registrar-mov-btn" type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-white font-bold py-2 rounded-lg text-sm transition-all">
                        <i class="fas fa-save mr-1"></i> Registrar
                    </button>
                </form>
                
                <!-- Cerrar caja -->
                <div class="border-t p-4">
                    <form method="POST" onsubmit="return confirm('¿Cerrar la caja? Se calculará el saldo final automáticamente.')">
                        <?= csrf_field() ?>
                        <button type="submit" name="cerrar_caja" class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-2.5 rounded-lg text-sm transition-all">
                            <i class="fas fa-door-closed mr-1"></i> Cerrar Caja
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Tab FACTURAS -->
              <div id="tab-facturas" class="flex-1 hidden flex-col bg-slate-50">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-500 text-white p-4">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-sm font-bold"><i class="fas fa-receipt mr-2"></i> Facturas Recientes</h2>
                            <p class="text-xs text-indigo-100 mt-1">Últimas 10 facturas generadas</p>
                        </div>
                    </div>
                </div>
                <div id="facturas-list" class="flex-1 max-h-[450px] overflow-y-auto divide-y">
                    <?php if (empty($facturas_recientes)): ?>
                    <div class="text-center text-gray-400 py-12">
                        <i class="fas fa-receipt text-4xl mb-2 opacity-30"></i>
                        <p>No hay facturas registradas</p>
                    </div>
                    <?php else: foreach ($facturas_recientes as $f): ?>
                    <div class="factura-item p-4 hover:bg-gray-50 transition-all border-b" onclick="verDetalleVenta(<?= $f['id'] ?>)">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="font-bold text-indigo-600">#<?= str_pad($f['id'], 8, '0', STR_PAD_LEFT) ?></p>
                                <p class="text-sm text-gray-700"><?= htmlspecialchars($f['cliente_nombre']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($f['motivo']) ?></p>
                                <p class="text-xs text-gray-400"><?= date('d/m/Y H:i', strtotime($f['fecha_venta'])) ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-emerald-600"><?= formatearCOP($f['total']) ?></p>
                                <p class="text-xs text-gray-400"><?= $f['metodo_pago'] ?></p>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-3 justify-end">
                            <button onclick="event.stopPropagation(); generarFacturaPDF(<?= $f['id'] ?>)" class="action-btn pdf" title="PDF"><i class="fas fa-file-pdf"></i></button>
                            <button onclick="event.stopPropagation(); enviarWhatsApp(<?= $f['id'] ?>)" class="action-btn whatsapp" title="WhatsApp"><i class="fab fa-whatsapp"></i></button>
                            <button onclick="event.stopPropagation(); abrirDevolucion(<?= $f['id'] ?>)" class="action-btn edit" title="Devolver"><i class="fas fa-rotate-left"></i></button>
                            <button onclick="event.stopPropagation(); eliminarVenta(<?= $f['id'] ?>)" class="action-btn delete" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<!-- MODAL NUEVO CLIENTE -->
<div id="modalNuevoCliente" class="modal">
    <div class="modal-content" style="background: #ffffff; border-radius: 24px; max-width: 500px; width: 95%; max-height: 90vh; overflow-y: auto; padding: 0; box-shadow: 0 25px 80px rgba(0,0,0,0.15); border: 1px solid #e5e7eb;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 24px 32px 16px; border-bottom: 1px solid #e5e7eb; margin-bottom: 0; background: linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%); border-radius: 24px 24px 0 0;">
            <h3 style="color: #111827; font-size: 20px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-user-plus" style="color: #10b981;"></i> Nuevo Cliente
            </h3>
            <button onclick="cerrarModalCliente()" style="background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 18px; transition: all 0.3s; hover: background #e5e7eb;">
                ×
            </button>
        </div>
        <form id="formNuevoCliente" style="padding: 24px;">
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    <i class="fas fa-user" style="margin-right: 8px; color: #10b981;"></i>Nombre Completo *
                </label>
                <input type="text" id="nuevo_cliente_nombre" placeholder="Ej: Juan Pérez González"
                       style="width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 12px 16px; font-size: 14px; background: #ffffff; color: #111827; outline: none; transition: all 0.3s;"
                       onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)';"
                       onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';"
                       required>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    <i class="fas fa-phone" style="margin-right: 8px; color: #10b981;"></i>Teléfono
                </label>
                <input type="tel" id="nuevo_cliente_telefono" placeholder="Ej: 3001234567"
                       style="width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 12px 16px; font-size: 14px; background: #ffffff; color: #111827; outline: none; transition: all 0.3s;"
                       onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)';"
                       onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    <i class="fas fa-envelope" style="margin-right: 8px; color: #10b981;"></i>Correo Electrónico
                </label>
                <input type="email" id="nuevo_cliente_email" placeholder="Ej: cliente@email.com"
                       style="width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 12px 16px; font-size: 14px; background: #ffffff; color: #111827; outline: none; transition: all 0.3s;"
                       onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)';"
                       onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
            </div>

            <div style="margin-bottom: 24px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    <i class="fas fa-map-marker-alt" style="margin-right: 8px; color: #10b981;"></i>Dirección
                </label>
                <input type="text" id="nuevo_cliente_direccion" placeholder="Ej: Cra 6 #19-29, Santa Marta"
                       style="width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 12px 16px; font-size: 14px; background: #ffffff; color: #111827; outline: none; transition: all 0.3s;"
                       onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)';"
                       onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
            </div>

            <div style="margin-bottom: 24px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    <i class="fas fa-tags" style="margin-right: 8px; color: #10b981;"></i>Tipo de Cliente
                </label>
                <select id="nuevo_cliente_tipo" style="width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 12px 16px; font-size: 14px; background: #ffffff; color: #111827; outline: none; transition: all 0.3s; cursor: pointer;"
                        onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)';"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
                    <option value="particular" style="background: #ffffff; color: #111827;">👤 Particular</option>
                    <option value="empresa" style="background: #ffffff; color: #111827;">🏢 Empresa</option>
                    <option value="vip" style="background: #ffffff; color: #111827;">⭐ VIP</option>
                </select>
            </div>

            <div style="display: flex; gap: 12px; margin-top: 32px;">
                <button type="submit" style="flex: 1; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 14px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);">
                    <i class="fas fa-save" style="margin-right: 8px;"></i>Guardar Cliente
                </button>
                <button type="button" onclick="cerrarModalCliente()" style="flex: 1; background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; padding: 14px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                    <i class="fas fa-times" style="margin-right: 8px;"></i>Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR MOVIMIENTO -->
<div id="modalEditarMovimiento" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit text-amber-500"></i> Editar Movimiento</h3>
            <button onclick="cerrarModalEditar()">×</button>
        </div>
        <form id="formEditarMovimiento">
            <input type="hidden" id="edit_mov_id">
            <div class="mb-3">
                <label class="block text-xs font-bold text-gray-500 mb-1">Tipo</label>
                <select id="edit_tipo" class="w-full border rounded-lg p-2 text-sm">
                    <option value="ingreso">💰 Ingreso</option>
                    <option value="egreso">💸 Egreso</option>
                    <option value="devolucion">↩️ Devolución</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-bold text-gray-500 mb-1">Método de Pago</label>
                <select id="edit_metodo" class="w-full border rounded-lg p-2 text-sm">
                    <option value="Efectivo">💵 Efectivo</option>
                    <option value="Nequi">📱 Nequi</option>
                    <option value="Transferencia/QR">📲 Transferencia/QR</option>
                    <option value="Datafono">💳 Datáfono</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-bold text-gray-500 mb-1">Monto</label>
                <input type="text" id="edit_monto" class="w-full border rounded-lg p-2 text-sm money-input" style="text-align: right;">
            </div>
            <div class="mb-3">
                <label class="block text-xs font-bold text-gray-500 mb-1">Descripción</label>
                <textarea id="edit_descripcion" rows="2" class="w-full border rounded-lg p-2 text-sm"></textarea>
            </div>
            <div class="flex gap-2 mt-4">
                <button type="submit" class="flex-1 bg-indigo-600 text-white py-2 rounded-lg text-sm font-bold">Guardar Cambios</button>
                <button type="button" onclick="cerrarModalEditar()" class="flex-1 bg-gray-200 text-gray-700 py-2 rounded-lg text-sm font-bold">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DETALLE VENTA -->
<div id="modalDetalleVenta" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-file-invoice-dollar"></i> Detalle de Venta</h3>
            <button onclick="cerrarModal()">×</button>
        </div>
        <div id="modalDetalleContent" style="padding: 24px; background: #0a0a0a; border-radius: 0 0 24px 24px;">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-2xl text-gray-400 mb-4"></i>
                <p class="text-gray-300">Cargando detalle de venta...</p>
            </div>
        </div>
        <div id="modal-factura-btns" class="hidden" style="padding: 16px 24px 24px; background: #0a0a0a; border-top: 1px solid rgba(212,175,55,0.2); border-radius: 0 0 24px 24px; display: flex; gap: 12px; justify-content: flex-end;">
            <button id="modal-btn-pdf" class="btn-panel-pdf" style="background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; border: none; padding: 10px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);">
                <i class="fas fa-file-pdf mr-2"></i>PDF
            </button>
            <button id="modal-btn-wa" class="btn-panel-wa" style="background: linear-gradient(135deg, #25D366, #128C7E); color: white; border: none; padding: 10px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);">
                <i class="fab fa-whatsapp mr-2"></i>WhatsApp
            </button>
        </div>
    </div>
</div>

<audio id="beep" src="beep.mp3" preload="auto"></audio>

<script>
// ====================================================================
// VARIABLES GLOBALES
// ====================================================================
const CSRF_TOKEN = '<?= csrf_token() ?>';
let carrito = [];
let detalles_venta = [];
let productos_detalle = [];
let ultimoScanToken = null;
let facturaActualId = null;
let timeoutBusquedaCliente;
let timeoutBusquedaProducto;
let scannerMode = 'manual';
let timeoutScannerAuto;

function fmt(v) { 
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(v || 0);
}
function escH(t) { const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

function mostrarToast(msg, error=false) {
    const t = document.createElement('div');
    t.className = 'toast' + (error?' error':'');
    t.innerHTML = `<i class="fas ${error?'fa-exclamation-triangle':'fa-check-circle'} mr-2"></i> ${msg}`;
    document.body.appendChild(t);
    setTimeout(()=>t.remove(), 3500);
}

function reproducirBeep() {
    const beep = document.getElementById('beep');
    if (beep) {
        beep.currentTime = 0;
        beep.play().catch(() => {});
    }
}

function obtenerTotalCarrito() {
    const input = document.getElementById('total-costo');
    if (!input) return 0;
    return parseFloat(String(input.value || '').replace(/[^0-9]/g, '')) || 0;
}

function moneyValueFromInput(input) {
    if (!input) return 0;
    return parseFloat(String(input.value || '').replace(/[^0-9]/g, '')) || 0;
}

function formatMoneyInput(input) {
    if (!input) return;
    input.addEventListener('input', function(e) {
        let value = this.value.replace(/[^0-9]/g, '');
        if (value === '') value = '0';
        let number = parseInt(value, 10);
        this.value = number.toLocaleString('es-CO');
    });
}

// ====================================================================
// TABS
// ====================================================================
function mostrarTab(tab) {
    const movimientosTab = document.getElementById('tab-movimientos');
    const facturasTab = document.getElementById('tab-facturas');
    const movBtn = document.getElementById('tab-movimientos-btn');
    const factBtn = document.getElementById('tab-facturas-btn');
    
    if (tab === 'movimientos') {
        movimientosTab.classList.remove('hidden');
        facturasTab.classList.add('hidden');
        movBtn.classList.add('text-emerald-600', 'border-emerald-600');
        movBtn.classList.remove('text-slate-400');
        factBtn.classList.remove('text-emerald-600', 'border-emerald-600');
        factBtn.classList.add('text-slate-400');
    } else {
        movimientosTab.classList.add('hidden');
        facturasTab.classList.remove('hidden');
        factBtn.classList.add('text-emerald-600', 'border-emerald-600');
        factBtn.classList.remove('text-slate-400');
        movBtn.classList.remove('text-emerald-600', 'border-emerald-600');
        movBtn.classList.add('text-slate-400');
        cargarFacturas();
    }
}

function cargarFacturas() {
    fetch('?listar_facturas=1')
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.facturas) {
                const container = document.getElementById('facturas-list');
                if (data.facturas.length === 0) {
                    container.innerHTML = '<div class="text-center text-gray-400 py-12"><i class="fas fa-receipt text-4xl mb-2 opacity-30"></i><p>No hay facturas registradas</p></div>';
                    return;
                }
                container.innerHTML = data.facturas.map(f => `
                    <div class="factura-item p-4 hover:bg-gray-50 transition-all border-b" onclick="verDetalleVenta(${f.id})">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="font-bold text-indigo-600">#${String(f.id).padStart(8, '0')}</p>
                                <p class="text-sm text-gray-700">${escH(f.cliente_nombre)}</p>
                                <p class="text-xs text-gray-400">${new Date(f.fecha_venta).toLocaleString()}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-emerald-600">${fmt(f.total)}</p>
                                <p class="text-xs text-gray-400">${f.metodo_pago}</p>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-3 justify-end">
                            <button onclick="event.stopPropagation(); generarFacturaPDF(${f.id})" class="action-btn pdf" title="PDF"><i class="fas fa-file-pdf"></i></button>
                            <button onclick="event.stopPropagation(); enviarWhatsApp(${f.id})" class="action-btn whatsapp" title="WhatsApp"><i class="fab fa-whatsapp"></i></button>
                            <button onclick="event.stopPropagation(); eliminarVenta(${f.id})" class="action-btn delete" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </div>
                `).join('');
            }
        });
}

function cargarMovimientos() {
    fetch('?listar_movimientos=1&limit=10')
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                mostrarToast(data.error || 'Error al cargar movimientos', true);
                return;
            }

            const contenedor = document.getElementById('movimientos-list');
            const movimientos = data.movimientos || [];
            if (contenedor) {
                if (movimientos.length === 0) {
                    contenedor.innerHTML = '<div class="text-center text-gray-400 py-12"><i class="fas fa-inbox text-4xl mb-2 opacity-30"></i><p>Sin movimientos</p></div>';
                } else {
                    contenedor.innerHTML = movimientos.map(renderMovimientoItem).join('');
                }
            }

            const totales = data.totales || {};
            const totalVentas = parseFloat(totales.ventas || 0);
            const totalIngresos = parseFloat(totales.ingresos || 0);
            const totalEgresos = parseFloat(totales.egresos || 0);
            const totalMovimientos = parseInt(totales.movimientos || 0, 10);

            // Actualizar los elementos del resumen
            const statVentas = document.getElementById('stat-total-ventas');
            const statEgresos = document.getElementById('stat-total-egresos');
            const statSaldoActual = document.getElementById('stat-saldo-actual');
            const statSaldoInicial = document.getElementById('stat-saldo-inicial');
            const countLabel = document.getElementById('movimientos-count-label');

            if (statVentas) statVentas.textContent = fmt(totalVentas);
            if (statEgresos) statEgresos.textContent = fmt(totalEgresos);
            if (statSaldoActual) statSaldoActual.textContent = fmt(parseFloat(data.saldo_actual || 0));
            if (statSaldoInicial) statSaldoInicial.textContent = fmt(parseFloat(data.saldo_inicial || 0));
            if (countLabel) countLabel.textContent = `Sesión actual • ${totalMovimientos} registros`;
        })
        .catch(() => mostrarToast('❌ No se pudieron actualizar los movimientos', true));
}

function renderMovimientoItem(m) {
    const tipo = String(m.tipo || '').toLowerCase();
    const badgeClass = tipo === 'venta' ? 'bg-emerald-100 text-emerald-700' : (tipo === 'ingreso' ? 'bg-blue-100 text-blue-700' : (tipo === 'devolucion' ? 'bg-rose-100 text-rose-700' : 'bg-red-100 text-red-700'));
    const icono = tipo === 'venta' ? '💰' : (tipo === 'ingreso' ? '⬇️' : (tipo === 'devolucion' ? '↩️' : '⬆️'));
    const descripcionTexto = String(m.descripcion || '').trim();
    const ventaMotivo = String(m.venta_motivo || '').trim();
    const textoMovimiento = descripcionTexto && descripcionTexto !== '0' ? descripcionTexto : (ventaMotivo !== '0' ? ventaMotivo : '');
    const descripcion = escH(textoMovimiento.substring(0, 30));
    const fecha = m.fecha ? new Date(m.fecha.replace(' ', 'T')) : null;
    const hora = fecha && !Number.isNaN(fecha.getTime()) ? fecha.toLocaleTimeString('es-CO') : '';
    const monto = fmt(parseFloat(m.monto || 0));
    const acciones = tipo === 'venta' && m.venta_id
        ? `
            <button onclick="verDetalleVenta(${m.venta_id})" class="action-btn view" title="Ver detalle"><i class="fas fa-eye"></i></button>
            <button onclick="generarFacturaPDF(${m.venta_id})" class="action-btn pdf" title="PDF"><i class="fas fa-file-pdf"></i></button>
            <button onclick="enviarWhatsApp(${m.venta_id})" class="action-btn whatsapp" title="WhatsApp"><i class="fab fa-whatsapp"></i></button>
            <button onclick="abrirDevolucion(${m.venta_id})" class="action-btn edit" title="Devolver"><i class="fas fa-rotate-left"></i></button>
            <button onclick="eliminarVenta(${m.venta_id})" class="action-btn delete" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
        `
        : `
            <button onclick="editarMovimiento(${m.id})" class="action-btn edit" title="Editar"><i class="fas fa-edit"></i></button>
            <button onclick="eliminarMovimiento(${m.id})" class="action-btn delete" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
        `;

    return `
        <div class="p-4 hover:bg-gray-50 transition-all movimiento-item" data-id="${m.id}">
            <div class="flex justify-between items-start mb-2">
                <span class="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">#${m.id}</span>
                <span class="text-xs font-mono text-gray-400">${hora}</span>
            </div>
            <div class="flex justify-between items-center">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="px-2 py-0.5 rounded-full text-xs font-bold ${badgeClass}">${icono} ${tipo.toUpperCase()}</span>
                    <div class="flex flex-col">
                        <span class="text-sm text-gray-600">${descripcion}</span>
                        ${tipo === 'venta' && ventaMotivo && ventaMotivo !== '0' && ventaMotivo !== textoMovimiento ? `<span class="text-xs text-indigo-600 font-medium">${escH(ventaMotivo)}</span>` : ''}
                    </div>
                </div>
                <span class="text-base font-bold ${['egreso','devolucion'].includes(tipo) ? 'text-red-500' : 'text-emerald-600'}">
                    ${['egreso','devolucion'].includes(tipo) ? '-' : '+'} ${monto}
                </span>
            </div>
            <div class="flex gap-2 mt-3">
                ${acciones}
            </div>
        </div>
    `;
}

// ====================================================================
// CARRITO
// ====================================================================
function actualizarCarrito() {
    const cartList = document.getElementById('cart-list');
    const emptyCart = document.getElementById('empty-cart');
    const cartTotal = document.getElementById('cart-total');
    const itemsCount = document.getElementById('cart-items-count');
    let total = 0, items = 0;
    
    if (carrito.length === 0) {
        if (emptyCart) emptyCart.style.display = 'block';
        if (cartList) cartList.innerHTML = '';
        if (cartTotal) cartTotal.innerHTML = fmt(0);
        if (itemsCount) itemsCount.innerHTML = '0 items';
        actualizarCambio();
        return;
    }
    if (emptyCart) emptyCart.style.display = 'none';
    
    cartList.innerHTML = carrito.map((p, i) => {
        const sub = p.cantidad * p.precio;
        total += sub;
        items += p.cantidad;
        return `<div class="bg-white rounded-xl p-3 mb-2 flex flex-wrap justify-between items-center gap-2 border border-gray-100 shadow-sm">
            <div class="flex-1">
                <div class="font-bold text-sm">${escH(p.nombre)}</div>
                <div class="text-xs text-gray-400">${fmt(p.precio)} c/u | Stock: ${p.stock}</div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="cambiarCantidad(${i},-1)" class="quantity-btn">-</button>
                <span class="w-8 text-center font-bold">${p.cantidad}</span>
                <button onclick="cambiarCantidad(${i},1)" class="quantity-btn">+</button>
            </div>
            <div class="min-w-[100px] text-right">
                <div class="font-bold text-emerald-600 text-sm">${fmt(sub)}</div>
                <button onclick="eliminarDelCarrito(${i})" class="text-red-500 text-xs mt-1"><i class="fas fa-trash-alt"></i> Eliminar</button>
            </div>
        </div>`;
    }).join('');
    cartTotal.innerHTML = fmt(total);
    itemsCount.innerHTML = `${items} item${items!==1?'s':''}`;
    const montoRecibido = document.getElementById('monto-recibido');
    if (montoRecibido && moneyValueFromInput(montoRecibido) === 0) {
        montoRecibido.value = total.toLocaleString('es-CO');
    }
    actualizarCambio();
}

function agregarProducto(p) {
    const ex = carrito.find(x => x.id === p.id);
    if (ex) {
        if (ex.cantidad + 1 > p.stock) { mostrarToast(`⚠️ Stock máximo: ${p.stock}`, true); return; }
        ex.cantidad++;
    } else {
        if (p.stock <= 0) { mostrarToast(`❌ ${p.nombre} sin stock`, true); return; }
        carrito.push({ id: p.id, nombre: p.nombre, precio: parseFloat(p.precio), cantidad: 1, stock: p.stock, codigo_barras: p.codigo_barras });
    }
    actualizarCarrito();
    mostrarToast(`✅ ${p.nombre} agregado al carrito`);
}

function cambiarCantidad(idx, delta) {
    const n = carrito[idx].cantidad + delta;
    if (n < 1) { eliminarDelCarrito(idx); return; }
    if (n > carrito[idx].stock) { mostrarToast(`⚠️ Máximo ${carrito[idx].stock}`, true); return; }
    carrito[idx].cantidad = n;
    actualizarCarrito();
}

function eliminarDelCarrito(idx) {
    const nombre = carrito[idx].nombre;
    carrito.splice(idx, 1);
    actualizarCarrito();
    mostrarToast(`🗑️ ${nombre} eliminado`);
}

function limpiarCarrito() {
    if (carrito.length && confirm('¿Limpiar todo el carrito?')) {
        carrito = [];
        actualizarCarrito();
        const clienteInput = document.getElementById('cliente-input');
        const clienteNombre = document.getElementById('cliente-nombre');
        const clienteId = document.getElementById('cliente-id');
        if (clienteInput) clienteInput.value = '';
        if (clienteNombre) clienteNombre.value = '';
        if (clienteId) clienteId.value = '';
        const montoRecibido = document.getElementById('monto-recibido');
        if (montoRecibido) montoRecibido.value = '';
        mostrarToast('🧹 Carrito limpio');
    }
}

// ====================================================================
// CLIENTES
// ====================================================================
document.getElementById('cliente-input')?.addEventListener('input', function() {
    clearTimeout(timeoutBusquedaCliente);
    const q = this.value.trim();
    document.getElementById('cliente-nombre').value = q;
    if (q.length < 2) { document.getElementById('client-search-results').style.display = 'none'; return; }
    timeoutBusquedaCliente = setTimeout(() => {
        fetch(`?buscar_clientes=${encodeURIComponent(q)}`).then(r=>r.json()).then(data => {
            const div = document.getElementById('client-search-results');
            if (data.length === 0) {
                div.innerHTML = `<div class="client-search-item text-emerald-600" onclick="mostrarModalNuevoCliente()">
                    <i class="fas fa-plus-circle"></i> Crear nuevo cliente: "${escH(q)}"
                </div>`;
            } else {
                div.innerHTML = data.map(c => `
                    <div class="client-search-item" onclick='seleccionarCliente(${JSON.stringify(c.nombre)}, ${JSON.stringify(c.telefono || "")}, ${Number(c.id || 0)})'>
                        <div class="font-semibold text-sm">${escH(c.nombre)}</div>
                        <div class="text-xs text-gray-400">📞 ${c.telefono || 'Sin teléfono'}</div>
                    </div>
                `).join('');
                div.innerHTML += `<div class="client-search-item border-t text-emerald-600" onclick="mostrarModalNuevoCliente()">
                    <i class="fas fa-plus-circle"></i> Crear nuevo cliente...
                </div>`;
            }
            div.style.display = 'block';
        });
    }, 300);
});

function seleccionarCliente(nombre, telefono) {
    document.getElementById('cliente-input').value = nombre;
    document.getElementById('cliente-nombre').value = nombre;
    document.getElementById('cliente-id').value = arguments[2] || '';
    document.getElementById('client-search-results').style.display = 'none';
    mostrarToast(`✅ Cliente seleccionado: ${nombre}`);
}

function mostrarModalNuevoCliente() {
    const nombreActual = document.getElementById('cliente-input')?.value.trim() || '';
    document.getElementById('nuevo_cliente_nombre').value = nombreActual;
    document.getElementById('nuevo_cliente_telefono').value = '';
    document.getElementById('nuevo_cliente_email').value = '';
    document.getElementById('nuevo_cliente_direccion').value = '';
    document.getElementById('nuevo_cliente_tipo').value = 'particular';
    document.getElementById('modalNuevoCliente').style.display = 'flex';
}

function cerrarModalCliente() { document.getElementById('modalNuevoCliente').style.display = 'none'; }

document.getElementById('formNuevoCliente')?.addEventListener('submit', async e => {
    e.preventDefault();
    const nombre = document.getElementById('nuevo_cliente_nombre').value.trim();
    const telefono = document.getElementById('nuevo_cliente_telefono').value.trim();
    const email = document.getElementById('nuevo_cliente_email').value.trim();
    const direccion = document.getElementById('nuevo_cliente_direccion').value.trim();
    const tipo = document.getElementById('nuevo_cliente_tipo').value.trim();
    if (!nombre) { mostrarToast('❌ El nombre es requerido', true); return; }
    
    const btn = e.submitter;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
    btn.disabled = true;
    
    try {
        const fd = new URLSearchParams({ _csrf: CSRF_TOKEN, crear_cliente: 1, nombre, telefono, email, direccion, tipo });
        const r = await fetch(window.location.href, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { 
            seleccionarCliente(d.nombre, d.telefono || '', d.cliente_id || ''); 
            cerrarModalCliente(); 
            mostrarToast(`✅ Cliente "${d.nombre}" creado`);
        } else { 
            mostrarToast(`❌ ${d.error}`, true);
        }
    } catch { mostrarToast('❌ Error al crear cliente', true); }
    finally { btn.innerHTML = originalText; btn.disabled = false; }
});

document.getElementById('btn-nuevo-cliente')?.addEventListener('click', mostrarModalNuevoCliente);
function getClienteNombre() { return document.getElementById('cliente-nombre').value || document.getElementById('cliente-input')?.value || 'Cliente general'; }

function actualizarModoScannerUI() {
    const manualBtn = document.getElementById('modo-manual-btn');
    const scannerBtn = document.getElementById('modo-scanner-btn');
    const help = document.getElementById('scanner-mode-help');
    const badge = document.getElementById('scanner-mode-badge');
    const connectionStatus = document.getElementById('connection-status');
    const buscarBtn = document.getElementById('buscar-btn');
    const buscarInput = document.getElementById('buscar-input');
    const resultados = document.getElementById('product-search-results');
    if (!manualBtn || !scannerBtn || !help || !badge || !buscarBtn || !buscarInput) return;

    manualBtn.classList.toggle('active', scannerMode === 'manual');
    scannerBtn.classList.toggle('active', scannerMode === 'scanner');

    if (scannerMode === 'scanner') {
        help.textContent = 'Modo escáner: al capturar un código de barras se agregará directo al carrito.';
        badge.innerHTML = '<i class="fas fa-barcode mr-1 text-emerald-500"></i> Escáner automático';
        if (connectionStatus) connectionStatus.innerHTML = '<i class="fas fa-barcode text-xs"></i> Escáner automático activo';
        buscarBtn.innerHTML = '<i class="fas fa-plus-circle mr-2"></i> Agregar';
        buscarInput.placeholder = 'Escanea o escribe el código de barras para agregar al carrito...';
        if (resultados) resultados.style.display = 'none';
    } else {
        help.textContent = 'Modo manual: busca por nombre o código y elige productos de la lista.';
        badge.innerHTML = '<i class="fas fa-hand-pointer mr-1 text-indigo-500"></i> Modo manual';
        if (connectionStatus) connectionStatus.innerHTML = '<i class="fas fa-keyboard text-xs"></i> Búsqueda manual activa';
        buscarBtn.innerHTML = '<i class="fas fa-search mr-2"></i> Buscar';
        buscarInput.placeholder = '🔍 Código de barras o nombre del producto...';
    }
}

function setScannerMode(mode) {
    scannerMode = mode === 'scanner' ? 'scanner' : 'manual';
    actualizarModoScannerUI();
}

function ocultarResultadosProducto() {
    const div = document.getElementById('product-search-results');
    if (div) {
        div.style.display = 'none';
        div.innerHTML = '';
    }
}

function seleccionarProductoManual(producto) {
    reproducirBeep();
    agregarProducto(producto);
    const input = document.getElementById('buscar-input');
    if (input) input.value = '';
    ocultarResultadosProducto();
}

async function buscarProductosManual(q) {
    const div = document.getElementById('product-search-results');
    if (!div) return;
    if (!q || q.trim().length < 1) {
        ocultarResultadosProducto();
        return;
    }
    try {
        const r = await fetch(`?buscar_productos=${encodeURIComponent(q.trim())}`);
        const d = await r.json();
        if (!d.ok || !d.productos) {
            ocultarResultadosProducto();
            return;
        }
        if (d.productos.length === 0) {
            div.innerHTML = `<div class="product-search-item text-sm text-gray-500">No se encontraron productos para "${escH(q)}".</div>`;
            div.style.display = 'block';
            return;
        }
        div.innerHTML = d.productos.map(p => `
            <div class="product-search-item" onclick='seleccionarProductoManual(${JSON.stringify(p)})'>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold text-sm text-slate-800">${escH(p.nombre)}</div>
                        <div class="text-xs text-slate-400">Código: ${escH(p.codigo_barras || 'Sin código')} • Stock: ${p.stock}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-bold text-emerald-600">${fmt(p.precio)}</div>
                        <div class="text-[11px] text-slate-400">Agregar</div>
                    </div>
                </div>
            </div>
        `).join('');
        div.style.display = 'block';
    } catch {
        ocultarResultadosProducto();
    }
}

async function agregarProductoDesdeBusqueda(q) {
    if (!q) {
        mostrarToast('🔍 Ingresa un código o nombre', true);
        return;
    }
    const r = await fetch(`?buscar=${encodeURIComponent(q)}`);
    const d = await r.json();
    if (d.ok) {
        reproducirBeep();
        agregarProducto(d.producto);
        const input = document.getElementById('buscar-input');
        if (input) input.value = '';
        ocultarResultadosProducto();
    } else {
        mostrarToast(`❌ ${d.error}`, true);
    }
}

function actualizarCambio() {
    const total = obtenerTotalCarrito();
    const metodo = document.getElementById('metodo-pago')?.value || 'Efectivo';
    const montoRecibidoInput = document.getElementById('monto-recibido');
    const cambioValor = document.getElementById('cambio-valor');
    const estadoCambio = document.getElementById('estado-cambio');
    const cambioAyuda = document.getElementById('cambio-ayuda');
    if (!montoRecibidoInput || !cambioValor || !estadoCambio || !cambioAyuda) return;

    const totalLabel = document.getElementById('cart-total');
    if (totalLabel) totalLabel.textContent = fmt(total);

    if (metodo !== 'Efectivo') {
        montoRecibidoInput.value = total > 0 ? total.toLocaleString('es-CO') : '';
        montoRecibidoInput.disabled = true;
        const texto = total > 0 ? fmt(0) : fmt(0);
        cambioValor.textContent = texto;
        estadoCambio.textContent = 'Sin cambio';
        estadoCambio.className = 'text-[11px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded-full';
        cambioAyuda.textContent = 'Para pagos digitales el cambio es 0.';
        return;
    }

    montoRecibidoInput.disabled = false;
    const recibido = moneyValueFromInput(montoRecibidoInput);
    const diferencia = recibido - total;
    cambioValor.textContent = fmt(Math.max(diferencia, 0));

    if (total <= 0) {
        estadoCambio.textContent = 'Sin venta';
        estadoCambio.className = 'text-[11px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded-full';
        cambioAyuda.textContent = 'Agrega productos al carrito para calcular el cambio.';
    } else if (recibido === 0) {
        estadoCambio.textContent = 'Pendiente';
        estadoCambio.className = 'text-[11px] font-bold text-amber-700 bg-amber-100 px-2 py-1 rounded-full';
        cambioAyuda.textContent = `Total a cobrar: ${fmt(total)}.`;
    } else if (diferencia < 0) {
        estadoCambio.textContent = 'Falta dinero';
        estadoCambio.className = 'text-[11px] font-bold text-red-700 bg-red-100 px-2 py-1 rounded-full';
        cambioAyuda.textContent = `Faltan ${fmt(Math.abs(diferencia))} para completar la venta.`;
    } else if (diferencia === 0) {
        estadoCambio.textContent = 'Pago exacto';
        estadoCambio.className = 'text-[11px] font-bold text-emerald-700 bg-emerald-100 px-2 py-1 rounded-full';
        cambioAyuda.textContent = 'El cliente entregó el valor exacto.';
    } else {
        estadoCambio.textContent = 'Dar cambio';
        estadoCambio.className = 'text-[11px] font-bold text-indigo-700 bg-indigo-100 px-2 py-1 rounded-full';
        cambioAyuda.textContent = `Debes entregar ${fmt(diferencia)} de cambio.`;
    }
}

// ====================================================================
// VENTA
// ====================================================================
async function registrarVenta() {
    const cliente = getClienteNombre();
    const metodo = document.getElementById('metodo-pago')?.value || 'Efectivo';
    const motivo = document.getElementById('venta-motivo')?.value || 'Venta general';
    const total = obtenerTotalCarrito();
    if (total <= 0) { mostrarToast('❌ Ingresa el costo', true); return; }
    const montoRecibido = moneyValueFromInput(document.getElementById('monto-recibido'));
    if (metodo === 'Efectivo' && montoRecibido < total) {
        mostrarToast('❌ El efectivo recibido no alcanza para cubrir el total', true);
        return;
    }
    const btn = document.getElementById('vender-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
    btn.disabled = true;
    try {
        const fd = new URLSearchParams({ 
            _csrf: CSRF_TOKEN,
            registrar_venta: 1, 
            items_json: JSON.stringify(carrito),
            manual_total: carrito.length === 0 ? 1 : 0,
            cliente_nombre: cliente,
            motivo: motivo,
            detalles_json: JSON.stringify(detalles_venta),
            metodo_pago: metodo, 
            total: total,
            monto_recibido: metodo === 'Efectivo' ? montoRecibido : total
        });
        const r = await fetch(window.location.href, { method:'POST', body:fd });
        const d = await r.json();
        if (d.ok) {
            const cambio = Math.max((metodo === 'Efectivo' ? montoRecibido : total) - total, 0);
            mostrarToast(`✅ Venta #${d.venta_id} registrada por ${fmt(d.total)}${cambio > 0 ? ` | Cambio: ${fmt(cambio)}` : ''}`);
            carrito = [];
            const inputCosto = document.getElementById('total-costo');
            if (inputCosto) inputCosto.value = '';
            actualizarCambio();
            document.getElementById('cliente-input').value = '';
            document.getElementById('cliente-nombre').value = '';
            document.getElementById('cliente-id').value = '';
            document.getElementById('venta-motivo').value = '';
            document.getElementById('monto-recibido').value = '';
            actualizarCambio();
            mostrarFacturaPanel(d.venta_id);
            cargarFacturas();
            cargarMovimientos();
        } else mostrarToast(`❌ ${d.error}`, true);
    } catch (error) {
        console.error('Error al registrar venta:', error);
        mostrarToast(`❌ Error al procesar la venta: ${error.message}`, true);
    }
    finally { btn.innerHTML = '<i class="fas fa-check-circle mr-2"></i> REGISTRAR VENTA'; btn.disabled = false; }
}

function mostrarFacturaPanel(ventaId) {
    facturaActualId = ventaId;
    document.getElementById('factura-venta-id').textContent = ventaId;
    document.getElementById('factura-panel').classList.remove('hidden');
}

// ====================================================================
// FACTURA PDF DARK MODE CON DECORACIÓN LATERAL
// ====================================================================
async function generarFacturaPDF(ventaId) {
    try {
        const preview = document.getElementById('modalDetalleContent');
        const iframe = preview ? preview.querySelector('#factura-iframe') : null;

        const buildAndSavePdf = (facturaEl, stylesText = '') => {
            const container = document.createElement('div');
            if (stylesText) {
                const style = document.createElement('style');
                style.textContent = stylesText;
                container.appendChild(style);
            }
            const factura = facturaEl.cloneNode(true);
            factura.style.margin = '0';
            factura.style.width = '100%';
            factura.style.maxWidth = 'none';
            container.appendChild(factura);

            const width = Math.max(1, factura.scrollWidth || factura.offsetWidth || 500);
            const height = Math.max(1, factura.scrollHeight || factura.offsetHeight || 700);
            const opt = {
                margin: 0,
                filename: `factura_${String(ventaId).padStart(8, '0')}.pdf`,
                image: { type: 'jpeg', quality: 1 },
                html2canvas: {
                    scale: 1,
                    letterRendering: true,
                    backgroundColor: '#000000',
                    useCORS: true,
                    logging: false,
                    scrollX: 0,
                    scrollY: 0
                },
                jsPDF: { unit: 'px', format: [width, height], orientation: 'portrait' }
            };
            html2pdf().set(opt).from(container).save();
        };

        if (iframe && iframe.contentDocument) {
            const facturaEnModal = iframe.contentDocument.querySelector('.factura');
            if (facturaEnModal) {
                const styles = Array.from(iframe.contentDocument.querySelectorAll('style'))
                    .map(s => s.textContent || '')
                    .join('\n');
                buildAndSavePdf(facturaEnModal, styles);
                return;
            }
        }

        const r = await fetch(`../facturacion/?action=generar_pdf&id=${ventaId}`);
        const html = await r.text();
        const div = document.createElement('div');
        div.innerHTML = html;
        const factura = div.querySelector('.factura') || div;
        const styles = Array.from(div.querySelectorAll('style')).map(s => s.textContent || '').join('\n');
        buildAndSavePdf(factura, styles);
    } catch {
        mostrarToast('❌ Error al generar PDF', true);
    }
}

async function uploadFacturaPdf(blob, ventaId) {
    const formData = new FormData();
    formData.append('_csrf', CSRF_TOKEN);
    formData.append('id', ventaId);
    formData.append('pdf', blob, `recibo_${String(ventaId).padStart(8, '0')}.pdf`);

    const resp = await fetch(`?action=upload_pdf`, {
        method: 'POST',
        body: formData
    });
    const result = await resp.json();
    if (!result.ok) {
        throw new Error(result.error || 'Error al subir PDF');
    }
    return result.url;
}

async function buildFacturaPdfBlob(ventaId) {
    const preview = document.getElementById('modalDetalleContent');
    const iframe = preview ? preview.querySelector('#factura-iframe') : null;

    const build = async (facturaEl, stylesText = '') => {
        const container = document.createElement('div');
        if (stylesText) {
            const style = document.createElement('style');
            style.textContent = stylesText;
            container.appendChild(style);
        }
        const factura = facturaEl.cloneNode(true);
        factura.style.margin = '0';
        factura.style.width = '100%';
        factura.style.maxWidth = 'none';
        container.appendChild(factura);

        const width = Math.max(1, factura.scrollWidth || factura.offsetWidth || 500);
        const height = Math.max(1, factura.scrollHeight || factura.offsetHeight || 700);
        const opt = {
            margin: 0,
            filename: `recibo_${String(ventaId).padStart(8, '0')}.pdf`,
            image: { type: 'jpeg', quality: 1 },
            html2canvas: {
                scale: 1,
                letterRendering: true,
                backgroundColor: '#000000',
                useCORS: true,
                logging: false,
                scrollX: 0,
                scrollY: 0
            },
            jsPDF: {
                unit: 'px',
                format: [width, height],
                orientation: 'portrait'
            }
        };

        try {
            return await html2pdf().set(opt).from(container).outputPdf('blob');
        } catch (error) {
            const pdf = await html2pdf().set(opt).from(container).toPdf().get('pdf');
            return pdf.output('blob');
        }
    };

    if (iframe && iframe.contentDocument) {
        const facturaEnModal = iframe.contentDocument.querySelector('.factura');
        if (facturaEnModal) {
            const styles = Array.from(iframe.contentDocument.querySelectorAll('style'))
                .map(s => s.textContent || '')
                .join('\n');
            return await build(facturaEnModal, styles);
        }
    }

    const r = await fetch(`../facturacion/?action=generar_pdf&id=${ventaId}`);
    const html = await r.text();
    const div = document.createElement('div');
    div.innerHTML = html;
    const factura = div.querySelector('.factura') || div;
    const styles = Array.from(div.querySelectorAll('style')).map(s => s.textContent || '').join('\n');
    return await build(factura, styles);
}

function formatearCOP(valor) {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(valor || 0);
}



async function enviarWhatsApp(ventaId) {
    const telefono = prompt('Numero WhatsApp con codigo de pais (ej: 573001234567):', '57');
    if (!telefono || telefono.trim() === '' || telefono.trim() === '57') {
        mostrarToast('Numero cancelado o invalido', true);
        return;
    }

    mostrarToast('Preparando mensaje...');

    try {
        // 1. Obtener datos de la venta (descripcion viene del backend via movimientos_caja)
        const r = await fetch(`../facturacion/?action=get_factura_data&id=${ventaId}`);
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
        const mensaje = `*LUXURY PREMIUM STORE*
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

async function generarFacturaPDF(ventaId) {
    mostrarToast('📄 Generando PDF...');
    const preview = document.getElementById('modalDetalleContent');
    const iframe = preview ? preview.querySelector('#factura-iframe') : null;

    const buildAndSavePdf = async (facturaEl, stylesText = '') => {
        const container = document.createElement('div');
        if (stylesText) {
            const style = document.createElement('style');
            style.textContent = stylesText;
            container.appendChild(style);
        }
        const factura = facturaEl.cloneNode(true);
        factura.style.margin = '0';
        factura.style.width = '100%';
        factura.style.maxWidth = 'none';
        container.appendChild(factura);

        const width = Math.max(1, factura.scrollWidth || factura.offsetWidth || 500);
        const height = Math.max(1, factura.scrollHeight || factura.offsetHeight || 700);
        const opt = {
            margin: 0,
            filename: `recibo_${String(ventaId).padStart(8, '0')}.pdf`,
            image: { type: 'jpeg', quality: 1 },
            html2canvas: {
                scale: 1,
                letterRendering: true,
                backgroundColor: '#000000',
                useCORS: true,
                logging: false,
                scrollX: 0,
                scrollY: 0
            },
            jsPDF: {
                unit: 'px',
                format: [width, height],
                orientation: 'portrait'
            }
        };

        html2pdf().set(opt).from(container).save();
        mostrarToast('✅ PDF generado exitosamente');
    };

    try {
        if (iframe && iframe.contentDocument) {
            const facturaEnModal = iframe.contentDocument.querySelector('.factura');
            if (facturaEnModal) {
                const styles = Array.from(iframe.contentDocument.querySelectorAll('style'))
                    .map(s => s.textContent || '')
                    .join('\n');
                await buildAndSavePdf(facturaEnModal, styles);
                return;
            }
        }

        const r = await fetch(`../facturacion/?action=generar_pdf&id=${ventaId}`);
        const html = await r.text();
        const div = document.createElement('div');
        div.innerHTML = html;
        const factura = div.querySelector('.factura') || div;
        const styles = Array.from(div.querySelectorAll('style')).map(s => s.textContent || '').join('\n');
        await buildAndSavePdf(factura, styles);
    } catch {
        mostrarToast('❌ Error al generar PDF', true);
    }
}

async function verDetalleVenta(ventaId) {
    const modal = document.getElementById('modalDetalleVenta');
    const content = document.getElementById('modalDetalleContent');
    modal.style.display = 'flex';
    content.innerHTML = '<div class="text-center py-12"><i class="fas fa-spinner fa-spin text-3xl text-gray-400 mb-4"></i><p class="text-gray-300 text-lg">Cargando detalle de venta...</p></div>';

    try {
        const r = await fetch(`../facturacion/?action=generar_pdf&id=${ventaId}`);
        const html = await r.text();
        content.innerHTML = '<iframe id="factura-iframe" style="width:100%;height:600px;border:0;border-radius:16px;background:#0a0a0a;box-shadow:0 8px 32px rgba(0,0,0,0.3);"></iframe>';
        const frame = document.getElementById('factura-iframe');
        if (frame) frame.srcdoc = html;
    } catch {
        content.innerHTML = '<div class="text-center py-12"><i class="fas fa-exclamation-triangle text-3xl text-red-400 mb-4"></i><p class="text-red-400 text-lg">Error al cargar el detalle</p></div>';
        return;
    }

    document.getElementById('modal-factura-btns').classList.remove('hidden');
    document.getElementById('modal-btn-pdf').onclick = () => generarFacturaPDF(ventaId);
    document.getElementById('modal-btn-wa').onclick = () => enviarWhatsApp(ventaId);
}

// ====================================================================
// MOVIMIENTOS
// ====================================================================
async function editarMovimiento(movId) {
    const modal = document.getElementById('modalEditarMovimiento');
    modal.style.display = 'flex';
    try {
        const r = await fetch(`?editar_movimiento=${movId}`);
        const d = await r.json();
        if (d.ok) {
            document.getElementById('edit_mov_id').value = d.movimiento.id;
            document.getElementById('edit_tipo').value = d.movimiento.tipo;
            document.getElementById('edit_metodo').value = d.movimiento.metodo_pago || 'Efectivo';
            document.getElementById('edit_monto').value = d.movimiento.monto.toLocaleString('es-CO');
            document.getElementById('edit_descripcion').value = d.movimiento.descripcion || '';
        } else { mostrarToast('Error al cargar', true); cerrarModalEditar(); }
    } catch { mostrarToast('Error', true); }
}

document.getElementById('formEditarMovimiento')?.addEventListener('submit', async e => {
    e.preventDefault();
    const montoInput = document.getElementById('edit_monto');
    const monto = parseFloat(montoInput.value.replace(/[^0-9]/g, '')) || 0;
    const fd = new URLSearchParams({ 
        _csrf: CSRF_TOKEN,
        actualizar_movimiento: 1, 
        id: document.getElementById('edit_mov_id').value,
        tipo: document.getElementById('edit_tipo').value,
        metodo_pago: document.getElementById('edit_metodo').value,
        monto: monto,
        descripcion: document.getElementById('edit_descripcion').value
    });
    const r = await fetch(window.location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.ok) { mostrarToast(`✅ ${d.mensaje}`); cerrarModalEditar(); setTimeout(()=>window.location.reload(), 1000); }
    else mostrarToast(`❌ ${d.error}`, true);
});

function cerrarModalEditar() { document.getElementById('modalEditarMovimiento').style.display = 'none'; }

function abrirDevolucion(ventaId) {
    window.location.href = `../devoluciones/?venta_id=${ventaId}`;
}

async function eliminarVenta(ventaId) {
    if (!confirm('⚠️ ¿Eliminar esta venta? Se restaurará el stock.')) return;
    const fd = new URLSearchParams({ _csrf: CSRF_TOKEN, eliminar_venta: 1, venta_id: ventaId });
    const r = await fetch(window.location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.ok) { mostrarToast('✅ Venta eliminada'); cargarMovimientos(); cargarFacturas(); }
    else mostrarToast('❌ Error', true);
}

async function eliminarMovimiento(movId) {
    if (!confirm('¿Eliminar este movimiento?')) return;
    const fd = new URLSearchParams({ _csrf: CSRF_TOKEN, eliminar_movimiento: 1, mov_id: movId });
    const r = await fetch(window.location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.ok) { mostrarToast('✅ Movimiento eliminado'); cargarMovimientos(); }
    else mostrarToast('❌ Error', true);
}

async function registrarMovimientoManual() {
    const tipo = document.getElementById('mov-tipo')?.value;
    const metodo = document.getElementById('mov-metodo')?.value;
    const montoInput = document.getElementById('mov-monto');
    const monto = parseFloat(montoInput?.value.replace(/[^0-9]/g, '') || '0');
    const motivo = document.getElementById('mov-motivo')?.value || '';
    if (monto <= 0) { mostrarToast('❌ Monto inválido', true); return; }
    const fd = new URLSearchParams({ _csrf: CSRF_TOKEN, registrar_movimiento_manual: 1, tipo, metodo_pago: metodo, monto, motivo });
    const r = await fetch(window.location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.ok) { mostrarToast(`✅ ${d.mensaje}`); montoInput.value = ''; document.getElementById('mov-motivo').value = ''; cargarMovimientos(); }
    else mostrarToast(`❌ ${d.error}`, true);
}

document.getElementById('formRegistrarMovimiento')?.addEventListener('submit', async e => {
    e.preventDefault();
    await registrarMovimientoManual();
});

// ====================================================================
// BUSCAR PRODUCTO Y ESCÁNER
// ====================================================================
async function buscarProducto() {
    const q = document.getElementById('buscar-input')?.value.trim();
    if (!q) { mostrarToast('🔍 Ingresa un código', true); return; }
    if (scannerMode === 'scanner') {
        await agregarProductoDesdeBusqueda(q);
        return;
    }
    await buscarProductosManual(q);
}

function checkScan() {
    if (scannerMode !== 'scanner') return;
    fetch(`?poll=1`).then(r=>r.json()).then(d=>{
        const scanToken = d?.producto ? `${d.producto.id}-${d.producto.timestamp || Date.now()}` : null;
        if (d.ok && d.producto && scanToken !== ultimoScanToken) {
            ultimoScanToken = scanToken;
            reproducirBeep();
            agregarProducto(d.producto);
            const connectionStatus = document.getElementById('connection-status');
            if (connectionStatus) {
                connectionStatus.innerHTML = '🟢 Producto recibido!';
                setTimeout(() => {
                    connectionStatus.innerHTML = '🟢 Escáner listo';
                }, 2000);
            }
        }
    });
}

function testEscanner() {
    setScannerMode('scanner');
    fetch(`?scan=1&codigo=123456789`).then(r=>r.json()).then(d=>{
        if (d.ok) {
            reproducirBeep();
            agregarProducto(d.producto);
        }
        else mostrarToast(`❌ ${d.error}`, true);
    });
}

// ====================================================================
// MODALES Y EVENTOS
// ====================================================================
function cerrarModal() { document.getElementById('modalDetalleVenta').style.display = 'none'; }
document.getElementById('btn-panel-pdf')?.addEventListener('click', () => { if (facturaActualId) generarFacturaPDF(facturaActualId); });
document.getElementById('btn-panel-wa')?.addEventListener('click', () => { if (facturaActualId) enviarWhatsApp(facturaActualId); });
document.getElementById('btn-panel-dev')?.addEventListener('click', () => { if (facturaActualId) abrirDevolucion(facturaActualId); });
document.getElementById('btn-panel-cerrar')?.addEventListener('click', () => { document.getElementById('factura-panel').classList.add('hidden'); });

function reloj() {
    const relojSistema = document.getElementById('reloj_sistema');
    if (relojSistema) {
        relojSistema.textContent = new Date().toLocaleTimeString('es-CO');
    }
}
setInterval(reloj, 1000); reloj();

// ==================== Gráfico resumen caja ====================
const chartEl = document.getElementById('chartResumenCaja');
const chartSaldoEl = document.getElementById('chartSaldoCaja');
const timeframeButtons = document.querySelectorAll('.timeframe-btn');

const CHART_DATA = {
    dias: {
        labels: <?= json_encode($chart_labels_dias ?? [], JSON_UNESCAPED_UNICODE) ?>,
        ventas: <?= json_encode($chart_ventas_dias ?? []) ?>,
        ingresos: <?= json_encode($chart_ingresos_dias ?? []) ?>,
        egresos: <?= json_encode($chart_egresos_dias ?? []) ?>,
        saldo: <?= json_encode($chart_saldo_dias ?? []) ?>
    },
    semanas: {
        labels: <?= json_encode($chart_labels_semanas ?? [], JSON_UNESCAPED_UNICODE) ?>,
        ventas: <?= json_encode($chart_ventas_semanas ?? []) ?>,
        ingresos: <?= json_encode($chart_ingresos_semanas ?? []) ?>,
        egresos: <?= json_encode($chart_egresos_semanas ?? []) ?>,
        saldo: <?= json_encode($chart_saldo_semanas ?? []) ?>
    },
    meses: {
        labels: <?= json_encode($chart_labels_meses ?? [], JSON_UNESCAPED_UNICODE) ?>,
        ventas: <?= json_encode($chart_ventas_meses ?? []) ?>,
        ingresos: <?= json_encode($chart_ingresos_meses ?? []) ?>,
        egresos: <?= json_encode($chart_egresos_meses ?? []) ?>,
        saldo: <?= json_encode($chart_saldo_meses ?? []) ?>
    }
};

let summaryChart = null;
let saldoChart = null;
let currentPeriod = 'dias';

function buildSummaryChart(period) {
    const data = CHART_DATA[period] || CHART_DATA.dias;
    const config = {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'Ventas',
                    data: data.ventas,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    display: true, 
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        font: { size: 11, weight: 'bold' }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            let value = context.raw;
                            return label + ': ' + fmt(value);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: {
                        callback: (v) => fmt(v),
                        stepSize: 50000
                    },
                    title: {
                        display: true,
                        text: 'Monto en Pesos (COP)',
                        font: { size: 10, weight: 'bold' }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 } }
                }
            }
        }
    };

    if (summaryChart) {
        summaryChart.destroy();
    }
    summaryChart = new Chart(chartEl, config);
}

function buildSaldoChart(period) {
    const data = CHART_DATA[period] || CHART_DATA.dias;
    const config = {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Saldo neto',
                data: data.saldo,
                backgroundColor: 'rgba(99,102,241,0.3)',
                borderColor: '#6366f1',
                borderWidth: 2,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { ticks: { callback: (v) => fmt(v) } }
            }
        }
    };

    if (saldoChart) {
        saldoChart.destroy();
    }
    saldoChart = new Chart(chartSaldoEl, config);
}

function switchChartPeriod(period) {
    if (!CHART_DATA[period]) return;
    currentPeriod = period;
    timeframeButtons.forEach(btn => {
        if (btn.dataset.period === period) {
            btn.classList.add('bg-indigo-600', 'text-white', 'shadow-sm');
            btn.classList.remove('bg-gray-100', 'text-slate-700');
        } else {
            btn.classList.remove('bg-indigo-600', 'text-white', 'shadow-sm');
            btn.classList.add('bg-gray-100', 'text-slate-700');
        }
    });
    buildSummaryChart(period);
    buildSaldoChart(period);
}

if (chartEl && chartSaldoEl && typeof Chart !== 'undefined') {
    switchChartPeriod(currentPeriod);
    timeframeButtons.forEach(btn => btn.addEventListener('click', () => switchChartPeriod(btn.dataset.period)));
}

const toggleChartBtn = document.getElementById('toggle-chart-btn');
const chartSection = document.getElementById('chart-section');
function toggleChartVisibility() {
    if (!chartSection || !toggleChartBtn) return;
    const hidden = chartSection.classList.toggle('hidden');
    toggleChartBtn.textContent = hidden ? 'Mostrar gráfico' : 'Ocultar gráfico';
}

toggleChartBtn?.addEventListener('click', toggleChartVisibility);

document.getElementById('vender-btn')?.addEventListener('click', registrarVenta);
document.getElementById('limpiar-btn')?.addEventListener('click', () => {
    const inputCosto = document.getElementById('total-costo');
    if (inputCosto) inputCosto.value = '';
    const monto = document.getElementById('monto-recibido');
    if (monto) monto.value = '';
    actualizarCambio();
});
document.getElementById('metodo-pago')?.addEventListener('change', actualizarCambio);
document.getElementById('monto-recibido')?.addEventListener('input', actualizarCambio);
document.getElementById('total-costo')?.addEventListener('input', actualizarCambio);
document.querySelectorAll('.quick-cash-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById('monto-recibido');
        const total = obtenerTotalCarrito();
        if (!input) return;
        if (btn.dataset.multiple === 'exacto') {
            input.value = total > 0 ? total.toLocaleString('es-CO') : '';
        } else {
            const value = parseFloat(btn.dataset.value || '0');
            input.value = value > 0 ? value.toLocaleString('es-CO') : '';
        }
        actualizarCambio();
    });
});

document.addEventListener('click', e => {
    const results = document.getElementById('client-search-results');
    const input = document.getElementById('cliente-input');
    if (!results || !input) return;
    if (e.target !== input && !results.contains(e.target)) {
        results.style.display = 'none';
    }
});

document.addEventListener('click', e => {
    const results = document.getElementById('product-search-results');
    const input = document.getElementById('buscar-input');
    if (!results || !input) return;
    if (e.target !== input && !results.contains(e.target)) {
        ocultarResultadosProducto();
    }
});

formatMoneyInput(document.getElementById('mov-monto'));
formatMoneyInput(document.getElementById('edit_monto'));
formatMoneyInput(document.getElementById('monto-recibido'));
formatMoneyInput(document.getElementById('total-costo'));

actualizarCambio();
mostrarToast('✨ Sistema listo', false);
</script>
<?php luxury_render_nav_end(); ?>
</body>
</html>
