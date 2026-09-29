<?php
// pages/empleados.php - Gestión de Empleados
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

require_admin();

// --- Funciones auxiliares ---
function sanitize_input($conn, $data) {
    return is_array($data) ? '' : mysqli_real_escape_string($conn, trim($data));
}

// 🟢 Agregar empleado
if (isset($_POST['guardar'])) {
    $nombre = sanitize_input($conn, $_POST['nombre']);
    $cargo = sanitize_input($conn, $_POST['cargo']);
    $telefono = sanitize_input($conn, $_POST['telefono']);
    $correo = sanitize_input($conn, $_POST['correo']);
    $salario = sanitize_input($conn, $_POST['salario']);
    $fecha_ingreso = sanitize_input($conn, $_POST['fecha_ingreso']);

    if ($nombre != '' && $cargo != '') {
        $stmt = $conn->prepare("INSERT INTO empleados (nombre, cargo, telefono, correo, salario, fecha_ingreso) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssds", $nombre, $cargo, $telefono, $correo, $salario, $fecha_ingreso);
        
        if ($stmt->execute()) {
            echo "<script>alert('✅ Empleado agregado correctamente'); window.location='./';</script>";
            exit;
        } else {
            echo "<script>alert('❌ Error al agregar empleado: " . addslashes($stmt->error) . "');</script>";
        }
        $stmt->close();
    } else {
        echo "<script>alert('❌ Nombre y cargo son obligatorios');</script>";
    }
}

// ✏️ Editar empleado
if (isset($_POST['editar'])) {
    $id = intval($_POST['id']);
    $nombre = sanitize_input($conn, $_POST['nombre']);
    $cargo = sanitize_input($conn, $_POST['cargo']);
    $telefono = sanitize_input($conn, $_POST['telefono']);
    $correo = sanitize_input($conn, $_POST['correo']);
    $salario = sanitize_input($conn, $_POST['salario']);
    $fecha_ingreso = sanitize_input($conn, $_POST['fecha_ingreso']);

    $stmt = $conn->prepare("UPDATE empleados SET nombre=?, cargo=?, telefono=?, correo=?, salario=?, fecha_ingreso=? WHERE id=?");
    $stmt->bind_param("ssssdsi", $nombre, $cargo, $telefono, $correo, $salario, $fecha_ingreso, $id);
    
    if ($stmt->execute()) {
        echo "<script>alert('✏️ Datos del empleado actualizados correctamente'); window.location='./';</script>";
        exit;
    } else {
        echo "<script>alert('❌ Error al actualizar: " . addslashes($stmt->error) . "');</script>";
    }
    $stmt->close();
}

// 🔴 Eliminar empleado
if (isset($_POST['eliminar'])) {
    $id = intval($_POST['eliminar']);
    
    $stmt = $conn->prepare("DELETE FROM empleados WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo "<script>alert('🗑️ Empleado eliminado correctamente'); window.location='./';</script>";
        exit;
    } else {
        echo "<script>alert('❌ Error al eliminar: " . addslashes($stmt->error) . "');</script>";
    }
    $stmt->close();
}

// --- LÓGICA DE PAGINACIÓN Y FILTRO ---
$limit = 10;
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$safe_search = mysqli_real_escape_string($conn, $search_term);

// Determinar la cláusula WHERE
$where_clause = "";
if (!empty($safe_search)) {
    $where_clause = " WHERE nombre LIKE '%$safe_search%' 
                      OR cargo LIKE '%$safe_search%' 
                      OR correo LIKE '%$safe_search%'
                      OR telefono LIKE '%$safe_search%'";
}

// 1. Obtener el total de registros
$count_query = "SELECT COUNT(id) AS total FROM empleados $where_clause";
$count_result = mysqli_query($conn, $count_query);

if (!$count_result) {
    die("Error en la consulta: " . mysqli_error($conn));
}

$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $limit);

// Calcular OFFSET
$offset = ($page - 1) * $limit;

