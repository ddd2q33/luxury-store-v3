<?php
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('stock');

$minimo = 5;

if (isset($_POST['accion_stock']) && isset($_POST['producto_id'])) {
    $id = intval($_POST['producto_id']);
    $accion = $_POST['accion_stock'];

    if ($accion !== 'sumar' && $accion !== 'restar') {
        die("Acción no válida");
    }

    $stmt_sel = $conn->prepare("SELECT stock FROM productos WHERE id=?");
    $stmt_sel->bind_param("i", $id);
    $stmt_sel->execute();
    $res = $stmt_sel->get_result();
    $stmt_sel->close();
    if ($row = $res->fetch_assoc()) {
        $stock_actual = intval($row['stock']);
        $nuevo_stock = $stock_actual;

        if ($accion === 'sumar') {
            $nuevo_stock++;
        } elseif ($accion === 'restar' && $stock_actual > 0) {
            $nuevo_stock--;
        }

        $stmt_upd = $conn->prepare("UPDATE productos SET stock=? WHERE id=?");
        $stmt_upd->bind_param("ii", $nuevo_stock, $id);
        $stmt_upd->execute();
        $stmt_upd->close();

        $stmt_ins = $conn->prepare("INSERT INTO movimientos_stock (producto_id, tipo, cantidad, fecha) VALUES (?, ?, 1, NOW())");
        $stmt_ins->bind_param("is", $id, $accion);
        $stmt_ins->execute();
        $stmt_ins->close();

        $params = [
            'buscar' => $_POST['buscar'] ?? '',
            'categoria' => $_POST['categoria'] ?? '',
            'estado' => $_POST['estado'] ?? '',
            'historial' => $_POST['historial'] ?? '',
            'p' => $_POST['p'] ?? 1,
        ];

        $params = array_filter($params, function ($value) {
            return $value !== '';
        });

        $query_string = http_build_query($params);
        echo "<script>window.location='?$query_string';</script>";
        exit;
    }
}

$categorias = [];
$catRes = mysqli_query($conn, "SELECT id, nombre FROM categorias ORDER BY nombre ASC");
while ($c = mysqli_fetch_assoc($catRes)) {
    $categorias[$c['id']] = $c['nombre'];
}

$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$categoria = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$ver_historial = isset($_GET['historial']) ? intval($_GET['historial']) : 0;

$condiciones = [];

if ($busqueda !== '') {
    $busqueda_segura = mysqli_real_escape_string($conn, $busqueda);
    $condiciones[] = "nombre LIKE '%$busqueda_segura%'";
}
if ($categoria > 0) {
    $condiciones[] = "categoria_id = $categoria";
}
if ($estado !== '') {
    if ($estado === 'Bajo') {
        $condiciones[] = "stock > 0 AND stock <= $minimo";
    } elseif ($estado === 'Normal') {
        $condiciones[] = "stock > $minimo";
    } elseif ($estado === 'Sin stock') {
        $condiciones[] = "stock = 0";
    }
}

$where = count($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';

require_once dirname(__DIR__) . '/views/partials/app_nav.php';

$items_per_page = 5;
$current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;

$count_query = "SELECT COUNT(*) as total_records FROM productos $where";
$count_res = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_res)['total_records'];
$total_pages = $total_records > 0 ? (int)ceil($total_records / $items_per_page) : 1;
$offset = ($current_page - 1) * $items_per_page;

if ($total_records > 0 && $current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $items_per_page;
}

if ($total_records === 0) {
    $offset = 0;
}

$url_params = http_build_query(array_filter([
    'buscar' => $busqueda,
    'categoria' => $categoria > 0 ? $categoria : null,
    'estado' => $estado,
]));
$base_url = '?' . $url_params . '&p=';

$query = "SELECT id, nombre, categoria_id, stock, precio FROM productos $where ORDER BY nombre ASC LIMIT $items_per_page OFFSET $offset";
$result = mysqli_query($conn, $query);

$historial = [];
if ($ver_historial > 0) {
    $histRes = mysqli_query($conn, "SELECT tipo, cantidad, fecha FROM movimientos_stock WHERE producto_id=$ver_historial ORDER BY fecha DESC LIMIT 20");
    while ($m = mysqli_fetch_assoc($histRes)) {
        $historial[] = $m;
    }
}

