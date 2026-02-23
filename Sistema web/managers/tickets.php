<?php
session_start();

// Configurar tiempo máximo de inactividad (3 horas = 10800 segundos)
$max_inactivity_time = 10800; // 3 horas en segundos

// Verificar inactividad
if (isset($_SESSION['last_activity'])) {
    $session_time = time() - $_SESSION['last_activity'];
    if ($session_time > $max_inactivity_time) {
        // Session expired due to inactivity
        session_unset();
        session_destroy();
        header("Location: ../login.php?error=session_expired");
        exit();
    }
}

// Actualizar timestamp de última actividad
$_SESSION['last_activity'] = time();

// Verificar si el usuario está logueado y es técnico
if(!isset($_SESSION['technician_id']) || strpos($_SESSION['technician_role'], 'dep_') !== 0){
    header("Location: ../login.php");
    exit();
}

include_once '../config.php';
include_once '../Technician.php';

$database = new Database();
$db = $database->getConnection();

// VERIFICAR SI EL USUARIO ESTÁ ACTIVO EN LA TABLA TECHNICIANS
$user_check = new Technician($db);
$user_data_result = $user_check->getUserById($_SESSION['technician_id']);

// Si el usuario no existe o está inactivo en technicians, cerrar sesión inmediatamente
if (!$user_data_result || $user_check->active == 'no') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?error=user_inactive");
    exit();
}

// Cargar datos del usuario actual en la sesión
$_SESSION['technician_name'] = $user_check->name;
$_SESSION['technician_email'] = $user_check->email;
$_SESSION['technician_role'] = $user_check->role;
$_SESSION['technician_phone'] = $user_check->phone;

// Obtener el rol/departamento del usuario logueado
$current_user_role = $_SESSION['technician_role'];
$current_user_id = $_SESSION['technician_id'];

// NORMALIZACIÓN - Si el rol es 'dep_sale' pero en tickets es 'dep_sales'
if ($current_user_role == 'dep_sale') {
    $user_department = 'dep_sales';
} else {
    $user_department = $current_user_role;
}

// Verificar si el usuario tiene datos faciales
$user = new Technician($db);
$user->id = $_SESSION['technician_id'];
$has_facial_data = isset($_SESSION['has_facial_data']) ? $_SESSION['has_facial_data'] : $user->hasFacialData();

// Contador para mostrar popup cada 10 logins
if(!isset($_SESSION['login_count'])) {
    $_SESSION['login_count'] = 1;
} else {
    $_SESSION['login_count']++;
}

$show_popup = !$has_facial_data && ($_SESSION['login_count'] % 10 == 0);

// Variables para el formulario
$ticket = [
    'id' => '',
    'query_id' => '',
    'need' => '',
    'urgency' => 'medium_urg',
    'department' => $user_department,
    'status' => 'open',
    'response' => '', // NUEVO CAMPO AÑADIDO
    'last_user' => $_SESSION['technician_name'], // NUEVO CAMPO - USUARIO ACTUAL
    'creation_date' => date('Y-m-d H:i:s'),
    'update_date' => date('Y-m-d H:i:s'),
    'technicians_assign' => '',
    'date_assign' => '',
    'process_date' => '',
    'date_done' => ''
];

$action = 'create';
$page_title = 'Tickets - ' . htmlspecialchars($user_department);

// Definir enums - VALORES EXACTOS DE LA BD
$urgency_levels = [
   'low_urg' => 'Low Urgency',
'medium_urg' => 'Medium Urgency', 
'high_urg' => 'High Urgency'
];

$departments = [
  'dep_it' => 'Technology/Systems',
'dep_hr' => 'Human Resources',
'dep_finance' => 'Finance',
'dep_marketing' => 'Marketing',
'dep_sales' => 'Sales',
'dep_operations' => 'Operations',
'dep_support' => 'Support',
'dep_other' => 'Other'
];

$statuses = [
    'open' => 'Open',
    'in_process' => 'In Process',
    'closed' => 'Closed'
];

// Obtener lista de técnicos del mismo departamento
try {
    $stmt_tech = $db->prepare("SELECT id, name, email FROM technicians WHERE role = :department AND active = 'yes' ORDER BY name");
    $stmt_tech->bindParam(':department', $user_department);
    $stmt_tech->execute();
    $technicians = $stmt_tech->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $technicians = [];
}

// Separar los datos del formulario de los datos de la BD
$form_data = $ticket;

