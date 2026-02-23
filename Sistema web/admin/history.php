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
$query = [
    'id' => '',
    'number' => '',
    'user_question' => '',
    'faq_question_id' => '',
    'faq_answer' => '',
    'category' => 'General',
    'query_date' => '',
    'was_helpful' => ''
];


$action = 'create';
$page_title = 'Created Recrod';

// Procesar formulario
if($_POST){
    $query = [
        'number' => $_POST['number'] ?? '',
        'user_question' => $_POST['user_question'] ?? '',
        'faq_question_id' => $_POST['faq_question_id'] ?? NULL,
        'faq_answer' => $_POST['faq_answer'] ?? NULL,
        'category' => $_POST['category'] ?? 'General',
        'query_date' => $_POST['query_date'] ?? '',
        'was_helpful' => $_POST['was_helpful'] ?? NULL
    ];

    if(isset($_POST['id']) && !empty($_POST['id'])){
        $action = 'update';
        $query['id'] = $_POST['id'];
        
        $query_sql = "UPDATE query_history SET 
                  number = :number,
                  user_question = :user_question, 
                  faq_question_id = :faq_question_id,
                  faq_answer = :faq_answer,
                  category = :category, 
                  query_date = :query_date,
                  was_helpful = :was_helpful
                  WHERE id = :id";
    } else {
        $query_sql = "INSERT INTO query_history 
                  (number, user_question, faq_question_id, faq_answer, category, query_date, was_helpful)
                  VALUES 
                  (:number, :user_question, :faq_question_id, :faq_answer, :category, :query_date, :was_helpful)";
    }

    try {
        $stmt = $db->prepare($query_sql);
        
        if(isset($query['id'])){
            $stmt->bindParam(':id', $query['id']);
        }
        
        $stmt->bindParam(':number', $query['number']);
        $stmt->bindParam(':user_question', $query['user_question']);
        
        $faq_question_id = empty($query['faq_question_id']) ? NULL : $query['faq_question_id'];
        $faq_answer = empty($query['faq_answer']) ? NULL : $query['faq_answer'];
        $was_helpful = empty($query['was_helpful']) ? NULL : $query['was_helpful'];
        
        $stmt->bindParam(':faq_question_id', $faq_question_id);
        $stmt->bindParam(':faq_answer', $faq_answer);
        $stmt->bindParam(':category', $query['category']);
        $stmt->bindParam(':query_date', $query['query_date']);
        $stmt->bindParam(':was_helpful', $was_helpful);
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "Registro de consulta " . ($action == 'create' ? 'creado' : 'actualizado') . " correctamente";
            header("Location: history.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al guardar el registro de consulta: " . $e->getMessage();
    }
}

// Editar registro
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Registro de Consulta';
    
    $query_sql = "SELECT * FROM query_history WHERE id = :id";
    $stmt = $db->prepare($query_sql);
    $stmt->bindParam(':id', $_GET['edit']);
    $stmt->execute();
    
    $query = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$query){
        $_SESSION['error_message'] = "Registro no encontrado";
        header("Location: history.php");
        exit();
    }
}

// Eliminar registro
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $query_sql = "DELETE FROM query_history WHERE id = :id";
    $stmt = $db->prepare($query_sql);
    $stmt->bindParam(':id', $_GET['delete']);
    
    try {
        if($stmt->execute()){
            $_SESSION['success_message'] = "Registro eliminado correctamente";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al eliminar: " . $e->getMessage();
    }
    
    header("Location: history.php");
    exit();
}

// Obtener lista de registros
$query_sql = "SELECT * FROM query_history ORDER BY query_date DESC";
$stmt = $db->prepare($query_sql);
$stmt->execute();
$query_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Historial de Consultas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
      :root {
    --primary: #2c3e50;
    --secondary: #34495e;
    --success: #27ae60;
    --warning: #f39c12;
    --danger: #e74c3c;
    --info: #3498db;
}

* { 
    margin: 0; 
    padding: 0; 
    box-sizing: border-box; 
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
}

body { 
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
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 2rem;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 768px) {
    .page-header {
        flex-direction: row;
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
    text-align: center;
    justify-content: center;
}

.btn-primary { background: var(--info); color: white; } 
.btn-success { background: var(--success); color: white; } 
.btn-danger { background: var(--danger); color: white; }

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
    color: var(--primary); 
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
    flex-wrap: wrap;
}

@media (max-width: 480px) {
    .form-actions {
        justify-content: center;
    }
    
    .form-actions .btn {
        flex: 1;
        min-width: 120px;
    }
}

