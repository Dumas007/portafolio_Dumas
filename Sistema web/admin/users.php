<?php
session_start();

// Configurar tiempo máximo de inactividad (3 horas = 10800 segundos)
$max_inactivity_time = 10800;

// Verificar inactividad
if (isset($_SESSION['last_activity'])) {
    $session_time = time() - $_SESSION['last_activity'];
    if ($session_time > $max_inactivity_time) {
        session_unset();
        session_destroy();
        header("Location: ../login.php?error=session_expired");
        exit();
    }
}

// Actualizar timestamp de última actividad
$_SESSION['last_activity'] = time();

// Verificar que el usuario sea Admin
if(!isset($_SESSION['technician_id']) || ($_SESSION['technician_role'] != 'Admin' && $_SESSION['technician_role'] != 'admin')){
    header("Location: ../login.php");
    exit();
}

include_once '../config.php';

$database = new Database();
$db = $database->getConnection();

// VERIFICAR SI EL USUARIO ESTÁ ACTIVO
$query = "SELECT * FROM technicians WHERE id = :id AND active = 'yes'";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $_SESSION['technician_id']);
$stmt->execute();
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_data) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?error=user_inactive");
    exit();
}

// OBTENER ESTADÍSTICAS PARA LAS CARDS
$stats = [];

// Total de técnicos
$query_total = "SELECT COUNT(*) as total FROM technicians";
$stmt_total = $db->prepare($query_total);
$stmt_total->execute();
$stats['total_technicians'] = $stmt_total->fetchColumn();

// Técnicos activos
$query_active = "SELECT COUNT(*) as active FROM technicians WHERE active = 'yes'";
$stmt_active = $db->prepare($query_active);
$stmt_active->execute();
$stats['active_technicians'] = $stmt_active->fetchColumn();

// Técnicos con datos faciales
$query_facial = "SELECT COUNT(*) as facial FROM technicians WHERE facial_data IS NOT NULL AND facial_data != '' AND facial_data != 'NULL'";
$stmt_facial = $db->prepare($query_facial);
$stmt_facial->execute();
$stats['technicians_with_face'] = $stmt_facial->fetchColumn();

// Técnicos por rol
$query_roles = "SELECT role, COUNT(*) as count FROM technicians WHERE role IS NOT NULL GROUP BY role";
$stmt_roles = $db->prepare($query_roles);
$stmt_roles->execute();
$stats['roles_distribution'] = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);

// Variables para el formulario
$technician = [
    'id' => '',
    'phone' => '',
    'name' => '',
    'email' => '',
    'pass' => '',
    'facial_data' => '',
    'role' => '',
    'active' => 'yes'
];

$action = 'create';
$page_title = 'Create User';

// CORRECCIÓN: Primero procesar las acciones GET antes del POST
// Eliminar técnico
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $query = "DELETE FROM technicians WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['delete']);
    
    try {
        if($stmt->execute()){
            $_SESSION['success_message'] = "User deleted successfully";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error deleting user: " . $e->getMessage();
    }
    
    header("Location: users.php");
    exit();
}

// Editar técnico - MEJORADO con validación
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Edit User';
    
    // Validar que el ID sea numérico
    $edit_id = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($edit_id === false) {
        $_SESSION['error_message'] = "Invalid user ID";
        header("Location: users.php");
        exit();
    }
    
    $query = "SELECT * FROM technicians WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $edit_id, PDO::PARAM_INT);
    
    if($stmt->execute()){
        $technician = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$technician){
            $_SESSION['error_message'] = "User not found";
            header("Location: users.php");
            exit();
        }
        
        // Mostrar la contraseña actual en texto plano
        $technician['pass'] = $technician['password'];
    } else {
        $_SESSION['error_message'] = "Error loading user";
        header("Location: users.php");
        exit();
    }
}

