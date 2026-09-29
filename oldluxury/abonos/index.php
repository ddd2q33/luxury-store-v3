<?php
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('abonos');

// ====================================================================
// 📦 PROCESAMIENTO (CRUD DE ABONOS)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 💰 GUARDAR NUEVO ABONO
    if ($accion === 'guardar') {
        $proveedor_id = intval($_POST['proveedor_id']);
        $usuario_id = $_SESSION['user_id'] ?? 1;
        $monto = floatval($_POST['monto']);
        $referencia = trim($_POST['referencia']);

        if ($monto <= 0) {
            echo "<script>alert('❌ El monto debe ser mayor a 0'); window.location='./';</script>";
            exit;
        }

        $stmt = $conn->prepare("SELECT saldo_deuda FROM proveedores WHERE id=?");
        $stmt->bind_param("i", $proveedor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if (!$res || $res->num_rows == 0) {
            echo "<script>alert('❌ Proveedor no encontrado'); window.location='./';</script>";
            exit;
        }

        $deuda_anterior = floatval($res->fetch_assoc()['saldo_deuda']);
        $deuda_nueva = max(0, $deuda_anterior - $monto);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO abonos_proveedores 
                (proveedor_id, usuario_id, monto, deuda_anterior, deuda_nueva, referencia, fecha_abono)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("iiddds", $proveedor_id, $usuario_id, $monto, $deuda_anterior, $deuda_nueva, $referencia);
            $stmt->execute();
            $stmt->close();

            $stmt2 = $conn->prepare("UPDATE proveedores SET saldo_deuda=? WHERE id=?");
            $stmt2->bind_param("di", $deuda_nueva, $proveedor_id);
            $stmt2->execute();
            $stmt2->close();

            $conn->commit();

            echo "<script>
                alert('💰 Abono registrado: Deuda anterior " . format_currency($deuda_anterior) . " → Deuda actual " . format_currency($deuda_nueva) . "');
                window.location='./';
            </script>";
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('❌ Error al guardar abono: " . addslashes($e->getMessage()) . "'); window.location='./';</script>";
        }
        exit;
    }

    // 🗑️ ELIMINAR ABONO
    if ($accion === 'eliminar') {
        $id = intval($_POST['id']);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT proveedor_id, monto FROM abonos_proveedores WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            if (!$res || $res->num_rows == 0) throw new Exception("Abono no encontrado.");

            $data = $res->fetch_assoc();
            $proveedor_id = $data['proveedor_id'];
            $monto = $data['monto'];

            $stmt = $conn->prepare("UPDATE proveedores SET saldo_deuda = saldo_deuda + ? WHERE id=?");
            $stmt->bind_param("di", $monto, $proveedor_id);
            $stmt->execute();
            $stmt->close();

            $stmt2 = $conn->prepare("DELETE FROM abonos_proveedores WHERE id=?");
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $stmt2->close();

            $conn->commit();
            echo "<script>alert('🗑️ Abono eliminado y deuda revertida correctamente.'); window.location='./';</script>";
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('❌ Error al eliminar abono: " . addslashes($e->getMessage()) . "'); window.location='./';</script>";
        }
        exit;
    }
}

// ====================================================================
// 📊 CONSULTAS (LISTA DE ABONOS + FILTRO + PAGINACIÓN + TOTALES POR PROVEEDOR)
// ====================================================================
$limit = 5;
$page_num = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$offset = ($page_num - 1) * $limit;

$buscar = trim($_GET['buscar'] ?? '');
$where = "";
$search_params = [];
$search_types = "";
if ($buscar !== '') {
    $like = "%$buscar%";
    $where = "WHERE p.nombre LIKE ? OR a.referencia LIKE ? OR DATE(a.fecha_abono) LIKE ?";
    $search_params = [$like, $like, $like];
    $search_types = "sss";
}

