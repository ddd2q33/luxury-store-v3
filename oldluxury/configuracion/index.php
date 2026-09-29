<?php
// pages/configuracion.php - Gestión de Usuarios (Solo Administradores)
require_once dirname(__DIR__) . '/views/partials/app_nav.php';
require_once dirname(__DIR__) . '/init.php';

require_admin();

// getAvatarUrl() ahora proviene de init.php

// Crear directorio para imágenes de perfil si no existe
$upload_dir = dirname(__DIR__) . "/uploads/avatars/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Variables para mensajes
$mensaje = '';
$error = '';

// ====================================================================
// FUNCIÓN PARA SUBIR IMAGEN
// ====================================================================
function subirImagen($file, $user_id) {
    $target_dir = dirname(__DIR__) . "/uploads/avatars/";
    
    // Validar tipo de archivo (MIME real detectado del contenido, no el reportado por el navegador)
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    
    if (!$file_type || !in_array($file_type, $allowed_types, true)) {
        return ['success' => false, 'error' => 'Solo se permiten imágenes JPG, PNG, GIF o WEBP'];
    }
    
    // Validar tamaño (máximo 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['success' => false, 'error' => 'La imagen no debe exceder los 2MB'];
    }
    
    // Generar nombre único
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "user_" . $user_id . "_" . time() . "." . $extension;
    $target_file = $target_dir . $filename;
    
    // Mover archivo
    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['success' => false, 'error' => 'Error al subir la imagen'];
    }
}

// ====================================================================
// FUNCIÓN PARA ELIMINAR IMAGEN
// ====================================================================
function eliminarImagen($user_id, $conn) {
    // Obtener nombre de la imagen actual
    $stmt = $conn->prepare("SELECT imagen_perfil FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if ($user && !empty($user['imagen_perfil'])) {
$posibles = [
            dirname(__DIR__) . "/uploads/avatars/" . $user['imagen_perfil'],
            __DIR__ . "/uploads/avatars/" . $user['imagen_perfil'],
            $_SERVER['DOCUMENT_ROOT'] . "/luxuryv3/uploads/avatars/" . $user['imagen_perfil'],
        ];
        foreach ($posibles as $file_path) {
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        // Actualizar base de datos
        $update = $conn->prepare("UPDATE usuarios SET imagen_perfil = NULL WHERE id = ?");
        $update->bind_param("i", $user_id);
        $update->execute();
        $update->close();

        if (!empty($_SESSION['user_id']) && intval($_SESSION['user_id']) === intval($user_id)) {
            $_SESSION['user_avatar'] = null;
            $_SESSION['imagen_perfil'] = null;
        }
        
        return true;
    }
    return false;
}

// ====================================================================
// CRUD DE USUARIOS
// ====================================================================

// Crear nuevo usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_usuario'])) {
    $nombre = trim($_POST['nombre']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $rol = $_POST['rol'];
    $password = $_POST['password'];
    $estado = $_POST['estado'];
    
    // Validaciones
    if (empty($nombre) || empty($username) || empty($email) || empty($password)) {
        $error = 'Todos los campos son obligatorios';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email no válido';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } else {
        // Verificar si ya existe el username o email
        $check = $conn->prepare("SELECT id FROM usuarios WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'El nombre de usuario o email ya existe';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Procesar imagen si se subió
            $imagen = null;
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === 0) {
                $upload_result = subirImagen($_FILES['imagen'], 0);
                if ($upload_result['success']) {
                    $imagen = $upload_result['filename'];
                } else {
                    $error = $upload_result['error'];
                }
            }
            
            if (empty($error)) {
                $stmt = $conn->prepare("INSERT INTO usuarios (nombre, username, email, rol, password, estado, imagen_perfil) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssss", $nombre, $username, $email, $rol, $hash, $estado, $imagen);
                
                if ($stmt->execute()) {
                    $mensaje = "Usuario '$username' creado exitosamente";
                } else {
                    $error = "Error al crear usuario: " . $stmt->error;
                }
                $stmt->close();
            }
        }
        $check->close();
    }
}