// Procesar formulario POST
if($_POST && $_SERVER['REQUEST_METHOD'] == 'POST'){
    // DEBUG: Registrar datos recibidos
    error_log("POST data received for facial_data: " . (!empty($_POST['facial_data']) ? strlen($_POST['facial_data']) . " chars" : "EMPTY"));
    
    $technician = [
        'phone' => $_POST['phone'] ?? '',
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'pass' => $_POST['pass'] ?? '',
        'facial_data' => $_POST['facial_data'] ?? '',
        'role' => $_POST['role'] ?? 'technician',
        'active' => $_POST['active'] ?? 'yes'
    ];

    if(isset($_POST['id']) && !empty($_POST['id'])){
        // Actualizar usuario/técnico
        $action = 'update';
        $technician['id'] = $_POST['id'];
        
        // Validar ID
        $technician_id = filter_var($technician['id'], FILTER_VALIDATE_INT);
        if ($technician_id === false) {
            $_SESSION['error_message'] = "Invalid user ID";
            header("Location: users.php");
            exit();
        }
        
        // Si no se proporciona nueva contraseña, mantener la actual
        if(empty($technician['pass'])) {
            $query = "UPDATE technicians SET 
                      phone = :phone,
                      name = :name, 
                      email = :email, 
                      facial_data = :facial_data, 
                      role = :role, 
                      active = :active
                      WHERE id = :id";
        } else {
            // CONTRASEÑA EN TEXTO PLANO
            $query = "UPDATE technicians SET 
                      phone = :phone,
                      name = :name, 
                      email = :email, 
                      password = :pass,
                      facial_data = :facial_data, 
                      role = :role, 
                      active = :active
                      WHERE id = :id";
        }
    } else {
        // Crear usuario/técnico - contraseña obligatoria
        if(empty($technician['pass'])) {
            $_SESSION['error_message'] = "Password is required for new users";
            header("Location: users.php");
            exit();
        }
        
        // CONTRASEÑA EN TEXTO PLANO
        $query = "INSERT INTO technicians 
                  (phone, name, email, password, facial_data, role, active, registration_date) 
                  VALUES 
                  (:phone, :name, :email, :pass, :facial_data, :role, :active, NOW())";
    }

    try {
        $stmt = $db->prepare($query);
        
        if(isset($technician['id'])){
            $stmt->bindParam(':id', $technician_id, PDO::PARAM_INT);
        }
        
        $stmt->bindParam(':phone', $technician['phone']);
        $stmt->bindParam(':name', $technician['name']);
        $stmt->bindParam(':email', $technician['email']);
        $stmt->bindParam(':facial_data', $technician['facial_data']);
        $stmt->bindParam(':role', $technician['role']);
        $stmt->bindParam(':active', $technician['active']);
        
        // Solo bindear password si se está creando o actualizando con nueva contraseña
        if($action == 'create' || !empty($technician['pass'])) {
            $stmt->bindParam(':pass', $technician['pass']); // Texto plano
        }
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "User " . ($action == 'create' ? 'created' : 'updated') . " successfully";
            header("Location: users.php");
            exit();
        } else {
            throw new Exception("Error executing query");
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error saving user: " . $e->getMessage();
        error_log("Error saving technician: " . $e->getMessage());
    }
}

