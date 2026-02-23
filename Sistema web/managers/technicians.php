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

if(!isset($_SESSION['technician_id']) || strpos($_SESSION['technician_role'], 'dep_') !== 0){
    header("Location: ../login.php");
    exit();
}
include_once '../config.php';

$database = new Database();
$db = $database->getConnection();

// VERIFICAR SI EL USUARIO ESTÁ ACTIVO EN LA TABLA TECHNICIANS
$query = "SELECT * FROM technicians WHERE id = :id AND active = 'yes'";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $_SESSION['technician_id']);
$stmt->execute();
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Si el usuario no existe o está inactivo en technicians, cerrar sesión inmediatamente
if (!$user_data) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?error=user_inactive");
    exit();
}

// Obtener el departamento del usuario logueado
$current_user_role = $_SESSION['technician_role'];
$current_user_id = $_SESSION['technician_id'];

// NORMALIZACIÓN - Si el rol es 'dep_sale' pero en whitelist es 'dep_sales'
if ($current_user_role == 'dep_sale') {
    $user_department = 'dep_sales';
} else {
    $user_department = $current_user_role;
}

// OBTENER ESTADÍSTICAS PARA LAS CARDS - FILTRADO POR DEPARTAMENTO
$stats = [];

// Total de usuarios del departamento
$query_total = "SELECT COUNT(*) as total FROM users WHERE role = :department";
$stmt_total = $db->prepare($query_total);
$stmt_total->bindParam(':department', $user_department);
$stmt_total->execute();
$stats['total_users'] = $stmt_total->fetchColumn();

// Usuarios activos del departamento
$query_active = "SELECT COUNT(*) as active FROM users WHERE active = 'yes' AND role = :department";
$stmt_active = $db->prepare($query_active);
$stmt_active->bindParam(':department', $user_department);
$stmt_active->execute();
$stats['active_users'] = $stmt_active->fetchColumn();

// Usuarios inactivos del departamento
$stats['inactive_users'] = $stats['total_users'] - $stats['active_users'];

// Variables para el formulario
$technician = [
    'id' => '',
    'number' => '',
    'name' => '',
    'email' => '',
    'role' => $user_department, // Usar el departamento del usuario logueado por defecto
    'active' => 'yes'
];

$action = 'create';
$page_title = 'Crear Usuario - ' . htmlspecialchars($user_department);

// Procesar formulario
if($_POST){
    $technician = [
        'number' => $_POST['number'] ?? '',
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'role' => $user_department, // Forzar el departamento del usuario logueado
        'active' => $_POST['active'] ?? 'yes'
    ];

    if(isset($_POST['id']) && !empty($_POST['id'])){
        // Actualizar usuario - SOLO SI PERTENECE AL DEPARTAMENTO DEL USUARIO
        $action = 'update';
        $technician['id'] = $_POST['id'];
        
        $query = "UPDATE users SET 
                  number = :number,
                  name = :name, 
                  email = :email, 
                  role = :role, 
                  active = :active
                  WHERE id = :id AND role = :user_department";
    } else {
        // Crear usuario - SIEMPRE CON EL DEPARTAMENTO DEL USUARIO LOGEADO
        $query = "INSERT INTO users 
                  (number, name, email, role, active, registration_date) 
                  VALUES 
                  (:number, :name, :email, :role, :active, NOW())";
    }

    try {
        $stmt = $db->prepare($query);
        
        if(isset($technician['id'])){
            $stmt->bindParam(':id', $technician['id']);
            $stmt->bindParam(':user_department', $user_department); // Para la cláusula WHERE en update
        }
        
        $stmt->bindParam(':number', $technician['number']);
        $stmt->bindParam(':name', $technician['name']);
        $stmt->bindParam(':email', $technician['email']);
        $stmt->bindParam(':role', $technician['role']);
        $stmt->bindParam(':active', $technician['active']);
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "Usuario " . ($action == 'create' ? 'creado' : 'actualizado') . " correctamente";
            header("Location: technicians.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al guardar el usuario: " . $e->getMessage();
    }
}

// Editar Usuario - SOLO SI PERTENECE AL DEPARTAMENTO DEL USUARIO
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Usuario - ' . htmlspecialchars($user_department);
    
    $query = "SELECT * FROM users WHERE id = :id AND role = :department";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['edit']);
    $stmt->bindParam(':department', $user_department);
    $stmt->execute();
    
    $technician = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$technician){
        $_SESSION['error_message'] = "Usuario no encontrado o no tienes permisos para editarlo";
        header("Location: technicians.php");
        exit();
    }
}

