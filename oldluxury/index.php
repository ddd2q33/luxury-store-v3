<?php
// index.php - Página de inicio de sesión
session_start();

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ./dashboard');
    exit;
}

require_once "config.php";

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Por favor ingrese usuario y contraseña';
    } else {
        $stmt = $conn->prepare("SELECT id, nombre, username, email, rol, password, estado FROM usuarios WHERE (username = ? OR email = ?) AND estado = 'activo'");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nombre'] = $user['nombre'];
            $_SESSION['user_username'] = $user['username'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['rol'] = $user['rol'];
            
            $update = $conn->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?");
            $update->bind_param("i", $user['id']);
            $update->execute();
            $update->close();
            
            header('Location: ./dashboard');
            exit;
        } else {
            $error = 'Usuario o contraseña incorrectos';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury Store - Iniciar Sesión</title>
    <script src="assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .animate-shake {
            animation: shake 0.3s ease-in-out;
        }
        
        .password-container {
            position: relative;
        }
        
        .password-container input {
            padding-right: 45px;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            background: transparent;
            border: none;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            color: #9ca3af;
            transition: color 0.2s;
            padding: 0;
            margin: 0;
            width: auto;
            height: auto;
        }
        
        .toggle-password:hover {
            color: #8b5cf6;
        }
        
        .toggle-password:focus {
            outline: none;
        }
        
        /* Eliminar el ícono por defecto del input password en navegadores */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
        }
        
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-caps-lock-indicator {
            display: none;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-900 flex items-center justify-center p-4">
    <div class="max-w-md w-full animate-fade-in">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="inline-block p-4 bg-gray-800/50 rounded-2xl mb-4">
                <img src="./assets/img/logo.png" alt="Luxury Store" class="h-20 w-auto mx-auto">
            </div>
            <h1 class="text-3xl font-bold text-white">Luxury Store</h1>
            <p class="text-gray-400 text-sm mt-1">Sistema de Gestión de Ventas</p>
        </div>
        
        <!-- Formulario -->
        <div class="bg-gray-800/50 backdrop-blur-sm rounded-2xl p-8 shadow-2xl border border-gray-700">
            <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-900/50 border-l-4 border-red-500 text-red-200 rounded-lg flex items-center gap-3 animate-shake">
                <i class="fas fa-exclamation-circle text-red-400"></i>
                <span class="text-sm"><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-5">
                    <label class="block text-gray-300 text-sm font-bold mb-2">
                        <i class="fas fa-user text-gray-500 mr-2"></i>Usuario o Email
                    </label>
                    <input type="text" name="username" required autofocus
                           class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 text-white placeholder-gray-400"
                           placeholder="usuario@ejemplo.com">
                </div>
                
                <div class="mb-6">
                    <label class="block text-gray-300 text-sm font-bold mb-2">
                        <i class="fas fa-lock text-gray-500 mr-2"></i>Contraseña
                    </label>
                    <div class="password-container">
                        <input type="password" name="password" id="password" required 
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 text-white placeholder-gray-400"
                               placeholder="••••••••">
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" 
                        class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-bold py-3 rounded-xl hover:from-purple-700 hover:to-indigo-700 transition-all duration-200 transform hover:scale-[1.02] shadow-lg flex items-center justify-center gap-2">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Ingresar</span>
                </button>
            </form>
            
            <div class="mt-8 pt-6 border-t border-gray-700 text-center">
                <p class="text-xs text-gray-500">
                    <i class="fas fa-shield-alt mr-1"></i> Sistema seguro
                </p>
                <p class="text-xs text-gray-600 mt-1">© 2026 Luxury Store</p>
            </div>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
