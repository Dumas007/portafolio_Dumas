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

// NORMALIZACIÓN - Si el rol es 'dep_sale' pero en preguntas es 'dep_sales'
if ($current_user_role == 'dep_sale') {
    $user_department = 'dep_sales';
} else {
    $user_department = $current_user_role;
}

// Variables para el formulario
$question = [
    'id' => '',
    'question' => '',
    'answer' => '',
    'category' => $user_department, // Usar el departamento del usuario logueado por defecto
    'keywords' => '',
    'active' => 'yes'
];

$action = 'create';
$page_title = 'Question - ' . htmlspecialchars($user_department);

// Definir categorías predefinidas - SOLO LA CATEGORÍA DEL USUARIO LOGEADO
$categories = [
    $user_department => $user_department // Solo mostrar la categoría del usuario
];

// Procesar formulario - SOLO PERMITIR ACTUALIZACIONES, NO CREACIONES
if($_POST){
    // VERIFICAR SI ES UNA ACTUALIZACIÓN VÁLIDA (TIENE ID)
    if(!isset($_POST['id']) || empty($_POST['id'])) {
        $_SESSION['error_message'] = "No tiene permisos para crear nuevas preguntas";
        header("Location: questions.php");
        exit();
    }
    
    $question = [
        'question' => $_POST['question'] ?? '',
        'answer' => $_POST['answer'] ?? '',
        'category' => $user_department, // Forzar la categoría del usuario logueado
        'keywords' => $_POST['keywords'] ?? '',
        'active' => $_POST['active'] ?? 'yes'
    ];

    // SOLO PERMITIR ACTUALIZACIÓN SI TIENE ID
    $action = 'update';
    $question['id'] = $_POST['id'];
    
    $query = "UPDATE questions SET 
              question = :question,
              answer = :answer, 
              category = :category, 
              keywords = :keywords,
              active = :active,
              last_access = NOW()
              WHERE id = :id AND category = :user_department";

    try {
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':id', $question['id']);
        $stmt->bindParam(':user_department', $user_department); // Para la cláusula WHERE en update
        
        $stmt->bindParam(':question', $question['question']);
        $stmt->bindParam(':answer', $question['answer']);
        $stmt->bindParam(':category', $question['category']);
        $stmt->bindParam(':keywords', $question['keywords']);
        $stmt->bindParam(':active', $question['active']);
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "Pregunta actualizada correctamente";
            header("Location: questions.php");
            exit();
        } else {
            $_SESSION['error_message'] = "No se pudo actualizar la pregunta o no tienes permisos";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al actualizar la pregunta: " . $e->getMessage();
    }
}

// Editar pregunta - SOLO SI PERTENECE AL DEPARTAMENTO DEL USUARIO
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Pregunta - ' . htmlspecialchars($user_department);
    
    $query = "SELECT * FROM questions WHERE id = :id AND category = :department";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['edit']);
    $stmt->bindParam(':department', $user_department);
    $stmt->execute();
    
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$question){
        $_SESSION['error_message'] = "Pregunta no encontrada o no tienes permisos para editarla";
        header("Location: questions.php");
        exit();
    }
}

// Eliminar pregunta - SOLO SI PERTENECE AL DEPARTAMENTO DEL USUARIO
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $query = "DELETE FROM questions WHERE id = :id AND category = :department";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['delete']);
    $stmt->bindParam(':department', $user_department);
    
    try {
        if($stmt->execute()){
            $_SESSION['success_message'] = "Pregunta eliminada correctamente";
        } else {
            $_SESSION['error_message'] = "No se pudo eliminar la pregunta o no tienes permisos";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al eliminar la pregunta: " . $e->getMessage();
    }
    
    header("Location: questions.php");
    exit();
}

// Obtener lista de preguntas - SOLO DEL DEPARTAMENTO DEL USUARIO
$query = "SELECT * FROM questions WHERE category = :department ORDER BY creation_date DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user_department);
$stmt->execute();
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas para el departamento del usuario
$stats = [
    'total' => count($questions),
    'active' => 0,
    'inactive' => 0
];

