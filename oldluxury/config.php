<?php
// config.php
date_default_timezone_set('America/Bogota');

// Credenciales: se pueden sobreescribir con variables de entorno (DB_HOST, DB_USER, DB_PASS, DB_NAME)
$host = getenv('DB_HOST') ?: "localhost";
$user = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$database = getenv('DB_NAME') ?: "luxury";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Configuración de empresa
$empresa = [
    'nombre' => 'Luxury Premium',
    'direccion' => 'Calle 123 #45-67, Bogotá',
    'telefono' => '+57 300 123 4567',
    'email' => 'info@luxurypremium.com',
    'web' => 'www.luxurypremium.com'
];

// Verificar si la función ya existe antes de declararla
if (!function_exists('tienePermiso')) {
    function tienePermiso($rol_requerido) {
        return isset($_SESSION['rol']) && $_SESSION['rol'] === $rol_requerido;
    }
}

if (!function_exists('esAdmin')) {
    function esAdmin() {
        return isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
    }
}

if (!function_exists('esEmpleado')) {
    function esEmpleado() {
        return isset($_SESSION['rol']) && $_SESSION['rol'] === 'empleado';
    }
}

if (!function_exists('usuarioActual')) {
    function usuarioActual() {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'nombre' => $_SESSION['user_nombre'] ?? null,
            'username' => $_SESSION['user_username'] ?? null,
            'email' => $_SESSION['user_email'] ?? null,
            'rol' => $_SESSION['rol'] ?? null
        ];
    }
}

if (!function_exists('tieneAcceso')) {
    function tieneAcceso($pagina) {
        // Páginas que solo pueden ver administradores
        $paginas_admin = ['proveedores', 'empleados', 'configuracion', 'reportes'];
        
        // Páginas que pueden ver todos (empleados y admin)
        $paginas_todos = ['dashboard', 'caja', 'ventas', 'clientes', 'pedidos',
                         'productos', 'inventario', 'stock', 'categorias',
                         'ingresos', 'movimientos', 'facturacion', 'devoluciones',
                         'gastos', 'abonos', 'perfil'];
        
        if (in_array($pagina, $paginas_todos)) {
            return true;
        }
        
        if (in_array($pagina, $paginas_admin) && esAdmin()) {
            return true;
        }
        
        return false;
    }
}

// ====================================================================
// FUNCIONES DE WHATSAPP API
// ====================================================================

// ====================================================================
// CSRF PROTECTION
// ====================================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate() {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = $_POST['_csrf'] ?? '';
            if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
                http_response_code(403);
                die('Token de seguridad inválido. Recargue la página e intente de nuevo.');
            }
        }
        return true;
    }
}

// Validar automáticamente todo POST cuando hay sesión activa
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_SESSION['user_id'])) {
    csrf_validate();
}

if (!function_exists('guardarFacturaPDF')) {
    function guardarFacturaPDF($venta_id, $tipo = 'factura') {
        // Crear directorio si no existe (uploads vive dentro del proyecto)
        $upload_dir = __DIR__ . '/uploads/' . ($tipo === 'factura' ? 'facturas' : 'recibos');
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            return ['success' => false, 'error' => 'No se pudo crear el directorio'];
        }

        $filename = ($tipo === 'factura' ? 'factura_' : 'recibo_') . str_pad($venta_id, 8, '0', STR_PAD_LEFT) . '.pdf';
        $file_path = $upload_dir . '/' . $filename;

        // Retornar la ruta del archivo (se guardará desde JavaScript)
        $base_url = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
        $file_url = $base_url . '/uploads/' . ($tipo === 'factura' ? 'facturas' : 'recibos') . '/' . rawurlencode($filename);

        return ['success' => true, 'file_path' => $file_path, 'file_url' => $file_url, 'filename' => $filename];
    }
}

?>