// Obtener estadísticas para las cards informativas - FILTRADO POR DEPARTAMENTO
try {
    // Total de tickets del departamento
    $stmt_total = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE department = :department");
    $stmt_total->bindParam(':department', $user_department);
    $stmt_total->execute();
    $stats['total_tickets'] = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

    // Tickets abiertos del departamento
    $stmt_open = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE status = 'open' AND department = :department");
    $stmt_open->bindParam(':department', $user_department);
    $stmt_open->execute();
    $stats['open_tickets'] = $stmt_open->fetch(PDO::FETCH_ASSOC)['total'];

    // Tickets en proceso del departamento
    $stmt_process = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE status = 'in_process' AND department = :department");
    $stmt_process->bindParam(':department', $user_department);
    $stmt_process->execute();
    $stats['process_tickets'] = $stmt_process->fetch(PDO::FETCH_ASSOC)['total'];

    // Tickets cerrados del departamento
    $stmt_closed = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE status = 'closed' AND department = :department");
    $stmt_closed->bindParam(':department', $user_department);
    $stmt_closed->execute();
    $stats['closed_tickets'] = $stmt_closed->fetch(PDO::FETCH_ASSOC)['total'];

    // Tickets por urgencia del departamento
    $stmt_urgency = $db->prepare("SELECT urgency, COUNT(*) as total FROM tickets WHERE department = :department GROUP BY urgency");
    $stmt_urgency->bindParam(':department', $user_department);
    $stmt_urgency->execute();
    $stats['urgency_stats'] = $stmt_urgency->fetchAll(PDO::FETCH_ASSOC);

    // Tickets de hoy del departamento
    $stmt_today = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE DATE(creation_date) = CURDATE() AND department = :department");
    $stmt_today->bindParam(':department', $user_department);
    $stmt_today->execute();
    $stats['today_tickets'] = $stmt_today->fetch(PDO::FETCH_ASSOC)['total'];

} catch (Exception $e) {
    $stats = [
        'total_tickets' => 0,
        'open_tickets' => 0,
        'process_tickets' => 0,
        'closed_tickets' => 0,
        'today_tickets' => 0,
        'urgency_stats' => []
    ];
}

// Procesar formulario de ACTUALIZACIÓN (solo permitir actualizar, no crear)
if($_POST && isset($_POST['id']) && !empty($_POST['id'])){
    // SOLO PERMITIR ACTUALIZACIÓN, NO CREACIÓN
    $form_data = [
        'id' => $_POST['id'],
        'query_id' => $_POST['query_id'] ?? '',
        'need' => $_POST['need'] ?? '',
        'urgency' => $_POST['urgency'] ?? 'medium_urg',
        'department' => $user_department, // Forzar el departamento del usuario
        'status' => $_POST['status'] ?? 'open',
        'response' => $_POST['response'] ?? '', // NUEVO CAMPO AÑADIDO
        'last_user' => $_POST['last_user'] ?? $_SESSION['technician_name'], // NUEVO CAMPO AÑADIDO
        'technicians_assign' => $_POST['technicians_assign'] ?? '',
        'date_assign' => $_POST['date_assign'] ?? '',
        'process_date' => $_POST['process_date'] ?? '',
        'date_done' => $_POST['date_done'] ?? ''
    ];

    $action = 'update';
    
    // Verificar si el ticket está cerrado
    $check_closed = $db->prepare("SELECT status FROM tickets WHERE id = :id");
    $check_closed->bindParam(':id', $form_data['id']);
    $check_closed->execute();
    $current_status = $check_closed->fetch(PDO::FETCH_ASSOC)['status'];
    
    // Si el ticket está cerrado, no permitir edición
    if ($current_status == 'closed') {
        $_SESSION['error_message'] = "No se puede editar un ticket cerrado";
        header("Location: tickets.php");
        exit();
    }
    
    // Determinar si se está asignando un técnico
    $check_current_tech = $db->prepare("SELECT technicians_assign FROM tickets WHERE id = :id");
    $check_current_tech->bindParam(':id', $form_data['id']);
    $check_current_tech->execute();
    $current_tech = $check_current_tech->fetch(PDO::FETCH_ASSOC)['technicians_assign'];
    
    $is_assigning = !empty($form_data['technicians_assign']) && $form_data['technicians_assign'] != $current_tech;
    
    // VALIDACIÓN: No permitir estado "in_process" sin técnico asignado
    if ($form_data['status'] == 'in_process' && empty($form_data['technicians_assign'])) {
        $_SESSION['error_message'] = "No se puede establecer el estado 'En Proceso' sin un técnico asignado";
        header("Location: tickets.php?edit=" . $form_data['id']);
        exit();
    }
    
    // CAMBIO AUTOMÁTICO: Si se asigna un técnico, cambiar estado a "in_process"
    if ($is_assigning && $current_status == 'open') {
        $form_data['status'] = 'in_process';
    }
    
    // Determinar si se está cambiando a "en proceso"
    $is_processing = $form_data['status'] == 'in_process' && $current_status != 'in_process';
    
    // Determinar si se está cerrando el ticket
    $is_closing = $form_data['status'] == 'closed' && $current_status != 'closed';
    
    // Construir la consulta de actualización
    $query = "UPDATE tickets SET 
              need = :need, 
              urgency = :urgency, 
              department = :department, 
              status = :status,
              response = :response, 
              last_user = :last_user, 
              update_date = NOW()";
    
    // Agregar campos condicionales
    if ($is_assigning) {
        $query .= ", technicians_assign = :technicians_assign, date_assign = NOW()";
    }
    
    if ($is_processing) {
        $query .= ", process_date = NOW()";
    }
    
    if ($is_closing) {
        $query .= ", date_done = NOW()";
    }
    
    $query .= " WHERE id = :id AND department = :user_department";

    try {
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':id', $form_data['id']);
        $stmt->bindParam(':user_department', $user_department);
        $stmt->bindParam(':need', $form_data['need']);
        $stmt->bindParam(':urgency', $form_data['urgency']);
        $stmt->bindParam(':department', $form_data['department']);
        $stmt->bindParam(':status', $form_data['status']);
        $stmt->bindParam(':response', $form_data['response']);
        $stmt->bindParam(':last_user', $form_data['last_user']);
        
        if ($is_assigning) {
            $stmt->bindParam(':technicians_assign', $form_data['technicians_assign']);
        }
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "Ticket actualizado correctamente";
            header("Location: tickets.php");
            exit();
        } else {
            throw new Exception("Error en ejecución");
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al actualizar el ticket: " . $e->getMessage();
        $ticket = $form_data;
    }
}

