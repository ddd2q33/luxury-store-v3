<?php
// perfil/index.php - Perfil de Usuario (Sin Sidebar)
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

enforce_access('perfil');

$user_username = $_SESSION['user_username'] ?? '';
$user_telefono = $_SESSION['telefono'] ?? '';
$user_fecha_registro = date('Y-m-d');

// ===== VERIFICAR COLUMNAS DE LA TABLA USUARIOS =====
$columnas = $conn->query("SHOW COLUMNS FROM usuarios");
$columnas_existentes = [];
if ($columnas) {
    while ($col = $columnas->fetch_assoc()) {
        $columnas_existentes[] = $col['Field'];
    }
}

// Construir consulta solo con columnas que existen
$select_fields = ['id', 'nombre', 'username', 'email', 'rol', 'imagen_perfil'];
if (in_array('fecha_registro', $columnas_existentes, true)) {
    $select_fields[] = 'fecha_registro';
}
if (in_array('telefono', $columnas_existentes, true)) {
    $select_fields[] = 'telefono';
}

$select_sql = "SELECT " . implode(", ", $select_fields) . " FROM usuarios WHERE id = ?";
$stmt_user = $conn->prepare($select_sql);

if ($stmt_user) {
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user_data = $stmt_user->get_result()->fetch_assoc();
    $stmt_user->close();
    
    if ($user_data) {
        $user_nombre = $user_data['nombre'];
        $user_username = $user_data['username'];
        $user_email = $user_data['email'];
        $user_rol = $user_data['rol'];
        $user_avatar = $user_data['imagen_perfil'] ?? $user_avatar;
        $user_fecha_registro = $user_data['fecha_registro'] ?? date('Y-m-d');
        if (in_array('telefono', $columnas_existentes) && isset($user_data['telefono'])) {
            $user_telefono = $user_data['telefono'];
        }
        
        // Actualizar sesión
        $_SESSION['user_nombre'] = $user_nombre;
        $_SESSION['user_username'] = $user_username;
        $_SESSION['user_email'] = $user_email;
        $_SESSION['rol'] = $user_rol;
        $_SESSION['user_avatar'] = $user_avatar;
        $_SESSION['imagen_perfil'] = $user_avatar;
        if (in_array('telefono', $columnas_existentes)) {
            $_SESSION['telefono'] = $user_telefono;
        }
    }
}

$is_admin = ($user_rol === 'admin');

// getAvatarUrl() ahora proviene de init.php

