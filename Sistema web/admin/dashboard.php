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

// Verificación directa y forzada de datos faciales
$query_facial = "SELECT facial_data FROM technicians WHERE id = :id";
$stmt_facial = $db->prepare($query_facial);
$stmt_facial->bindParam(":id", $_SESSION['technician_id']);
$stmt_facial->execute();
$facial_result = $stmt_facial->fetch(PDO::FETCH_ASSOC);

// Determinar si tiene datos faciales
$has_facial_data = false;
if ($facial_result && !empty($facial_result['facial_data']) && $facial_result['facial_data'] != 'NULL') {
    $has_facial_data = true;
}

// Actualizar sesión con el valor real
$_SESSION['has_facial_data'] = $has_facial_data;

// Inicializar contador de logins si no existe
if (!isset($_SESSION['login_count'])) {
    $_SESSION['login_count'] = 0;
}

// Solo incrementar si es un nuevo acceso (evitar incremento múltiple)
if (!isset($_SESSION['login_incremented'])) {
    $_SESSION['login_count']++;
    $_SESSION['login_incremented'] = true;
}

// CORRECCIÓN: Mostrar popup solo si NO tiene datos faciales Y es cada 10 logins
$show_popup = !$has_facial_data && ($_SESSION['login_count'] > 0) && ($_SESSION['login_count'] % 10 === 0);

// Obtener estadísticas de todas las tablas
$stats = [];

// Usuarios - CORREGIDO: usando technicians en lugar de users
$query_users = "SELECT COUNT(*) as total FROM technicians";
$stmt_users = $db->prepare($query_users);
$stmt_users->execute();
$stats['total_users'] = $stmt_users->fetchColumn();

$query_active_users = "SELECT COUNT(*) as active FROM technicians WHERE active = 'yes'";
$stmt_active_users = $db->prepare($query_active_users);
$stmt_active_users->execute();
$stats['active_users'] = $stmt_active_users->fetchColumn();

$query_facial_users = "SELECT COUNT(*) as facial FROM technicians WHERE facial_data IS NOT NULL AND facial_data != 'NULL'";
$stmt_facial_users = $db->prepare($query_facial_users);
$stmt_facial_users->execute();
$stats['users_with_face'] = $stmt_facial_users->fetchColumn();

// Tickets
try {
    $query_tickets = "SELECT COUNT(*) as total FROM tickets";
    $stmt_tickets = $db->prepare($query_tickets);
    $stmt_tickets->execute();
    $stats['total_tickets'] = $stmt_tickets->fetchColumn();

    $query_open_tickets = "SELECT COUNT(*) as open FROM tickets WHERE status = 'open'";
    $stmt_open_tickets = $db->prepare($query_open_tickets);
    $stmt_open_tickets->execute();
    $stats['open_tickets'] = $stmt_open_tickets->fetchColumn();
} catch (Exception $e) {
    $stats['total_tickets'] = 0;
    $stats['open_tickets'] = 0;
}

// Departamentos
try {
    $query_departments = "SELECT COUNT(*) as total FROM departments";
    $stmt_departments = $db->prepare($query_departments);
    $stmt_departments->execute();
    $stats['total_departments'] = $stmt_departments->fetchColumn();
} catch (Exception $e) {
    $stats['total_departments'] = 0;
}

// Niveles de urgencia
try {
    $query_urgency = "SELECT COUNT(*) as total FROM urgency_levels";
    $stmt_urgency = $db->prepare($query_urgency);
    $stmt_urgency->execute();
    $stats['total_urgency_levels'] = $stmt_urgency->fetchColumn();
} catch (Exception $e) {
    $stats['total_urgency_levels'] = 0;
}

// Técnicos - CORREGIDO: usando technicians
try {
    $query_technicians = "SELECT COUNT(*) as total FROM technicians";
    $stmt_technicians = $db->prepare($query_technicians);
    $stmt_technicians->execute();
    $stats['total_technicians'] = $stmt_technicians->fetchColumn();
} catch (Exception $e) {
    $stats['total_technicians'] = 0;
}

// Preguntas
try {
    $query_questions = "SELECT COUNT(*) as total FROM questions";
    $stmt_questions = $db->prepare($query_questions);
    $stmt_questions->execute();
    $stats['total_questions'] = $stmt_questions->fetchColumn();
} catch (Exception $e) {
    $stats['total_questions'] = 0;
}