// Editar ticket - SOLO SI PERTENECE AL DEPARTAMENTO DEL USUARIO
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Ticket - ' . htmlspecialchars($user_department);
    
    if(!$_POST){
        $query = "SELECT * FROM tickets WHERE id = :id AND department = :department";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $_GET['edit']);
        $stmt->bindParam(':department', $user_department);
        
        try {
            $stmt->execute();
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if(!$ticket){
                $_SESSION['error_message'] = "Ticket no encontrado o no tienes permisos para editarlo";
                header("Location: tickets.php");
                exit();
            }
            
            // Verificar si el ticket está cerrado
            $is_closed = $ticket['status'] == 'closed';
            
            $ticket['query_id'] = $ticket['query_id'] ?? '';
            $ticket['need'] = $ticket['need'] ?? '';
            $ticket['urgency'] = $ticket['urgency'] ?? 'medium_urg';
            $ticket['department'] = $ticket['department'] ?? $user_department;
            $ticket['status'] = $ticket['status'] ?? 'open';
            $ticket['response'] = $ticket['response'] ?? ''; // NUEVO CAMPO AÑADIDO
            $ticket['last_user'] = $ticket['last_user'] ?? $_SESSION['technician_name']; // NUEVO CAMPO AÑADIDO
            $ticket['technicians_assign'] = $ticket['technicians_assign'] ?? '';
            $ticket['date_assign'] = $ticket['date_assign'] ?? '';
            $ticket['process_date'] = $ticket['process_date'] ?? '';
            $ticket['date_done'] = $ticket['date_done'] ?? '';
            
            $form_data = $ticket;
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error al cargar el ticket: " . $e->getMessage();
            header("Location: tickets.php");
            exit();
        }
    }
}

// ELIMINAR TICKET - NO PERMITIDO
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $_SESSION['error_message'] = "No tienes permisos para eliminar tickets";
    header("Location: tickets.php");
    exit();
}

// Obtener lista de tickets - SOLO DEL DEPARTAMENTO DEL USUARIO
try {
    $query = "SELECT * FROM tickets WHERE department = :department ORDER BY creation_date DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $user_department);
    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tickets = [];
    $_SESSION['error_message'] = "Error al cargar la lista de tickets: " . $e->getMessage();
}

$display_data = $_POST ? $form_data : $ticket;
// Verificar si el ticket está cerrado para deshabilitar edición
$is_ticket_closed = isset($display_data['status']) && $display_data['status'] == 'closed';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Management - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
 <style>
:root {
    --primary: #2c3e50;
    --secondary: #34495e;
    --success: #27ae60;
    --warning: #f39c12;
    --danger: #e74c3c;
    --info: #3498db;
    --light: #ecf0f1;
    --dark: #2c3e50;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f8f9fa;
    color: #333;
    line-height: 1.6;
}

/* CONTENEDOR PRINCIPAL */
.admin-container {
    padding: 1rem;
    max-width: 1400px;
    margin: 0 auto;
}

@media (min-width: 768px) {
    .admin-container {
        padding: 2rem;
    }
}

/* HEADER */
.admin-header {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

@media (min-width: 768px) {
    .admin-header {
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        padding: 1.5rem 2rem;
    }
}

.header-content h1 {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

@media (min-width: 768px) {
    .header-content h1 {
        font-size: 2rem;
    }
}

.department-badge {
    background: rgba(255,255,255,0.2);
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-size: 0.8rem;
    margin-left: 1rem;
    font-weight: 600;
}

/* NAVEGACIÓN */
.admin-nav {
    background: var(--secondary);
    padding: 0.75rem 1rem;
    display: flex;
    gap: 0.5rem;
    border-bottom: 4px solid var(--success);
    flex-wrap: wrap;
    overflow-x: auto;
}

@media (min-width: 768px) {
    .admin-nav {
        padding: 1rem 2rem;
        gap: 1.5rem;
    }
}

.admin-nav a {
    color: white;
    text-decoration: none;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    transition: all 0.3s;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    white-space: nowrap;
}

@media (min-width: 768px) {
    .admin-nav a {
        padding: 0.75rem 1.25rem;
        font-size: 1rem;
    }
}

.admin-nav a:hover, .admin-nav a.active {
    background: var(--success);
    transform: translateY(-2px);
}

/* PAGE HEADER */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 768px) {
    .page-header {
        flex-direction: row;
        justify-content: space-between;
    }
}

/* BOTONES */
.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    transition: all 0.3s;
    font-size: 0.9rem;
    white-space: nowrap;
}

