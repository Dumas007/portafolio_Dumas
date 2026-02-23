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

// Variables para el formulario
$urgency = [
    'id' => '',
    'urgency_code' => '',
    'urgency_name' => '',
    'description' => '',
    'level' => '',
    'keywords' => '',
    'active' => 1
];

$action = 'create';
$page_title = 'Create level Urgency';

// Procesar formulario
if($_POST){
    $urgency = [
        'urgency_code' => $_POST['urgency_code'] ?? '',
        'urgency_name' => $_POST['urgency_name'] ?? '',
        'description' => $_POST['description'] ?? '',
        'level' => $_POST['level'] ?? '',
        'keywords' => $_POST['keywords'] ?? '',
        'active' => $_POST['active'] ?? 1
    ];

    if(isset($_POST['id']) && !empty($_POST['id'])){
        // Actualizar nivel de urgencia
        $action = 'update';
        $urgency['id'] = $_POST['id'];
        
        $query = "UPDATE urgency_levels SET 
                  urgency_code = :urgency_code,
                  urgency_name = :urgency_name, 
                  description = :description,
                  level = :level,
                  keywords = :keywords, 
                  active = :active 
                  WHERE id = :id";
    } else {
        // Crear nivel de urgencia
        $query = "INSERT INTO urgency_levels 
                  (urgency_code, urgency_name, description, level, keywords, active, creation_date) 
                  VALUES 
                  (:urgency_code, :urgency_name, :description, :level, :keywords, :active, NOW())";
    }

    try {
        $stmt = $db->prepare($query);
        
        if(isset($urgency['id'])){
            $stmt->bindParam(':id', $urgency['id']);
        }
        
        $stmt->bindParam(':urgency_code', $urgency['urgency_code']);
        $stmt->bindParam(':urgency_name', $urgency['urgency_name']);
        $stmt->bindParam(':description', $urgency['description']);
        $stmt->bindParam(':level', $urgency['level']);
        $stmt->bindParam(':keywords', $urgency['keywords']);
        $stmt->bindParam(':active', $urgency['active']);
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "Nivel de urgencia " . ($action == 'create' ? 'creado' : 'actualizado') . " correctamente";
            header("Location: urgency.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al guardar el nivel de urgencia: " . $e->getMessage();
    }
}

// Editar nivel de urgencia
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Nivel de Urgencia';
    
    $query = "SELECT * FROM urgency_levels WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['edit']);
    $stmt->execute();
    
    $urgency = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$urgency){
        $_SESSION['error_message'] = "Nivel de urgencia no encontrado";
        header("Location: urgency.php");
        exit();
    }
}

// Eliminar nivel de urgencia
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $query = "DELETE FROM urgency_levels WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['delete']);
    
    try {
        if($stmt->execute()){
            $_SESSION['success_message'] = "Nivel de urgencia eliminado correctamente";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al eliminar el nivel de urgencia: " . $e->getMessage();
    }
    
    header("Location: urgency.php");
    exit();
}

// Obtener lista de niveles de urgencia
$query = "SELECT * FROM urgency_levels ORDER BY level";
$stmt = $db->prepare($query);
$stmt->execute();
$urgency_levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urgency Levels Management - Admin</title>
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

