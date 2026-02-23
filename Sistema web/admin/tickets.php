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

// Verificar si el usuario está logueado y es Admin
if(!isset($_SESSION['technician_id']) || $_SESSION['technician_role'] != 'Admin'){
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

// Variables para el formulario - USANDO LOS VALORES EXACTOS DE LA BD
$ticket = [
    'id' => '',
    'query_id' => '',
    'need' => '',
    'urgency' => 'medium_urg',
    'department' => 'dep_it',
    'status' => 'open',
    'response' => '', // NUEVO CAMPO AÑADIDO
    'creation_date' => date('Y-m-d H:i:s'),
    'update_date' => date('Y-m-d H:i:s')
];

$action = 'create';
$page_title = 'Created Ticket';

// Definir enums - VALORES EXACTOS DE LA BD
$urgency_levels = [
    'low_urg' => 'Low Urgency',
'medium_urg' => 'Medium Urgency', 
'high_urg' => 'High Urgency'
];

$departments = [
    'dep_it' => 'Tecnología/Systems',
    'dep_hr' => 'Recursos Humanos',
    'dep_finance' => 'Finanzas',
    'dep_marketing' => 'Marketing',
    'dep_sales' => 'Ventas',
    'dep_operations' => 'Operaciones',
    'dep_support' => 'Soporte',
    'dep_other' => 'Otro'
];

$statuses = [
    'open' => 'Open',
    'in_process' => 'In Process',
    'closed' => 'Closed'
];

// Separar los datos del formulario de los datos de la BD
$form_data = $ticket;

// Obtener estadísticas para las cards informativas
try {
    // Total de tickets
    $stmt_total = $db->prepare("SELECT COUNT(*) as total FROM tickets");
    $stmt_total->execute();
    $stats['total_tickets'] = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

    // Tickets abiertos
    $stmt_open = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE status = 'open'");
    $stmt_open->execute();
    $stats['open_tickets'] = $stmt_open->fetch(PDO::FETCH_ASSOC)['total'];

    // Tickets en proceso
    $stmt_process = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE status = 'in_process'");
    $stmt_process->execute();
    $stats['process_tickets'] = $stmt_process->fetch(PDO::FETCH_ASSOC)['total'];

    // Tickets cerrados
    $stmt_closed = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE status = 'closed'");
    $stmt_closed->execute();
    $stats['closed_tickets'] = $stmt_closed->fetch(PDO::FETCH_ASSOC)['total'];

    // Tickets por urgencia
    $stmt_urgency = $db->prepare("SELECT urgency, COUNT(*) as total FROM tickets GROUP BY urgency");
    $stmt_urgency->execute();
    $stats['urgency_stats'] = $stmt_urgency->fetchAll(PDO::FETCH_ASSOC);

    // Tickets por departamento
    $stmt_dept = $db->prepare("SELECT department, COUNT(*) as total FROM tickets GROUP BY department");
    $stmt_dept->execute();
    $stats['dept_stats'] = $stmt_dept->fetchAll(PDO::FETCH_ASSOC);

    // Tickets de hoy
    $stmt_today = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE DATE(creation_date) = CURDATE()");
    $stmt_today->execute();
    $stats['today_tickets'] = $stmt_today->fetch(PDO::FETCH_ASSOC)['total'];

} catch (Exception $e) {
    $stats = [
        'total_tickets' => 0,
        'open_tickets' => 0,
        'process_tickets' => 0,
        'closed_tickets' => 0,
        'today_tickets' => 0,
        'urgency_stats' => [],
        'dept_stats' => []
    ];
}

// Procesar formulario
if($_POST){
    $form_data = [
        'id' => $_POST['id'] ?? '',
        'query_id' => $_POST['query_id'] ?? '',
        'need' => $_POST['need'] ?? '',
        'urgency' => $_POST['urgency'] ?? 'medium_urg',
        'department' => $_POST['department'] ?? 'dep_it',
        'status' => $_POST['status'] ?? 'open',
        'response' => $_POST['response'] ?? '' // NUEVO CAMPO AÑADIDO
    ];

    if(isset($_POST['id']) && !empty($_POST['id'])){
        $action = 'update';
        $query = "UPDATE tickets SET 
                  need = :need, 
                  urgency = :urgency, 
                  department = :department, 
                  status = :status,
                  response = :response, 
                  update_date = NOW() 
                  WHERE id = :id";
    } else {
        $query = "INSERT INTO tickets 
                  (query_id, need, urgency, department, status, response, creation_date, update_date) 
                  VALUES 
                  (:query_id, :need, :urgency, :department, :status, :response, NOW(), NOW())";
    }

    try {
        $stmt = $db->prepare($query);
        
        if($action == 'update' && !empty($form_data['id'])){
            $stmt->bindParam(':id', $form_data['id']);
        }
        
        if($action == 'create'){
            $stmt->bindParam(':query_id', $form_data['query_id']);
        }
        
        $stmt->bindParam(':need', $form_data['need']);
        $stmt->bindParam(':urgency', $form_data['urgency']);
        $stmt->bindParam(':department', $form_data['department']);
        $stmt->bindParam(':status', $form_data['status']);
        $stmt->bindParam(':response', $form_data['response']); // NUEVO CAMPO AÑADIDO
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "Ticket " . ($action == 'create' ? 'creado' : 'actualizado') . " correctamente";
            header("Location: tickets.php");
            exit();
        } else {
            throw new Exception("Error en ejecución");
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al guardar el ticket: " . $e->getMessage();
        $ticket = $form_data;
    }
}

// Editar ticket
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Ticket';
    
    if(!$_POST){
        $query = "SELECT * FROM tickets WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $_GET['edit']);
        
        try {
            $stmt->execute();
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if(!$ticket){
                $_SESSION['error_message'] = "Ticket no encontrado";
                header("Location: tickets.php");
                exit();
            }
            
            $ticket['query_id'] = $ticket['query_id'] ?? '';
            $ticket['need'] = $ticket['need'] ?? '';
            $ticket['urgency'] = $ticket['urgency'] ?? 'medium_urg';
            $ticket['department'] = $ticket['department'] ?? 'dep_it';
            $ticket['status'] = $ticket['status'] ?? 'open';
            $ticket['response'] = $ticket['response'] ?? ''; // NUEVO CAMPO AÑADIDO
            
            $form_data = $ticket;
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error al cargar el ticket: " . $e->getMessage();
            header("Location: tickets.php");
            exit();
        }
    }
}