// Obtener lista de técnicos
$query = "SELECT * FROM technicians ORDER BY name";
$stmt = $db->prepare($query);
$stmt->execute();
$technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener roles para el select
$query_roles = "SELECT DISTINCT role FROM technicians WHERE role IS NOT NULL ORDER BY role";
$stmt_roles = $db->prepare($query_roles);
$stmt_roles->execute();
$roles = $stmt_roles->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin</title>
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

        /* Statistics cards styles */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
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

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .stat-subtext {
            color: #888;
            font-size: 0.8rem;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.8;
        }

        .stat-card.total .stat-icon { color: var(--info); }
        .stat-card.active .stat-icon { color: var(--success); }
        .stat-card.facial .stat-icon { color: var(--warning); }
        .stat-card.roles .stat-icon { color: var(--purple); }

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
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--info);
        }

        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #666;
            padding: 0.25rem;
        }

        .password-toggle:hover {
            color: var(--info);
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
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-secondary { background: #e9ecef; color: #495057; }

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

        .password-note {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
            font-style: italic;
        }

        .facial-data-info {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
            font-style: italic;
            background: #f8f9fa;
            padding: 0.5rem;
            border-radius: 4px;
            border-left: 3px solid var(--info);
        }

        .textarea-monospace {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.8rem;
            white-space: pre;
            line-height: 1.2;
        }

        .roles-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .role-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.25rem 0;
            font-size: 0.8rem;
        }

        .role-name {
            font-weight: 600;
        }

        .role-count {
            background: var(--info);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
        }

        .password-display {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.8rem;
            background: #f8f9fa;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            border: 1px solid #e9ecef;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="admin-header">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-tools"></i> User Management
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
        <a href="users.php" class="active"><i class="fas fa-users"></i> Users</a>
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
        <!-- Page Header -->
        <div class="page-header">
            <h2 style="font-size: 2rem;">
                <i class="fas fa-tools"></i> <?php echo $page_title; ?>
            </h2>
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo $stats['total_technicians']; ?></div>
                <div class="stat-label">TOTAL USERS</div>
                <div class="stat-subtext">Registered in the system</div>
            </div>
            
            <div class="stat-card active">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?php echo $stats['active_technicians']; ?></div>
                <div class="stat-label">ACTIVE USERS</div>
                <div class="stat-subtext"><?php echo round(($stats['active_technicians'] / max($stats['total_technicians'], 1)) * 100, 1); ?>% of total</div>
            </div>
            
            <div class="stat-card facial">
                <div class="stat-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="stat-number"><?php echo $stats['technicians_with_face']; ?></div>
                <div class="stat-label">WITH FACIAL RECOGNITION</div>
                <div class="stat-subtext"><?php echo round(($stats['technicians_with_face'] / max($stats['total_technicians'], 1)) * 100, 1); ?>% have FaceID</div>
            </div>
            
            <div class="stat-card roles">
                <div class="stat-icon">
                    <i class="fas fa-user-tag"></i>
                </div>
                <div class="stat-number"><?php echo count($stats['roles_distribution']); ?></div>
                <div class="stat-label">DIFFERENT ROLES</div>
               
            </div>
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

        <!-- User Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-user-cog"></i> User Information</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="users.php" id="technicianForm">
                    <?php if($action == 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($technician['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="phone"><i class="fas fa-id-card"></i> Technician Number *</label>
                            <input type="text" id="phone" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($technician['phone']); ?>" 
                                   required maxlength="20">
                        </div>

                        <div class="form-group">
                            <label for="name"><i class="fas fa-user"></i> Full Name *</label>
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
                            <label for="pass"><i class="fas fa-lock"></i> Password <?php echo $action == 'create' ? '*' : ''; ?></label>
                            <div class="password-container">
                                <input type="password" id="pass" name="pass" class="form-control" 
                                       value="<?php echo htmlspecialchars($technician['pass']); ?>"
                                       <?php echo $action == 'create' ? 'required' : ''; ?> maxlength="255">
                                <button type="button" class="password-toggle" onclick="togglePasswordVisibility()">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-note">
                                <?php if($action == 'update'): ?>
                                    Leave blank to keep current password
                                <?php else: ?>
                                    Password required for new users
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="facial_data"><i class="fas fa-user-circle"></i> Facial Data</label>
                            <textarea id="facial_data" name="facial_data" class="form-control textarea-monospace" 
                                      rows="6" 
                                      placeholder="Facial recognition data (embedding) - Read-only field"
                                      maxlength="65535"
                                      readonly
                                      style="background-color: #f8f9fa; cursor: not-allowed;"><?php 
                                echo htmlspecialchars($technician['facial_data'] ?? ''); 
                            ?></textarea>
                            <div class="facial-data-info">
                                <?php 
                                $facial_length = strlen($technician['facial_data'] ?? '');
                                if($facial_length > 0) {
                                    echo "Current length: " . $facial_length . " characters";
                                    if($facial_length > 1000) {
                                        echo " (extensive data)";
                                    }
                                } else {
                                    echo "No facial data registered";
                                }
                                ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="role"><i class="fas fa-user-tag"></i> Role</label>
                            <select id="role" name="role" class="form-control">
                                <option value="">Select role</option>
                                <?php foreach($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>" 
                                        <?php echo ($technician['role'] == $role) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="active"><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="active" name="active" class="form-control" required>
                                <option value="yes" <?php echo $technician['active'] == 'yes' ? 'selected' : ''; ?>>Active</option>
                                <option value="no" <?php echo $technician['active'] == 'no' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="users.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> 
                            <?php echo $action == 'create' ? 'Create User' : 'Update User'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Users List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Users List</h3>
            </div>
            <div class="card-body">
                <?php if(count($technicians) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Number</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Password</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Facial Data</th>
                                    <th>Registration Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($technicians as $tech): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($tech['id']); ?></td>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($tech['phone']); ?></td>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($tech['name']); ?></td>
                                    <td><?php echo htmlspecialchars($tech['email']); ?></td>
                                    <td>
                                        <code class="password-display">
                                            <?php echo htmlspecialchars($tech['password']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?php 
                                        $role_display = htmlspecialchars($tech['role']);
                                        if(!empty($role_display)) {
                                            echo '<span class="badge badge-info">' . $role_display . '</span>';
                                        } else {
                                            echo '<span class="badge badge-secondary">No role</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if($tech['active'] == 'yes'): ?>
                                            <span class="badge badge-success">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">INACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($tech['facial_data'])): ?>
                                            <span class="badge badge-success" title="Facial data present">
                                                <i class="fas fa-check"></i> Registered
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">
                                                <i class="fas fa-times"></i> No data
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($tech['registration_date'])); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="users.php?edit=<?php echo $tech['id']; ?>" 
                                               class="action-btn edit-btn" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="users.php?delete=<?php echo $tech['id']; ?>" 
                                               class="action-btn delete-btn" 
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this user?')">
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
                        <i class="fas fa-tools"></i>
                        <h3>No registered users</h3>
                        <p>Start by adding the first user using the form above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
        // Función para mostrar/ocultar contraseña
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('pass');
            const eyeIcon = document.querySelector('.password-toggle i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                eyeIcon.className = 'fas fa-eye';
            }
        }

        // Session timer
        let sessionTime = 10800;

        function updateSessionTimer() {
            sessionTime--;
            
            if (sessionTime <= 0) {
                window.location.href = '../login.php?error=session_expired';
                return;
            }
            
            const hours = Math.floor(sessionTime / 3600);
            const minutes = Math.floor((sessionTime % 3600) / 60);
            const seconds = sessionTime % 60;
            
            const timeString = 
                String(hours).padStart(2, '0') + ':' +
                String(minutes).padStart(2, '0') + ':' +
                String(seconds).padStart(2, '0');
            
            document.getElementById('timeRemaining').textContent = timeString;
            
            const timerElement = document.getElementById('sessionTimer');
            if (sessionTime <= 300) {
                timerElement.style.background = 'var(--danger)';
            } else if (sessionTime <= 900) {
                timerElement.style.background = 'var(--warning)';
            }
        }

        setInterval(updateSessionTimer, 1000);

        // Confirmation before deleting
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.delete-btn');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if(!confirm('Are you sure you want to delete this user?')) {
                        e.preventDefault();
                    }
                });
            });

            // Form validation
            const form = document.getElementById('technicianForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const phone = document.getElementById('phone').value.trim();
                    const name = document.getElementById('name').value.trim();
                    const pass = document.getElementById('pass').value.trim();
                    const action = '<?php echo $action; ?>';

                    if(!phone || !name) {
                        e.preventDefault();
                        alert('Please complete all required fields (*)');
                        return false;
                    }

                    if(action === 'create' && !pass) {
                        e.preventDefault();
                        alert('Password is required for new users');
                        return false;
                    }

                    const email = document.getElementById('email').value.trim();
                    if(email) {
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if(!emailRegex.test(email)) {
                            e.preventDefault();
                            alert('Please enter a valid email');
                            return false;
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>