.description-preview {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.level-indicator {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    font-weight: bold;
    color: white;
}

.level-1 { background: #e74c3c; }
.level-2 { background: #f39c12; }
.level-3 { background: #f1c40f; }
.level-4 { background: #2ecc71; }
.level-5 { background: #3498db; }
.level-6 { background: #9b59b6; }
.level-7 { background: #1abc9c; }
.level-8 { background: #d35400; }
.level-9 { background: #c0392b; }
.level-10 { background: #7f8c8d; }

/* Additional styles for visual improvements */
.form-text {
    font-size: 0.8rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.form-text i {
    margin-right: 0.25rem;
}

/* Responsive improvements */
@media (max-width: 768px) {
    .table {
        font-size: 0.8rem;
    }
    
    .table th, 
    .table td {
        padding: 0.5rem;
    }
    
    .btn {
        padding: 0.6rem 1rem;
        font-size: 0.8rem;
    }
    
    .card-header h3 {
        font-size: 1.1rem;
    }
}

/* Animation for better user experience */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.card {
    animation: fadeIn 0.3s ease-out;
}

/* Hover effects for better interactivity */
.form-control:hover {
    border-color: #b8daff;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.1);
}

/* Loading states */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
}

/* Focus styles for accessibility */
.form-control:focus,
.btn:focus {
    outline: 2px solid var(--info);
    outline-offset: 2px;
}

/* Print styles */
@media print {
    .admin-nav,
    .admin-header div:last-child,
    .form-actions,
    .actions {
        display: none;
    }
    
    .card {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}
    </style>
</head>
<body>
    <!-- Header -->
    <header class="admin-header">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-exclamation-triangle"></i> Urgency Levels Management
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
        <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>
        <a href="urgency.php" class="active"><i class="fas fa-exclamation-triangle"></i> Urgency</a>
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
                <i class="fas fa-exclamation-triangle"></i> <?php echo $page_title; ?>
            </h2>
            <a href="urgency.php" class="btn btn-primary">
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

        <!-- Urgency Level Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Urgency Level Information</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="urgency.php">
                    <?php if($action == 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($urgency['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="urgency_code"><i class="fas fa-code"></i> Urgency Code *</label>
                            <input type="text" id="urgency_code" name="urgency_code" class="form-control" 
                                   value="<?php echo htmlspecialchars($urgency['urgency_code']); ?>" 
                                   required maxlength="20" placeholder="Ex: CRIT, HIGH, MED, LOW">
                        </div>

                        <div class="form-group">
                            <label for="urgency_name"><i class="fas fa-signature"></i> Urgency Name *</label>
                            <input type="text" id="urgency_name" name="urgency_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($urgency['urgency_name']); ?>" 
                                   required maxlength="100" placeholder="Ex: Critical, High, Medium, Low">
                        </div>

                        <div class="form-group">
                            <label for="level"><i class="fas fa-sort-numeric-down"></i> Level *</label>
                            <input type="number" id="level" name="level" class="form-control" 
                                   value="<?php echo htmlspecialchars($urgency['level']); ?>" 
                                   required min="1" max="10" placeholder="1-10">
                        </div>

                        <div class="form-group">
                            <label for="active"><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="active" name="active" class="form-control" required>
                                <option value="1" <?php echo $urgency['active'] == 1 ? 'selected' : ''; ?>>Active</option>
                                <option value="0" <?php echo $urgency['active'] == 0 ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label for="description"><i class="fas fa-align-left"></i> Description</label>
                            <textarea id="description" name="description" class="form-control" 
                                      placeholder="Detailed description of the urgency level"
                                      maxlength="65535"><?php echo htmlspecialchars($urgency['description']); ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label for="keywords"><i class="fas fa-key"></i> Keywords</label>
                            <textarea id="keywords" name="keywords" class="form-control" 
                                      placeholder="Keywords separated by commas for searches"
                                      maxlength="65535"><?php echo htmlspecialchars($urgency['keywords']); ?></textarea>
                            <div class="form-text">
                                <i class="fas fa-info-circle"></i> Separate keywords with commas. Ex: critical, urgent, high priority
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="urgency.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> 
                            <?php echo $action == 'create' ? 'Create Urgency Level' : 'Update Urgency Level'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Urgency Levels List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Urgency Levels List</h3>
            </div>
            <div class="card-body">
                <?php if(count($urgency_levels) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Level</th>
                                    <th>Description</th>
                                    <th>Keywords</th>
                                    <th>Status</th>
                                    <th>Creation Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($urgency_levels as $level): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($level['urgency_code']); ?></td>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($level['urgency_name']); ?></td>
                                    <td>
                                        <span class="level-indicator level-<?php echo min($level['level'], 5); ?>">
                                            <?php echo $level['level']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="description-preview" title="<?php echo htmlspecialchars($level['description']); ?>">
                                            <?php echo !empty($level['description']) ? htmlspecialchars($level['description']) : '—'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="keywords-preview" title="<?php echo htmlspecialchars($level['keywords']); ?>">
                                            <?php echo !empty($level['keywords']) ? htmlspecialchars($level['keywords']) : '—'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($level['active'] == 1): ?>
                                            <span class="badge badge-success">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">INACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($level['creation_date'])); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="urgency.php?edit=<?php echo $level['id']; ?>" 
                                               class="action-btn edit-btn" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="urgency.php?delete=<?php echo $level['id']; ?>" 
                                               class="action-btn delete-btn" 
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this urgency level?')">
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
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>No urgency levels registered</h3>
                        <p>Start by adding the first urgency level using the form above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
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
                    if(!confirm('Are you sure you want to delete this urgency level?')) {
                        e.preventDefault();
                    }
                });
            });
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const code = document.getElementById('urgency_code').value.trim();
            const name = document.getElementById('urgency_name').value.trim();
            const level = document.getElementById('level').value.trim();

            if(!code || !name || !level) {
                e.preventDefault();
                alert('Please complete all required fields (*)');
                return false;
            }

            // Validate that code only contains letters, numbers and hyphens
            const codeRegex = /^[A-Za-z0-9\-_]+$/;
            if(!codeRegex.test(code)) {
                e.preventDefault();
                alert('Urgency code can only contain letters, numbers, hyphens and underscores');
                return false;
            }

            // Validate that level is a number between 1 and 10
            const levelNum = parseInt(level);
            if(isNaN(levelNum) || levelNum < 1 || levelNum > 10) {
                e.preventDefault();
                alert('Level must be a number between 1 and 10');
                return false;
            }
        });

        // Auto-generate code if empty based on name
        document.getElementById('urgency_name').addEventListener('blur', function() {
            const codeInput = document.getElementById('urgency_code');
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
    </script>
</body>
</html>