// Editar usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_usuario'])) {
    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $rol = $_POST['rol'];
    $estado = $_POST['estado'];
    
    if (empty($nombre) || empty($username) || empty($email)) {
        $error = 'Nombre, usuario y email son obligatorios';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email no válido';
    } else {
        // Verificar si el username o email ya existen (excepto el usuario actual)
        $check = $conn->prepare("SELECT id FROM usuarios WHERE (username = ? OR email = ?) AND id != ?");
        $check->bind_param("ssi", $username, $email, $id);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'El nombre de usuario o email ya está en uso por otro usuario';
        } else {
            $imagen = null;
            $update_imagen = false;
            
            // Procesar nueva imagen si se subió
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === 0) {
                $upload_result = subirImagen($_FILES['imagen'], $id);
                if ($upload_result['success']) {
                    $imagen = $upload_result['filename'];
                    $update_imagen = true;
                    
                    // Eliminar imagen anterior
                    $stmt_img = $conn->prepare("SELECT imagen_perfil FROM usuarios WHERE id = ?");
                    $stmt_img->bind_param("i", $id);
                    $stmt_img->execute();
                    $old_img = $stmt_img->get_result()->fetch_assoc();
                    if ($old_img && !empty($old_img['imagen_perfil'])) {
$posibles = [
                            dirname(__DIR__) . "/uploads/avatars/" . $old_img['imagen_perfil'],
                            __DIR__ . "/uploads/avatars/" . $old_img['imagen_perfil'],
                            $_SERVER['DOCUMENT_ROOT'] . "/luxuryv3/uploads/avatars/" . $old_img['imagen_perfil'],
                        ];
                        foreach ($posibles as $old_file) {
                            if (file_exists($old_file)) {
                                unlink($old_file);
                            }
                        }
                    }
                    $stmt_img->close();
                } else {
                    $error = $upload_result['error'];
                }
            }
            
            if (empty($error)) {
                if ($update_imagen) {
                    $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, username = ?, email = ?, rol = ?, estado = ?, imagen_perfil = ? WHERE id = ?");
                    $stmt->bind_param("ssssssi", $nombre, $username, $email, $rol, $estado, $imagen, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, username = ?, email = ?, rol = ?, estado = ? WHERE id = ?");
                    $stmt->bind_param("sssssi", $nombre, $username, $email, $rol, $estado, $id);
                }
                
                if ($stmt->execute()) {
                    if (!empty($_SESSION['user_id']) && intval($_SESSION['user_id']) === intval($id) && $update_imagen) {
                        $_SESSION['user_avatar'] = $imagen;
                        $_SESSION['imagen_perfil'] = $imagen;
                    }
                    $mensaje = "Usuario actualizado exitosamente";
                } else {
                    $error = "Error al actualizar usuario: " . $stmt->error;
                }
                $stmt->close();
            }
        }
        $check->close();
    }
}

// Eliminar imagen de perfil
if (isset($_POST['eliminar_imagen'])) {
    $id = intval($_POST['eliminar_imagen']);
    
    if (eliminarImagen($id, $conn)) {
        $mensaje = "Imagen de perfil eliminada exitosamente";
    } else {
        $error = "No se pudo eliminar la imagen o el usuario no tiene imagen";
    }
    
    // Redirigir para evitar reenvío
    echo "<script>window.location.href='./';</script>";
    exit;
}

// Cambiar contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_password'])) {
    $id = intval($_POST['id']);
    $nueva_password = $_POST['nueva_password'];
    $confirmar_password = $_POST['confirmar_password'];
    
    if (strlen($nueva_password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } elseif ($nueva_password !== $confirmar_password) {
        $error = 'Las contraseñas no coinciden';
    } else {
        $hash = password_hash($nueva_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $id);
        
        if ($stmt->execute()) {
            $mensaje = "✅ Contraseña actualizada exitosamente";
        } else {
            $error = "❌ Error al cambiar contraseña: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Eliminar usuario
if (isset($_POST['eliminar'])) {
    $id = intval($_POST['eliminar']);
    
    // No permitir eliminar al propio usuario
    if ($id == $_SESSION['user_id']) {
        $error = "❌ No puedes eliminar tu propio usuario";
    } else {
        // Eliminar imagen primero
        eliminarImagen($id, $conn);
        
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $mensaje = "✅ Usuario eliminado exitosamente";
        } else {
            $error = "❌ Error al eliminar usuario: " . $stmt->error;
        }
        $stmt->close();
    }
}

// ====================================================================
// OBTENER LISTA DE USUARIOS
// ====================================================================
$usuarios = [];
$sql = "SELECT id, nombre, username, email, rol, estado, imagen_perfil, ultimo_login, 
        DATE_FORMAT(ultimo_login, '%d/%m/%Y %H:%i') as ultimo_login_fmt 
        FROM usuarios 
        ORDER BY id DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }
}