$stmt_count = $conn->prepare("SELECT COUNT(*) AS total FROM abonos_proveedores a 
        LEFT JOIN proveedores p ON a.proveedor_id = p.id 
        $where");
if ($search_types !== "") {
    $stmt_count->bind_param($search_types, ...$search_params);
}
$stmt_count->execute();
$total = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_paginas = ceil($total / $limit);

$query = "
    SELECT a.*, p.nombre AS proveedor_nombre, u.nombre AS usuario_nombre
    FROM abonos_proveedores a
    LEFT JOIN proveedores p ON a.proveedor_id = p.id
    LEFT JOIN usuarios u ON a.usuario_id = u.id
    $where
    ORDER BY a.fecha_abono DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($query);
if ($search_types !== "") {
    $stmt->bind_param($search_types . "ii", ...array_merge($search_params, [$limit, $offset]));
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();
$abonos = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$proveedores = $conn->query("SELECT id, nombre FROM proveedores ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);

// ====================================================================
// 📊 CONSULTAR TOTAL DE ABONOS POR PROVEEDOR
// ====================================================================
$totales_por_proveedor = [];
$query_totales = "
    SELECT 
        p.id,
        p.nombre,
        COALESCE(SUM(a.monto), 0) AS total_abonado,
        p.saldo_deuda AS deuda_actual,
        (SELECT SUM(total_general) FROM compras WHERE proveedor_id = p.id) AS total_compras
    FROM proveedores p
    LEFT JOIN abonos_proveedores a ON p.id = a.proveedor_id
    GROUP BY p.id, p.nombre, p.saldo_deuda
    ORDER BY total_abonado DESC
";
$result_totales = $conn->query($query_totales);
$totales_por_proveedor = $result_totales->fetch_all(MYSQLI_ASSOC);

// Calcular gran total de todos los abonos
$gran_total_abonos = 0;
foreach ($totales_por_proveedor as $tp) {
    $gran_total_abonos += $tp['total_abonado'];
}
?>

<script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/chartjs/chart.umd.min.js"></script>

<?php luxury_render_nav_start('abonos'); ?>
<div class="">
    <h1 class="text-3xl font-bold text-gray-800 mb-6 flex items-center gap-3">
        <i class="fas fa-hand-holding-usd text-indigo-600"></i> Gestión de Abonos a Proveedores
    </h1>

    <!-- RESÚMENES Y TOTALES POR PROVEEDOR -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg shadow-lg p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm opacity-90">Total Abonado General</p>
                    <p class="text-2xl font-bold"><?= format_currency($gran_total_abonos) ?></p>
                    <p class="text-xs opacity-75 mt-1">Todos los proveedores</p>
                </div>
                <i class="fas fa-chart-line text-3xl opacity-50"></i>
            </div>
        </div>
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg shadow-lg p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm opacity-90">Total Proveedores</p>
                    <p class="text-2xl font-bold"><?= count($totales_por_proveedor) ?></p>
                    <p class="text-xs opacity-75 mt-1">Con abonos registrados</p>
                </div>
                <i class="fas fa-truck text-3xl opacity-50"></i>
            </div>
        </div>
        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg shadow-lg p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm opacity-90">Abonos Registrados</p>
                    <p class="text-2xl font-bold"><?= $total ?></p>
                    <p class="text-xs opacity-75 mt-1">Transacciones totales</p>
                </div>
                <i class="fas fa-money-bill-wave text-3xl opacity-50"></i>
            </div>
        </div>
    </div>

    <!-- TABLA DE TOTALES POR PROVEEDOR -->
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
            <i class="fas fa-chart-bar text-indigo-500"></i> 
            Resumen de Abonos por Proveedor
            <span class="text-sm text-gray-500 ml-2">(Total abonado a cada proveedor)</span>
        </h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-100 text-gray-700 uppercase tracking-wider">
                    <tr>
                        <th class="py-3 px-4 text-left">#</th>
                        <th class="py-3 px-4 text-left">Proveedor</th>
                        <th class="py-3 px-4 text-right">Total Abonado</th>
                        <th class="py-3 px-4 text-right">Total Compras</th>
                        <th class="py-3 px-4 text-right">Deuda Actual</th>
                        <th class="py-3 px-4 text-center">% Pagado</th>
                        
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (count($totales_por_proveedor) > 0): ?>
                        <?php foreach ($totales_por_proveedor as $index => $tp): 
                            $porcentaje_pagado = 0;
                            if ($tp['total_compras'] > 0) {
                                $porcentaje_pagado = ($tp['total_abonado'] / $tp['total_compras']) * 100;
                            }
                            $barra_color = $porcentaje_pagado >= 100 ? 'bg-green-500' : ($porcentaje_pagado >= 50 ? 'bg-yellow-500' : 'bg-red-500');
                        ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4"><?= $index + 1 ?>}</td>
                                <td class="py-3 px-4 font-semibold text-gray-800"><?= htmlspecialchars($tp['nombre']) ?></td>
                                <td class="py-3 px-4 text-right font-bold text-green-700"><?= format_currency($tp['total_abonado']) ?></td>
                                <td class="py-3 px-4 text-right text-blue-700"><?= format_currency($tp['total_compras'] ?? 0) ?></td>
                                <td class="py-3 px-4 text-right font-bold <?= $tp['deuda_actual'] > 0 ? 'text-red-600' : 'text-gray-500' ?>">
                                    <?= format_currency($tp['deuda_actual']) ?>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 bg-gray-200 rounded-full h-2">
                                            <div class="<?= $barra_color ?> h-2 rounded-full" style="width: <?= min(100, $porcentaje_pagado) ?>%"></div>
                                        </div>
                                        <span class="text-xs font-semibold"><?= number_format($porcentaje_pagado, 1) ?>%</span>
                                    </div>
                                </td>
                                
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="py-4 text-center text-gray-500">No hay abonos registrados</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-gray-50 font-semibold">
                    <tr>
                        <td colspan="2" class="py-3 px-4 text-right">TOTAL GENERAL:</td>
                        <td class="py-3 px-4 text-right text-green-800 text-lg"><?= format_currency($gran_total_abonos) ?></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- FORMULARIO NUEVO ABONO -->
    <div class="bg-gray-50 border rounded-lg p-6 shadow mb-6">
        <h2 class="text-lg font-semibold text-gray-700 mb-4">➕ Registrar Nuevo Abono</h2>
        <form method="POST" class="grid md:grid-cols-4 gap-4 items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="guardar">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Proveedor</label>
                <select name="proveedor_id" required class="w-full border rounded-lg px-3 py-2">
                    <option value="">-- Seleccione --</option>
                    <?php foreach ($proveedores as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Monto Abonado (COP)</label>
    <input type="text" name="monto_display" id="monto_display" 
           placeholder="$0" 
           class="w-full border rounded-lg px-3 py-2 text-lg font-semibold text-green-700">
    <input type="hidden" name="monto" id="monto_real" value="0">
</div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Referencia</label>
                <input type="text" name="referencia" placeholder="Pago factura #001" class="w-full border rounded-lg px-3 py-2">
            </div>

            <div>
                <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded-lg hover:bg-indigo-700">
                    <i class="fas fa-save mr-1"></i> Guardar
                </button>
            </div>
        </form>
    </div>

    <!-- FILTRO DE BÚSQUEDA -->
    <div class="mb-4">
        <form method="GET" class="flex gap-2">
            <input type="hidden" name="page" value="abonos">
            <input type="text" name="buscar" value="<?= htmlspecialchars($buscar) ?>" placeholder="Buscar proveedor, referencia o fecha" class="flex-1 border rounded-lg px-3 py-2">
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
                <i class="fas fa-search mr-1"></i> Buscar
            </button>
            <?php if ($buscar): ?>
                <a href="./" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">
                    <i class="fas fa-times mr-1"></i> Limpiar
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- LISTADO DE ABONOS -->
    <div class="bg-white shadow rounded-lg p-6">
        <h2 class="text-xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
            <i class="fas fa-history text-indigo-500"></i>
            Historial de Abonos
            <span class="text-sm text-gray-500">(Últimos <?= $limit ?> registros)</span>
        </h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-100 text-gray-700 uppercase tracking-wider">
                    <tr>
                        <th class="py-3 px-4 text-left">#</th>
                        <th class="py-3 px-4 text-left">Proveedor</th>
                        <th class="py-3 px-4 text-right">Monto Abonado</th>
                        <th class="py-3 px-4 text-right">Deuda Antes</th>
                        <th class="py-3 px-4 text-right">Deuda Después</th>
                        <th class="py-3 px-4">Referencia</th>
                        <th class="py-3 px-4">Fecha</th>
                        <th class="py-3 px-4 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (count($abonos) > 0): ?>
                        <?php foreach ($abonos as $i => $a): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4"><?= $offset + $i + 1 ?></td>
                                <td class="py-3 px-4 font-semibold text-gray-800">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-truck text-gray-400"></i>
                                        <?= htmlspecialchars($a['proveedor_nombre'] ?? '-') ?>
                                    </div>
                                </td>
                                <td class="py-3 px-4 text-right text-green-700 font-bold"><?= format_currency($a['monto']) ?></td>
                                <td class="py-3 px-4 text-right text-gray-600"><?= format_currency($a['deuda_anterior']) ?></td>
                                <td class="py-3 px-4 text-right <?= $a['deuda_nueva'] > 0 ? 'text-red-600' : 'text-blue-700' ?> font-bold">
                                    <?= format_currency($a['deuda_nueva']) ?>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="text-xs bg-gray-100 px-2 py-1 rounded">
                                        <?= htmlspecialchars($a['referencia'] ?: 'Sin referencia') ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <i class="fas fa-calendar-alt text-gray-400 mr-1"></i>
                                    <?= date('d/m/Y H:i', strtotime($a['fecha_abono'])) ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <form method="POST" onsubmit="return confirm('⚠️ ¿Eliminar este abono?\n\nEsta acción:\n- Eliminará el registro de abono\n- Revertirá la deuda del proveedor\n- No se puede deshacer\n\n¿Deseas continuar?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 transition" title="Eliminar Abono">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="py-8 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-2 block"></i>
                                No hay abonos registrados
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="8" class="py-3 px-4">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">
                                    Mostrando <?= count($abonos) ?> de <?= $total ?> abonos
                                </span>
                                <div class="flex gap-2">
                                    <?php if ($page_num > 1): ?>
                                        <a href="?p=<?= $page_num-1 ?>&buscar=<?= urlencode($buscar) ?>" 
                                           class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">
                                            &laquo; Anterior
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php for ($p = 1; $p <= min(5, $total_paginas); $p++): ?>
                                        <a href="?p=<?= $p ?>&buscar=<?= urlencode($buscar) ?>" 
                                           class="px-3 py-1 rounded <?= $p==$page_num ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                                            <?= $p ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($total_paginas > 5): ?>
                                        <span class="px-3 py-1">...</span>
                                        <a href="?p=<?= $total_paginas ?>&buscar=<?= urlencode($buscar) ?>" 
                                           class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">
                                            <?= $total_paginas ?>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($page_num < $total_paginas): ?>
                                        <a href="?p=<?= $page_num+1 ?>&buscar=<?= urlencode($buscar) ?>" 
                                           class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">
                                            Siguiente &raquo;
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<style>
    .transition {
        transition: all 0.2s ease;
    }
    .hover\:bg-gray-50:hover {
        background-color: #f9fafb;
    }
</style>
<?php luxury_render_nav_end(); ?>

<script>
// Función para formatear número a COP
function formatCOP(value) {
    // Eliminar todo lo que no sea número
    let numericValue = value.toString().replace(/[^0-9]/g, '');
    
    if (numericValue === '') {
        return { display: '$0', real: 0 };
    }
    
    // Convertir a número
    let number = parseInt(numericValue, 10);
    
    // Formatear con separadores de miles
    let formatted = new Intl.NumberFormat('es-CO').format(number);
    
    return {
        display: '$' + formatted,
        real: number
    };
}

// Formatear el monto mientras se escribe
const montoDisplay = document.getElementById('monto_display');
const montoReal = document.getElementById('monto_real');

if (montoDisplay) {
    // Evento cuando el usuario escribe
    montoDisplay.addEventListener('input', function(e) {
        let value = this.value;
        
        // Si el usuario borró todo
        if (value === '') {
            montoReal.value = 0;
            this.value = '$0';
            return;
        }
        
        // Obtener solo números del valor actual
        let numbers = value.replace(/[^0-9]/g, '');
        
        if (numbers === '') {
            montoReal.value = 0;
            this.value = '$0';
            return;
        }
        
        // Formatear el número
        let formatted = formatCOP(numbers);
        montoReal.value = formatted.real;
        
        // Actualizar el display sin perder la posición del cursor
        let cursorPos = this.selectionStart;
        let oldLength = this.value.length;
        this.value = formatted.display;
        
        // Ajustar posición del cursor
        let newLength = this.value.length;
        let diff = newLength - oldLength;
        this.setSelectionRange(cursorPos + diff, cursorPos + diff);
    });
    
    // Evento cuando el input pierde el foco
    montoDisplay.addEventListener('blur', function() {
        let value = montoReal.value;
        if (value == 0 || value == '0') {
            this.value = '$0';
        } else {
            let formatted = formatCOP(value);
            this.value = formatted.display;
        }
    });
    
    // Evento cuando el input recibe foco
    montoDisplay.addEventListener('focus', function() {
        let value = montoReal.value;
        if (value > 0) {
            this.value = value;
        } else {
            this.value = '';
        }
    });
    
    // Inicializar
    montoDisplay.value = '$0';
    montoReal.value = 0;
}
</script>