$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) as sin_stock,
        SUM(CASE WHEN stock > 0 AND stock <= $minimo THEN 1 ELSE 0 END) as bajo_stock,
        SUM(CASE WHEN stock > $minimo THEN 1 ELSE 0 END) as normal
    FROM productos
"));
?>

<script src="../assets/vendor/tailwind/tailwind.min.js"></script>
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">

<?php luxury_render_nav_start('stock'); ?>

<style>
    .stock-page {
        background: #f8fafc;
        border-radius: 24px;
        padding: 24px;
    }

    .stock-panel {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    }

    .stock-loading {
        opacity: 0.65;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }

    .stock-input {
        width: 100%;
        border: 1px solid #d1d5db;
        border-radius: 14px;
        padding: 12px 14px;
        background: #ffffff;
        color: #111827;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .stock-input:focus {
        outline: none;
        border-color: #60a5fa;
        box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.16);
    }

    .stock-stat {
        border-radius: 18px;
        padding: 18px;
    }

    .stock-action-group {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        flex-wrap: nowrap;
    }

    .stock-action-group form,
    .stock-action-group a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin: 0;
        vertical-align: middle;
    }

    .stock-action-btn {
        min-width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        border: 1px solid transparent;
        color: #fff;
        line-height: 1;
        text-decoration: none;
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.10);
        transition: transform 0.15s ease, opacity 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }

    .stock-action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(15, 23, 42, 0.14);
    }

    .stock-action-btn:disabled {
        opacity: 0.45;
        cursor: not-allowed;
        box-shadow: none;
        transform: none;
    }

    .stock-action-btn--plus {
        background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
        border-color: #86efac;
    }

    .stock-action-btn--minus {
        background: linear-gradient(180deg, #f87171 0%, #ef4444 100%);
        border-color: #fecaca;
    }

    .stock-action-btn--history {
        background: linear-gradient(180deg, #60a5fa 0%, #2563eb 100%);
        border-color: #bfdbfe;
    }

    .stock-submit-btn {
        min-height: 48px;
    }

    .stock-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 700;
    }

    .stock-pagination-link {
        min-width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        border: 1px solid #d1d5db;
        background: #fff;
        color: #374151;
        font-weight: 700;
        padding: 0 14px;
        box-shadow: 0 4px 10px rgba(15, 23, 42, 0.04);
        transition: all 0.15s ease;
    }

    .stock-pagination-link:hover {
        background: #f9fafb;
        border-color: #93c5fd;
        color: #1d4ed8;
    }

    .stock-pagination-link.is-active {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
        box-shadow: 0 8px 18px rgba(37, 99, 235, 0.22);
    }

    .stock-pagination-link.is-disabled {
        background: #f3f4f6;
        border-color: #e5e7eb;
        color: #9ca3af;
        box-shadow: none;
        pointer-events: none;
    }
</style>

<div id="stockApp" class="stock-page space-y-6">
    <div class="stock-panel p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-blue-700">
                    <i class="fas fa-boxes-stacked"></i>
                    Inventario
                </div>
                <h1 class="mt-3 text-3xl font-black text-gray-900">Control de stock</h1>
                <p class="mt-2 text-sm text-gray-600">Vista simple para buscar productos, ajustar existencias y revisar movimientos.</p>
            </div>
            <?php if ($busqueda || $categoria || $estado): ?>
                <a href="./" class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    <i class="fas fa-rotate-left"></i>
                    Limpiar filtros
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="stock-stat border border-blue-100 bg-blue-50">
            <p class="text-sm font-semibold text-blue-700">Total productos</p>
            <p class="mt-2 text-3xl font-black text-gray-900"><?= $stats['total'] ?></p>
        </div>
        <div class="stock-stat border border-red-100 bg-red-50">
            <p class="text-sm font-semibold text-red-700">Sin stock</p>
            <p class="mt-2 text-3xl font-black text-gray-900"><?= $stats['sin_stock'] ?></p>
        </div>
        <div class="stock-stat border border-yellow-100 bg-yellow-50">
            <p class="text-sm font-semibold text-yellow-700">Stock bajo</p>
            <p class="mt-2 text-3xl font-black text-gray-900"><?= $stats['bajo_stock'] ?></p>
        </div>
        <div class="stock-stat border border-green-100 bg-green-50">
            <p class="text-sm font-semibold text-green-700">Stock normal</p>
            <p class="mt-2 text-3xl font-black text-gray-900"><?= $stats['normal'] ?></p>
        </div>
    </div>

    <div class="stock-panel p-6">
        <form id="stockFiltersForm" method="GET" class="grid grid-cols-1 gap-4 lg:grid-cols-4 lg:items-end">
            <input type="hidden" name="p" value="1">

            <div>
                <label class="mb-2 block text-sm font-semibold text-gray-700">Buscar producto</label>
                <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Nombre del producto..." class="stock-input">
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-gray-700">Categoria</label>
                <select name="categoria" class="stock-input">
                    <option value="">Todas las categorias</option>
                    <?php foreach ($categorias as $id => $nombre): ?>
                        <option value="<?= $id ?>" <?= $categoria == $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-gray-700">Estado</label>
                <select name="estado" class="stock-input">
                    <option value="">Todos los estados</option>
                    <option value="Sin stock" <?= $estado === 'Sin stock' ? 'selected' : '' ?>>Sin stock</option>
                    <option value="Bajo" <?= $estado === 'Bajo' ? 'selected' : '' ?>>Stock bajo (<=<?= $minimo ?>)</option>
                    <option value="Normal" <?= $estado === 'Normal' ? 'selected' : '' ?>>Stock normal</option>
                </select>
            </div>

            <div class="flex items-end">
                <button id="stockSubmitButton" type="submit" class="stock-submit-btn inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white hover:bg-blue-700">
                    <i class="fas fa-search"></i>
                    Buscar
                </button>
            </div>
        </form>
    </div>

    <div class="stock-panel overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-gray-200 px-6 py-5 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-lg font-bold text-gray-900">Listado de productos</h2>
                <p class="text-sm text-gray-500">
                    <?php if ($total_records > 0): ?>
                        Mostrando <?= $offset + 1 ?> a <?= min($offset + $items_per_page, $total_records) ?> de <?= $total_records ?> resultados
                    <?php else: ?>
                        No hay resultados para los filtros actuales
                    <?php endif; ?>
                </p>
            </div>
            <div class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-gray-600">
                Pagina <?= $current_page ?> de <?= $total_pages ?>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-600">
                        <th class="px-5 py-4 font-bold">#</th>
                        <th class="px-5 py-4 font-bold">Producto</th>
                        <th class="px-5 py-4 font-bold">Categoria</th>
                        <th class="px-5 py-4 text-right font-bold">Precio</th>
                        <th class="px-5 py-4 text-center font-bold">Stock</th>
                        <th class="px-5 py-4 text-center font-bold">Estado</th>
                        <th class="px-5 py-4 text-center font-bold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <?php $contador = $offset + 1; ?>
                        <?php while ($p = mysqli_fetch_assoc($result)): ?>
                            <?php
                            $estado_texto = $p['stock'] == 0 ? 'Sin stock' : ($p['stock'] <= $minimo ? 'Stock bajo' : 'Disponible');
                            $color = $p['stock'] == 0 ? 'bg-red-100 text-red-700' : ($p['stock'] <= $minimo ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700');
                            $icon = $p['stock'] == 0 ? 'fa-circle-xmark' : ($p['stock'] <= $minimo ? 'fa-triangle-exclamation' : 'fa-circle-check');
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-4 font-semibold text-gray-500"><?= $contador++ ?></td>
                                <td class="px-5 py-4 font-semibold text-gray-900"><?= htmlspecialchars($p['nombre']) ?></td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                                        <?= htmlspecialchars($categorias[$p['categoria_id']] ?? 'Sin categoria') ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right font-bold text-gray-900">$<?= number_format($p['precio'], 0, ',', '.') ?></td>
                                <td class="px-5 py-4 text-center">
                                    <span class="inline-flex min-w-[56px] items-center justify-center rounded-xl bg-gray-100 px-3 py-2 text-base font-black text-gray-900">
                                        <?= $p['stock'] ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <span class="stock-badge <?= $color ?>">
                                        <i class="fas <?= $icon ?>"></i>
                                        <?= $estado_texto ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <div class="stock-action-group">
                                        <form method="POST" class="inline-flex">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="producto_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="accion_stock" value="sumar">
                                            <input type="hidden" name="buscar" value="<?= htmlspecialchars($busqueda) ?>">
                                            <input type="hidden" name="categoria" value="<?= $categoria ?>">
                                            <input type="hidden" name="estado" value="<?= htmlspecialchars($estado) ?>">
                                            <input type="hidden" name="historial" value="<?= $ver_historial ?>">
                                            <input type="hidden" name="p" value="<?= $current_page ?>">
                                            <button type="submit" class="stock-action-btn stock-action-btn--plus" title="Aumentar stock" aria-label="Aumentar stock">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </form>

                                        <form method="POST" class="inline-flex">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="producto_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="accion_stock" value="restar">
                                            <input type="hidden" name="buscar" value="<?= htmlspecialchars($busqueda) ?>">
                                            <input type="hidden" name="categoria" value="<?= $categoria ?>">
                                            <input type="hidden" name="estado" value="<?= htmlspecialchars($estado) ?>">
                                            <input type="hidden" name="historial" value="<?= $ver_historial ?>">
                                            <input type="hidden" name="p" value="<?= $current_page ?>">
                                            <button type="submit" class="stock-action-btn stock-action-btn--minus" title="Reducir stock" aria-label="Reducir stock" <?= $p['stock'] == 0 ? 'disabled' : '' ?>>
                                                <i class="fas fa-minus"></i>
                                            </button>
                                        </form>

                                        <?php
                                        $historial_url_params = http_build_query(array_filter([
                                            'historial' => $p['id'],
                                            'buscar' => $busqueda,
                                            'categoria' => $categoria > 0 ? $categoria : null,
                                            'estado' => $estado,
                                            'p' => $current_page,
                                        ]));
                                        ?>
                                        <a href="?<?= $historial_url_params ?>" class="stock-action-btn stock-action-btn--history" title="Ver historial" aria-label="Ver historial">
                                            <i class="fas fa-history"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            <?php if ($ver_historial === intval($p['id'])): ?>
                                <tr class="bg-blue-50/50">
                                    <td colspan="7" class="px-5 py-5">
                                        <div class="rounded-2xl border border-blue-100 bg-white p-5">
                                            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                                <div>
                                                    <h3 class="text-base font-bold text-gray-900">Historial de movimientos - <?= htmlspecialchars($p['nombre']) ?></h3>
                                                    <p class="text-sm text-gray-500">Ultimos 20 movimientos del producto.</p>
                                                </div>
                                                <?php
                                                $close_historial_params = http_build_query(array_filter([
                                                    'buscar' => $busqueda,
                                                    'categoria' => $categoria > 0 ? $categoria : null,
                                                    'estado' => $estado,
                                                    'p' => $current_page,
                                                ]));
                                                ?>
                                                <a href="?<?= $close_historial_params ?>" class="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                                    <i class="fas fa-times"></i>
                                                    Cerrar
                                                </a>
                                            </div>

                                            <?php if (count($historial) > 0): ?>
                                                <div class="space-y-3">
                                                    <?php foreach ($historial as $h): ?>
                                                        <?php $entrada = $h['tipo'] === 'sumar'; ?>
                                                        <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 md:flex-row md:items-center md:justify-between">
                                                            <div class="flex items-center gap-3">
                                                                <div class="flex h-10 w-10 items-center justify-center rounded-xl <?= $entrada ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                                                    <i class="fas <?= $entrada ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i>
                                                                </div>
                                                                <div>
                                                                    <p class="font-semibold text-gray-900"><?= $entrada ? 'Entrada' : 'Salida' ?> de <?= $h['cantidad'] ?> unidad(es)</p>
                                                                    <p class="text-sm text-gray-500">Movimiento registrado en stock</p>
                                                                </div>
                                                            </div>
                                                            <div class="text-sm font-medium text-gray-600">
                                                                <i class="far fa-calendar-alt mr-1"></i>
                                                                <?= date('d/m/Y H:i', strtotime($h['fecha'])) ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-gray-500">
                                                    No hay movimientos registrados para este producto
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-5 py-14 text-center">
                                <div class="mx-auto max-w-md">
                                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                                        <i class="fas fa-search text-xl"></i>
                                    </div>
                                    <h3 class="text-lg font-bold text-gray-900">No se encontraron productos</h3>
                                    <p class="mt-2 text-sm text-gray-500">Prueba con otros filtros o limpia la busqueda.</p>
                                    <?php if ($busqueda || $categoria || $estado): ?>
                                        <a href="./" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-700">
                                            <i class="fas fa-rotate-left"></i>
                                            Ver todos
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="flex flex-col gap-4 border-t border-gray-200 bg-gray-50 px-5 py-4 md:flex-row md:items-center md:justify-between">
                <p class="text-sm text-gray-600">
                    Mostrando <span class="font-semibold text-gray-900"><?= $offset + 1 ?></span> a
                    <span class="font-semibold text-gray-900"><?= min($offset + $items_per_page, $total_records) ?></span>
                    de <span class="font-semibold text-gray-900"><?= $total_records ?></span> resultados
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <a href="<?= $current_page > 1 ? $base_url . ($current_page - 1) : '#' ?>" class="stock-pagination-link <?= $current_page > 1 ? '' : 'is-disabled' ?>" aria-label="Pagina anterior">
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);

                    if ($start_page > 1) {
                        echo '<a href="' . $base_url . '1" class="stock-pagination-link">1</a>';
                        if ($start_page > 2) {
                            echo '<span class="stock-pagination-link" aria-hidden="true">...</span>';
                        }
                    }

                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="<?= $base_url . $i ?>" class="stock-pagination-link <?= $i === $current_page ? 'is-active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<span class="stock-pagination-link" aria-hidden="true">...</span>';
                        }
                        echo '<a href="' . $base_url . $total_pages . '" class="stock-pagination-link">' . $total_pages . '</a>';
                    }
                    ?>

                    <a href="<?= $current_page < $total_pages ? $base_url . ($current_page + 1) : '#' ?>" class="stock-pagination-link <?= $current_page < $total_pages ? '' : 'is-disabled' ?>" aria-label="Pagina siguiente">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    (function () {
        function initStockUI() {
            const app = document.getElementById('stockApp');
            const form = document.getElementById('stockFiltersForm');
            const submitButton = document.getElementById('stockSubmitButton');
            if (!app || !form) return;

            let typingTimer = null;
            let activeController = null;

            const searchInput = form.querySelector('input[name="buscar"]');
            const categoryInput = form.querySelector('select[name="categoria"]');
            const stateInput = form.querySelector('select[name="estado"]');

            function setLoading(isLoading) {
                app.classList.toggle('stock-loading', isLoading);
                if (submitButton) {
                    submitButton.disabled = isLoading;
                    submitButton.innerHTML = isLoading
                        ? '<i class="fas fa-spinner fa-spin"></i><span>Buscando...</span>'
                        : '<i class="fas fa-search"></i><span>Buscar</span>';
                }
            }

            async function loadStock(url, pushState = true) {
                if (activeController) {
                    activeController.abort();
                }

                activeController = new AbortController();
                setLoading(true);

                try {
                    const response = await fetch(url, {
                        signal: activeController.signal,
                        headers: {
                            'X-Requested-With': 'fetch'
                        }
                    });

                    const html = await response.text();
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const nextApp = doc.getElementById('stockApp');

                    if (!nextApp) {
                        window.location.href = url;
                        return;
                    }

                    app.replaceWith(nextApp);
                    if (pushState) {
                        window.history.pushState({}, '', url);
                    }
                    initStockUI();
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        window.location.href = url;
                    }
                } finally {
                    setLoading(false);
                }
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                const params = new URLSearchParams(new FormData(form));
                loadStock(window.location.pathname + '?' + params.toString());
            });

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    clearTimeout(typingTimer);
                    typingTimer = setTimeout(function () {
                        const params = new URLSearchParams(new FormData(form));
                        loadStock(window.location.pathname + '?' + params.toString());
                    }, 250);
                });
            }

            [categoryInput, stateInput].forEach(function (input) {
                if (!input) return;
                input.addEventListener('change', function () {
                    const params = new URLSearchParams(new FormData(form));
                    loadStock(window.location.pathname + '?' + params.toString());
                });
            });

            app.querySelectorAll('a[href^="?"]').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    loadStock(link.href);
                });
            });
        }

        window.addEventListener('popstate', function () {
            window.location.reload();
        });

        initStockUI();
    })();
</script>

<?php luxury_render_nav_end(); ?>
