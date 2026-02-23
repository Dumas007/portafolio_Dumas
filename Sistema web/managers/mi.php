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
if(!isset($_SESSION['technician_id']) || strpos($_SESSION['technician_role'], 'dep_') !== 0){
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

// Obtener información del usuario logueado
$current_user_id = $_SESSION['technician_id'];
$current_user_role = $_SESSION['technician_role'];
$current_user_name = $_SESSION['technician_name'];

// OBTENER ESTADÍSTICAS PARA LAS CARDS - SOLO DEL USUARIO LOGEADO
$stats = [];

// Total de técnicos - SOLO EL USUARIO ACTUAL
$query_total = "SELECT COUNT(*) as total FROM technicians WHERE id = :user_id";
$stmt_total = $db->prepare($query_total);
$stmt_total->bindParam(':user_id', $current_user_id);
$stmt_total->execute();
$stats['total_technicians'] = $stmt_total->fetchColumn();

// Técnicos activos - SOLO EL USUARIO ACTUAL
$query_active = "SELECT COUNT(*) as active FROM technicians WHERE id = :user_id AND active = 'yes'";
$stmt_active = $db->prepare($query_active);
$stmt_active->bindParam(':user_id', $current_user_id);
$stmt_active->execute();
$stats['active_technicians'] = $stmt_active->fetchColumn();

// Técnicos con datos faciales - SOLO EL USUARIO ACTUAL
$query_facial = "SELECT COUNT(*) as facial FROM technicians WHERE id = :user_id AND facial_data IS NOT NULL AND facial_data != '' AND facial_data != 'NULL'";
$stmt_facial = $db->prepare($query_facial);
$stmt_facial->bindParam(':user_id', $current_user_id);
$stmt_facial->execute();
$stats['technicians_with_face'] = $stmt_facial->fetchColumn();

// Técnicos por rol - SOLO EL USUARIO ACTUAL
$query_roles = "SELECT role, COUNT(*) as count FROM technicians WHERE id = :user_id AND role IS NOT NULL GROUP BY role";
$stmt_roles = $db->prepare($query_roles);
$stmt_roles->bindParam(':user_id', $current_user_id);
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
$page_title = 'Mi Perfil';

// CORRECCIÓN: Primero procesar las acciones GET antes del POST
// Eliminar técnico - SOLO PERMITIR ELIMINAR SU PROPIO USUARIO
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $delete_id = filter_var($_GET['delete'], FILTER_VALIDATE_INT);
    
    // Verificar que solo pueda eliminar su propio usuario
    if ($delete_id !== $current_user_id) {
        $_SESSION['error_message'] = "No tiene permisos para eliminar otros usuarios";
        header("Location: users.php");
        exit();
    }
    
    $query = "DELETE FROM technicians WHERE id = :id AND id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $delete_id);
    $stmt->bindParam(':user_id', $current_user_id);
    
    try {
        if($stmt->execute()){
            // Si elimina su propio usuario, cerrar sesión
            session_unset();
            session_destroy();
            $_SESSION['success_message'] = "Su cuenta ha sido eliminada correctamente";
            header("Location: ../login.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al eliminar el técnico: " . $e->getMessage();
    }
    
    header("Location: users.php");
    exit();
}

// Editar técnico - SOLO PERMITIR EDITAR SU PROPIO USUARIO
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Mi Perfil';
    
    // Validar que solo pueda editar su propio usuario
    $edit_id = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($edit_id === false || $edit_id !== $current_user_id) {
        $_SESSION['error_message'] = "Solo puede editar su propio perfil";
        header("Location: users.php");
        exit();
    }
    
    $query = "SELECT * FROM technicians WHERE id = :id AND id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $edit_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);
    
    if($stmt->execute()){
        $technician = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$technician){
            $_SESSION['error_message'] = "Técnico no encontrado";
            header("Location: users.php");
            exit();
        }
        
        // No mostrar la contraseña actual por seguridad
        $technician['pass'] = '';
    } else {
        $_SESSION['error_message'] = "Error al cargar el técnico";
        header("Location: users.php");
        exit();
    }
}

