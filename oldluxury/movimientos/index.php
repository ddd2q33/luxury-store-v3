<?php
// movimientos/index.php - Registro de Movimientos de Caja (Sin Sidebar)
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('movimientos');

// ===== FUNCIONES =====
function fmtM($n) { 
    return '$' . number_format((float)$n, 0, ',', '.'); 
}

function tipoBadge($tipo) {
    switch($tipo) {
        case 'venta':
            return '<span class="px-2 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700"><i class="fas fa-shopping-cart mr-1"></i>VENTA</span>';
        case 'ingreso':
            return '<span class="px-2 py-1 rounded-full text-[10px] font-bold bg-blue-100 text-blue-700"><i class="fas fa-arrow-down mr-1"></i>INGRESO</span>';
        case 'egreso':
            return '<span class="px-2 py-1 rounded-full text-[10px] font-bold bg-red-100 text-red-700"><i class="fas fa-arrow-up mr-1"></i>EGRESO</span>';
        default:
            return '<span class="px-2 py-1 rounded-full text-[10px] font-bold bg-gray-100 text-gray-700">' . strtoupper($tipo) . '</span>';
    }
}

// ===== FILTROS Y PAGINACIÓN =====
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$por_pagina = 5;
$offset = ($pagina - 1) * $por_pagina;

// Fecha fin con hora
$fecha_fin_datetime = $fecha_fin . ' 23:59:59';

// ===== CORREGIDO: Construir consulta base con array de parámetros =====
$where_conditions = ["DATE(fecha) BETWEEN ? AND ?"];
$params = [$fecha_inicio, $fecha_fin_datetime];
$types = "ss";

if ($tipo_filtro !== 'todos') {
    $where_conditions[] = "tipo = ?";
    $params[] = $tipo_filtro;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Contar total
$count_sql = "SELECT COUNT(*) as total FROM movimientos_caja WHERE $where_clause";
$stmt_count = $conn->prepare($count_sql);
if ($stmt_count) {
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_registros = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
} else {
    $total_registros = 0;
}

$total_paginas = ceil($total_registros / $por_pagina);

// Obtener movimientos con paginación
$sql = "SELECT id, tipo, monto, descripcion, metodo_pago, fecha, venta_id 
        FROM movimientos_caja 
        WHERE $where_clause 
        ORDER BY fecha DESC 
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if ($stmt) {
    // Agregar parámetros de paginación
    $params_paginacion = array_merge($params, [$por_pagina, $offset]);
    $types_paginacion = $types . "ii";
    $stmt->bind_param($types_paginacion, ...$params_paginacion);
    $stmt->execute();
    $movimientos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $movimientos = [];
}

// Calcular totales
$totales_sql = "SELECT 
    COALESCE(SUM(CASE WHEN tipo='venta' THEN monto ELSE 0 END),0) as ventas,
    COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto ELSE 0 END),0) as ingresos,
    COALESCE(SUM(CASE WHEN tipo='egreso' THEN monto ELSE 0 END),0) as egresos
    FROM movimientos_caja WHERE $where_clause";

$stmt_totales = $conn->prepare($totales_sql);
if ($stmt_totales) {
    $stmt_totales->bind_param($types, ...$params);
    $stmt_totales->execute();
    $totales = $stmt_totales->get_result()->fetch_assoc();
    $stmt_totales->close();
} else {
    $totales = ['ventas' => 0, 'ingresos' => 0, 'egresos' => 0];
}

$balance = $totales['ventas'] + $totales['ingresos'] - $totales['egresos'];