foreach ($questions as $q) {
    if ($q['active'] == 'yes') {
        $stats['active']++;
    } else {
        $stats['inactive']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Questions Management - Admin</title>
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

        /* CARDS */
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

        /* STATS */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid var(--info);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .stat-subtitle {
            font-size: 0.75rem;
            color: #999;
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

        .fixed-category {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            padding: 0.75rem;
            border-radius: 8px;
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
        }

        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce7ff; color: #004085; }
        .badge-primary { background: #d1ecf1; color: #0c5460; }

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

        /* PREVIEWS */
        .question-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .answer-preview {
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
    <!-- Header -->
    <header class="admin-header">
        <div class="header-content">
            <h1>
                <i class="fas fa-question-circle"></i> Knowledge Base
                <span class="department-badge">
                    <i class="fas fa-building"></i> <?php echo strtoupper($user_department); ?>
                </span>
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Welcome, <strong><?php echo htmlspecialchars($_SESSION['technician_name']); ?></strong> • 
                Department: <strong><?php echo htmlspecialchars($user_department); ?></strong> • 
                <?php echo date('m/d/Y H:i:s'); ?>
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
        <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>
        <a href="questions.php" class="active"><i class="fas fa-question-circle"></i> Questions</a>
        <a href="history.php"><i class="fas fa-history"></i> History</a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> Convertations Dashboard</a>
        <a href="reportetikets.php"><i class="fas fa-chart-bar"></i> Ticket Dashboard</a>
    </nav>
    <!-- Main Content -->
    <div class="admin-container">
        <!-- Page Header -->
        <div class="page-header">
            <h2 style="font-size: 2rem;">
                <i class="fas fa-question-circle"></i> <?php echo $page_title; ?>
            </h2>
            <?php if(isset($_GET['edit'])): ?>
                <a href="questions.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to list
                </a>
            <?php else: ?>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
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

        <!-- Department Statistics -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Questions</div>
                <div class="stat-subtitle">In <?php echo htmlspecialchars($user_department); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active']; ?></div>
                <div class="stat-label">Active Questions</div>
                <div class="stat-subtitle">Available for users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['inactive']; ?></div>
                <div class="stat-label">Inactive Questions</div>
                <div class="stat-subtitle">Not available</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">1</div>
                <div class="stat-label">Department</div>
                <div class="stat-subtitle"><?php echo htmlspecialchars($user_department); ?></div>
            </div>
        </div>

        <!-- Question Form - ONLY SHOWN IN EDIT MODE -->
        <?php if(isset($_GET['edit'])): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-edit"></i> Edit Question - <?php echo htmlspecialchars($user_department); ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" action="questions.php">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($question['id']); ?>">

                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="question"><i class="fas fa-question"></i> Question *</label>
                            <textarea id="question" name="question" class="form-control" 
                                      placeholder="Enter the complete question..."
                                      required readonly><?php echo htmlspecialchars($question['question']); ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label for="answer"><i class="fas fa-comment-dots"></i> Answer *</label>
                            <textarea id="answer" name="answer" class="form-control" 
                                      placeholder="Provide the complete answer..."
                                      required readonly><?php echo htmlspecialchars($question['answer']); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="category"><i class="fas fa-tag"></i> Department</label>
                            <div class="fixed-category">
                                <i class="fas fa-lock"></i> <?php echo htmlspecialchars($user_department); ?>
                            </div>
                            <input type="hidden" name="category" value="<?php echo htmlspecialchars($user_department); ?>" readonly>
                            <div class="field-info">
                                <i class="fas fa-info-circle"></i> Fixed department assigned to your area
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="active"><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="active" name="active" class="form-control" required>
                                <option value="yes" <?php echo $question['active'] == 'yes' ? 'selected' : ''; ?>>Active</option>
                                <option value="no" <?php echo $question['active'] == 'no' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label for="keywords"><i class="fas fa-key"></i> Keywords</label>
                            <textarea id="keywords" name="keywords" class="form-control" 
                                      placeholder="Keywords separated by commas"
                                      maxlength="65535" readonly><?php echo htmlspecialchars($question['keywords']); ?></textarea>
                            <div class="form-text">
                                <i class="fas fa-info-circle"></i> Important terms for searches
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="questions.php" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Question
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <!-- Message when no active editing -->
        <?php endif; ?>

        <!-- Department Questions List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Knowledge Base - <?php echo htmlspecialchars($user_department); ?></h3>
            </div>
            <div class="card-body">
                <?php if(count($questions) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Question</th>
                                    <th>Answer</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Creation Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($questions as $q): ?>
                                <tr>
                                    <td style="font-weight: 600;">#<?php echo htmlspecialchars($q['id']); ?></td>
                                    <td>
                                        <div class="question-preview" title="<?php echo htmlspecialchars($q['question']); ?>">
                                            <?php echo htmlspecialchars(substr($q['question'], 0, 60)) . (strlen($q['question']) > 60 ? '...' : ''); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="answer-preview" title="<?php echo htmlspecialchars($q['answer']); ?>">
                                            <?php echo htmlspecialchars(substr($q['answer'], 0, 80)) . (strlen($q['answer']) > 80 ? '...' : ''); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($q['category']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($q['active'] == 'yes'): ?>
                                            <span class="badge badge-success">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">INACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($q['creation_date'])); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="questions.php?edit=<?php echo $q['id']; ?>" 
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
                        <i class="fas fa-question-circle"></i>
                        <h3>No questions in <?php echo htmlspecialchars($user_department); ?></h3>
                        <p>Contact the administrator to add questions to your department.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
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

        // Verificar estado del usuario cada 30 segundos
        function checkUserStatus() {
            fetch('../check_user_status.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.active) {
                        // Usuario inactivo - cerrar sesión
                        window.location.href = '../login.php?error=user_inactive';
                    }
                })
                .catch(error => {
                    console.error('Error checking user status:', error);
                });
        }

        // Verificar estado cada 30 segundos
        setInterval(checkUserStatus, 30000);

        // Confirmación antes de eliminar
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.delete-btn');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if(!confirm('¿Estás seguro de que deseas eliminar esta pregunta?')) {
                        e.preventDefault();
                    }
                });
            });
        });

        // Validación del formulario - SOLO para el formulario de edición
        const questionForm = document.querySelector('form[method="POST"]');
        if (questionForm) {
            questionForm.addEventListener('submit', function(e) {
                const question = document.getElementById('question')?.value.trim();
                const answer = document.getElementById('answer')?.value.trim();

                // Solo validar si los elementos existen (estamos en modo edición)
                if (question !== undefined && answer !== undefined) {
                    if(!question || !answer) {
                        e.preventDefault();
                        alert('Por favor, complete todos los campos obligatorios (*)');
                        return false;
                    }

                    if(question.length < 5) {
                        e.preventDefault();
                        alert('La pregunta debe tener al menos 5 caracteres');
                        return false;
                    }

                    if(answer.length < 10) {
                        e.preventDefault();
                        alert('La respuesta debe tener al menos 10 caracteres');
                        return false;
                    }
                }
            });
        }

        // Auto-generar palabras clave basadas en la pregunta - SOLO en modo edición
        const questionInput = document.getElementById('question');
        if (questionInput) {
            questionInput.addEventListener('blur', function() {
                const question = this.value.trim().toLowerCase();
                const keywordsTextarea = document.getElementById('keywords');
                
                if(question && keywordsTextarea && !keywordsTextarea.value.trim()) {
                    // Extraer palabras clave (eliminar palabras comunes)
                    const commonWords = ['que', 'como', 'cuando', 'donde', 'porque', 'cual', 'quien', 'cuyo', 'para', 'con', 'sin', 'sobre', 'entre', 'hacia', 'hasta', 'desde', 'durante', 'mediante', 'excepto', 'ademas', 'tambien', 'asi', 'bien', 'entonces', 'ahora', 'antes', 'despues', 'si', 'si', 'no', 'ya', 'aun', 'todavia', 'siempre', 'nunca', 'jamas', 'tal', 'vez', 'quizas', 'puede', 'podria', 'debe', 'deberia', 'tener', 'tiene', 'tenia', 'hacer', 'hace', 'hacia', 'ser', 'es', 'era', 'estar', 'esta', 'estaba', 'ir', 'va', 'iba', 'ver', 've', 'veia', 'saber', 'sabe', 'sabia', 'pensar', 'piensa', 'pensaba', 'decir', 'dice', 'decia', 'dar', 'da', 'daba', 'encontrar', 'encuentra', 'encontraba'];
                    
                    const words = question.split(/\s+/)
                        .filter(word => word.length > 3 && !commonWords.includes(word.toLowerCase()))
                        .slice(0, 10); // Máximo 10 palabras clave
                    
                    if(words.length > 0) {
                        keywordsTextarea.value = words.join(', ');
                    }
                }
            });
        }
    </script>
</body>
</html>