// Procesar formulario POST - SOLO PERMITIR ACTUALIZAR SU PROPIO USUARIO
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

    // VERIFICAR QUE SOLO PUEDA ACTUALIZAR SU PROPIO USUARIO
    if(isset($_POST['id']) && !empty($_POST['id'])){
        // Actualizar usuario/técnico
        $action = 'update';
        $technician['id'] = $_POST['id'];
        
        // Validar que solo pueda actualizar su propio usuario
        $technician_id = filter_var($technician['id'], FILTER_VALIDATE_INT);
        if ($technician_id === false || $technician_id !== $current_user_id) {
            $_SESSION['error_message'] = "Solo puede actualizar su propio perfil";
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
                      WHERE id = :id AND id = :user_id";
        } else {
            // Si se proporciona contraseña, encriptarla
            $hashed_password = password_hash($technician['pass'], PASSWORD_DEFAULT);
            $query = "UPDATE technicians SET 
                      phone = :phone,
                      name = :name, 
                      email = :email, 
                      password = :pass,
                      facial_data = :facial_data, 
                      role = :role, 
                      active = :active
                      WHERE id = :id AND id = :user_id";
        }
    } else {
        // NO PERMITIR CREAR NUEVOS USUARIOS
        $_SESSION['error_message'] = "No tiene permisos para crear nuevos usuarios";
        header("Location: users.php");
        exit();
    }

    try {
        $stmt = $db->prepare($query);
        
        if(isset($technician['id'])){
            $stmt->bindParam(':id', $technician_id, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);
        }
        
        $stmt->bindParam(':phone', $technician['phone']);
        $stmt->bindParam(':name', $technician['name']);
        $stmt->bindParam(':email', $technician['email']);
        $stmt->bindParam(':facial_data', $technician['facial_data']);
        $stmt->bindParam(':role', $technician['role']);
        $stmt->bindParam(':active', $technician['active']);
        
        // Solo bindear password si se está actualizando con nueva contraseña
        if(!empty($technician['pass'])) {
            $stmt->bindParam(':pass', $hashed_password);
        }
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "Perfil actualizado correctamente";
            
            // Actualizar datos de sesión si se cambió el nombre
            if(isset($technician['name'])) {
                $_SESSION['technician_name'] = $technician['name'];
            }
            
            header("Location: users.php");
            exit();
        } else {
            throw new Exception("Error en la ejecución de la consulta");
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al guardar el técnico: " . $e->getMessage();
        error_log("Error saving technician: " . $e->getMessage());
    }
}

