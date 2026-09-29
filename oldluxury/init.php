<?php
/* init.php - Bootstrap compartido (Parte 1: sesion, zona horaria, BD) */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Bogota');

/* CONEXION A BD: config.php declara $conn y las funciones de sesion */
require_once __DIR__ . '/config.php';

if (!isset($conn) || !$conn) {
    die('Error de conexion a la base de datos');
}

/* Parte 2: guardas de autenticacion y roles */
if (!function_exists('require_login')) {
    function require_login() {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ../index.php');
            exit;
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin() {
        require_login();
        if (($_SESSION['rol'] ?? '') !== 'admin') {
            header('Location: ../index.php');
            exit;
        }
    }
}

if (!function_exists('enforce_access')) {
    function enforce_access($pagina) {
        require_login();
        if (!tieneAcceso($pagina) && !esAdmin()) {
            header('Location: ../dashboard/');
            exit;
        }
    }
}

/* Parte 3: helpers compartidos */
if (!function_exists('format_currency')) {
    function format_currency($number) {
        return '$' . number_format((float)$number, 0, ',', '.');
    }
}

if (!function_exists('format_number')) {
    function format_number($number) {
        return number_format((float)$number, 0, ',', '.');
    }
}

if (!function_exists('format_currency_decimals')) {
    function format_currency_decimals($number) {
        return '$' . number_format((float)$number, 2, ',', '.');
    }
}

if (!function_exists('porcentaje_cambio')) {
    function porcentaje_cambio($actual, $anterior) {
        if ($anterior == 0) return $actual > 0 ? 100 : 0;
        return round((($actual - $anterior) / $anterior) * 100, 1);
    }
}

if (!function_exists('getAvatarUrl')) {
    /* Resuelve la URL del avatar del usuario (archivo local o generado). */
    function getAvatarUrl($imagen, $nombre) {
        if (!empty($imagen)) {
            $rutas = [
                ['file' => dirname(__DIR__) . '/uploads/avatars/' . $imagen, 'url' => '../uploads/avatars/' . rawurlencode($imagen)],
                ['file' => __DIR__ . '/uploads/avatars/' . $imagen,          'url' => '../uploads/avatars/' . rawurlencode($imagen)],
            ];
            foreach ($rutas as $ruta) {
                if (is_file($ruta['file'])) {
                    return $ruta['url'];
                }
            }
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($nombre ?: 'Usuario') . '&background=6366f1&color=fff&size=128&bold=true';
    }
}

if (!function_exists('current_user')) {
    /* Datos del usuario en sesion. */
    function current_user() {
        return [
            'id'       => $_SESSION['user_id'] ?? null,
            'nombre'   => $_SESSION['user_nombre'] ?? 'Usuario',
            'username' => $_SESSION['user_username'] ?? null,
            'email'    => $_SESSION['user_email'] ?? null,
            'rol'      => $_SESSION['rol'] ?? 'empleado',
        ];
    }
}

/* ====================================================================
 * VARIABLES PARA LAS PAGINAS (compatibilidad con el codigo existente)
 * ==================================================================== */

require_login();

$user_id     = $_SESSION['user_id'];
$user_nombre = $_SESSION['user_nombre'] ?? 'Usuario';
$user_rol    = $_SESSION['rol'] ?? 'empleado';
$user_email  = $_SESSION['user_email'] ?? '';
$is_admin    = ($user_rol === 'admin');
$user_avatar = $_SESSION['user_avatar'] ?? ($_SESSION['imagen_perfil'] ?? null);

if (empty($user_avatar) && $conn) {
    $stmt_avatar = $conn->prepare('SELECT imagen_perfil FROM usuarios WHERE id = ?');
    if ($stmt_avatar) {
        $stmt_avatar->bind_param('i', $user_id);
        $stmt_avatar->execute();
        if ($row_avatar = $stmt_avatar->get_result()->fetch_assoc()) {
            $user_avatar = $row_avatar['imagen_perfil'] ?? null;
        }
        $stmt_avatar->close();
        $_SESSION['user_avatar'] = $user_avatar;
        $_SESSION['imagen_perfil'] = $user_avatar;
    }
}