// Procesar actualización de perfil
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'actualizar_perfil') {
        $nuevo_nombre = trim($_POST['nombre']);
        $nuevo_username = trim($_POST['username']);
        $nuevo_email = trim($_POST['email']);
        $nuevo_telefono = trim($_POST['telefono'] ?? '');
        
        if (empty($nuevo_nombre)) {
            $error = "El nombre es requerido";
        } else {
            // Verificar qué columnas actualizar
            $update_fields = ["nombre = ?", "username = ?", "email = ?"];
            $update_params = [$nuevo_nombre, $nuevo_username, $nuevo_email];
            $update_types = "sss";
            
            if (in_array('telefono', $columnas_existentes)) {
                $update_fields[] = "telefono = ?";
                $update_params[] = $nuevo_telefono;
                $update_types .= "s";
            }
            $update_params[] = $user_id;
            $update_types .= "i";
            
            $update_sql = "UPDATE usuarios SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $stmt_update = $conn->prepare($update_sql);
            
            if ($stmt_update) {
                $stmt_update->bind_param($update_types, ...$update_params);
                if ($stmt_update->execute()) {
                    $_SESSION['user_nombre'] = $nuevo_nombre;
                    $_SESSION['user_username'] = $nuevo_username;
                    $_SESSION['user_email'] = $nuevo_email;
                    if (in_array('telefono', $columnas_existentes)) {
                        $_SESSION['telefono'] = $nuevo_telefono;
                    }
                    $user_nombre = $nuevo_nombre;
                    $user_username = $nuevo_username;
                    $user_email = $nuevo_email;
                    $user_telefono = $nuevo_telefono;
                    $mensaje = "Perfil actualizado correctamente";
                } else {
                    $error = "Error al actualizar: " . $stmt_update->error;
                }
                $stmt_update->close();
            } else {
                $error = "Error en la preparación de la consulta";
            }
        }
    }
    
    // Cambiar contraseña
    if (isset($_POST['action']) && $_POST['action'] === 'cambiar_password') {
        $password_actual = $_POST['password_actual'];
        $password_nueva = $_POST['password_nueva'];
        $password_confirmar = $_POST['password_confirmar'];
        
        if (empty($password_actual) || empty($password_nueva) || empty($password_confirmar)) {
            $error = "Todos los campos son requeridos";
        } elseif ($password_nueva !== $password_confirmar) {
            $error = "Las contraseñas nuevas no coinciden";
        } elseif (strlen($password_nueva) < 6) {
            $error = "La contraseña debe tener al menos 6 caracteres";
        } else {
            $stmt_check = $conn->prepare("SELECT password FROM usuarios WHERE id = ?");
            if ($stmt_check) {
                $stmt_check->bind_param("i", $user_id);
                $stmt_check->execute();
                $user_pass = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();
                
                if (password_verify($password_actual, $user_pass['password'])) {
                    $new_hash = password_hash($password_nueva, PASSWORD_DEFAULT);
                    $stmt_update = $conn->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
                    if ($stmt_update) {
                        $stmt_update->bind_param("si", $new_hash, $user_id);
                        if ($stmt_update->execute()) {
                            $mensaje = "Contraseña actualizada correctamente";
                        } else {
                            $error = "Error al actualizar contraseña";
                        }
                        $stmt_update->close();
                    } else {
                        $error = "Error en la preparación de la consulta";
                    }
                } else {
                    $error = "Contraseña actual incorrecta";
                }
            } else {
                $error = "Error al verificar contraseña";
            }
        }
    }
    
    // Subir avatar
    if (isset($_POST['action']) && $_POST['action'] === 'subir_avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            // Validar el MIME real del contenido, no el reportado por el navegador
            $allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $real_mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['avatar']['tmp_name']);
            $map_mime_ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            
            if (!$real_mime || !isset($map_mime_ext[$real_mime])) {
                $error = "Formato no permitido. Use: JPG, PNG, GIF, WEBP";
            } else {
                $ext = $map_mime_ext[$real_mime];
                $filename = $_FILES['avatar']['name'];

                $upload_dir = dirname(__DIR__) . "/uploads/avatars/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = "user_" . $user_id . "_" . time() . "." . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_path)) {
                    // Verificar si existe la columna imagen_perfil
                    if (in_array('imagen_perfil', $columnas_existentes)) {
                        $stmt_update = $conn->prepare("UPDATE usuarios SET imagen_perfil = ? WHERE id = ?");
                        if ($stmt_update) {
                            $stmt_update->bind_param("si", $new_filename, $user_id);
                            if ($stmt_update->execute()) {
                                $user_avatar = $new_filename;
                                $_SESSION['user_avatar'] = $new_filename;
                                $_SESSION['imagen_perfil'] = $new_filename;
                                $mensaje = "Avatar actualizado correctamente";
                            } else {
                                $error = "Error al guardar en BD";
                            }
                            $stmt_update->close();
                        } else {
                            $error = "Error en la preparación de la consulta";
                        }
                    } else {
                        $mensaje = "Avatar subido, pero la tabla no tiene campo para guardarlo";
                    }
                } else {
                    $error = "Error al subir el archivo";
                }
            }
        } else {
            $error = "Seleccione un archivo válido";
        }
    }
}

$avatar_url = getAvatarUrl($user_avatar, $user_nombre);

// Obtener estadísticas del usuario
$total_ventas = 0;
$total_gastado = 0;

// Verificar si existe la columna vendedor_id en ventas
$check_vendedor = $conn->query("SHOW COLUMNS FROM ventas LIKE 'vendedor_id'");
$has_vendedor_id = $check_vendedor && $check_vendedor->num_rows > 0;