// Historial de consultas
try {
    $query_history = "SELECT COUNT(*) as total FROM query_history";
    $stmt_history = $db->prepare($query_history);
    $stmt_history->execute();
    $stats['total_query_history'] = $stmt_history->fetchColumn();
} catch (Exception $e) {
    $stats['total_query_history'] = 0;
}

// Obtener datos recientes de cada tabla
$recent_data = [];

// Usuarios recientes - CORREGIDO: usando technicians
$query_recent_users = "SELECT id, phone, name, email, role, registration_date FROM technicians ORDER BY registration_date DESC LIMIT 5";
$stmt_recent_users = $db->prepare($query_recent_users);
$stmt_recent_users->execute();
$recent_data['users'] = $stmt_recent_users->fetchAll(PDO::FETCH_ASSOC);

// El resto del código permanece igual...
// Tickets recientes
try {
    $query_recent_tickets = "SELECT 
                                id, 
                                query_id,
                                need as title, 
                                status, 
                                urgency, 
                                creation_date, 
                                department
                            FROM tickets 
                            ORDER BY creation_date DESC 
                            LIMIT 4";
    $stmt_recent_tickets = $db->prepare($query_recent_tickets);
    $stmt_recent_tickets->execute();
    $recent_data['tickets'] = $stmt_recent_tickets->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $recent_data['tickets'] = [];
    error_log("Error fetching recent tickets: " . $e->getMessage());
}

// Departamentos
try {
    $query_departments = "SELECT id, department_name, active,keywords FROM departments ORDER BY department_name LIMIT 5";
    $stmt_departments = $db->prepare($query_departments);
    $stmt_departments->execute();
    $recent_data['departments'] = $stmt_departments->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['departments'] = [];
}

// Niveles de urgencia
try {
    $query_urgency_levels = "SELECT id, urgency_name, description, level, active FROM urgency_levels ORDER BY level DESC LIMIT 5";
    $stmt_urgency_levels = $db->prepare($query_urgency_levels);
    $stmt_urgency_levels->execute();
    $recent_data['urgency_levels'] = $stmt_urgency_levels->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['urgency_levels'] = [];
    error_log("Error fetching urgency levels: " . $e->getMessage());
}

// Técnicos - CORREGIDO: usando technicians
try {
    $query_technicians = "SELECT name, email, role, phone, active, update_date FROM technicians ORDER BY name LIMIT 5";
    $stmt_technicians = $db->prepare($query_technicians);
    $stmt_technicians->execute();
    $recent_data['technicians'] = $stmt_technicians->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['technicians'] = [];
}

// Preguntas frecuentes
try {
    $query_questions = "SELECT id, question, answer, category, active FROM questions ORDER BY id DESC LIMIT 5";
    $stmt_questions = $db->prepare($query_questions);
    $stmt_questions->execute();
    $recent_data['questions'] = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['questions'] = [];
}