// Obtener lista de técnicos - SOLO EL USUARIO ACTUAL
$query = "SELECT * FROM technicians WHERE id = :user_id ORDER BY name";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $current_user_id);
$stmt->execute();
$technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener roles para el select - SOLO DEL USUARIO ACTUAL
$query_roles = "SELECT DISTINCT role FROM technicians WHERE id = :user_id AND role IS NOT NULL ORDER BY role";
$stmt_roles = $db->prepare($query_roles);
$stmt_roles->bindParam(':user_id', $current_user_id);
$stmt_roles->execute();
$roles = $stmt_roles->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Usuario</title>
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
        .stat-card.roles .stat-icon { color: #9b59b6; }

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

        .user-info-banner {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            text-align: center;
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
    </style>
</head>
<body>
    <!-- Header -->
    <header class="admin-header">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-user"></i> Mi Perfil
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Bienvenido, <strong><?php echo htmlspecialchars($current_user_name); ?></strong> • 
                Rol: <strong><?php echo htmlspecialchars($current_user_role); ?></strong> • 
                <?php echo date('d/m/Y H:i:s'); ?>
                <span class="session-timer" id="sessionTimer">
                    <i class="fas fa-clock"></i>
                    <span id="timeRemaining">03:00:00</span>
                </span>
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
        <a href="users.php" class="active"><i class="fas fa-user"></i> Mi Perfil</a>
        <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>
        <a href="questions.php"><i class="fas fa-question-circle"></i> Preguntas</a>
        <a href="history.php"><i class="fas fa-history"></i> Historial</a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> Reportes</a>
    </nav>

    <!-- Main Content -->
    <div class="admin-container">
        <!-- Aviso de permisos limitados -->
    

        <!-- Page Header -->
        <div class="page-header">
            <h2 style="font-size: 2rem;">
                <i class="fas fa-user-cog"></i> <?php echo $page_title; ?>
            </h2>
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Volver al Dashboard
            </a>
        </div>

        <!-- Cards de Estadísticas PERSONALES -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="stat-number">1</div>
                <div class="stat-label">MI PERFIL</div>
                <div class="stat-subtext">Información personal</div>
            </div>
            
            <div class="stat-card active">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?php echo $stats['active_technicians']; ?></div>
                <div class="stat-label">ESTADO</div>
                <div class="stat-subtext">
                    <?php echo $stats['active_technicians'] == 1 ? 'Activo' : 'Inactivo'; ?>
                </div>
            </div>
            
            <div class="stat-card facial">
                <div class="stat-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="stat-number"><?php echo $stats['technicians_with_face']; ?></div>
                <div class="stat-label">RECONOCIMIENTO FACIAL</div>
                <div class="stat-subtext">
                    <?php echo $stats['technicians_with_face'] == 1 ? 'Configurado' : 'No configurado'; ?>
                </div>
            </div>
            
            <div class="stat-card roles">
                <div class="stat-icon">
                    <i class="fas fa-user-tag"></i>
                </div>
                <div class="stat-number">1</div>
                <div class="stat-label">MI ROL</div>
                <div class="stat-subtext"><?php echo htmlspecialchars($current_user_role); ?></div>
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

        <!-- Formulario de Técnico -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-user-edit"></i> Información Personal</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="users.php" id="technicianForm">
                    <?php if($action == 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($technician['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="phone"><i class="fas fa-id-card"></i> Número de técnico *</label>
                            <input type="text" id="phone" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($technician['phone']); ?>" 
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
                            <label for="pass"><i class="fas fa-lock"></i> Nueva Contraseña</label>
                            <input type="password" id="pass" name="pass" class="form-control" 
                                   value="<?php echo htmlspecialchars($technician['pass']); ?>"
                                   maxlength="255">
                            <div class="password-note">
                                Dejar en blanco para mantener la contraseña actual
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="facial_data"><i class="fas fa-user-circle"></i> Datos Faciales</label>
                            <textarea id="facial_data" name="facial_data" class="form-control textarea-monospace" 
                                      rows="6" 
                                      placeholder="Datos de reconocimiento facial (embedding) - Campo de solo lectura"
                                      maxlength="65535"
                                      readonly
                                      style="background-color: #f8f9fa; cursor: not-allowed;"><?php 
                                echo htmlspecialchars($technician['facial_data'] ?? ''); 
                            ?></textarea>
                            <div class="facial-data-info">
                                <?php 
                                $facial_length = strlen($technician['facial_data'] ?? '');
                                if($facial_length > 0) {
                                    echo "Longitud actual: " . $facial_length . " caracteres";
                                    if($facial_length > 1000) {
                                        echo " (datos extensos)";
                                    }
                                } else {
                                    echo "Sin datos faciales registrados";
                                }
                                ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="role"><i class="fas fa-user-tag"></i> Rol</label>
                            <input type="text" id="role" name="role" class="form-control" 
                                   value="<?php echo htmlspecialchars($technician['role']); ?>" 
                                   readonly
                                   style="background-color: #f8f9fa; cursor: not-allowed;">
                            <div class="password-note">
                                El rol no se puede modificar
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
                        <a href="users.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Actualizar Perfil
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Información del Usuario Actual -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-info-circle"></i> Mi Información</h3>
            </div>
            <div class="card-body">
                <?php if(count($technicians) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Número</th>
                                    <th>Nombre</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th>Datos Faciales</th>
                                    <th>Fecha Registro</th>
                                    <th>Acciones</th>
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
                                        <?php 
                                        $role_display = htmlspecialchars($tech['role']);
                                        if(!empty($role_display)) {
                                            echo '<span class="badge badge-info">' . $role_display . '</span>';
                                        } else {
                                            echo '<span class="badge badge-secondary">Sin rol</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if($tech['active'] == 'yes'): ?>
                                            <span class="badge badge-success">ACTIVO</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">INACTIVO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($tech['facial_data'])): ?>
                                            <span class="badge badge-success" title="Datos faciales presentes">
                                                <i class="fas fa-check"></i>  Registrada
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">
                                                <i class="fas fa-times"></i> Sin datos
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($tech['registration_date'])); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="users.php?edit=<?php echo $tech['id']; ?>" 
                                               class="action-btn edit-btn" title="Editar Mi Perfil">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="users.php?delete=<?php echo $tech['id']; ?>" 
                                               class="action-btn delete-btn" 
                                               title="Eliminar Mi Cuenta"
                                               onclick="return confirm('¿Está seguro de eliminar su cuenta? Esta acción no se puede deshacer.')">
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
                        <i class="fas fa-user-slash"></i>
                        <h3>No se encontró información del usuario</h3>
                        <p>Contacte al administrador del sistema.</p>
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
        <div>Created by Erick Dumas &copy; <?php echo date('Y'); ?> | v1.0.0 | Usuario: <?php echo htmlspecialchars($current_user_name); ?></div>
    </footer>

    <script>
        // Timer de sesión
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

        // Confirmación antes de eliminar
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.delete-btn');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if(!confirm('¿Está completamente seguro de que desea eliminar su cuenta? Esta acción es irreversible y perderá todo acceso al sistema.')) {
                        e.preventDefault();
                    }
                });
            });

            // Validación del formulario
            const form = document.getElementById('technicianForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const phone = document.getElementById('phone').value.trim();
                    const name = document.getElementById('name').value.trim();

                    if(!phone || !name) {
                        e.preventDefault();
                        alert('Por favor, complete todos los campos obligatorios (*)');
                        return false;
                    }

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
            }
        });
    </script>
</body>
</html>