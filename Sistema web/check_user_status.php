<?php
// check_user_status.php
session_start();

if (!isset($_SESSION['technician_id'])) {
    echo json_encode(['active' => false]);
    exit();
}

include_once 'config.php';
include_once 'Technician.php';

$database = new Database();
$db = $database->getConnection();

$user_check = new Technician($db);
$user_check->id = $_SESSION['technician_id'];
$user_data = $user_check->getUserById();

// Función de verificación (la misma de arriba)
function isUserActive($user_data) {
    if (!$user_data) return false;
    
    if (isset($user_data['active'])) {
        $active = $user_data['active'];
        $active_values = ['yes', '1', 1, true, 'active', 'si', 'enabled', 'activo'];
        $inactive_values = ['no', '0', 0, false, 'inactive', 'false', 'disabled', 'inactivo'];
        
        if (in_array($active, $active_values, true)) return true;
        if (in_array($active, $inactive_values, true)) return false;
    }
    
    return true;
}

$is_active = isUserActive($user_data);

if (!$is_active) {
    // Limpiar sesión si el usuario está inactivo
    session_unset();
    session_destroy();
}

echo json_encode(['active' => $is_active]);
?>