// Historial de consultas
try {
    $query_history = "SELECT id, query_type, user_id, executed_at, result_count FROM query_history ORDER BY executed_at DESC LIMIT 5";
    $stmt_history = $db->prepare($query_history);
    $stmt_history->execute();
    $recent_data['query_history'] = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['query_history'] = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Comprehensive System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #34495e;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --purple: #9b59b6;
            --pink: #e84393;
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

        /* Rest of your existing styles */
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
        }

        @media (min-width: 768px) {
            .header-content h1 {
                font-size: 2rem;
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
            max-width: 1800px;
            margin: 0 auto;
        }

        @media (min-width: 768px) {
            .admin-container {
                padding: 2rem;
            }
        }

        .welcome-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 6px solid var(--success);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }

        @media (min-width: 768px) {
            .welcome-section {
                padding: 2rem;
                margin-bottom: 2rem;
                border-radius: 15px;
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 6px solid var(--info);
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .stat-card {
                padding: 2rem;
                border-radius: 15px;
            }
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        @media (min-width: 768px) {
            .stat-number {
                font-size: 3rem;
            }
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        @media (min-width: 768px) {
            .stat-label {
                font-size: 1rem;
            }
        }

        .stat-subtext {
            color: #888;
            font-size: 0.8rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: repeat(auto-fit, minmax(600px, 1fr));
                gap: 2rem;
            }
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: transform 0.3s;
        }

        @media (min-width: 768px) {
            .card {
                border-radius: 15px;
            }
        }

        .card:hover {
            transform: translateY(-3px);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 480px) {
            .card-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
            }
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (min-width: 768px) {
            .card-header h3 {
                font-size: 1.3rem;
                gap: 0.75rem;
            }
        }

        .card-body {
            padding: 1rem;
            max-height: 400px;
            overflow-y: auto;
        }

        @media (min-width: 768px) {
            .card-body {
                padding: 1.5rem;
            }
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .table {
                font-size: 0.9rem;
            }
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        @media (min-width: 768px) {
            .table th, .table td {
                padding: 1rem;
            }
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
            position: sticky;
            top: 0;
            background: #f8f9fa;
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .badge {
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        @media (min-width: 768px) {
            .badge {
                padding: 0.4rem 0.8rem;
                font-size: 0.75rem;
                border-radius: 20px;
            }
        }

        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce7ff; color: #004085; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .btn {
                padding: 0.75rem 1.5rem;
                font-size: 0.9rem;
                border-radius: 8px;
            }
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

        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--info), var(--primary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
            flex-shrink: 0;
        }

        @media (min-width: 768px) {
            .user-avatar {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }
        }

        .empty-state {
            text-align: center;
            padding: 1.5rem;
            color: #666;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #ddd;
        }

        @media (min-width: 768px) {
            .empty-state {
                padding: 2rem;
            }
            .empty-state i {
                font-size: 3rem;
            }
        }

        .system-health {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-top: 1.5rem;
        }

        @media (min-width: 768px) {
            .system-health {
                border-radius: 15px;
                padding: 2rem;
                margin-top: 2rem;
            }
        }

        .health-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        @media (max-width: 480px) {
            .health-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .health-item {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
            background: #f8f9fa;
        }

        @media (min-width: 768px) {
            .health-item {
                padding: 1.5rem;
                border-radius: 10px;
            }
        }

        .health-item.good { border-left: 4px solid var(--success); }
        .health-item.warning { border-left: 4px solid var(--warning); }
        .health-item.danger { border-left: 4px solid var(--danger); }

        .scroll-container {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 6px;
        }

        /* Mobile table improvements */
        .mobile-friendly-table {
            font-size: 0.75rem;
        }

        .mobile-friendly-table th,
        .mobile-friendly-table td {
            padding: 0.5rem;
        }

        @media (max-width: 480px) {
            .mobile-friendly-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
        .btn-Face{

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
        <div class="header-content">
            <h1>
                <i class="fas fa-shield-alt"></i> Administration Panel
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Welcome, <strong><?php echo htmlspecialchars($_SESSION['technician_name']); ?></strong> • 
                Role: Admin • 
                <?php echo date('d/m/Y H:i:s'); ?>
             
                <span class="facial-status <?php echo $has_facial_data ? 'registered' : 'not-registered'; ?>">
                    <i class="fas fa-<?php echo $has_facial_data ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo $has_facial_data ? 'Face Registered' : 'Face Not Registered'; ?>
                </span>
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
        <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Home</a>
        <a href="users.php"><i class="fas fa-users"></i> Users</a>
        <a href="departments.php"><i class="fas fa-building"></i> Departments</a>
        <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>
        <a href="urgency.php"><i class="fas fa-exclamation-triangle"></i> Urgency</a>
        <a href="technicians.php"><i class="fas fa-tools"></i> WhiteList</a>
        <a href="questions.php"><i class="fas fa-question-circle"></i> Questions</a>
        <a href="history.php"><i class="fas fa-history"></i> History</a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> Conversations Dashboard</a>
        <a href="reportetikets.php"><i class="fas fa-chart-bar"></i> Tickets Dashboard</a>
    </nav>

    <!-- Main Content -->
    <div class="admin-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h2 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-tachometer-alt"></i> System Dashboard
            </h2>
           <p style="font-size: 1rem; color: #666;">
    Complete summary of all system tables and statistics
    <?php if(!$has_facial_data): ?>
        <br>
        <div style="margin-top: 12px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 10px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-exclamation-triangle" style="color: #856404; font-size: 1.2rem;"></i>
                <span style="color: #856404; font-weight: 600;">
                    Improve your security - Register your face for biometric access
                </span>
            </div>
            <a href="../add_face.php" class="btn-Face" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(243, 156, 18, 0.4)'" 
               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(243, 156, 18, 0.3)'">
                <i class="fas fa-user-plus"></i>
                Add Facial Recognition
            </a>
        </div>
    <?php endif; ?>
</p>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card users">
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label"><i class="fas fa-users"></i> Authorized Users</div>
                  <div class="stat-subtext">Write to chatbot</div>
            </div>
            
            <div class="stat-card tickets">
                <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                <div class="stat-label"><i class="fas fa-ticket-alt"></i> TOTAL TICKETS</div>
                <div class="stat-subtext"><?php echo $stats['open_tickets']; ?> open tickets</div>
            </div>
            
            <div class="stat-card departments">
                <div class="stat-number"><?php echo $stats['total_departments']; ?></div>
                <div class="stat-label"><i class="fas fa-building"></i> DEPARTMENTS</div>
                <div class="stat-subtext">Organizational structure</div>
            </div>
            
            <div class="stat-card urgency">
                <div class="stat-number"><?php echo $stats['total_urgency_levels']; ?></div>
                <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> URGENCY LEVELS</div>
                <div class="stat-subtext">Priority settings</div>
            </div>
            
            <div class="stat-card technicians">
                <div class="stat-number"><?php echo $stats['total_technicians']; ?></div>
                <div class="stat-label"><i class="fas fa-tools"></i> Users</div>
                  <div class="stat-subtext"><?php echo $stats['active_users']; ?> active • <?php echo $stats['users_with_face']; ?> with FaceId</div>
                <div class="stat-subtext">Specialized staff</div>
            </div>
            
            <div class="stat-card questions">
                <div class="stat-number"><?php echo $stats['total_questions']; ?></div>
                <div class="stat-label"><i class="fas fa-question-circle"></i> QUESTIONS</div>
                <div class="stat-subtext">Knowledge base</div>
            </div>
            
            <div class="stat-card history">
                <div class="stat-number"><?php echo $stats['total_query_history']; ?></div>
                <div class="stat-label"><i class="fas fa-history"></i> HISTORY</div>
                <div class="stat-subtext">Query records</div>
            </div>
        </div>

        <!-- Main Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Recent Users -->
            <div class="card">
                <div class="card-header">
                 <h3><i class="fas fa-tools"></i> Authorized Numbers</h3>
                    <a href="users.php" class="btn btn-primary">Manage</a>
                </div>
                <div class="card-body">
                    <?php if(count($recent_data['users']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table mobile-friendly-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Contact</th>
                                        <th>Role</th>
                                        <th>Registration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_data['users'] as $user): ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars($user['name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: #666;">ID: <?php echo $user['id']; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.8rem;">
                                                <div><?php echo htmlspecialchars($user['email']); ?></div>
                                                <div style="color: #666;"><?php echo htmlspecialchars($user['phone']); ?></div>
                                            </div>
                                        </td>
                                        <td><span class="badge badge-info"><?php echo strtoupper($user['role']); ?></span></td>
                                        <td style="font-size: 0.75rem; color: #666;">
                                            <?php echo date('d/m/Y', strtotime($user['registration_date'])); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No registered users</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Tickets -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-ticket-alt"></i> Recent Tickets</h3>
                    <a href="tickets.php" class="btn btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if(count($recent_data['tickets']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table mobile-friendly-table">
                                <thead>
                                    <tr>
                                        <th>Ticket</th>
                                        <th>Need</th>
                                        <th>Department</th>
                                        <th>Urgency</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_data['tickets'] as $ticket): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; font-size: 0.85rem;">#<?php echo htmlspecialchars($ticket['id']); ?></div>
                                            <?php if(!empty($ticket['query_id'])): ?>
                                                <div style="font-size: 0.65rem; color: #888;">Query: <?php echo htmlspecialchars($ticket['query_id']); ?></div>
                                            <?php endif; ?>
                                            <div style="font-size: 0.7rem; color: #666;">
                                                <?php echo date('d/m H:i', strtotime($ticket['creation_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; font-size: 0.8rem;">
                                                <?php echo htmlspecialchars(substr($ticket['title'], 0, 30)); ?>
                                                <?php if(strlen($ticket['title']) > 30): ?>...<?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-primary" style="font-size: 0.65rem;">
                                                <?php echo htmlspecialchars($ticket['department']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $urgency_class = '';
                                            $urgency_text = '';
                                            switch(strtolower($ticket['urgency'])) {
                                                case 'high_urg': 
                                                    $urgency_class = 'badge-danger';
                                                    $urgency_text = 'HIGH';
                                                    break;
                                                case 'medium_urg': 
                                                    $urgency_class = 'badge-warning';
                                                    $urgency_text = 'MEDIUM';
                                                    break;
                                                case 'low_urg': 
                                                    $urgency_class = 'badge-info';
                                                    $urgency_text = 'LOW';
                                                    break;
                                                default: 
                                                    $urgency_class = 'badge-warning';
                                                    $urgency_text = strtoupper($ticket['urgency']);
                                            }
                                            ?>
                                            <span class="badge <?php echo $urgency_class; ?>" style="font-size: 0.65rem;">
                                                <?php echo $urgency_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if($ticket['status'] == 'open'): ?>
                                                <span class="badge badge-warning" style="font-size: 0.65rem;">OPEN</span>
                                            <?php elseif($ticket['status'] == 'in_process'): ?>
                                                <span class="badge badge-info" style="font-size: 0.65rem;">IN PROCESS</span>
                                            <?php else: ?>
                                                <span class="badge badge-success" style="font-size: 0.65rem;">CLOSED</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-ticket-alt"></i>
                            <p>No recent tickets</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rest of your existing cards (departments, urgency, technicians, questions) -->
            <!-- Departments -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-building"></i> Departments</h3>
                    <a href="departments.php" class="btn btn-primary">Manage</a>
                </div>
                <div class="card-body">
                    <?php if(count($recent_data['departments']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table mobile-friendly-table">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Keywords</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_data['departments'] as $dept): ?>
                                    <tr>
                                        <td style="font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars($dept['department_name']); ?></td>
                                        <td>
                                            <?php if($dept['active'] == 1): ?>
                                                <span class="badge badge-success" style="font-size: 0.65rem;">ACTIVE</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger" style="font-size: 0.65rem;">INACTIVE</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.8rem;"><?php echo htmlspecialchars(substr($dept['keywords'], 0, 20)); ?><?php if(strlen($dept['keywords']) > 20): ?>...<?php endif; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-building"></i>
                            <p>No registered departments</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Urgency Levels -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-exclamation-triangle"></i> Urgency Levels</h3>
                    <a href="urgency.php" class="btn btn-primary">Configure</a>
                </div>
                <div class="card-body">
                    <?php if(count($recent_data['urgency_levels']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table mobile-friendly-table">
                                <thead>
                                    <tr>
                                        <th>Level</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_data['urgency_levels'] as $urgency): ?>
                                    <tr>
                                        <td style="font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars($urgency['urgency_name']); ?></td>
                                        <td>
                                            <?php if(isset($urgency['level'])): ?>
                                                <span class="badge badge-<?php echo $urgency['level'] >= 8 ? 'danger' : ($urgency['level'] >= 5 ? 'warning' : 'info'); ?>" style="font-size: 0.65rem;">
                                                    Level <?php echo $urgency['level']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary" style="font-size: 0.65rem;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($urgency['active'] == 1): ?>
                                                <span class="badge badge-success" style="font-size: 0.65rem;">ACTIVE</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger" style="font-size: 0.65rem;">INACTIVE</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>No urgency levels configured</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Technicians -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-user-plus"></i> Recent Users</h3>
                    <a href="technicians.php" class="btn btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if(count($recent_data['technicians']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table mobile-friendly-table">
                                <thead>
                                    <tr>
                                        <th>Technician</th>
                                        <th>Department</th>
                                        <th>Phone</th>
                                        <th>Update Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_data['technicians'] as $tech): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars($tech['name']); ?></div>
                                            <div style="font-size: 0.7rem; color: #666;"><?php echo htmlspecialchars($tech['email']); ?></div>
                                        </td>
                                        <td style="font-size: 0.8rem;">
                                            <?php echo htmlspecialchars(substr($tech['role'], 0, 20)); ?>
                                            <?php if(strlen($tech['role']) > 20): ?>...<?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.8rem;">
                                            <?php echo htmlspecialchars($tech['phone']); ?>
                                        </td>
                                        <td style="font-size: 0.8rem;">
                                            <?php 
                                            if(!empty($tech['update_date'])) {
                                                echo date('d/m/Y H:i', strtotime($tech['update_date']));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if($tech['active'] == 1 || $tech['active'] == "yes"): ?>
                                                <span class="badge badge-success" style="font-size: 0.65rem;">ACTIVE</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger" style="font-size: 0.65rem;">INACTIVE</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-tools"></i>
                            <p>No registered technicians</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Frequently Asked Questions -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-question-circle"></i> Frequently Asked Questions</h3>
                    <a href="questions.php" class="btn btn-primary">Manage</a>
                </div>
                <div class="card-body">
                    <?php if(count($recent_data['questions']) > 0): ?>
                        <div class="scroll-container">
                            <div class="table-responsive">
                                <table class="table mobile-friendly-table">
                                    <thead>
                                        <tr>
                                            <th>Question</th>
                                            <th>Category</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent_data['questions'] as $question): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600; font-size: 0.8rem;">
                                                    <?php echo htmlspecialchars(substr($question['question'], 0, 40)); ?>
                                                    <?php if(strlen($question['question']) > 40): ?>...<?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if(!empty($question['category'])): ?>
                                                    <span class="badge badge-primary" style="font-size: 0.65rem;"><?php echo strtoupper($question['category']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary" style="font-size: 0.65rem;">NO CATEGORY</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($question['active'] == 'yes'): ?>
                                                    <span class="badge badge-success" style="font-size: 0.65rem;">ACTIVE</span>
                                                    <?php else: ?>
                                                    <span class="badge badge-danger" style="font-size: 0.65rem;">INACTIVE</span>
                                                    <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-question-circle"></i>
                            <p>No frequently asked questions</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- System Health -->
        <div class="system-health">
            <h3 style="margin-bottom: 1rem; color: var(--primary); font-size: 1.2rem;">
                <i class="fas fa-heartbeat"></i> System Status
            </h3>
            <div class="health-grid">
                <div class="health-item <?php echo $stats['total_users'] > 0 ? 'good' : 'danger'; ?>">
                    <i class="fas fa-users fa-2x" style="color: <?php echo $stats['total_users'] > 0 ? 'var(--success)' : 'var(--danger)'; ?>;"></i>
                    <div style="margin-top: 0.5rem; font-weight: 600; font-size: 0.9rem;">Users</div>
                    <div style="font-size: 0.8rem; color: #666;"><?php echo $stats['total_users'] > 0 ? 'Operational' : 'Critical'; ?></div>
                </div>
                <div class="health-item <?php echo $stats['total_departments'] > 0 ? 'good' : 'warning'; ?>">
                    <i class="fas fa-building fa-2x" style="color: <?php echo $stats['total_departments'] > 0 ? 'var(--success)' : 'var(--warning)'; ?>;"></i>
                    <div style="margin-top: 0.5rem; font-weight: 600; font-size: 0.9rem;">Departments</div>
                    <div style="font-size: 0.8rem; color: #666;"><?php echo $stats['total_departments'] > 0 ? 'Configured' : 'To be configured'; ?></div>
                </div>
                <div class="health-item good">
                    <i class="fas fa-database fa-2x" style="color: var(--success);"></i>
                    <div style="margin-top: 0.5rem; font-weight: 600; font-size: 0.9rem;">Database</div>
                    <div style="font-size: 0.8rem; color: #666;">Connected</div>
                </div>
                <div class="health-item good">
                    <i class="fas fa-shield-alt fa-2x" style="color: var(--success);"></i>
                    <div style="margin-top: 0.5rem; font-weight: 600; font-size: 0.9rem;">Security</div>
                    <div style="font-size: 0.8rem; color: #666;">Active</div>
                </div>
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

        // Update dashboard every 2 minutes
        setInterval(() => {
            window.location.reload();
        }, 120000);

        // Hover effects for cards
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });

        // Improve mobile scrolling
        document.addEventListener('touchstart', function() {}, {passive: true});

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