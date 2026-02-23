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

// Variables para el formulario
$department = [
    'id' => '',
    'department_code' => '',
    'department_name' => '',
    'keywords' => '',
    'active' => 1
];

$action = 'create';
$page_title = 'Create Departament';

// Procesar formulario
if($_POST){
    $department = [
        'department_code' => $_POST['department_code'] ?? '',
        'department_name' => $_POST['department_name'] ?? '',
        'keywords' => $_POST['keywords'] ?? '',
        'active' => $_POST['active'] ?? 1
    ];

    if(isset($_POST['id']) && !empty($_POST['id'])){
        // Actualizar departamento
        $action = 'update';
        $department['id'] = $_POST['id'];
        
        $query = "UPDATE departments SET 
                  department_code = :department_code,
                  department_name = :department_name, 
                  keywords = :keywords, 
                  active = :active 
                  WHERE id = :id";
    } else {
        // Crear departamento
        $query = "INSERT INTO departments 
                  (department_code, department_name, keywords, active, creation_date) 
                  VALUES 
                  (:department_code, :department_name, :keywords, :active, NOW())";
    }

    try {
        $stmt = $db->prepare($query);
        
        if(isset($department['id'])){
            $stmt->bindParam(':id', $department['id']);
        }
        
        $stmt->bindParam(':department_code', $department['department_code']);
        $stmt->bindParam(':department_name', $department['department_name']);
        $stmt->bindParam(':keywords', $department['keywords']);
        $stmt->bindParam(':active', $department['active']);
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "Departamento " . ($action == 'create' ? 'creado' : 'actualizado') . " correctamente";
            header("Location: departments.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al guardar el departamento: " . $e->getMessage();
    }
}

// Editar departamento
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Departamento';
    
    $query = "SELECT * FROM departments WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['edit']);
    $stmt->execute();
    
    $department = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$department){
        $_SESSION['error_message'] = "Departamento no encontrado";
        header("Location: departments.php");
        exit();
    }
}

// Eliminar departamento
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $query = "DELETE FROM departments WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['delete']);
    
    try {
        if($stmt->execute()){
            $_SESSION['success_message'] = "Departamento eliminado correctamente";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al eliminar el departamento: " . $e->getMessage();
    }
    
    header("Location: departments.php");
    exit();
}

// Obtener lista de departamentos
$query = "SELECT * FROM departments ORDER BY department_name";
$stmt = $db->prepare($query);
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Management - Admin</title>
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

        textarea.form-control {
            min-height: 100px;
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

        .keywords-preview {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
                <i class="fas fa-building"></i> Department Management
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
        <a href="departments.php" class="active"><i class="fas fa-building"></i> Departments</a>
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
                <i class="fas fa-building"></i> <?php echo $page_title; ?>
            </h2>
            <a href="departments.php" class="btn btn-primary">
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

        <!-- Department Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-building"></i> Department Information</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="departments.php">
                    <?php if($action == 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($department['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="department_code"><i class="fas fa-code"></i> Department Code *</label>
                            <input type="text" id="department_code" name="department_code" class="form-control" 
                                   value="<?php echo htmlspecialchars($department['department_code']); ?>" 
                                   required maxlength="20" placeholder="Ex: IT, HR, FIN">
                        </div>

                        <div class="form-group">
                            <label for="department_name"><i class="fas fa-signature"></i> Department Name *</label>
                            <input type="text" id="department_name" name="department_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($department['department_name']); ?>" 
                                   required maxlength="100" placeholder="Ex: Technology, Human Resources">
                        </div>

                        <div class="form-group full-width">
                            <label for="keywords"><i class="fas fa-key"></i> Keywords</label>
                            <textarea id="keywords" name="keywords" class="form-control" 
                                      placeholder="Keywords separated by commas for searches"
                                      maxlength="65535"><?php echo htmlspecialchars($department['keywords']); ?></textarea>
                            <div class="form-text">
                                <i class="fas fa-info-circle"></i> Separate keywords with commas. Ex: technology, systems, IT, computers
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="active"><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="active" name="active" class="form-control" required>
                                <option value="1" <?php echo $department['active'] == 1 ? 'selected' : ''; ?>>Active</option>
                                <option value="0" <?php echo $department['active'] == 0 ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="departments.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> 
                            <?php echo $action == 'create' ? 'Create Department' : 'Update Department'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Departments List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Departments List</h3>
            </div>
            <div class="card-body">
                <?php if(count($departments) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Keywords</th>
                                    <th>Status</th>
                                    <th>Creation Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($departments as $dept): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($dept['department_code']); ?></td>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($dept['department_name']); ?></td>
                                    <td>
                                        <div class="keywords-preview" title="<?php echo htmlspecialchars($dept['keywords']); ?>">
                                            <?php echo !empty($dept['keywords']) ? htmlspecialchars($dept['keywords']) : '—'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($dept['active'] == 1): ?>
                                            <span class="badge badge-success">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">INACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($dept['creation_date'])); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="departments.php?edit=<?php echo $dept['id']; ?>" 
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
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <h3>No departments registered</h3>
                        <p>Start by adding the first department using the form above.</p>
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
                    if(!confirm('Are you sure you want to delete this department?')) {
                        e.preventDefault();
                    }
                });
            });
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const code = document.getElementById('department_code').value.trim();
            const name = document.getElementById('department_name').value.trim();

            if(!code || !name) {
                e.preventDefault();
                alert('Please complete all required fields (*)');
                return false;
            }

            // Validate that code only contains letters, numbers and hyphens
            const codeRegex = /^[A-Za-z0-9\-_]+$/;
            if(!codeRegex.test(code)) {
                e.preventDefault();
                alert('Department code can only contain letters, numbers, hyphens and underscores');
                return false;
            }
        });

        // Auto-generate code if empty based on name
        document.getElementById('department_name').addEventListener('blur', function() {
            const codeInput = document.getElementById('department_code');
            const nameInput = this.value.trim();
            
            if(nameInput && !codeInput.value.trim()) {
                // Generate automatic code (first letters of each word in uppercase)
                const code = nameInput.split(' ')
                    .map(word => word.charAt(0).toUpperCase())
                    .join('')
                    .substring(0, 10);
                
                codeInput.value = code;
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