// ===== PROCESAR POST (Agregar/Eliminar) =====
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Agregar movimiento manual
    if (isset($_POST['action']) && $_POST['action'] === 'agregar_movimiento') {
        $tipo = $_POST['tipo'];
        $monto = floatval($_POST['monto']);
        $descripcion = trim($_POST['descripcion']);
        $metodo_pago = $_POST['metodo_pago'];
        
        if ($monto <= 0) {
            $error = "El monto debe ser mayor a 0";
        } elseif (empty($descripcion)) {
            $error = "La descripción es requerida";
        } else {
            $stmt_insert = $conn->prepare("INSERT INTO movimientos_caja (tipo, monto, descripcion, metodo_pago, fecha) VALUES (?, ?, ?, ?, NOW())");
            $stmt_insert->bind_param("sdss", $tipo, $monto, $descripcion, $metodo_pago);
            if ($stmt_insert->execute()) {
                $mensaje = "Movimiento registrado correctamente";
            } else {
                $error = "Error al registrar: " . $stmt_insert->error;
            }
            $stmt_insert->close();
        }
    }
    
    // Eliminar movimiento
    if (isset($_POST['action']) && $_POST['action'] === 'eliminar_movimiento') {
        $id = intval($_POST['id']);
        $stmt_delete = $conn->prepare("DELETE FROM movimientos_caja WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        if ($stmt_delete->execute()) {
            $mensaje = "Movimiento eliminado correctamente";
        } else {
            $error = "Error al eliminar";
        }
        $stmt_delete->close();
    }
    
    // Redirigir para evitar reenvío del formulario
    if ($mensaje || $error) {
        $redirect_url = "?fecha_inicio=$fecha_inicio&fecha_fin=$fecha_fin&tipo=$tipo_filtro&pagina=$pagina";
        if ($mensaje) $redirect_url .= "&mensaje=" . urlencode($mensaje);
        if ($error) $redirect_url .= "&error=" . urlencode($error);
        header("Location: $redirect_url");
        exit;
    }
}