// Eliminar ticket
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $query = "DELETE FROM tickets WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['delete']);
    
    try {
        if($stmt->execute()){
            $_SESSION['success_message'] = "Ticket eliminado correctamente";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al eliminar el ticket: " . $e->getMessage();
    }
    
    header("Location: tickets.php");
    exit();
}

// Obtener lista de tickets
try {
    $query = "SELECT * FROM tickets ORDER BY creation_date DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tickets = [];
    $_SESSION['error_message'] = "Error al cargar la lista de tickets: " . $e->getMessage();
}

$display_data = $_POST ? $form_data : $ticket;
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

        /* Modal/Popup Styles */
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

        .login-count-badge {
            background: var(--info);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-left: 1rem;
            font-weight: 600;
        }

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

        .session-timer {
            background: var(--warning);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-left: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

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

        /* Rest of existing styles... */
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

        .page-header {
            display: flex;
            justify-content: between;
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
        }

        .btn-primary { 
            background: var(--info); 
            color: white; 
        }

        .btn-success { 
            background: var(--success); 
            color: white;
        }

        .btn-danger { 
            background: var(--danger); 
            color: white;
        }

        .btn-warning { 
            background: var(--warning); 
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

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

        /* Informational Cards */
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

        .stat-trend {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .trend-up { background: #d4edda; color: #155724; }
        .trend-down { background: #f8d7da; color: #721c24; }

        /* Colors for cards */
        .bg-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .bg-success { background: linear-gradient(135deg, var(--success), #2ecc71); color: white; }
        .bg-warning { background: linear-gradient(135deg, var(--warning), #f1c40f); color: white; }
        .bg-danger { background: linear-gradient(135deg, var(--danger), #c0392b); color: white; }
        .bg-info { background: linear-gradient(135deg, var(--info), #2980b9); color: white; }
        .bg-purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; }

        .icon-primary { background: rgba(52, 152, 219, 0.1); color: var(--info); }
        .icon-success { background: rgba(39, 174, 96, 0.1); color: var(--success); }
        .icon-warning { background: rgba(243, 156, 18, 0.1); color: var(--warning); }
        .icon-danger { background: rgba(231, 76, 60, 0.1); color: var(--danger); }
        .icon-info { background: rgba(52, 152, 219, 0.1); color: var(--info); }
        .icon-purple { background: rgba(155, 89, 182, 0.1); color: #9b59b6; }

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
    </style>
</head>
<body>
    <!-- Facial Registration Modal -->
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
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-ticket-alt"></i> Ticket Management
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Welcome, <strong><?php echo htmlspecialchars($_SESSION['technician_role']); ?></strong> • 
                <?php echo date('d/m/Y H:i:s'); ?>
               
               
                
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
        <a href="users.php"><i class="fas fa-users"></i> Users</a>
        <a href="departments.php"><i class="fas fa-building"></i> Departments</a>
        <a href="tickets.php" class="active"><i class="fas fa-ticket-alt"></i> Tickets</a>
        <a href="urgency.php"><i class="fas fa-exclamation-triangle"></i> Urgency</a>
        <a href="technicians.php"><i class="fas fa-tools"></i> WhiteList</a>
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
            <a href="tickets.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
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

        <!-- Facial registration banner -->
        <?php if(!$has_facial_data): ?>
       
        <?php endif; ?>

        <!-- Informational Cards -->
        <div class="stats-grid">
            <!-- Total Tickets -->
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

            <!-- Open Tickets -->
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

            <!-- In Process -->
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

            <!-- Closed Tickets -->
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

            <!-- Today's Tickets -->
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

            <!-- Resolution Rate -->
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

        <!-- Ticket Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-ticket-alt"></i> Ticket Information</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="tickets.php">
                    <?php if($action == 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($display_data['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="query_id"><i class="fas fa-hashtag"></i> Query ID</label>
                            <?php if($action == 'update'): ?>
                                <input type="number" id="query_id" name="query_id" class="form-control" 
                                       value="<?php echo htmlspecialchars($display_data['query_id']); ?>" 
                                       readonly>
                                <div class="field-info">
                                    <i class="fas fa-lock"></i> Read-only field in edit mode
                                </div>
                            <?php else: ?>
                                <input type="number" id="query_id" name="query_id" class="form-control" 
                                       value="<?php echo htmlspecialchars($display_data['query_id']); ?>" 
                                       min="1" placeholder="Related query number">
                                <div class="field-info">
                                    <i class="fas fa-info-circle"></i> Optional ID of related query
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="urgency"><i class="fas fa-exclamation-triangle"></i> Urgency Level *</label>
                            <select id="urgency" name="urgency" class="form-control" required>
                                <?php foreach($urgency_levels as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" 
                                        <?php echo ($display_data['urgency'] == $value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="department"><i class="fas fa-building"></i> Department *</label>
                            <select id="department" name="department" class="form-control" required>
                                <?php foreach($departments as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" 
                                        <?php echo ($display_data['department'] == $value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-info">
                                <i class="fas fa-sync-alt"></i> You can modify the department
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="status"><i class="fas fa-tasks"></i> Status *</label>
                            <select id="status" name="status" class="form-control" required>
                                <?php foreach($statuses as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" 
                                        <?php echo ($display_data['status'] == $value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-info">
                                <i class="fas fa-sync-alt"></i> You can modify the status
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="need"><i class="fas fa-comment-dots"></i> Need/Description *</label>
                            <textarea id="need" name="need" class="form-control" 
                                      placeholder="Describe in detail the need or problem..."
                                      required><?php echo htmlspecialchars($display_data['need']); ?></textarea>
                            <div class="field-info">
                                <i class="fas fa-info-circle"></i> Clearly describe what you need or the problem to solve
                            </div>
                        </div>

                        <!-- NUEVO CAMPO RESPONSE AÑADIDO -->
                        <div class="form-group full-width">
                            <label for="response"><i class="fas fa-reply"></i> Response/Solution</label>
                            <textarea id="response" name="response" class="form-control" 
                                      placeholder="Enter the response or solution to the ticket..."><?php echo htmlspecialchars($display_data['response']); ?></textarea>
                            <div class="field-info">
                                <i class="fas fa-info-circle"></i> Optional field to document the response or solution provided
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="tickets.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> 
                            <?php echo $action == 'create' ? 'Create Ticket' : 'Update Ticket'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tickets List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Tickets List</h3>
            </div>
            <div class="card-body">
                <?php if(count($tickets) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Query</th>
                                    <th>Need</th>
                                    <th>Urgency</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Response</th> <!-- NUEVA COLUMNA AÑADIDA -->
                                    <th>Creation Date</th>
                                    <th>Last Update</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($tickets as $tkt): ?>
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
                                            $department_label = $departments[$tkt['department']] ?? $tkt['department'];
                                        ?>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($department_label); ?>
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
                                    <td><?php echo date('d/m/Y H:i', strtotime($tkt['creation_date'])); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($tkt['update_date'])); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="tickets.php?edit=<?php echo $tkt['id']; ?>" 
                                               class="action-btn edit-btn" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="tickets.php?delete=<?php echo $tkt['id']; ?>" 
                                               class="action-btn delete-btn" 
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this ticket?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-ticket-alt"></i>
                        <h3>No tickets registered</h3>
                        <p>Start by adding the first ticket using the form above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Minimalist Footer -->
    <footer style="
        background: transparent;
        padding: 0.5rem 1rem;
        text-align: center;
        color: #adb5bd;
        font-size: 0.75rem;
        margin-top: 1rem;
    ">
        <div>Created by Erick Dumas &copy; <?php echo date('Y'); ?> | v1.0.0</div>
    </footer>

    <script>
        // Function to close modal
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

        // Session timer (3 hours = 10800 seconds)
        let sessionTime = 10800; // 3 hours in seconds

        function updateSessionTimer() {
            sessionTime--;
            
            if (sessionTime <= 0) {
                // Session expired - redirect to login
                window.location.href = '../login.php?error=session_expired';
                return;
            }
            
            // Calculate remaining hours, minutes and seconds
            const hours = Math.floor(sessionTime / 3600);
            const minutes = Math.floor((sessionTime % 3600) / 60);
            const seconds = sessionTime % 60;
            
            // Format time
            const timeString = 
                String(hours).padStart(2, '0') + ':' +
                String(minutes).padStart(2, '0') + ':' +
                String(seconds).padStart(2, '0');
            
            // Update display
            document.getElementById('timeRemaining').textContent = timeString;
            
            // Change color when less than 5 minutes remain
            const timerElement = document.getElementById('sessionTimer');
            if (sessionTime <= 300) { // 5 minutes
                timerElement.style.background = 'var(--danger)';
            } else if (sessionTime <= 900) { // 15 minutes
                timerElement.style.background = 'var(--warning)';
            }
        }

        // Start timer
        setInterval(updateSessionTimer, 1000);

        // Check user status every 30 seconds
        function checkUserStatus() {
            fetch('../check_user_status.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.active) {
                        // User inactive - logout
                        window.location.href = '../login.php?error=user_inactive';
                    }
                })
                .catch(error => {
                    console.error('Error checking user status:', error);
                });
        }

        // Check status every 30 seconds
        setInterval(checkUserStatus, 30000);

        // Confirmation before deleting
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.delete-btn');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if(!confirm('Are you sure you want to delete this ticket?')) {
                        e.preventDefault();
                    }
                });
            });
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const need = document.getElementById('need').value.trim();
            const urgency = document.getElementById('urgency').value;
            const department = document.getElementById('department').value;
            const status = document.getElementById('status').value;

            if(!need || !urgency || !department || !status) {
                e.preventDefault();
                alert('Please complete all required fields (*)');
                return false;
            }

            if(need.length < 10) {
                e.preventDefault();
                alert('The need description must be at least 10 characters long');
                return false;
            }
        });

        // Animation for cards
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
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