// ELIMINAR USUARIO - NO PERMITIDO
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $_SESSION['error_message'] = "No tienes permisos para eliminar usuarios de la whitelist";
    header("Location: technicians.php");
    exit();
}

// Obtener lista de Usuarios - SOLO DEL DEPARTAMENTO DEL USUARIO
$query = "SELECT * FROM users WHERE role = :department ORDER BY name";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user_department);
$stmt->execute();
$technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener roles para el select - SOLO EL ROL DEL USUARIO ACTUAL
$roles = [$user_department];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Admin</title>
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
            --teal: #1abc9c;
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

        /* Estilos para las cards de estadísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            text-align: center;
            border-top: 6px solid var(--info);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--info), transparent);
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }

        .stat-number {
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .stat-label {
            color: #555;
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: 0.5px;
        }

        .stat-subtext {
            color: #777;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.9;
            filter: drop-shadow(2px 2px 3px rgba(0,0,0,0.2));
        }

        .stat-card.total { border-top-color: var(--info); }
        .stat-card.active { border-top-color: var(--success); }
        .stat-card.inactive { border-top-color: var(--danger); }
        .stat-card.roles { border-top-color: var(--purple); }

        .stat-card.total .stat-icon { color: var(--info); }
        .stat-card.active .stat-icon { color: var(--success); }
        .stat-card.inactive .stat-icon { color: var(--danger); }
        .stat-card.roles .stat-icon { color: var(--purple); }

        /* Estilos compactos para la card de roles */
        .roles-container {
            margin-top: 0.8rem;
        }

        .role-item-compact {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255, 255, 255, 0.7);
            padding: 0.5rem 0.7rem;
            margin-bottom: 0.4rem;
            border-radius: 8px;
            border-left: 3px solid var(--purple);
            transition: all 0.2s ease;
        }

        .role-item-compact:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateX(2px);
        }

        .role-icon-small {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--purple), #8e44ad);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.7rem;
            flex-shrink: 0;
        }

        .role-name-compact {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            flex-grow: 1;
            margin-left: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .role-count-compact {
            background: linear-gradient(135deg, var(--purple), #8e44ad);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.7rem;
            min-width: 25px;
            text-align: center;
            flex-shrink: 0;
        }

        .role-more {
            text-align: center;
            padding: 0.4rem;
            background: rgba(158, 84, 255, 0.1);
            border-radius: 6px;
            margin-top: 0.2rem;
        }

        .more-text {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--purple);
            letter-spacing: 0.5px;
        }

        .percentage-badge {
            background: linear-gradient(135deg, var(--teal), #16a085);
            color: white;
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-left: 0.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
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
            border-radius: 16px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 2rem;
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-3px);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

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
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--info);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
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
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            font-weight: 700;
            color: #555;
            border-bottom: 2px solid #dee2e6;
        }

        .table tr:hover {
            background: #f8f9fa;
        }

        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success { 
            background: linear-gradient(135deg, #27ae60, #2ecc71); 
            color: white; 
            box-shadow: 0 2px 4px rgba(39, 174, 96, 0.3);
        }
        .badge-danger { 
            background: linear-gradient(135deg, #e74c3c, #c0392b); 
            color: white;
            box-shadow: 0 2px 4px rgba(231, 76, 60, 0.3);
        }
        .badge-info { 
            background: linear-gradient(135deg, #3498db, #2980b9); 
            color: white;
            box-shadow: 0 2px 4px rgba(52, 152, 219, 0.3);
        }
        .badge-warning { 
            background: linear-gradient(135deg, #f39c12, #e67e22); 
            color: white;
            box-shadow: 0 2px 4px rgba(243, 156, 18, 0.3);
        }

        .actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.6rem;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .edit-btn { 
            background: linear-gradient(135deg, var(--warning), #e67e22); 
            color: white; 
        }
        .delete-btn { 
            background: linear-gradient(135deg, var(--danger), #c0392b); 
            color: white; 
        }

        .action-btn:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
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
          .permission-notice {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            text-align: center;
            font-weight: 600;
        }
        
        .disabled-feature {
            opacity: 0.6;
            pointer-events: none;
            cursor: not-allowed;
        }
        
        .view-only-badge {
            background: var(--info);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }
        
        .department-badge {
            background: linear-gradient(135deg, var(--purple), #8e44ad);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="admin-header">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-tools"></i> Gestión de Whitelist
               
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Bienvenido, <strong><?php echo htmlspecialchars($_SESSION['technician_name']); ?></strong> • 
                Departamento: <strong><?php echo htmlspecialchars($user_department); ?></strong> • 
                <?php echo date('d/m/Y H:i:s'); ?>
            </p>
        </div>
        <div>
            <a href="../logout.php" class="btn btn-danger">
                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
            </a>
        </div>
    </header>

    <!-- Navigation -->
    <nav class="admin-nav">
        <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="users.php" ><i class="fas fa-users"></i> Usuarios</a>
        <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>
        <a href="technicians.php" class="active"><i class="fas fa-tools"></i> Whitelist</a>
        <a href="questions.php"><i class="fas fa-question-circle"></i> Preguntas</a>
        <a href="history.php"><i class="fas fa-history"></i> Historial</a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> Reportes</a>
             <a href="reportetikets.php"><i class="fas fa-chart-bar"></i> Reporte ticket</a>
    </nav>

    <!-- Main Content -->
    <div class="admin-container">
        <!-- Aviso de permisos limitados -->
      

        <!-- Page Header -->
        <div class="page-header">
            <h2 style="font-size: 2rem;">
                <i class="fas fa-tools"></i> <?php echo $page_title; ?>
            </h2>
            <?php if(isset($_GET['edit'])): ?>
                <a href="technicians.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Volver a la lista
                </a>
            <?php else: ?>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Volver al Dashboard
                </a>
            <?php endif; ?>
        </div>

        <!-- Cards de Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">TOTAL EN WHITELIST</div>
                <div class="stat-subtext"><?php echo htmlspecialchars($user_department); ?></div>
            </div>
            
            <div class="stat-card active">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?php echo $stats['active_users']; ?></div>
                <div class="stat-label">ACTIVOS EN SISTEMA</div>
                <div class="stat-subtext">
                    <?php echo round(($stats['active_users'] / max($stats['total_users'], 1)) * 100, 1); ?>% del total
                    <span class="percentage-badge"><?php echo round(($stats['active_users'] / max($stats['total_users'], 1)) * 100, 1); ?>%</span>
                </div>
            </div>
            
            <div class="stat-card inactive">
                <div class="stat-icon">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="stat-number"><?php echo $stats['inactive_users']; ?></div>
                <div class="stat-label">INACTIVOS</div>
                <div class="stat-subtext">
                    <?php echo round(($stats['inactive_users'] / max($stats['total_users'], 1)) * 100, 1); ?>% del total
                    <span class="percentage-badge"><?php echo round(($stats['inactive_users'] / max($stats['total_users'], 1)) * 100, 1); ?>%</span>
                </div>
            </div>
            
            <div class="stat-card roles">
                <div class="stat-icon">
                    <i class="fas fa-user-tag"></i>
                </div>
                <div class="stat-number">1</div>
                <div class="stat-label">DEPARTAMENTO</div>
                <div class="stat-subtext"><?php echo htmlspecialchars($user_department); ?></div>
            </div>
        </div>

        <!-- Mensajes de alerta -->
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

        <!-- Formulario de Usuarios -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-user-cog"></i> Información de Usuario - <?php echo htmlspecialchars($user_department); ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" action="technicians.php">
                    <?php if($action == 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($technician['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="number"><i class="fas fa-id-card"></i> Número de Usuario *</label>
                            <input type="text" id="number" name="number" class="form-control" 
                                   value="<?php echo htmlspecialchars($technician['number']); ?>" 
                                   required maxlength="20">
                        </div>

                        <div class="form-group">
                            <label for="name"><i class="fas fa-user"></i> Nombre completo *</label>
                            <input type="text" id="name" name="name" class="form-control" 
                                   value="<?php echo htmlspecialchars($technician['name']); ?>" 
                                   required maxlength="100">
                        </div>

                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($technician['email']); ?>" 
                                   maxlength="100">
                        </div>

                        <div class="form-group">
                          
                            <input type="hidden" id="role" class="form-control" 
                                   value="<?php echo htmlspecialchars($user_department); ?>" 
                                   readonly>
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars($user_department); ?>">
                            <div class="field-info">
                                <i class="fas fa-lock"></i> Departamento fijo: <?php echo htmlspecialchars($user_department); ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="active"><i class="fas fa-toggle-on"></i> Estado</label>
                            <select id="active" name="active" class="form-control" required>
                                <option value="yes" <?php echo $technician['active'] == 'yes' ? 'selected' : ''; ?>>Activo</option>
                                <option value="no" <?php echo $technician['active'] == 'no' ? 'selected' : ''; ?>>Inactivo</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="technicians.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> 
                            <?php echo $action == 'create' ? 'Crear Usuario' : 'Actualizar Usuario'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de Usuarios -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Lista de Usuarios - <?php echo htmlspecialchars($user_department); ?></h3>
            </div>
            <div class="card-body">
                <?php if(count($technicians) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Número</th>
                                    <th>Nombre</th>
                                    <th>Email</th>
                                    <th>Departamento</th>
                                    <th>Estado</th>
                                    <th>Fecha Registro</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($technicians as $tech): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($tech['number']); ?></td>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($tech['name']); ?></td>
                                    <td><?php echo htmlspecialchars($tech['email']); ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars(strtoupper($tech['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($tech['active'] == 'yes'): ?>
                                            <span class="badge badge-success">ACTIVO</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">INACTIVO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($tech['registration_date'])); ?></td>
                                    <td>
                                        <div class="actions">
                                            <!-- Botón de editar -->
                                            <a href="technicians.php?edit=<?php echo $tech['id']; ?>" 
                                               class="action-btn edit-btn" title="Editar">
                                                <i class="fas fa-edit"></i>
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
                        <i class="fas fa-tools"></i>
                        <h3>No hay usuarios en <?php echo htmlspecialchars($user_department); ?></h3>
                        <p>Comienza agregando el primer usuario usando el formulario superior.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <!-- Footer minimalista -->
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
        // Timer de sesión (3 horas = 10800 segundos)
        let sessionTime = 10800; // 3 horas en segundos

        function updateSessionTimer() {
            sessionTime--;
            
            if (sessionTime <= 0) {
                // Sesión expirada - redirigir al login
                window.location.href = '../login.php?error=session_expired';
                return;
            }
            
            // Calcular horas, minutos y segundos restantes
            const hours = Math.floor(sessionTime / 3600);
            const minutes = Math.floor((sessionTime % 3600) / 60);
            const seconds = sessionTime % 60;
            
            // Formatear el tiempo
            const timeString = 
                String(hours).padStart(2, '0') + ':' +
                String(minutes).padStart(2, '0') + ':' +
                String(seconds).padStart(2, '0');
            
            // Actualizar el display
            document.getElementById('timeRemaining').textContent = timeString;
            
            // Cambiar color cuando queden menos de 5 minutos
            const timerElement = document.getElementById('sessionTimer');
            if (sessionTime <= 300) { // 5 minutos
                timerElement.style.background = 'var(--danger)';
            } else if (sessionTime <= 900) { // 15 minutos
                timerElement.style.background = 'var(--warning)';
            }
        }

        // Iniciar el timer
        setInterval(updateSessionTimer, 1000);

        // Confirmación antes de eliminar
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.delete-btn');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if(!confirm('¿Estás seguro de que deseas eliminar este usuario?')) {
                        e.preventDefault();
                    }
                });
            });
        });

        // Validación del formulario
        document.querySelector('form').addEventListener('submit', function(e) {
            const number = document.getElementById('number').value.trim();
            const name = document.getElementById('name').value.trim();

            if(!number || !name) {
                e.preventDefault();
                alert('Por favor, complete todos los campos obligatorios (*)');
                return false;
            }

            // Validación básica de email si se proporciona
            const email = document.getElementById('email').value.trim();
            if(email) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if(!emailRegex.test(email)) {
                    e.preventDefault();
                    alert('Por favor, ingrese un email válido');
                    return false;
                }
            }
        });
    </script>
</body>
</html>