if ($is_admin) {
    $stmt_stats = $conn->query("SELECT COUNT(*) as total, COALESCE(SUM(total),0) as suma FROM ventas");
    if ($stmt_stats) {
        $stats = $stmt_stats->fetch_assoc();
        $total_ventas = $stats['total'];
        $total_gastado = $stats['suma'];
    }
} elseif ($has_vendedor_id) {
    $stmt_stats = $conn->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total),0) as suma FROM ventas WHERE vendedor_id = ?");
    if ($stmt_stats) {
        $stmt_stats->bind_param("i", $user_id);
        $stmt_stats->execute();
        $stats = $stmt_stats->get_result()->fetch_assoc();
        $total_ventas = $stats['total'];
        $total_gastado = $stats['suma'];
        $stmt_stats->close();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury Store - Mi Perfil</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        .toast { position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 12px 20px; border-radius: 12px; z-index: 1000; animation: slideIn 0.3s ease; }
        .toast.error { background: #ef4444; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .avatar-preview { transition: transform 0.3s ease; }
        .avatar-preview:hover { transform: scale(1.05); }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
<?php luxury_render_nav_start('perfil'); ?>

    <div class="">

        <!-- Header con botón volver -->
        <div class="mb-6 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-extrabold text-gray-800 flex items-center gap-3">
                    <span class="bg-gradient-to-r from-emerald-500 to-green-600 p-2 rounded-2xl">
                        <i class="fas fa-user-circle text-white text-2xl"></i>
                    </span>
                    Mi Perfil
                </h1>
                <p class="text-gray-500 mt-1">Gestiona tu información personal y configuración de cuenta</p>
            </div>
            <div class="text-right">
                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold <?= $is_admin ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>">
                    <i class="fas fa-shield-alt mr-1"></i> <?= $is_admin ? 'Administrador' : 'Empleado' ?>
                </span>
            </div>
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Columna izquierda: Avatar e información -->
            <div class="space-y-6">
                
                <!-- Tarjeta de Avatar -->
                <div class="bg-white rounded-2xl shadow-xl p-6 text-center border border-gray-100">
                    <div class="relative inline-block avatar-preview">
                        <img src="<?= $avatar_url ?>" class="w-32 h-32 rounded-2xl object-cover border-4 border-emerald-500 shadow-lg mx-auto" alt="<?= htmlspecialchars($user_nombre) ?>" onerror="this.src='../assets/img/avatars/default.svg'">
                        <div class="absolute bottom-2 right-2 w-5 h-5 bg-green-500 border-2 border-white rounded-full"></div>
                    </div>
                    <h2 class="text-xl font-bold text-gray-800 mt-4"><?= htmlspecialchars($user_nombre) ?></h2>
                    <p class="text-sm text-gray-500">@<?= htmlspecialchars($user_username) ?></p>
                    
                    <form method="POST" enctype="multipart/form-data" class="mt-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="subir_avatar">
                        <div class="flex flex-col gap-2">
                            <label class="block text-sm font-medium text-gray-700 text-left">Cambiar foto de perfil</label>
                            <input type="file" name="avatar" accept="image/*" class="w-full text-sm text-gray-500 file:mr-2 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-bold transition">
                                <i class="fas fa-upload mr-2"></i> Subir foto
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tarjeta de estadísticas -->
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-2xl shadow-xl p-6 text-white">
                    <h3 class="text-sm font-bold opacity-80 uppercase tracking-wider mb-3">Estadísticas</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span><i class="fas fa-calendar-alt mr-2"></i> Miembro desde</span>
                            <span class="font-bold"><?= date('d/m/Y', strtotime($user_fecha_registro)) ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span><i class="fas fa-shopping-cart mr-2"></i> Ventas realizadas</span>
                            <span class="font-bold"><?= number_format($total_ventas) ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span><i class="fas fa-dollar-sign mr-2"></i> Total facturado</span>
                            <span class="font-bold">$<?= number_format($total_gastado, 0, ',', '.') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Acción rápida: Cerrar sesión -->
                <div class="bg-white rounded-2xl shadow-xl p-4 border border-gray-100 text-center">
                    <a href="../logout.php" class="text-red-500 hover:text-red-700 text-sm flex items-center justify-center gap-2" onclick="return confirm('¿Cerrar sesión?')">
                        <i class="fas fa-sign-out-alt"></i> Cerrar sesión
                    </a>
                </div>
            </div>

            <!-- Columna derecha: Formularios -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- Información personal -->
                <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-user-edit text-emerald-500"></i> Información Personal
                    </h3>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="actualizar_perfil">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre completo</label>
                                <input type="text" name="nombre" value="<?= htmlspecialchars($user_nombre) ?>" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de usuario</label>
                                <input type="text" name="username" value="<?= htmlspecialchars($user_username) ?>" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Correo electrónico</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($user_email) ?>" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                                <input type="tel" name="telefono" value="<?= htmlspecialchars($user_telefono) ?>" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white px-6 py-2 rounded-lg font-bold transition">
                                <i class="fas fa-save mr-2"></i> Guardar cambios
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Cambiar contraseña -->
                <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-key text-amber-500"></i> Cambiar Contraseña
                    </h3>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cambiar_password">
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña actual</label>
                                <input type="password" name="password_actual" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nueva contraseña</label>
                                <input type="password" name="password_nueva" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none">
                                <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar nueva contraseña</label>
                                <input type="password" name="password_confirmar" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-2 rounded-lg font-bold transition">
                                <i class="fas fa-key mr-2"></i> Actualizar contraseña
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Información de sesión -->
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-200">
                    <h3 class="text-sm font-bold text-gray-600 mb-3 flex items-center gap-2">
                        <i class="fas fa-info-circle text-blue-500"></i> Información de sesión
                    </h3>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div><span class="text-gray-500">ID de usuario:</span></div>
                        <div class="font-mono">#<?= $user_id ?></div>
                        <div><span class="text-gray-500">Último acceso:</span></div>
                        <div><?= date('d/m/Y H:i:s') ?></div>
                        <div><span class="text-gray-500">IP actual:</span></div>
                        <div><?= $_SERVER['REMOTE_ADDR'] ?></div>
                        <div><span class="text-gray-500">Navegador:</span></div>
                        <div class="truncate"><?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido') ?></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Auto ocultar mensajes después de 5 segundos
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
