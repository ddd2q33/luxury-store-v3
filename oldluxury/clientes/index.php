<?php
// clientes.php - GESTIÓN DE CLIENTES MODERNA (CRUD + Modal)
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('clientes');

// ====================================================================
// ⚙️ MANEJO DE CLIENTES
// ====================================================================

// 🟢 Agregar cliente
if (isset($_POST['guardar'])) {
    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono']);
    $correo = trim($_POST['correo']);
    $direccion = trim($_POST['direccion']);
    $ciudad = trim($_POST['ciudad']);
    $notas = trim($_POST['notas']);

    if ($nombre != '') {
        $stmt = $conn->prepare("INSERT INTO clientes (nombre, telefono, correo, direccion, ciudad, notas) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $nombre, $telefono, $correo, $direccion, $ciudad, $notas);
        $stmt->execute();
        $stmt->close();
        echo "<script>alert('✅ Cliente agregado correctamente'); window.location='./';</script>";
    }
}

// ✏️ Editar cliente
if (isset($_POST['editar'])) {
    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono']);
    $correo = trim($_POST['correo']);
    $direccion = trim($_POST['direccion']);
    $ciudad = trim($_POST['ciudad']);
    $notas = trim($_POST['notas']);

    if ($nombre != '') {
        $stmt = $conn->prepare("UPDATE clientes SET 
            nombre=?,
            telefono=?,
            correo=?,
            direccion=?,
            ciudad=?,
            notas=?
            WHERE id=?");
        $stmt->bind_param("ssssssi", $nombre, $telefono, $correo, $direccion, $ciudad, $notas, $id);
        $stmt->execute();
        $stmt->close();
        echo "<script>alert('✏️ Datos del cliente actualizados correctamente'); window.location='./';</script>";
    }
}

// 🔴 Eliminar cliente
if (isset($_POST['eliminar'])) {
    $id = intval($_POST['eliminar']);
    $stmt = $conn->prepare("DELETE FROM clientes WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo "<script>alert('🗑️ Cliente eliminado'); window.location='./';</script>";
}

// ====================================================================
// 🔍 LÓGICA DE BÚSQUEDA Y PAGINACIÓN
// ====================================================================

// 1. Configuración de Paginación (8 clientes por página)
$clientes_por_pagina = 5;
$pagina_actual = isset($_GET['p']) ? intval($_GET['p']) : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

$offset = ($pagina_actual - 1) * $clientes_por_pagina;

// 2. Configuración de Búsqueda
$busqueda_q = isset($_GET['q']) ? mysqli_real_escape_string($conn, trim($_GET['q'])) : '';
$where_clause = '';

if (!empty($busqueda_q)) {
    $where_clause = " WHERE nombre LIKE '%$busqueda_q%' 
                      OR telefono LIKE '%$busqueda_q%' 
                      OR correo LIKE '%$busqueda_q%' 
                      OR direccion LIKE '%$busqueda_q%'
                      OR ciudad LIKE '%$busqueda_q%'";
}

// 3. Consulta para contar el total de registros
$total_sql = "SELECT COUNT(id) AS total FROM clientes $where_clause";
$total_result = mysqli_query($conn, $total_sql);
$total_clientes = $total_result ? mysqli_fetch_assoc($total_result)['total'] : 0;
$total_paginas = max(1, ceil($total_clientes / $clientes_por_pagina));

// Ajustar página actual
if ($pagina_actual > $total_paginas && $total_paginas > 0) {
    $pagina_actual = $total_paginas;
    $offset = ($pagina_actual - 1) * $clientes_por_pagina;
}

// 4. Consulta final con LIMIT y OFFSET
$sql = "SELECT * FROM clientes $where_clause ORDER BY id DESC LIMIT $clientes_por_pagina OFFSET $offset";
$result = mysqli_query($conn, $sql);

function get_pagination_link($page, $q) {
    $link = "?p=$page";
    if (!empty($q)) {
        $link .= "&q=" . urlencode($q);
    }
    return $link;
}

// ====================================================================
// ESTADÍSTICAS (CON VERIFICACIÓN DE COLUMNAS)
// ====================================================================
// Verificar si existe la columna fecha_registro
$check_column = $conn->query("SHOW COLUMNS FROM clientes LIKE 'fecha_registro'");
$has_fecha_registro = $check_column && $check_column->num_rows > 0;

// Contar nuevos hoy (si existe fecha_registro)
$nuevos_hoy = 0;
if ($has_fecha_registro) {
    $hoy = date('Y-m-d');
    $hoy_result = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE DATE(fecha_registro) = '$hoy'");
    if ($hoy_result) {
        $nuevos_hoy = $hoy_result->fetch_assoc()['total'];
    }
}

// Contar nuevos este mes (si existe fecha_registro)
$nuevos_mes = 0;
if ($has_fecha_registro) {
    $mes_actual = date('Y-m');
    $mes_result = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE DATE_FORMAT(fecha_registro, '%Y-%m') = '$mes_actual'");
    if ($mes_result) {
        $nuevos_mes = $mes_result->fetch_assoc()['total'];
    }
}

// Contar clientes con teléfono
$con_telefono = 0;
$telefono_result = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE telefono IS NOT NULL AND telefono != ''");
if ($telefono_result) {
    $con_telefono = $telefono_result->fetch_assoc()['total'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury POS | Gestión de Clientes</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        @import url('../assets/vendor/inter/inter.css');
        * { font-family: 'Inter', sans-serif; }
        
        .toast { position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 12px 24px; border-radius: 12px; z-index: 1000; animation: slideIn 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .toast.error { background: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 32px; max-width: 600px; width: 90%; max-height: 85vh; overflow-y: auto; padding: 32px; animation: modalFade 0.2s ease; }
        @keyframes modalFade { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        
        .cliente-row { transition: all 0.2s; }
        .cliente-row:hover { background-color: #f8fafc; transform: translateX(2px); }
        
        .stats-card { transition: all 0.3s; }
        .stats-card:hover { transform: translateY(-3px); box-shadow: 0 12px 25px -8px rgba(0,0,0,0.15); }
        
        .pagination a { transition: all 0.2s; }
        .pagination a:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 to-gray-100 min-h-screen">
<?php luxury_render_nav_start('clientes'); ?>

<div>

    <!-- Header -->
    <div class="bg-white rounded-3xl shadow-xl p-6 mb-8 border border-slate-100">
        <div class="flex justify-between items-center flex-wrap gap-4">
            <div>
                <h1 class="text-3xl font-black text-slate-800 flex items-center gap-3">
                    <i class="fas fa-users text-emerald-500 text-3xl"></i>
                    Gestión de Clientes
                </h1>
                <p class="text-slate-400 text-sm font-medium mt-1">Administra tu base de datos de clientes</p>
            </div>
            <div class="bg-emerald-100 text-emerald-700 px-4 py-2 rounded-full text-sm font-bold">
                <i class="fas fa-users mr-2"></i> Total: <?= number_format($total_clientes) ?> clientes
            </div>
        </div>
    </div>

    <!-- Barra de Búsqueda con Botón Modal -->
    <div class="bg-white rounded-3xl shadow-xl p-6 mb-8 border border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex-1 w-full">
                <form method="GET" class="flex gap-3">
                    <input type="hidden" name="page" value="clientes">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="search" name="q" value="<?= htmlspecialchars($busqueda_q) ?>" 
                               placeholder="Buscar por nombre, teléfono, correo, dirección o ciudad..."
                               class="w-full pl-12 pr-4 py-4 bg-slate-50 border-2 border-slate-200 rounded-2xl focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all">
                    </div>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-4 rounded-2xl font-bold transition-all shadow-md flex items-center gap-2">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                    <?php if (!empty($busqueda_q)): ?>
                        <a href="./" class="bg-slate-500 hover:bg-slate-600 text-white px-6 py-4 rounded-2xl font-bold transition-all flex items-center gap-2">
                            <i class="fas fa-times"></i> Limpiar
                        </a>
                    <?php endif; ?>
                </form>
            </div>
            <button onclick="abrirModalCliente()" class="bg-gradient-to-r from-emerald-600 to-green-600 hover:from-emerald-700 hover:to-green-700 text-white px-8 py-4 rounded-2xl font-bold transition-all shadow-md flex items-center gap-2 whitespace-nowrap">
                <i class="fas fa-user-plus"></i> Nuevo Cliente
            </button>
        </div>
    </div>

    <!-- Tabla de Clientes -->
    <div class="bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden">
        <div class="bg-gradient-to-r from-gray-800 to-gray-900 text-white p-5">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-lg font-bold flex items-center gap-2">
                        <i class="fas fa-list"></i> Lista de Clientes
                    </h2>
                    <p class="text-xs opacity-70">Clientes registrados en el sistema</p>
                </div>
                <div class="bg-white/20 px-3 py-1 rounded-full text-xs font-bold">
                    Página <?= $pagina_actual ?> de <?= $total_paginas ?>
                </div>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b">
                     
                        <th class="p-5 text-left">ID</th>
                        <th class="p-5 text-left">Nombre</th>
                        <th class="p-5 text-left">Teléfono</th>
                        <th class="p-5 text-left">Correo</th>
                        <th class="p-5 text-left">Dirección</th>
                        <th class="p-5 text-left">Ciudad</th>
                        <th class="p-5 text-left">Notas</th>
                        <th class="p-5 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php
                    if ($result && mysqli_num_rows($result) > 0) {
                        $i = $offset + 1; 
                        while ($row = mysqli_fetch_assoc($result)) {
                            echo "
                            <form method='POST' class='cliente-row transition-colors'>
                                <input type='hidden' name='id' value='{$row['id']}'>
                                <input type='hidden' name='_csrf' value='" . csrf_token() . "'>
                                <tr class='hover:bg-gray-50'>
                                    <td class='p-5 font-mono text-sm text-slate-500'>{$i}    </td>
                                    <td class='p-5'><input name='nombre' value=\"" . htmlspecialchars($row['nombre']) . "\" class='border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none' required> </td>
                                    <td class='p-5'><input name='telefono' value=\"" . htmlspecialchars($row['telefono']) . "\" class='border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-emerald-500 outline-none'> </td>
                                    <td class='p-5'><input name='correo' value=\"" . htmlspecialchars($row['correo']) . "\" class='border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-emerald-500 outline-none'> </td>
                                    <td class='p-5'><input name='direccion' value=\"" . htmlspecialchars($row['direccion']) . "\" class='border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-emerald-500 outline-none'> </td>
                                    <td class='p-5'><input name='ciudad' value=\"" . htmlspecialchars($row['ciudad']) . "\" class='border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-emerald-500 outline-none'> </td>
                                    <td class='p-5'><input name='notas' value=\"" . htmlspecialchars($row['notas']) . "\" class='border rounded-xl p-2 w-full text-sm focus:ring-2 focus:ring-emerald-500 outline-none'> </td>
                                    <td class='p-5 text-center'>
                                        <div class='flex items-center justify-center gap-2'>
                                            <button type='submit' name='editar' class='text-emerald-600 hover:text-emerald-800 p-2 rounded-lg hover:bg-emerald-50 transition' title='Guardar cambios'>
                                                <i class='fas fa-save text-lg'></i>
                                            </button>
                                            <button onclick='eliminarCliente({$row['id']})' class='text-red-500 hover:text-red-700 p-2 rounded-lg hover:bg-red-50 transition' title='Eliminar'>
                                                <i class='fas fa-trash-alt text-lg'></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </form>";
                            $i++;
                        }
                    } else {
                        $empty_message = !empty($busqueda_q) ? "No se encontraron clientes que coincidan con \"" . htmlspecialchars($busqueda_q) . "\"." : "No hay clientes registrados.";
                        echo "<tr><td colspan='8' class='p-12 text-center text-slate-400'><i class='fas fa-users text-5xl mb-3 opacity-30'></i><br>$empty_message</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        
        <!-- Paginación Mejorada -->
        <?php if ($total_paginas > 1): ?>
            <div class="pagination p-6 bg-slate-50 flex justify-center gap-2 flex-wrap border-t border-slate-100">
                <?php if ($pagina_actual > 1): ?>
                    <a href="<?= get_pagination_link($pagina_actual - 1, $busqueda_q) ?>" 
                       class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-sm bg-white text-slate-600 hover:bg-slate-100 transition shadow-sm">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>
                
                <?php 
                $rango = 2;
                $inicio = max(1, $pagina_actual - $rango);
                $fin = min($total_paginas, $pagina_actual + $rango);
                
                if ($inicio > 1) {
                    echo '<a href="' . get_pagination_link(1, $busqueda_q) . '" class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-sm bg-white text-slate-600 hover:bg-slate-100">1</a>';
                    if ($inicio > 2) {
                        echo '<span class="w-10 h-10 flex items-center justify-center text-slate-400">...</span>';
                    }
                }
                
                for ($p = $inicio; $p <= $fin; $p++):
                    $active = ($p == $pagina_actual) ? 'bg-emerald-600 text-white shadow-md' : 'bg-white text-slate-600 hover:bg-slate-100';
                ?>
                    <a href="<?= get_pagination_link($p, $busqueda_q) ?>" 
                       class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-sm transition-all <?= $active ?>">
                        <?= $p ?>
                    </a>
                <?php endfor;
                
                if ($fin < $total_paginas) {
                    if ($fin < $total_paginas - 1) {
                        echo '<span class="w-10 h-10 flex items-center justify-center text-slate-400">...</span>';
                    }
                    echo '<a href="' . get_pagination_link($total_paginas, $busqueda_q) . '" class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-sm bg-white text-slate-600 hover:bg-slate-100">' . $total_paginas . '</a>';
                }
                ?>
                
                <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="<?= get_pagination_link($pagina_actual + 1, $busqueda_q) ?>" 
                       class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-sm bg-white text-slate-600 hover:bg-slate-100 transition shadow-sm">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div class="text-center pb-4 text-xs text-slate-400">
                Mostrando <span class="font-semibold"><?= mysqli_num_rows($result) ?></span> de 
                <span class="font-semibold"><?= number_format($total_clientes) ?></span> clientes
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL NUEVO CLIENTE (FLOTANTE) -->
<div id="modal-cliente" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-black text-slate-800 flex items-center gap-2">
                <i class="fas fa-user-plus text-emerald-500"></i> Nuevo Cliente
            </h3>
            <button onclick="cerrarModalCliente()" class="text-slate-400 hover:text-red-500 text-3xl transition">&times;</button>
        </div>
        
        <form method="POST" id="formNuevoCliente">
            <?= csrf_field() ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Nombre *</label>
                    <input type="text" name="nombre" id="nuevo_nombre" required 
                           class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Teléfono</label>
                    <input type="text" name="telefono" id="nuevo_telefono" 
                           class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:border-emerald-500 outline-none transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Correo</label>
                    <input type="email" name="correo" id="nuevo_correo" 
                           class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:border-emerald-500 outline-none transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Ciudad</label>
                    <input type="text" name="ciudad" id="nuevo_ciudad" 
                           class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:border-emerald-500 outline-none transition-all">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Dirección</label>
                    <input type="text" name="direccion" id="nuevo_direccion" 
                           class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:border-emerald-500 outline-none transition-all">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Notas</label>
                    <textarea name="notas" id="nuevo_notas" rows="3" 
                              class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:border-emerald-500 outline-none transition-all"></textarea>
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="submit" name="guardar" class="flex-1 bg-gradient-to-r from-emerald-600 to-green-600 hover:from-emerald-700 hover:to-green-700 text-white py-3 rounded-xl font-bold transition-all shadow-md">
                    <i class="fas fa-save mr-2"></i> Guardar Cliente
                </button>
                <button type="button" onclick="cerrarModalCliente()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 py-3 rounded-xl font-bold transition-all">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ====================================================================
// MODAL FUNCTIONS
// ====================================================================
const CLIENTES_CSRF = '<?= csrf_token() ?>';

function eliminarCliente(id) {
    if (!confirm('⚠️ ¿Eliminar este cliente? Esta acción no se puede deshacer.')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="_csrf" value="' + CLIENTES_CSRF + '">' +
        '<input type="hidden" name="eliminar" value="' + id + '">';
    document.body.appendChild(form);
    form.submit();
}

function abrirModalCliente() {
    const modal = document.getElementById('modal-cliente');
    if (modal) {
        // Limpiar formulario
        document.getElementById('nuevo_nombre').value = '';
        document.getElementById('nuevo_telefono').value = '';
        document.getElementById('nuevo_correo').value = '';
        document.getElementById('nuevo_direccion').value = '';
        document.getElementById('nuevo_ciudad').value = '';
        document.getElementById('nuevo_notas').value = '';
        modal.classList.add('active');
        // Enfocar el primer campo
        setTimeout(() => document.getElementById('nuevo_nombre').focus(), 100);
    }
}

function cerrarModalCliente() {
    const modal = document.getElementById('modal-cliente');
    if (modal) modal.classList.remove('active');
}

// Cerrar modal al hacer clic fuera
window.onclick = function(event) {
    const modal = document.getElementById('modal-cliente');
    if (event.target === modal) {
        cerrarModalCliente();
    }
}

// Validación antes de enviar el formulario
document.getElementById('formNuevoCliente')?.addEventListener('submit', function(e) {
    const nombre = document.getElementById('nuevo_nombre').value.trim();
    if (!nombre) {
        e.preventDefault();
        alert('❌ El nombre del cliente es requerido');
        return false;
    }
});

function mostrarToast(msg, error = false) {
    const t = document.createElement('div');
    t.className = 'toast' + (error ? ' error' : '');
    t.innerHTML = `<i class="fas ${error ? 'fa-exclamation-triangle' : 'fa-check-circle'} mr-2"></i> ${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>

<?php luxury_render_nav_end(); ?>
</body>
</html>
