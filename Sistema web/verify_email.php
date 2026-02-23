<?php
session_start();
include_once 'config.php';
include_once 'Technician.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();
$technician = new Technician($db);

$response = ['exists' => false];

if($_POST && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    
    if(filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $existingTechnician = $technician->getTechnicianByEmail($email);
        $response['exists'] = (bool)$existingTechnician;
    }
}

echo json_encode($response);
?>