// ====================================================================
// ESTADÍSTICAS
// ====================================================================
$total_usuarios = count($usuarios);
$total_administradores = 0;
$total_empleados = 0;
$total_activos = 0;

foreach ($usuarios as $u) {
    if ($u['rol'] === 'admin') $total_administradores++;
    if ($u['rol'] === 'empleado') $total_empleados++;
    if ($u['estado'] === 'activo') $total_activos++;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Gestión de Usuarios</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <script src="../assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        .modal {
            transition: all 0.3s ease;
        }
        .modal.hidden {
            display: none;
        }
        .card-hover {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .avatar-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .avatar-preview-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .avatar-container {
            position: relative;
            display: inline-block;
        }
        .delete-avatar-btn {
            position: absolute;
            bottom: -5px;
            right: -5px;
            background: red;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid white;
        }
        .delete-avatar-btn:hover {
            transform: scale(1.1);
            background: darkred;
        }
    </style>
</head>
<body class="bg-gray-100">
<?php luxury_render_nav_start('configuracion'); ?>
<div class="">
    
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-gray-900 flex items-center gap-3">
            <i class="fas fa-cog text-purple-600"></i> 
            Configuración del Sistema
        </h1>
        <p class="text-gray-500 text-sm mt-2">
            <i class="fas fa-shield-alt mr-1"></i> Gestión de usuarios, roles, imágenes de perfil y seguridad
        </p>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
    <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-lg flex items-center justify-between">
        <div class="flex items-center gap-2">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($mensaje) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg flex items-center justify-between">
        <div class="flex items-center gap-2">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Apariencia del sistema -->
    <div class="bg-white rounded-xl shadow-xl p-5 mb-8">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3">
                <span class="w-11 h-11 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center">
                    <i class="fas fa-palette"></i>
                </span>
                <div>
                    <h2 class="font-bold text-gray-800">Apariencia</h2>
                    <p class="text-xs text-gray-500">Tema visual para todo el sistema</p>
                </div>
            </div>
            <div class="inline-flex bg-gray-100 rounded-xl p-1">
                <button type="button" data-tema="light" onclick="setTema('light')" class="tema-op rounded-lg px-5 py-2.5 text-sm font-bold flex items-center gap-2 transition">
                    <i class="fas fa-sun"></i> Claro
                </button>
                <button type="button" data-tema="dark" onclick="setTema('dark')" class="tema-op rounded-lg px-5 py-2.5 text-sm font-bold flex items-center gap-2 transition">
                    <i class="fas fa-moon"></i> Oscuro
                </button>
            </div>
        </div>
    </div>

    <!-- Tarjetas de Estadísticas -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-gradient-to-r from-purple-500 to-indigo-600 rounded-xl shadow-lg p-5 text-white">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-sm opacity-90">Total Usuarios</p>
                    <p class="text-3xl font-bold"><?= $total_usuarios ?></p>
                </div>
                <i class="fas fa-users text-4xl opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-blue-500 to-cyan-600 rounded-xl shadow-lg p-5 text-white">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-sm opacity-90">Administradores</p>
                    <p class="text-3xl font-bold"><?= $total_administradores ?></p>
                </div>
                <i class="fas fa-user-shield text-4xl opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl shadow-lg p-5 text-white">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-sm opacity-90">Empleados</p>
                    <p class="text-3xl font-bold"><?= $total_empleados ?></p>
                </div>
                <i class="fas fa-user-tie text-4xl opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-orange-500 to-red-600 rounded-xl shadow-lg p-5 text-white">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-sm opacity-90">Usuarios Activos</p>
                    <p class="text-3xl font-bold"><?= $total_activos ?></p>
                </div>
                <i class="fas fa-check-circle text-4xl opacity-50"></i>
            </div>
        </div>
    </div>

    <!-- Botón para crear nuevo usuario -->
    <div class="mb-6 flex justify-end">
        <button onclick="openCreateModal()" 
                class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all flex items-center gap-2">
            <i class="fas fa-plus-circle"></i>
            Nuevo Usuario
        </button>
    </div>

    <!-- Tabla de Usuarios -->
    <div class="bg-white rounded-xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-list-alt text-purple-600"></i>
                Lista de Usuarios del Sistema
            </h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Avatar</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Usuario</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Nombre</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Rol</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Último Login</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($usuarios as $usuario): ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-sm text-gray-500"><?= $usuario['id'] ?></td>
                        <td class="px-6 py-4">
                            <div class="avatar-container">
                                <img src="<?= getAvatarUrl($usuario['imagen_perfil'] ?? '', $usuario['username']) ?>" 
                                     class="avatar-preview-sm" 
                                     alt="<?= htmlspecialchars($usuario['nombre']) ?>">
                                <?php if (!empty($usuario['imagen_perfil'])): ?>
                                <div class="delete-avatar-btn" onclick="confirmDeleteImage(<?= $usuario['id'] ?>, '<?= htmlspecialchars($usuario['nombre']) ?>')" title="Eliminar imagen">
                                    <i class="fas fa-times text-xs"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-medium text-gray-900"><?= htmlspecialchars($usuario['username']) ?></span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700"><?= htmlspecialchars($usuario['nombre']) ?></td>
                        <td class="px-6 py-4 text-sm text-gray-500"><?= htmlspecialchars($usuario['email']) ?></td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs rounded-full <?= $usuario['rol'] == 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>">
                                <?= $usuario['rol'] == 'admin' ? 'Administrador' : 'Empleado' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs rounded-full <?= $usuario['estado'] == 'activo' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                <?= $usuario['estado'] == 'activo' ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <?= $usuario['ultimo_login_fmt'] ?? 'Nunca' ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex justify-center gap-2">
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($usuario)) ?>)" 
                                        class="bg-yellow-500 text-white p-2 rounded-lg hover:bg-yellow-600 transition" title="Editar">
                                    <i class="fas fa-edit text-xs"></i>
                                </button>
                                <button onclick="openPasswordModal(<?= $usuario['id'] ?>, '<?= htmlspecialchars($usuario['nombre']) ?>')" 
                                        class="bg-blue-500 text-white p-2 rounded-lg hover:bg-blue-600 transition" title="Cambiar Contraseña">
                                    <i class="fas fa-key text-xs"></i>
                                </button>
                                <?php if ($usuario['id'] != $_SESSION['user_id']): ?>
                                <button onclick="confirmDelete(<?= $usuario['id'] ?>, '<?= htmlspecialchars($usuario['nombre']) ?>')" 
                                        class="bg-red-500 text-white p-2 rounded-lg hover:bg-red-600 transition" title="Eliminar">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL CREAR USUARIO -->
