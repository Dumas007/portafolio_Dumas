<?php
session_start();
include_once 'config.php';
include_once 'Technician.php';

header('Content-Type: application/json');

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['hasFacialData' => false, 'error' => 'Error de seguridad']);
    exit;
}

if (empty($_POST['email'])) {
    echo json_encode(['hasFacialData' => false, 'error' => 'Email requerido']);
    exit;
}

$email = trim($_POST['email']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['hasFacialData' => false, 'error' => 'Email inválido']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT facial_data, active FROM technicians WHERE email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":email", $email);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        echo json_encode(['hasFacialData' => false, 'error' => 'Email no registrado']);
        exit;
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['active'] !== 'yes') {
        echo json_encode(['hasFacialData' => false, 'error' => 'Técnico inactivo']);
        exit;
    }
    
    $hasFacialData = !empty($user['facial_data']);
    
    echo json_encode([
        'hasFacialData' => $hasFacialData,
        'message' => $hasFacialData ? 'Email verificado. Rostros registrados encontrados.' : 'No hay datos faciales registrados'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['hasFacialData' => false, 'error' => 'Error del servidor']);
}
?>