.btn-primary { 
    background: var(--info); 
    color: white; 
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
}

.btn-success { 
    background: var(--success); 
    color: white;
    box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
}

.btn-danger { 
    background: var(--danger); 
    color: white;
    box-shadow: 0 2px 8px rgba(231, 76, 60, 0.3);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

/* STATUS FACIAL */
.facial-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-left: 1rem;
}

.facial-status.registered {
    background: var(--success);
    color: white;
}

.facial-status.not-registered {
    background: var(--warning);
    color: white;
}

/* STATS GRID */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.3s;
}

.stat-card:hover {
    transform: translateY(-3px);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-content {
    flex: 1;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
}

.progress-container {
    margin-top: 0.5rem;
}

.progress-bar {
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

/* COLORES PARA ICONOS */
.icon-primary { background: rgba(52, 152, 219, 0.1); color: var(--info); }
.icon-success { background: rgba(39, 174, 96, 0.1); color: var(--success); }
.icon-warning { background: rgba(243, 156, 18, 0.1); color: var(--warning); }
.icon-danger { background: rgba(231, 76, 60, 0.1); color: var(--danger); }
.icon-info { background: rgba(52, 152, 219, 0.1); color: var(--info); }
.icon-purple { background: rgba(155, 89, 182, 0.1); color: #9b59b6; }

/* COLORES PARA FONDOS */
.bg-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
.bg-success { background: linear-gradient(135deg, var(--success), #2ecc71); }
.bg-warning { background: linear-gradient(135deg, var(--warning), #f1c40f); }
.bg-danger { background: linear-gradient(135deg, var(--danger), #c0392b); }
.bg-info { background: linear-gradient(135deg, var(--info), #2980b9); }
.bg-purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); }

/* CARDS */
.card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    overflow: hidden;
    margin-bottom: 2rem;
    transition: transform 0.3s;
}

.card:hover {
    transform: translateY(-5px);
}

.card-header {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    padding: 1.5rem;
}

.card-body {
    padding: 1.5rem;
}

/* FORMULARIOS */
.form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 768px) {
    .form-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.form-group {
    margin-bottom: 1rem;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: var(--dark);
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: var(--info);
}

.form-control[readonly] {
    background-color: #f8f9fa;
    cursor: not-allowed;
    opacity: 0.7;
}

textarea.form-control {
    min-height: 120px;
    resize: vertical;
}

.form-actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 1rem;
}

/* TABLAS */
.table-container {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.table th, .table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #555;
}

.table tr:hover {
    background: #f8f9fa;
}

/* BADGES */
.badge {
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-success { background: #d4edda; color: #155724; }
.badge-danger { background: #f8d7da; color: #721c24; }
.badge-warning { background: #fff3cd; color: #856404; }
.badge-info { background: #cce7ff; color: #004085; }
.badge-primary { background: #d1ecf1; color: #0c5460; }
.badge-secondary { background: #e9ecef; color: #495057; }

.urgency-low { background: #d4edda; color: #155724; }
.urgency-medium { background: #fff3cd; color: #856404; }
.urgency-high { background: #f8d7da; color: #721c24; }

/* ACCIONES */
.actions {
    display: flex;
    gap: 0.5rem;
}

.action-btn {
    padding: 0.5rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.edit-btn { background: var(--warning); color: white; }
.delete-btn { background: var(--danger); color: white; }

.action-btn:hover {
    transform: translateY(-2px);
}

/* ALERTS */
.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* EMPTY STATE */
.empty-state {
    text-align: center;
    padding: 3rem;
    color: #666;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    color: #ddd;
}

/* UTILIDADES */
.need-preview {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.ticket-id {
    font-family: monospace;
    font-weight: 600;
    color: var(--primary);
}

.field-info {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* FORM NOTES */
.disabled-form {
    opacity: 0.7;
    pointer-events: none;
}

.form-note {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

.form-note.warning {
    background: #f8d7da;
    border-color: #f5c6cb;
}

.form-note.info {
    background: #cce7ff;
    border-color: #99ceff;
}

.form-note.success {
    background: #d4edda;
    border-color: #c3e6cb;
}

/* DATE INFO */
.date-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.date-info-item {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}

.date-info-label {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 0.5rem;
}

.date-info-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #495057;
}

.date-info-value.empty {
    color: #adb5bd;
    font-style: italic;
}

/* AUTO REFRESH */
.auto-refresh-indicator {
    position: fixed;
    top: 10px;
    right: 10px;
    background: var(--success);
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.refresh-countdown {
    font-weight: bold;
    background: rgba(255,255,255,0.2);
    padding: 0.2rem 0.5rem;
    border-radius: 10px;
    min-width: 30px;
    text-align: center;
}

/* MODAL */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
    animation: fadeIn 0.3s ease;
}

.modal-content {
    background: white;
    border-radius: 20px;
    padding: 2.5rem;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    border: 4px solid var(--warning);
    animation: slideUp 0.4s ease;
    text-align: center;
    position: relative;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { 
        opacity: 0;
        transform: translateY(30px) scale(0.9);
    }
    to { 
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-icon {
    font-size: 4rem;
    color: var(--warning);
    margin-bottom: 1.5rem;
}

.modal-title {
    font-size: 1.8rem;
    font-weight: bold;
    margin-bottom: 1rem;
    color: var(--primary);
}

.modal-message {
    color: #666;
    margin-bottom: 2rem;
    line-height: 1.6;
    font-size: 1.1rem;
}

.modal-buttons {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
}

.modal-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    font-size: 1rem;
    min-width: 140px;
    justify-content: center;
}

.modal-btn-primary {
    background: var(--success);
    color: white;
}

.modal-btn-primary:hover {
    background: #219653;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(39, 174, 96, 0.4);
}

.modal-btn-secondary {
    background: var(--secondary);
    color: white;
}

.modal-btn-secondary:hover {
    background: #2c3e50;
    transform: translateY(-2px);
}

.modal-btn-close {
    background: var(--danger);
    color: white;
}

.modal-btn-close:hover {
    background: #c0392b;
    transform: translateY(-2px);
}

/* FACE BUTTON */
.btn-Face {
    background: linear-gradient(135deg, #f39c12, #e67e22); 
    color: white; 
    padding: 10px 20px; 
    border-radius: 8px; 
    text-decoration: none; 
    font-size: 0.9rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(243, 156, 18, 0.3);
    border: none;
    cursor: pointer;
}

/* FOOTER */
footer {
    background: transparent;
    padding: 0.5rem 1rem;
    text-align: center;
    color: #adb5bd;
    font-size: 0.75rem;
    margin-top: 1rem;
}
</style>
</head>
<body>
    <!-- Auto Refresh Indicator -->
  

    <!-- Modal for facial registration -->
    <?php if($show_popup): ?>
    <div class="modal-overlay" id="facialModal">
        <div class="modal-content">
            <div class="modal-icon">
                <i class="fas fa-user-plus"></i>
            </div>
            <h2 class="modal-title">Improve Your Security!</h2>
            <p class="modal-message">
                You don't have your face registered in the system. Facial recognition allows you to access faster and more securely.<br><br>
                <strong>Current login:</strong> <?php echo $_SESSION['login_count']; ?>
            </p>
            <div class="modal-buttons">
                <a href="../add_face.php" class="modal-btn modal-btn-primary">
                    <i class="fas fa-camera"></i> Register Face
                </a>
                <button onclick="closeModal()" class="modal-btn modal-btn-close">
                    <i class="fas fa-times"></i> Remind Later
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

       <!-- Header -->
<header class="admin-header">
    <div class="header-content">
        <h1>
            <i class="fas fa-ticket-alt"></i> Ticket Management
            <span class="department-badge">
                <i class="fas fa-building"></i> <?php echo strtoupper($user_department); ?>
            </span>
        </h1>
        <p style="opacity: 0.9; font-size: 0.9rem;">
            Welcome, <strong><?php echo htmlspecialchars($_SESSION['technician_name']); ?></strong> • 
            Department: <strong><?php echo htmlspecialchars($user_department); ?></strong> • 
            <?php echo date('m/d/Y H:i:s'); ?>
            <?php if(!$has_facial_data): ?>
                <span class="facial-status not-registered">
                    <i class="fas fa-exclamation-triangle"></i> Face Not Registered
                </span>
            <?php else: ?>
                <span class="facial-status registered">
                    <i class="fas fa-check-circle"></i> Face Registered
                </span>
            <?php endif; ?>
        </p>
    </div>
    <div>
        <a href="../logout.php" class="btn btn-danger">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</header>

<!-- Navigation -->
<nav class="admin-nav">
    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Home</a>
    <a href="tickets.php" class="active"><i class="fas fa-ticket-alt"></i> Tickets</a>
    <a href="questions.php"><i class="fas fa-question-circle"></i> Questions</a>
    <a href="history.php"><i class="fas fa-history"></i> History</a>
    <a href="reports.php"><i class="fas fa-chart-bar"></i> Conversations Dashboard</a>
    <a href="reportetikets.php"><i class="fas fa-chart-bar"></i> Tickets Dashboard</a>
</nav>

    <!-- Main Content -->
    <div class="admin-container">
        <!-- Page Header -->
        <div class="page-header">
            <h2 style="font-size: 2rem;">
                <i class="fas fa-ticket-alt"></i> <?php echo $page_title; ?>
            </h2>
            <?php if(isset($_GET['edit'])): ?>
                <a href="tickets.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to list
                </a>
            <?php else: ?>
                <button onclick="refreshTable()" class="btn btn-primary">
                    <i class="fas fa-sync-alt"></i> Refresh Now
                </button>
            <?php endif; ?>
        </div>

        <!-- Alert messages -->
        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if(isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Informational Cards -->
        <div class="stats-grid">
            <!-- Total Department Tickets -->
            <div class="stat-card">
                <div class="stat-icon icon-primary">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                    <div class="stat-label">Total Tickets</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill bg-primary" style="width: 100%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Open Department Tickets -->
            <div class="stat-card">
                <div class="stat-icon icon-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['open_tickets']; ?></div>
                    <div class="stat-label">Open Tickets</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill bg-warning" style="width: <?php echo $stats['total_tickets'] > 0 ? ($stats['open_tickets'] / $stats['total_tickets'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- In Process Department -->
            <div class="stat-card">
                <div class="stat-icon icon-info">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['process_tickets']; ?></div>
                    <div class="stat-label">In Process</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill bg-info" style="width: <?php echo $stats['total_tickets'] > 0 ? ($stats['process_tickets'] / $stats['total_tickets'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Closed Department Tickets -->
            <div class="stat-card">
                <div class="stat-icon icon-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['closed_tickets']; ?></div>
                    <div class="stat-label">Closed Tickets</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill bg-success" style="width: <?php echo $stats['total_tickets'] > 0 ? ($stats['closed_tickets'] / $stats['total_tickets'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Today's Department Tickets -->
            <div class="stat-card">
                <div class="stat-icon icon-purple">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['today_tickets']; ?></div>
                    <div class="stat-label">Today's Tickets</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill bg-purple" style="width: <?php echo $stats['today_tickets'] > 0 ? 100 : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Department Resolution Rate -->
            <div class="stat-card">
                <div class="stat-icon icon-danger">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number">
                        <?php 
                            $resolution_rate = $stats['total_tickets'] > 0 ? round(($stats['closed_tickets'] / $stats['total_tickets']) * 100, 1) : 0;
                            echo $resolution_rate . '%';
                        ?>
                    </div>
                    <div class="stat-label">Resolution Rate</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill bg-danger" style="width: <?php echo $resolution_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ticket Edit Form -->
        <?php if(isset($_GET['edit']) && !empty($_GET['edit'])): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-edit"></i> Edit Ticket - <?php echo htmlspecialchars($user_department); ?></h3>
            </div>
            <div class="card-body">
                <?php if($is_ticket_closed): ?>
                <div class="form-note warning">
                    <i class="fas fa-lock"></i> <strong>Ticket Closed</strong> - This ticket is closed and cannot be modified.
                </div>
                <?php endif; ?>
                
                <!-- Date information -->
                <div class="form-note info">
                    <i class="fas fa-info-circle"></i> <strong>Ticket Information</strong>
                    <div class="date-info-grid">
                        <div class="date-info-item">
                            <div class="date-info-label">Creation Date</div>
                            <div class="date-info-value">
                                <?php echo date('d/m/Y H:i', strtotime($display_data['creation_date'])); ?>
                            </div>
                        </div>
                        <div class="date-info-item">
                            <div class="date-info-label">Assignment Date</div>
                            <div class="date-info-value <?php echo empty($display_data['date_assign']) ? 'empty' : ''; ?>">
                                <?php echo !empty($display_data['date_assign']) ? date('d/m/Y H:i', strtotime($display_data['date_assign'])) : 'Not assigned'; ?>
                            </div>
                        </div>
                        <div class="date-info-item">
                            <div class="date-info-label">Process Start Date</div>
                            <div class="date-info-value <?php echo empty($display_data['process_date']) ? 'empty' : ''; ?>">
                                <?php echo !empty($display_data['process_date']) ? date('d/m/Y H:i', strtotime($display_data['process_date'])) : 'Not started'; ?>
                            </div>
                        </div>
                        <div class="date-info-item">
                            <div class="date-info-label">Closing Date</div>
                            <div class="date-info-value <?php echo empty($display_data['date_done']) ? 'empty' : ''; ?>">
                                <?php echo !empty($display_data['date_done']) ? date('d/m/Y H:i', strtotime($display_data['date_done'])) : 'Not closed'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Note about automatic assignment -->
                <?php if(!$is_ticket_closed && empty($display_data['technicians_assign'])): ?>
                <div class="form-note success">
                    <i class="fas fa-robot"></i> <strong>Automatic Assignment</strong> - When assigning a technician, the status will automatically change to "In Process".
                </div>
                <?php endif; ?>
                
                <form method="POST" action="tickets.php" <?php echo $is_ticket_closed ? 'class="disabled-form"' : ''; ?> id="ticketForm">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($display_data['id']); ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="query_id"><i class="fas fa-hashtag"></i> Query ID</label>
                            <input type="number" id="query_id" name="query_id" class="form-control" 
                                   value="<?php echo htmlspecialchars($display_data['query_id']); ?>" 
                                   readonly>
                            <div class="field-info">
                                <i class="fas fa-lock"></i> Read-only field
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="urgency"><i class="fas fa-exclamation-triangle"></i> Urgency Level *</label>
                            <select id="urgency" name="urgency" class="form-control" required <?php echo $is_ticket_closed ? 'disabled' : ''; ?>>
                                <?php foreach($urgency_levels as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" 
                                        <?php echo ($display_data['urgency'] == $value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="department"><i class="fas fa-building"></i> Department</label>
                            <input type="text" id="department" class="form-control" 
                                   value="<?php echo htmlspecialchars($departments[$display_data['department']] ?? $display_data['department']); ?>" 
                                   readonly>
                            <input type="hidden" name="department" value="<?php echo htmlspecialchars($display_data['department']); ?>">
                            <div class="field-info">
                                <i class="fas fa-lock"></i> You cannot change the department
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="status"><i class="fas fa-tasks"></i> Status *</label>
                            <select id="status" name="status" class="form-control" required <?php echo $is_ticket_closed ? 'disabled' : ''; ?>>
                                <?php foreach($statuses as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" 
                                        <?php echo ($display_data['status'] == $value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-info">
                                <i class="fas fa-info-circle"></i> 
                                Cannot set "In Process" without assigned technician
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="technicians_assign"><i class="fas fa-user-cog"></i> Assign Technician *</label>
                            <select id="technicians_assign" name="technicians_assign" class="form-control" required <?php echo $is_ticket_closed ? 'disabled' : ''; ?>>
                                <option value="">-- Select Technician --</option>
                                <?php foreach($technicians as $tech): ?>
                                    <option value="<?php echo htmlspecialchars($tech['id']); ?>" 
                                        <?php echo ($display_data['technicians_assign'] == $tech['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tech['name'] . ' (' . $tech['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-info">
                                <i class="fas fa-robot"></i> 
                                When assigning a technician, status will automatically change to "In Process"
                            </div>
                        </div>

                        <!-- NUEVO CAMPO LAST_USER AÑADIDO -->
                      <div class="form-group">
                    
                        <input type="hidden" id="last_user" name="last_user" class="form-control" 
                               value="<?php echo htmlspecialchars($_SESSION['technician_name']); ?>" 
                               required readonly <?php echo $is_ticket_closed ? 'disabled' : ''; ?>>
                       
                    </div>

                        <div class="form-group full-width">
                            <label for="need"><i class="fas fa-comment-dots"></i> Need/Description *</label>
                            <textarea id="need" name="need" class="form-control" 
                                      placeholder="Describe in detail the need or problem..."readonly
                                      required <?php echo $is_ticket_closed ? 'readonly' : ''; ?>><?php echo htmlspecialchars($display_data['need']); ?></textarea >
                        </div>

                        <!-- NUEVO CAMPO RESPONSE AÑADIDO -->
                        <div class="form-group full-width">
                            <label for="response"><i class="fas fa-reply"></i> Response/Solution</label>
                            <textarea id="response" name="response" class="form-control" 
                                      placeholder="Enter the response or solution to the ticket..."
                                      <?php echo $is_ticket_closed ? 'readonly' : ''; ?>><?php echo htmlspecialchars($display_data['response']); ?></textarea>
                            <div class="field-info">
                                <i class="fas fa-info-circle"></i> 
                                Optional field to document the response or solution provided
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="tickets.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <?php if(!$is_ticket_closed): ?>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Ticket
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
  <div class="" id="refreshIndicator">
        <i class="fas fa-sync-alt"></i>
        Auto-refresh in: <span class="refresh-countdown" id="countdown">10</span>s
    </div><br>
        <!-- Department Tickets List -->
        <div id="ticketsTableContainer">
            <?php 
            // Include the table directly here to avoid creating separate file
            if(count($tickets) > 0): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Ticket List - <?php echo htmlspecialchars($user_department); ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Query</th>
                                        <th>Need</th>
                                        <th>Urgency</th>
                                        <th>Status</th>
                                        <th>Response</th>
                                        <th>Last User</th> <!-- NUEVA COLUMNA AÑADIDA -->
                                        <th>Assigned Technician</th>
                                        <th>Creation Date</th>
                                        <th>Process Start</th>
                                        <th>Closing</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($tickets as $tkt): 
                                        // Get assigned technician name
                                        $assigned_tech_name = '';
                                        if (!empty($tkt['technicians_assign'])) {
                                            foreach($technicians as $tech) {
                                                if ($tech['id'] == $tkt['technicians_assign']) {
                                                    $assigned_tech_name = $tech['name'];
                                                    break;
                                                }
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td class="ticket-id">#<?php echo htmlspecialchars($tkt['id']); ?></td>
                                        <td>
                                            <?php if(!empty($tkt['query_id'])): ?>
                                                <span class="badge badge-primary">#<?php echo htmlspecialchars($tkt['query_id']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="need-preview" title="<?php echo htmlspecialchars($tkt['need']); ?>">
                                                <?php echo htmlspecialchars(substr($tkt['need'], 0, 50)) . (strlen($tkt['need']) > 50 ? '...' : ''); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                                $urgency_label = $urgency_levels[$tkt['urgency']] ?? $tkt['urgency'];
                                                $urgency_class = 'urgency-' . str_replace('_urg', '', $tkt['urgency']);
                                            ?>
                                            <span class="badge <?php echo $urgency_class; ?>">
                                                <?php echo htmlspecialchars($urgency_label); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                                $status_class = '';
                                                switch($tkt['status']) {
                                                    case 'open': $status_class = 'badge-warning'; break;
                                                    case 'in_process': $status_class = 'badge-primary'; break;
                                                    case 'closed': $status_class = 'badge-success'; break;
                                                    default: $status_class = 'badge-secondary';
                                                }
                                            ?>
                                            <span class="badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($statuses[$tkt['status']] ?? $tkt['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if(!empty($tkt['response'])): ?>
                                                <div class="need-preview" title="<?php echo htmlspecialchars($tkt['response']); ?>">
                                                    <?php echo htmlspecialchars(substr($tkt['response'], 0, 50)) . (strlen($tkt['response']) > 50 ? '...' : ''); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($tkt['last_user'])): ?>
                                                <span class="badge badge-secondary"><?php echo htmlspecialchars($tkt['last_user']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($assigned_tech_name)): ?>
                                                <span class="badge badge-secondary"><?php echo htmlspecialchars($assigned_tech_name); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($tkt['creation_date'])); ?></td>
                                        <td>
                                            <?php if(!empty($tkt['process_date'])): ?>
                                                <?php echo date('d/m/Y H:i', strtotime($tkt['process_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($tkt['date_done'])): ?>
                                                <?php echo date('d/m/Y H:i', strtotime($tkt['date_done'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <!-- Only edit button -->
                                                <a href="tickets.php?edit=<?php echo $tkt['id']; ?>" 
                                                   class="action-btn edit-btn" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <div class="empty-state">
                            <i class="fas fa-ticket-alt"></i>
                            <h3>No tickets in <?php echo htmlspecialchars($user_department); ?></h3>
                            <p>Currently there are no tickets registered in your department.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Minimalist footer -->
    <footer style="
        background: transparent;
        padding: 0.5rem 1rem;
        text-align: center;
        color: #adb5bd;
        font-size: 0.75rem;
        margin-top: 1rem;
    ">
        <div>Created by Erick Dumas &copy; <?php echo date('Y'); ?> | v1.0.0 | Department: <?php echo htmlspecialchars($user_department); ?></div>
    </footer>
   <script>
        // Function to close the modal
        function closeModal() {
            const modal = document.getElementById('facialModal');
            if (modal) {
                modal.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => {
                    modal.remove();
                }, 300);
            }
        }

        // Close modal with ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Close modal by clicking outside
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('facialModal');
            if (modal && e.target === modal) {
                closeModal();
            }
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('ticketForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const need = document.getElementById('need').value.trim();
                    const urgency = document.getElementById('urgency').value;
                    const department = document.getElementById('department').value;
                    const status = document.getElementById('status').value;
                    const technician = document.getElementById('technicians_assign').value;
                    const lastUser = document.getElementById('last_user').value.trim();

                    if(!need || !urgency || !department || !status || !technician || !lastUser) {
                        e.preventDefault();
                        alert('Please complete all required fields (*)');
                        return false;
                    }

                    if(need.length < 10) {
                        e.preventDefault();
                        alert('The need description must be at least 10 characters long');
                        return false;
                    }

                    // Specific validation: do not allow "in_process" without technician
                    if (status === 'in_process' && !technician) {
                        e.preventDefault();
                        alert('Cannot set status "In Process" without an assigned technician');
                        return false;
                    }
                });

                // Automatic status change when assigning technician
                const technicianSelect = document.getElementById('technicians_assign');
                const statusSelect = document.getElementById('status');
                
                if (technicianSelect && statusSelect) {
                    technicianSelect.addEventListener('change', function() {
                        if (this.value && statusSelect.value === 'open') {
                            statusSelect.value = 'in_process';
                            
                            // Show informative message
                            const existingNote = document.querySelector('.assignment-note');
                            if (!existingNote) {
                                const note = document.createElement('div');
                                note.className = 'form-note success assignment-note';
                                note.innerHTML = '<i class="fas fa-info-circle"></i> Status has been automatically changed to "In Process" because a technician was assigned.';
                                technicianSelect.parentNode.appendChild(note);
                                
                                // Remove message after 5 seconds
                                setTimeout(() => {
                                    note.remove();
                                }, 5000);
                            }
                        }
                    });
                }
            }
        });

        // Table auto-refresh every 10 seconds (only on main page)
        let refreshCountdown = 10;
        let refreshInterval;

        function startAutoRefresh() {
            // Only start auto-refresh if we are NOT in edit mode
            if (!window.location.href.includes('edit=')) {
                refreshInterval = setInterval(function() {
                    refreshCountdown--;
                    document.getElementById('countdown').textContent = refreshCountdown;
                    
                    if (refreshCountdown <= 0) {
                        refreshTable();
                        refreshCountdown = 10;
                    }
                }, 1000);
            } else {
                // Hide indicator if we are in edit mode
                document.getElementById('refreshIndicator').style.display = 'none';
            }
        }

        function refreshTable() {
            // Reload the entire page to update data
            location.reload();
        }

        function showRefreshNotification() {
            const notification = document.createElement('div');
            notification.className = 'alert alert-success';
            notification.style.position = 'fixed';
            notification.style.top = '60px';
            notification.style.right = '20px';
            notification.style.zIndex = '1000';
            notification.innerHTML = '<i class="fas fa-check-circle"></i> Table updated successfully';
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Start auto-refresh when page loads
        document.addEventListener('DOMContentLoaded', function() {
            startAutoRefresh();
        });

        // Pause auto-refresh when window is not visible
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearInterval(refreshInterval);
            } else {
                startAutoRefresh();
            }
        });

        // Add fadeOut animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeOut {
                from { opacity: 1; }
                to { opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>