.table-container { 
    overflow-x: auto; 
    -webkit-overflow-scrolling: touch;
} 

.table { 
    width: 100%; 
    border-collapse: collapse; 
    font-size: 0.9rem;
    min-width: 800px;
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
    white-space: nowrap;
} 

.table tr:hover { 
    background: #f8f9fa; 
}

@media (max-width: 768px) {
    .table {
        font-size: 0.8rem;
    }
    
    .table th, .table td {
        padding: 0.75rem 0.5rem;
    }
}

.badge { 
    padding: 0.4rem 0.8rem; 
    border-radius: 20px; 
    font-size: 0.75rem; 
    font-weight: 600; 
    display: inline-block;
}

.badge-primary { background: #cce7ff; color: #004085; } 
.badge-info { background: #d1ecf1; color: #0c5460; } 
.badge-warning { background: #fff3cd; color: #856404; }

.actions { 
    display: flex; 
    gap: 0.5rem; 
    flex-wrap: wrap;
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
    min-width: 36px;
    min-height: 36px;
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

.text-preview { 
    max-width: 200px; 
    overflow: hidden; 
    text-overflow: ellipsis; 
    white-space: nowrap; 
}

.answer-preview { 
    max-width: 250px; 
    overflow: hidden; 
    text-overflow: ellipsis; 
    white-space: nowrap; 
}

/* Mejoras para móviles */
@media (max-width: 480px) {
    .admin-nav {
        padding: 0.5rem;
        gap: 0.25rem;
    }
    
    .admin-nav a {
        padding: 0.4rem 0.6rem;
        font-size: 0.8rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .form-grid {
        gap: 1rem;
    }
    
    .btn {
        padding: 0.6rem 1rem;
        font-size: 0.85rem;
    }
    
    .page-header h2 {
        font-size: 1.5rem;
        text-align: center;
    }
}

/* Scroll suave */
html {
    scroll-behavior: smooth;
}

/* Mejora de accesibilidad */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
    
    html {
        scroll-behavior: auto;
    }
}

/* Additional responsive improvements */
@media (max-width: 1024px) {
    .admin-container {
        padding: 1rem;
    }
    
    .card-body {
        padding: 1.25rem;
    }
}

@media (max-width: 640px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .table {
        min-width: 600px;
    }
    
    .page-header {
        text-align: center;
    }
    
    .page-header h2 {
        font-size: 1.4rem;
    }
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
    
    .btn {
        display: none;
    }
}

/* Loading animation */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.card {
    animation: fadeIn 0.5s ease-out;
}

/* Focus styles for better accessibility */
.form-control:focus,
.btn:focus,
.action-btn:focus {
    outline: 2px solid var(--info);
    outline-offset: 2px;
}

/* Hover effects enhancement */
.form-control:hover {
    border-color: #b8daff;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
}
    </style>
</head>
<body>
    <header class="admin-header">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-history"></i> Query History
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Welcome, <strong><?php echo htmlspecialchars($_SESSION['technician_role']); ?></strong> • 
                <?php echo date('d/m/Y H:i:s'); ?>
               
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
        <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Home</a>
        <a href="users.php"><i class="fas fa-users"></i> Users</a>
        <a href="departments.php"><i class="fas fa-building"></i> Departments</a>
        <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>
        <a href="urgency.php"><i class="fas fa-exclamation-triangle"></i> Urgency</a>
        <a href="technicians.php"><i class="fas fa-tools"></i> WhiteList</a>
        <a href="questions.php"><i class="fas fa-question-circle"></i> Questions</a>
        <a href="history.php" class="active"><i class="fas fa-history"></i> History</a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> Conversations Dashboard</a>
        <a href="reportetikets.php"><i class="fas fa-chart-bar"></i> Tickets Dashboard</a>
    </nav>

    <div class="admin-container">
            <div class="page-header">
            <h2 style="font-size: 2rem;">
                <i class="fas fa-question-circle"></i> <?php echo $page_title; ?>
            </h2>
               <a href="history.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

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

        <div class="card">
            <div class="card-header">
                <h3>Query Information</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="history.php">
                    <?php if($action == 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($query['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="number">Number *</label>
                            <input type="text" id="number" name="number" class="form-control" 
                                   value="<?php echo htmlspecialchars($query['number']); ?>" 
                                   required maxlength="20">
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category" class="form-control" 
                                   value="<?php echo htmlspecialchars($query['category']); ?>" 
                                   maxlength="100">
                        </div>
                        <div class="form-group">
                            <label for="faq_question_id">FAQ Question ID</label>
                            <input type="number" id="faq_question_id" name="faq_question_id" class="form-control" 
                                   value="<?php echo htmlspecialchars($query['faq_question_id']); ?>" 
                                   min="0">
                        </div>
                        <div class="form-group">
                            <label for="query_date">Date and Time *</label>
                            <input type="datetime-local" id="query_date" name="query_date" class="form-control" 
                                   value="<?php echo $query['query_date'] ? date('Y-m-d\TH:i', strtotime($query['query_date'])) : date('Y-m-d\TH:i'); ?>" 
                                   required>
                        </div>
                        <div class="form-group">
                            <label for="was_helpful">Was Helpful</label>
                            <select id="was_helpful" name="was_helpful" class="form-control">
                                <option value="">-- Select --</option>
                                <option value="yes" <?php echo ($query['was_helpful'] ?? '') == 'yes' ? 'selected' : ''; ?>>Yes</option>
                                <option value="no" <?php echo ($query['was_helpful'] ?? '') == 'no' ? 'selected' : ''; ?>>No</option>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label for="user_question">User Question *</label>
                            <textarea id="user_question" name="user_question" class="form-control" 
                                      placeholder="Enter the user's question..."
                                      required><?php echo htmlspecialchars($query['user_question']); ?></textarea>
                        </div>
                        <div class="form-group full-width">
                            <label for="faq_answer">FAQ Answer</label>
                            <textarea id="faq_answer" name="faq_answer" class="form-control"
                                      placeholder="Enter the FAQ answer..."><?php echo htmlspecialchars($query['faq_answer']); ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="history.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> 
                            <?php echo $action == 'create' ? 'Create' : 'Update'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Query Records</h3>
            </div>
            <div class="card-body">
                <?php if(count($query_history) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Number</th>
                                    <th>User Question</th>
                                    <th>FAQ Answer</th>
                                    <th>Category</th>
                                    <th>FAQ ID</th>
                                    <th>Was Helpful</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($query_history as $history): ?>
                                <tr>
                                    <td><?php echo $history['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($history['number']); ?></strong></td>
                                    <td>
                                        <div class="text-preview" title="<?php echo htmlspecialchars($history['user_question']); ?>">
                                            <?php echo htmlspecialchars($history['user_question']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="answer-preview" title="<?php echo htmlspecialchars($history['faq_answer']); ?>">
                                            <?php echo !empty($history['faq_answer']) ? htmlspecialchars($history['faq_answer']) : '—'; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($history['category']); ?></td>
                                    <td><?php echo $history['faq_question_id'] ?: '—'; ?></td>
                                    <td>
                                        <?php if(($history['was_helpful'] ?? '') == 'yes'): ?>
                                            <span class="badge badge-primary">YES</span>
                                        <?php elseif(($history['was_helpful'] ?? '') == 'no'): ?>
                                            <span class="badge badge-info">NO</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($history['query_date'])); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="history.php?edit=<?php echo $history['id']; ?>" 
                                               class="action-btn edit-btn" 
                                               title="Edit record">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="history.php?delete=<?php echo $history['id']; ?>" 
                                               class="action-btn delete-btn" 
                                               title="Delete record"
                                               onclick="return confirm('Are you sure you want to delete this record?')">
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
                        <i class="fas fa-history"></i>
                        <h3>No records</h3>
                        <p>Start by creating the first record.</p>
                    </div>
                <?php endif; ?>
            </div>
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
        <div>Created by Erick Dumas &copy; <?php echo date('Y'); ?> | v1.0.0</div>
    </footer>

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

        document.addEventListener('DOMContentLoaded', function() {
            // Delete confirmation
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    if(!confirm('Are you sure you want to delete this record?')) {
                        e.preventDefault();
                    }
                });
            });

            // Basic form validation
            document.querySelector('form').addEventListener('submit', function(e) {
                const number = document.getElementById('number').value.trim();
                const userQuestion = document.getElementById('user_question').value.trim();
                const queryDate = document.getElementById('query_date').value.trim();
                
                if(!number || !userQuestion || !queryDate) {
                    e.preventDefault();
                    alert('Please complete all required fields (*)');
                    return false;
                }
            });

            // Improve mobile experience
            if (window.innerWidth < 768) {
                // Adjust textarea height on mobile
                const textareas = document.querySelectorAll('textarea');
                textareas.forEach(textarea => {
                    textarea.style.minHeight = '120px';
                });
            }

            // Prevent zoom on inputs in iOS
            document.addEventListener('touchstart', function() {}, {passive: true});
        });

        // Handle window resizing
        window.addEventListener('resize', function() {
            // You can add additional logic if needed
        });
    </script>
</body>
</html>