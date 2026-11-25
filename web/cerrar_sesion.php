<?php
session_start(); // Iniciar sesión para poder localizarla

// 1. Limpiar todas las variables de sesión
$_SESSION = array();

// 2. Destruir la cookie de sesión si existe
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destruir la sesión en el servidor
session_destroy();

// 4. Redirigir automáticamente al login de clientes de forma instantánea
header("Location: login_cliente.php");
exit;
?>