// Recoger mensajes de URL
if (isset($_GET['mensaje'])) $mensaje = $_GET['mensaje'];
if (isset($_GET['error'])) $error = $_GET['error'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury Store - Movimientos de Caja</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        .toast { position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 12px 20px; border-radius: 12px; z-index: 1000; animation: slideIn 0.3s ease; }
        .toast.error { background: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 24px; max-width: 500px; width: 90%; padding: 24px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; margin-bottom: 16px; }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
<?php luxury_render_nav_start('movimientos'); ?>

    <div class="">

        <!-- Header con botón volver -->
        <div class="mb-6 flex justify-between items-center flex-wrap gap-4">
            <div>
                
                <h1 class="text-3xl font-extrabold text-gray-800 flex items-center gap-3">
                    <span class="bg-gradient-to-r from-amber-500 to-orange-500 p-2 rounded-2xl">
                        <i class="fas fa-exchange-alt text-white text-2xl"></i>
                    </span>
                    Movimientos de Caja
                </h1>
                <p class="text-gray-500 mt-1">Registro de ingresos, egresos y ventas</p>
            </div>
            <button onclick="abrirModalNuevoMovimiento()" class="bg-emerald-500 hover:bg-emerald-600 text-white px-5 py-2.5 rounded-xl font-bold transition shadow-md flex items-center gap-2">
                <i class="fas fa-plus-circle"></i> Nuevo Movimiento
            </button>
        </div>

        <?php if ($mensaje): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4 flex items-center justify-between">
            <span><i class="fas fa-check-circle mr-2"></i> <?= htmlspecialchars($mensaje) ?></span>
            <button onclick="this.parentElement.remove()" class="text-green-700">&times;</button>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4 flex items-center justify-between">
            <span><i class="fas fa-exclamation-triangle mr-2"></i> <?= htmlspecialchars($error) ?></span>
            <button onclick="this.parentElement.remove()" class="text-red-700">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Tarjetas de resumen -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-gradient-to-br from-emerald-500 to-green-600 rounded-2xl p-5 text-white shadow-lg">
                <p class="text-xs font-bold opacity-80 uppercase">Ventas</p>
                <p class="text-2xl font-extrabold mt-1"><?= fmtM($totales['ventas']) ?></p>
            </div>
            <div class="bg-gradient-to-br from-blue-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg">
                <p class="text-xs font-bold opacity-80 uppercase">Ingresos</p>
                <p class="text-2xl font-extrabold mt-1"><?= fmtM($totales['ingresos']) ?></p>
            </div>
            <div class="bg-gradient-to-br from-red-500 to-rose-600 rounded-2xl p-5 text-white shadow-lg">
                <p class="text-xs font-bold opacity-80 uppercase">Egresos</p>
                <p class="text-2xl font-extrabold mt-1"><?= fmtM($totales['egresos']) ?></p>
            </div>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl p-5 text-white shadow-lg">
                <p class="text-xs font-bold opacity-80 uppercase">Balance</p>
                <p class="text-2xl font-extrabold mt-1"><?= fmtM($balance) ?></p>
            </div>
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-2xl shadow-xl p-5 mb-6 border border-gray-100">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" value="<?= $fecha_inicio ?>" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Fecha Fin</label>
                    <input type="date" name="fecha_fin" value="<?= $fecha_fin ?>" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Tipo</label>
                    <select name="tipo" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none">
                        <option value="todos" <?= $tipo_filtro == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="venta" <?= $tipo_filtro == 'venta' ? 'selected' : '' ?>>Ventas</option>
                        <option value="ingreso" <?= $tipo_filtro == 'ingreso' ? 'selected' : '' ?>>Ingresos</option>
                        <option value="egreso" <?= $tipo_filtro == 'egreso' ? 'selected' : '' ?>>Egresos</option>
                    </select>
                </div>
                <div>
                    <input type="hidden" name="pagina" value="1">
                    <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white px-5 py-2 rounded-lg font-bold transition">
                        <i class="fas fa-search mr-2"></i> Filtrar
                    </button>
                </div>
                <div>
                    <a href="?" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2 rounded-lg font-bold transition inline-block">
                        <i class="fas fa-undo mr-2"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- Tabla de movimientos -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-[10px] text-gray-400 uppercase tracking-wider border-b">
                        <tr>
                            <th class="p-4 text-left">ID</th>
                            <th class="p-4 text-left">Fecha/Hora</th>
                            <th class="p-4 text-left">Tipo</th>
                            <th class="p-4 text-left">Descripción</th>
                            <th class="p-4 text-right">Monto</th>
                            <th class="p-4 text-center">Método</th>
                            <th class="p-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($movimientos)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-gray-400">
                                <i class="fas fa-inbox text-4xl mb-2 opacity-30"></i>
                                <p>No hay movimientos en este período</p>
                            </td>
                        </tr>
                        <?php else: foreach ($movimientos as $m): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="p-4 font-mono text-xs text-gray-400">#<?= $m['id'] ?></td>
                            <td class="p-4 text-xs text-gray-600"><?= date('d/m/Y H:i:s', strtotime($m['fecha'])) ?></td>
                            <td class="p-4"><?= tipoBadge($m['tipo']) ?></td>
                            <td class="p-4 text-gray-700 max-w-[250px] truncate" title="<?= htmlspecialchars($m['descripcion'] ?? '') ?>">
                                <?= htmlspecialchars($m['descripcion'] ?? '-') ?>
                            </td>
                            <td class="p-4 text-right font-bold <?= $m['tipo'] == 'egreso' ? 'text-red-600' : 'text-emerald-600' ?>">
                                <?= $m['tipo'] == 'egreso' ? '-' : '' ?><?= fmtM($m['monto']) ?>
                            </td>
                            <td class="p-4 text-center text-xs">
                                <span class="px-2 py-1 bg-gray-100 rounded-full"><?= htmlspecialchars($m['metodo_pago'] ?? 'Efectivo') ?></span>
                            </td>
                            <td class="p-4 text-center">
                                <button onclick="eliminarMovimiento(<?= $m['id'] ?>)" class="text-red-500 hover:text-red-700 transition" title="Eliminar">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
            <div class="px-5 py-4 bg-gray-50 border-t flex justify-between items-center flex-wrap gap-3">
                <p class="text-xs text-gray-500">Mostrando <?= count($movimientos) ?> de <?= $total_registros ?> registros</p>
                <div class="flex gap-1">
                    <?php if ($pagina > 1): ?>
                    <a href="?fecha_inicio=<?= $fecha_inicio ?>&fecha_fin=<?= $fecha_fin ?>&tipo=<?= $tipo_filtro ?>&pagina=<?= $pagina-1 ?>" class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-gray-600 text-xs hover:bg-gray-50 transition">Anterior</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $pagina-2); $i <= min($total_paginas, $pagina+2); $i++): ?>
                    <a href="?fecha_inicio=<?= $fecha_inicio ?>&fecha_fin=<?= $fecha_fin ?>&tipo=<?= $tipo_filtro ?>&pagina=<?= $i ?>" class="px-3 py-1.5 rounded-lg border text-xs font-medium transition <?= $i == $pagina ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white border-gray-300 text-gray-600 hover:bg-gray-50' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($pagina < $total_paginas): ?>
                    <a href="?fecha_inicio=<?= $fecha_inicio ?>&fecha_fin=<?= $fecha_fin ?>&tipo=<?= $tipo_filtro ?>&pagina=<?= $pagina+1 ?>" class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-gray-600 text-xs hover:bg-gray-50 transition">Siguiente</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Totales pie de tabla -->
            <?php if (!empty($movimientos)): ?>
            <div class="px-5 py-3 bg-gray-50 border-t flex justify-between items-center flex-wrap gap-2">
                <p class="text-xs text-gray-400"><strong class="text-gray-600"><?= $total_registros ?></strong> movimientos en el período</p>
                <div class="flex gap-4 text-xs font-bold">
                    <span class="text-emerald-600">Ventas <?= fmtM($totales['ventas']) ?></span>
                    <span class="text-blue-600">Ingresos <?= fmtM($totales['ingresos']) ?></span>
                    <span class="text-red-600">Egresos <?= fmtM($totales['egresos']) ?></span>
                    <span class="text-indigo-600">Balance <?= fmtM($balance) ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL NUEVO MOVIMIENTO -->
    <div id="modalNuevoMovimiento" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-xl font-bold flex items-center gap-2">
                    <i class="fas fa-plus-circle text-emerald-500"></i> Nuevo Movimiento
                </h3>
                <button onclick="cerrarModalMovimiento()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <form method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="agregar_movimiento">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Tipo</label>
                    <select name="tipo" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 outline-none">
                        <option value="ingreso">📥 Ingreso (dinero que entra)</option>
                        <option value="egreso">📤 Egreso (gasto o retiro)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Monto</label>
                    <input type="number" name="monto" step="0.01" required placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Método de Pago</label>
                    <select name="metodo_pago" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 outline-none">
                        <option value="Efectivo">💵 Efectivo</option>
                        <option value="Nequi">📱 Nequi</option>
                        <option value="Transferencia">📲 Transferencia</option>
                        <option value="Datafono">💳 Datáfono</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Descripción</label>
                    <textarea name="descripcion" rows="2" required placeholder="Ej: Pago de servicios, Compra de mercancía, etc." class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 outline-none"></textarea>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white py-2 rounded-lg font-bold transition">Registrar</button>
                    <button type="button" onclick="cerrarModalMovimiento()" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded-lg font-bold transition">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal
        const modal = document.getElementById('modalNuevoMovimiento');
        
        function abrirModalNuevoMovimiento() {
            modal.classList.add('active');
            modal.style.display = 'flex';
        }
        
        function cerrarModalMovimiento() {
            modal.classList.remove('active');
            modal.style.display = 'none';
        }
        
        // Eliminar movimiento
        function eliminarMovimiento(id) {
            if (confirm('¿Eliminar este movimiento? Esta acción no se puede deshacer.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="eliminar_movimiento">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            if (event.target === modal) {
                cerrarModalMovimiento();
            }
        }
        
        // Auto ocultar mensajes
        setTimeout(() => {
            document.querySelectorAll('.bg-green-100, .bg-red-100').forEach(el => {
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 500);
            });
        }, 5000);
    </script>
<?php luxury_render_nav_end(); ?>
</body>
</html>
