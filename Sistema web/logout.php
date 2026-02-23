<?php
// ==================== CONFIGURACIÓN DE SEGURIDAD ====================
session_start();

// ==================== HEADERS PARA PREVENIR CACHÉ ====================
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

// ==================== DESTRUCCIÓN COMPLETA DE SESIÓN ====================
// 1. Limpiar todas las variables de sesión
$_SESSION = array();

// 2. Destruir la cookie de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], 
        $params["domain"], 
        $params["secure"], 
        $params["httponly"]
    );
}

// 3. Destruir la sesión
session_destroy();

// ==================== LIMPIAR CACHÉ DEL CLIENTE ====================
// Timestamp para evitar cache
$timestamp = time();

// ==================== REDIRECCIÓN SEGURA ====================
header("Location: login.php?logout=success&t=" . $timestamp);
exit();
?>