if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// 2. Consultar empleados
$main_query = "SELECT id, nombre, cargo, telefono, correo, salario, fecha_ingreso, 
               DATE_FORMAT(fecha_ingreso, '%Y-%m-%d') as fecha_ingreso_fmt,
               DATE_FORMAT(creado_en, '%d/%m/%Y %H:%i') as creado_en_fmt
               FROM empleados $where_clause 
               ORDER BY id DESC 
               LIMIT $limit OFFSET $offset";
$result = mysqli_query($conn, $main_query);

if (!$result) {
    die("Error en la consulta: " . mysqli_error($conn));
}
?>

<script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
<?php luxury_render_nav_start('empleados'); ?>
<div class="p-0">
    <h1 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
        <i class="fas fa-user-tie text-blue-600 mr-2"></i> Gestión de Empleados
    </h1>

    <!-- Formulario para agregar empleado -->
    <div class="bg-white p-6 rounded-2xl shadow-md mb-8">
        <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
            <i class="fas fa-user-plus text-green-500 mr-2"></i> Registrar Nuevo Empleado
        </h2>

        <form action="" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm mb-1 font-medium">Nombre completo *</label>
                <input type="text" name="nombre" class="w-full border rounded-lg p-2 focus:ring-blue-500 focus:border-blue-500" required>
            </div>
            <div>
                <label class="block text-sm mb-1 font-medium">Cargo *</label>
                <input type="text" name="cargo" class="w-full border rounded-lg p-2 focus:ring-blue-500 focus:border-blue-500" required>
            </div>
            <div>
                <label class="block text-sm mb-1 font-medium">Teléfono</label>
                <input type="text" name="telefono" class="w-full border rounded-lg p-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm mb-1 font-medium">Correo</label>
                <input type="email" name="correo" class="w-full border rounded-lg p-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm mb-1 font-medium">Salario</label>
                <input type="number" name="salario" class="w-full border rounded-lg p-2 focus:ring-blue-500 focus:border-blue-500" step="0.01">
            </div>
            <div>
                <label class="block text-sm mb-1 font-medium">Fecha de ingreso</label>
                <input type="date" name="fecha_ingreso" class="w-full border rounded-lg p-2 focus:ring-blue-500 focus:border-blue-500" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-span-full text-right mt-4">
                <button type="submit" name="guardar" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow-md transition flex items-center gap-2 justify-center">
                    <i class="fas fa-save"></i> Guardar Empleado
                </button>
            </div>
        </form>
    </div>

    <!-- Tabla de empleados -->
    <div class="bg-white p-6 rounded-2xl shadow-md">
        <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
            <i class="fas fa-list text-blue-500 mr-2"></i> Lista de Empleados
        </h2>
        
        <!-- Formulario de Búsqueda -->
        <div class="mb-4">
            <form action="" method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="page" value="empleados">
                <input type="text" name="search" value="<?= htmlspecialchars($search_term) ?>" 
                       placeholder="Buscar por nombre, cargo, correo o teléfono..." 
                       class="flex-grow md:w-1/3 border rounded-lg p-2 focus:ring-blue-500 focus:border-blue-500">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow-md transition">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <?php if (!empty($search_term)): ?>
                    <a href="./" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded-lg shadow-md transition">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-left text-gray-600">
                <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
                    <tr>
                        <th class="py-3 px-4">#</th>
                        <th class="py-3 px-4">Nombre</th>
                        <th class="py-3 px-4">Cargo</th>
                        <th class="py-3 px-4">Teléfono</th>
                        <th class="py-3 px-4">Correo</th>
                        <th class="py-3 px-4">Salario</th>
                        <th class="py-3 px-4">Fecha Ingreso</th>
                        <th class="py-3 px-4 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (mysqli_num_rows($result) > 0) {
                        $i = $offset + 1;
                        while ($row = mysqli_fetch_assoc($result)) {
                            ?>
                            <tr class="border-b hover:bg-gray-50">
                                <form method="POST" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <td class="py-3 px-4"><?= $i ?></td>
                                    <td class="py-3 px-4">
                                        <input name="nombre" value="<?= htmlspecialchars($row['nombre']) ?>" 
                                               class="border rounded p-1 w-full text-sm" required>
                                    </td>
                                    <td class="py-3 px-4">
                                        <input name="cargo" value="<?= htmlspecialchars($row['cargo']) ?>" 
                                               class="border rounded p-1 w-full text-sm" required>
                                    </td>
                                    <td class="py-3 px-4">
                                        <input name="telefono" value="<?= htmlspecialchars($row['telefono'] ?? '') ?>" 
                                               class="border rounded p-1 w-full text-sm">
                                    </td>
                                    <td class="py-3 px-4">
                                        <input name="correo" value="<?= htmlspecialchars($row['correo'] ?? '') ?>" 
                                               class="border rounded p-1 w-full text-sm">
                                    </td>
                                    <td class="py-3 px-4">
                                        <input name="salario" type="number" step="0.01" value="<?= htmlspecialchars($row['salario'] ?? 0) ?>" 
                                               class="border rounded p-1 w-full text-sm">
                                    </td>
                                    <td class="py-3 px-4">
                                        <input name="fecha_ingreso" type="date" value="<?= $row['fecha_ingreso_fmt'] ?? date('Y-m-d') ?>" 
                                               class="border rounded p-1 w-full text-sm">
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="submit" name="editar" class="text-blue-600 hover:text-blue-800" title="Guardar cambios">
                                                <i class="fas fa-save"></i>
                                            </button>
<a href="#" onclick="eliminarEmpleado(<?= $row['id'] ?>); return false;" 
                                               class="text-red-600 hover:text-red-800" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </form>
                            </tr>
                            <?php
                            $i++;
                        }
                    } else {
                        $empty_message = !empty($search_term) ? "No se encontraron empleados que coincidan con \"" . htmlspecialchars($search_term) . "\"." : "No hay empleados registrados.";
                        echo "<tr><td colspan='8' class='text-center py-4 text-gray-500'>$empty_message</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <?php if ($total_pages > 1): ?>
            <div class="mt-6 flex justify-between items-center flex-wrap">
                <p class="text-sm text-gray-700 mb-2 md:mb-0">
                    Mostrando página <span class="font-medium"><?= $page ?></span> de <span class="font-medium"><?= $total_pages ?></span> 
                    (Total: <span class="font-medium"><?= $total_records ?></span> empleados)
                </p>
                <nav class="relative z-0 inline-flex rounded-lg shadow-sm -space-x-px">
                    <?php 
                    $search_param = !empty($search_term) ? "&search=" . urlencode($search_term) : '';
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="?p=<?= $page-1 ?><?= $search_param ?>" 
                           class="relative inline-flex items-center px-3 py-2 rounded-l-lg border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-100">
                            <i class="fas fa-chevron-left text-xs"></i>
                        </a>
                    <?php endif; ?>

                    <?php 
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    if ($end_page - $start_page < 4) {
                        $start_page = max(1, $total_pages - 4);
                        $end_page = min($total_pages, $start_page + 4);
                    }
                    for ($p = $start_page; $p <= $end_page; $p++): 
                        $active_class = ($p == $page) ? 'bg-blue-600 text-white z-10 border-blue-500' : 'bg-white text-gray-700 hover:bg-gray-100';
                    ?>
                        <a href="?p=<?= $p ?><?= $search_param ?>" 
                           class="relative hidden md:inline-flex items-center px-4 py-2 border text-sm font-medium <?= $active_class ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?p=<?= $page+1 ?><?= $search_param ?>" 
                           class="relative inline-flex items-center px-3 py-2 rounded-r-lg border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-100">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
const EMPLEADOS_CSRF = '<?= csrf_token() ?>';
function eliminarEmpleado(id) {
    if (!confirm('¿Eliminar este empleado? Esta acción no se puede deshacer.')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="_csrf" value="' + EMPLEADOS_CSRF + '">' +
        '<input type="hidden" name="eliminar" value="' + id + '">';
    document.body.appendChild(form);
    form.submit();
}
</script>
<?php luxury_render_nav_end(); ?>