<div id="createModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-70 flex items-center justify-center z-50 modal">
    <div class="bg-white rounded-xl shadow-2xl p-6 w-11/12 max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-gray-800">
                <i class="fas fa-user-plus text-purple-600 mr-2"></i>
                Crear Nuevo Usuario
            </h2>
            <button onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="space-y-4">
                <div class="flex justify-center mb-4">
                    <div class="relative">
                        <img id="create_avatar_preview" src="../assets/img/avatars/default.svg" 
                             class="avatar-preview" alt="Avatar Preview">
                        <label for="create_imagen" class="absolute bottom-0 right-0 bg-purple-600 rounded-full p-1 cursor-pointer hover:bg-purple-700 transition">
                            <i class="fas fa-camera text-white text-xs"></i>
                        </label>
                        <input type="file" id="create_imagen" name="imagen" accept="image/*" class="hidden" onchange="previewImage(this, 'create_avatar_preview')">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo</label>
                    <input type="text" name="nombre" required
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-purple-500 focus:border-purple-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de Usuario</label>
                    <input type="text" name="username" required
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-purple-500 focus:border-purple-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" required
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-purple-500 focus:border-purple-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
                    <select name="rol" class="w-full border border-gray-300 rounded-lg px-4 py-2">
                        <option value="empleado">👤 Empleado</option>
                        <option value="admin">👑 Administrador</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                    <select name="estado" class="w-full border border-gray-300 rounded-lg px-4 py-2">
                        <option value="activo">✅ Activo</option>
                        <option value="inactivo">❌ Inactivo</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
                    <input type="password" name="password" required minlength="6"
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-purple-500 focus:border-purple-500">
                    <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres</p>
                </div>
                
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeCreateModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                        Cancelar
                    </button>
                    <button type="submit" name="crear_usuario" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition">
                        <i class="fas fa-save mr-1"></i> Crear Usuario
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR USUARIO -->
<div id="editModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-70 flex items-center justify-center z-50 modal">
    <div class="bg-white rounded-xl shadow-2xl p-6 w-11/12 max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-gray-800">
                <i class="fas fa-edit text-yellow-600 mr-2"></i>
                Editar Usuario
            </h2>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="edit_id">
            <div class="space-y-4">
                <div class="flex justify-center mb-4">
                    <div class="relative">
                        <img id="edit_avatar_preview" src="" class="avatar-preview" alt="Avatar Preview">
                        <label for="edit_imagen" class="absolute bottom-0 right-0 bg-yellow-500 rounded-full p-1 cursor-pointer hover:bg-yellow-600 transition">
                            <i class="fas fa-camera text-white text-xs"></i>
                        </label>
                        <input type="file" id="edit_imagen" name="imagen" accept="image/*" class="hidden" onchange="previewImage(this, 'edit_avatar_preview')">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo</label>
                    <input type="text" name="nombre" id="edit_nombre" required
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-yellow-500 focus:border-yellow-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de Usuario</label>
                    <input type="text" name="username" id="edit_username" required
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-yellow-500 focus:border-yellow-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="edit_email" required
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-yellow-500 focus:border-yellow-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
                    <select name="rol" id="edit_rol" class="w-full border border-gray-300 rounded-lg px-4 py-2">
                        <option value="empleado">👤 Empleado</option>
                        <option value="admin">👑 Administrador</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                    <select name="estado" id="edit_estado" class="w-full border border-gray-300 rounded-lg px-4 py-2">
                        <option value="activo">✅ Activo</option>
                        <option value="inactivo">❌ Inactivo</option>
                    </select>
                </div>
                
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeEditModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                        Cancelar
                    </button>
                    <button type="submit" name="editar_usuario" 
                            class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition">
                        <i class="fas fa-save mr-1"></i> Guardar Cambios
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CAMBIAR CONTRASEÑA -->
<div id="passwordModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-70 flex items-center justify-center z-50 modal">
    <div class="bg-white rounded-xl shadow-2xl p-6 w-11/12 max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-gray-800">
                <i class="fas fa-key text-blue-600 mr-2"></i>
                Cambiar Contraseña
            </h2>
            <button onclick="closePasswordModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="password_id">
            <div class="space-y-4">
                <div class="p-3 bg-blue-50 rounded-lg">
                    <p class="text-sm text-blue-800">
                        <i class="fas fa-info-circle mr-1"></i>
                        Cambiando contraseña para: <strong id="password_nombre"></strong>
                    </p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nueva Contraseña</label>
                    <input type="password" name="nueva_password" id="nueva_password" required minlength="6"
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar Contraseña</label>
                    <input type="password" name="confirmar_password" id="confirmar_password" required
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closePasswordModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                        Cancelar
                    </button>
                    <button type="submit" name="cambiar_password" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        <i class="fas fa-save mr-1"></i> Cambiar Contraseña
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';

