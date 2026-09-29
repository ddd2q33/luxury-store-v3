<?php
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('categorias');

// NOTA IMPORTANTE: Se asume que $conn es la conexión a la base de datos ya establecida.

// 🟢 Guardar nueva categoría
if (isset($_POST['guardar'])) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);

    if ($nombre != '') {
        $stmt = $conn->prepare("INSERT INTO categorias (nombre, descripcion) VALUES (?, ?)");
        $stmt->bind_param("ss", $nombre, $descripcion);
        $stmt->execute();
        $stmt->close();
        echo "<script>alert('✅ Categoría agregada correctamente'); window.location='./';</script>";
    }
}

// ✏️ Editar categoría
if (isset($_POST['editar'])) {
    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $stmt = $conn->prepare("UPDATE categorias SET nombre=?, descripcion=? WHERE id=?");
    $stmt->bind_param("ssi", $nombre, $descripcion, $id);
    $stmt->execute();
    $stmt->close();
    echo "<script>alert('✏️ Categoría actualizada correctamente'); window.location='./';</script>";
}

// 🔴 Eliminar categoría
if (isset($_POST['eliminar'])) {
    $id = intval($_POST['eliminar']);
    $stmt = $conn->prepare("DELETE FROM categorias WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo "<script>alert('🗑️ Categoría eliminada'); window.location='./';</script>";
}

// ====================================================================
// 📈 LÓGICA DE PAGINACIÓN Y CONSULTA DE CATEGORÍAS
// ====================================================================

// --- 1. Definir variables de paginación
$elementos_por_pagina = 5; // Límite de 10 por página
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$pagina_actual = max(1, $pagina_actual); // Asegura que la página sea al menos 1
$offset = ($pagina_actual - 1) * $elementos_por_pagina;

// --- 2. Contar el total de categorías
$total_query = mysqli_query($conn, "SELECT COUNT(*) AS total FROM categorias");
$total_elementos = mysqli_fetch_assoc($total_query)['total'];
$total_paginas = ceil($total_elementos / $elementos_por_pagina);

// --- 3. Consultar categorías para la tabla con LIMIT y OFFSET
$query_categorias = "
    SELECT * FROM categorias 
    ORDER BY id DESC
    LIMIT $elementos_por_pagina OFFSET $offset
";
$result = mysqli_query($conn, $query_categorias);

// --- 4. Función auxiliar para construir la URL de paginación
function buildUrl($pagina) {
    return "?pagina=$pagina";
}
?>
<script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/chartjs/chart.umd.min.js"></script>


<?php luxury_render_nav_start('categorias'); ?>
<div class="p-0">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-800 flex items-center">
            <i class="fas fa-tags text-purple-600 mr-2"></i> Categorías (Total: <?= number_format($total_elementos) ?>)
        </h2>
        <button onclick="toggleModal(true)" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg shadow flex items-center">
            <i class="fas fa-plus mr-2"></i> Nueva Categoría
        </button>
    </div>

    <div class="overflow-x-auto bg-white shadow-md rounded-lg">
        <table class="min-w-full text-sm text-left">
            <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
                <tr>
                    <th class="px-6 py-3">#</th>
                    <th class="px-6 py-3">Nombre</th>
                    <th class="px-6 py-3">Descripción</th>
                    <th class="px-6 py-3 text-center">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php
                if (mysqli_num_rows($result) > 0) {
                    // El contador debe considerar el offset para empezar en el número correcto
                    $i = $offset + 1;
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "
                        <tr class='hover:bg-gray-50'>
                            <form method='POST'>
                                <input type='hidden' name='id' value='{$row['id']}'>
                                <?= csrf_field() ?>
                                <td class='px-6 py-3'>{$i}</td>
                                <td class='px-6 py-3'>
                                    <input type='text' name='nombre' value='" . htmlspecialchars($row['nombre']) . "' class='w-full border rounded p-1'>
                                </td>
                                <td class='px-6 py-3'>
                                    <input type='text' name='descripcion' value='" . htmlspecialchars($row['descripcion']) . "' class='w-full border rounded p-1'>
                                </td>
                                <td class='px-6 py-3 text-center flex items-center justify-center gap-2'>
                                    <button type='submit' name='editar' class='bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded' title='Guardar edición'>
                                        <i class='fas fa-save'></i>
                                    </button>
<button onclick='eliminarCategoria({$row['id']})' class='bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded' title='Eliminar'>
                                        <i class='fas fa-trash'></i>
                                    </button>
                                </td>
                            </form>
                        </tr>";
                        $i++;
                    }
                } else {
                    echo "<tr><td colspan='4' class='text-center py-4 text-gray-500'>No hay categorías registradas en esta página.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_paginas > 1): ?>
        <div class="mt-6 flex justify-center items-center space-x-2">
            
            <?php if ($pagina_actual > 1): ?>
                <a href="<?= buildUrl($pagina_actual - 1) ?>" class="px-3 py-1 border border-gray-300 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm text-gray-700">&laquo; Anterior</a>
            <?php endif; ?>

            <?php 
            $rango = 2; // Mostrar 2 páginas antes y 2 después de la actual
            $inicio = max(1, $pagina_actual - $rango);
            $fin = min($total_paginas, $pagina_actual + $rango);

            if ($inicio > 1) {
                echo '<a href="' . buildUrl(1) . '" class="px-3 py-1 border border-gray-300 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm text-gray-700">1</a>';
                if ($inicio > 2) {
                    echo '<span class="px-3 py-1 text-gray-500">...</span>';
                }
            }

            for ($i = $inicio; $i <= $fin; $i++):
                $clase = ($i === $pagina_actual) ? 'bg-purple-600 text-white font-bold shadow-md' : 'bg-gray-100 text-gray-700 border border-gray-300 hover:bg-gray-200';
            ?>
                <a href="<?= buildUrl($i) ?>" class="px-3 py-1 rounded-lg text-sm <?= $clase ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($fin < $total_paginas): ?>
                <?php if ($fin < $total_paginas - 1): ?>
                    <span class="px-3 py-1 text-gray-500">...</span>
                <?php endif; ?>
                <a href="<?= buildUrl($total_paginas) ?>" class="px-3 py-1 border border-gray-300 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm text-gray-700"><?= $total_paginas ?></a>
            <?php endif; ?>

            <?php if ($pagina_actual < $total_paginas): ?>
                <a href="<?= buildUrl($pagina_actual + 1) ?>" class="px-3 py-1 border border-gray-300 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm text-gray-700">Siguiente &raquo;</a>
            <?php endif; ?>

        </div>
    <?php endif; ?>
    </div>

<div id="modalCategoria" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
    <div class="bg-white w-full max-w-md rounded-lg shadow-lg p-6">
        <h3 class="text-xl font-semibold mb-4 flex items-center">
            <i class="fas fa-tag mr-2 text-purple-600"></i> Nueva Categoría
        </h3>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700">Nombre de la categoría</label>
                <input name="nombre" type="text" class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500" placeholder="Ej. Calzado, Ropa, Accesorios" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Descripción</label>
                <textarea name="descripcion" class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500" rows="2" placeholder="Breve descripción de la categoría"></textarea>
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="toggleModal(false)" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">
                    <i class="fas fa-times mr-1"></i> Cancelar
                </button>
                <button type="submit" name="guardar" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
                    <i class="fas fa-save mr-1"></i> Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const modalCategoria = document.getElementById("modalCategoria");
    const toggleModal = (show) => {
        modalCategoria.classList.toggle("hidden", !show);
    };

    function eliminarCategoria(id) {
        if (!confirm('¿Eliminar esta categoría?')) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' +
            '<input type="hidden" name="eliminar" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
</script>
<?php luxury_render_nav_end(); ?>