function csrfDelete(extraFields) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="_csrf" value="' + CSRF_TOKEN + '">';
    for (const [k, v] of Object.entries(extraFields)) {
        form.innerHTML += '<input type="hidden" name="' + k + '" value="' + v + '">';
    }
    document.body.appendChild(form);
    form.submit();
}
    // Previsualizar imagen
    function previewImage(input, previewId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById(previewId).src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
    
    // Confirmar eliminación de imagen
    function confirmDeleteImage(id, nombre) {
        if (confirm(`¿Estás seguro de eliminar la imagen de perfil de "${nombre}"?\n\nSe restaurará el avatar por defecto.`)) {
            csrfDelete({ eliminar_imagen: id });
        }
    }
    
    // Modal Crear
    function openCreateModal() {
        document.getElementById('createModal').classList.remove('hidden');
        document.getElementById('create_avatar_preview').src = '../assets/img/avatars/default.svg';
        document.getElementById('create_imagen').value = '';
    }
    
    function closeCreateModal() {
        document.getElementById('createModal').classList.add('hidden');
    }
    
    // Modal Editar
    function openEditModal(usuario) {
        document.getElementById('edit_id').value = usuario.id;
        document.getElementById('edit_nombre').value = usuario.nombre;
        document.getElementById('edit_username').value = usuario.username;
        document.getElementById('edit_email').value = usuario.email;
        document.getElementById('edit_rol').value = usuario.rol;
        document.getElementById('edit_estado').value = usuario.estado;
        
        // Cargar avatar actual
        const imgEl = document.getElementById('edit_avatar_preview');
let avatarUrl = usuario.imagen_perfil
            ? '../uploads/avatars/' + usuario.imagen_perfil
            : '../assets/img/avatars/default.svg';
        if (imgEl) {
            imgEl.onerror = () => {
                if (usuario.imagen_perfil) {
                    imgEl.onerror = null;
                    imgEl.src = '../uploads/avatars/' + usuario.imagen_perfil;
                }
            };
            imgEl.src = avatarUrl;
        }
        document.getElementById('edit_imagen').value = '';
        
        document.getElementById('editModal').classList.remove('hidden');
    }
    
    function closeEditModal() {
        document.getElementById('editModal').classList.add('hidden');
    }
    
    // Modal Contraseña
    function openPasswordModal(id, nombre) {
        document.getElementById('password_id').value = id;
        document.getElementById('password_nombre').innerText = nombre;
        document.getElementById('nueva_password').value = '';
        document.getElementById('confirmar_password').value = '';
        document.getElementById('passwordModal').classList.remove('hidden');
    }
    
    function closePasswordModal() {
        document.getElementById('passwordModal').classList.add('hidden');
    }
    
    // Confirmar eliminación de usuario
    function confirmDelete(id, nombre) {
        if (confirm(`¿Estás seguro de eliminar al usuario "${nombre}"?\n\nEsta acción no se puede deshacer.`)) {
            csrfDelete({ eliminar: id });
        }
    }
    
    // Cerrar modales con ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeCreateModal();
            closeEditModal();
            closePasswordModal();
        }
    });
    
    // Cerrar modales haciendo clic fuera
    window.onclick = function(event) {
        const modals = ['createModal', 'editModal', 'passwordModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target === modal) {
                modal.classList.add('hidden');
            }
        });
    }
</script>

<script>
    function reflejarTema() {
        const dark = document.documentElement.classList.contains('dark');
        document.querySelectorAll('.tema-op').forEach(function (b) {
            const on = (b.dataset.tema === 'dark') === dark;
            b.classList.toggle('bg-white', on);
            b.classList.toggle('text-purple-600', on);
            b.classList.toggle('shadow', on);
            b.classList.toggle('text-gray-500', !on);
        });
    }
    function setTema(t) {
        document.documentElement.classList.toggle('dark', t === 'dark');
        try { localStorage.setItem('luxTema', t); } catch (e) {}
        reflejarTema();
    }
    reflejarTema();
</script>
<?php luxury_render_nav_end(); ?>
</body>
</html>
