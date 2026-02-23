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

if(!isset($_SESSION['technician_id']) || $_SESSION['technician_role'] != 'Admin'){
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

// Variables para el formulario
$question = [
    'id' => '',
    'question' => '',
    'answer' => '',
    'category' => '',
    'keywords' => '',
    'active' => 'yes'
];

$action = 'create';
$page_title = 'Created Question';

// Definir categorías predefinidas
$categories = [
    'dep_customer_service' => 'Customer Service',
    'dep_it' => 'IT',
    'dep_sales' => 'Sales',
    'dep_hr' => 'Human Resources',
    'dep_backoffice' => 'Back Office',
    'dep_finance' => 'Finance',
    'dep_workforce' => 'WorkForce',
    'dep_dispatch' => 'Dispatch',
    'dep_marketing' => 'Marketing',
    'other' => 'Otro',
];

// Procesar formulario
if($_POST){
    $question = [
        'question' => $_POST['question'] ?? '',
        'answer' => $_POST['answer'] ?? '',
        'category' => $_POST['category'] ?? 'general',
        'keywords' => $_POST['keywords'] ?? '',
        'active' => $_POST['active'] ?? 'yes'
    ];

    if(isset($_POST['id']) && !empty($_POST['id'])){
        // Actualizar pregunta
        $action = 'update';
        $question['id'] = $_POST['id'];
        
        $query = "UPDATE questions SET 
                  question = :question,
                  answer = :answer, 
                  category = :category, 
                  keywords = :keywords,
                  active = :active,
                  last_access = NOW()
                  WHERE id = :id";
    } else {
        // Crear pregunta
        $query = "INSERT INTO questions 
                  (question, answer, category, keywords, active, creation_date, last_access) 
                  VALUES 
                  (:question, :answer, :category, :keywords, :active, NOW(), NOW())";
    }

    try {
        $stmt = $db->prepare($query);
        
        if(isset($question['id'])){
            $stmt->bindParam(':id', $question['id']);
        }
        
        $stmt->bindParam(':question', $question['question']);
        $stmt->bindParam(':answer', $question['answer']);
        $stmt->bindParam(':category', $question['category']);
        $stmt->bindParam(':keywords', $question['keywords']);
        $stmt->bindParam(':active', $question['active']);
        
        if($stmt->execute()){
            $_SESSION['success_message'] = "question " . ($action == 'create' ? 'creada' : 'Update') . " ok";
            header("Location: questions.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al guardar la pregunta: " . $e->getMessage();
    }
}

// Editar pregunta
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $action = 'update';
    $page_title = 'Editar Pregunta';
    
    $query = "SELECT * FROM questions WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['edit']);
    $stmt->execute();
    
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$question){
        $_SESSION['error_message'] = "Pregunta no encontrada";
        header("Location: questions.php");
        exit();
    }
}

// Eliminar pregunta
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $query = "DELETE FROM questions WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['delete']);
    
    try {
        if($stmt->execute()){
            $_SESSION['success_message'] = "Pregunta eliminada correctamente";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error al eliminar la pregunta: " . $e->getMessage();
    }
    
    header("Location: questions.php");
    exit();
}

// Búsqueda de preguntas
$search_query = "";
$where_conditions = [];
$query_params = [];

if(isset($_GET['search']) && !empty($_GET['search'])){
    $search_query = trim($_GET['search']);
    $search_term = "%{$search_query}%";
    
    $where_conditions[] = "(question LIKE :search OR answer LIKE :search OR keywords LIKE :search)";
    $query_params[':search'] = $search_term;
}

// Construir consulta base
$base_query = "SELECT * FROM questions";
if(!empty($where_conditions)){
    $base_query .= " WHERE " . implode(" AND ", $where_conditions);
}
$base_query .= " ORDER BY creation_date DESC";

// Obtener lista de preguntas
$stmt = $db->prepare($base_query);
foreach($query_params as $key => $value){
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas por categoría
$category_stats = [];
foreach ($categories as $key => $name) {
    $category_stats[$key] = [
        'name' => $name,
        'total' => 0,
        'active' => 0
    ];
}

foreach ($questions as $q) {
    $category = $q['category'];
    if (isset($category_stats[$category])) {
        $category_stats[$category]['total']++;
        if ($q['active'] == 'yes') {
            $category_stats[$category]['active']++;
        }
    }
}

// Contar preguntas sin categoría definida
$uncategorized = [
    'total' => 0,
    'active' => 0
];

foreach ($questions as $q) {
    if (!isset($categories[$q['category']])) {
        $uncategorized['total']++;
        if ($q['active'] == 'yes') {
            $uncategorized['active']++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Management - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS styles remain the same, only adding search bar styles */
        
        .search-container {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .search-form {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .search-input-group {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 2px solid #e9ecef;
            border-radius: 25px;
            font-size: 1rem;
            transition: all 0.3s;
            background: #f8f9fa;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--info);
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .search-btn {
            background: var(--info);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .clear-search {
            background: var(--secondary);
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .clear-search:hover {
            background: #2c3e50;
            transform: translateY(-2px);
        }

        .search-results-info {
            margin-top: 1rem;
            padding: 0.75rem;
            background: #e3f2fd;
            border-radius: 8px;
            border-left: 4px solid var(--info);
        }

        .search-highlight {
            background: #fff3cd;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .search-input-group {
                width: 100%;
            }
            
            .search-btn,
            .clear-search {
                width: 100%;
                justify-content: center;
            }
        }

        /* Rest of the CSS styles remain exactly the same */
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
        }

        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce7ff; color: #004085; }
        .badge-primary { background: #d1ecf1; color: #0c5460; }

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

        .stat-card.general { border-left-color: #3498db; }
        .stat-card.technical { border-left-color: #e74c3c; }
        .stat-card.billing { border-left-color: #f39c12; }
        .stat-card.support { border-left-color: #27ae60; }
        .stat-card.hr { border-left-color: #9b59b6; }
        .stat-card.it { border-left-color: #34495e; }
        .stat-card.finance { border-left-color: #16a085; }
        .stat-card.operations { border-left-color: #d35400; }
        .stat-card.sales { border-left-color: #c0392b; }
        .stat-card.marketing { border-left-color: #8e44ad; }
        .stat-card.other { border-left-color: #7f8c8d; }

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

        .category-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .category-stat-card {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 3px solid;
        }

        .category-stat-card.general { border-top-color: #3498db; }
        .category-stat-card.technical { border-top-color: #e74c3c; }
        .category-stat-card.billing { border-top-color: #f39c12; }
        .category-stat-card.support { border-top-color: #27ae60; }
        .category-stat-card.hr { border-top-color: #9b59b6; }
        .category-stat-card.it { border-top-color: #34495e; }
        .category-stat-card.finance { border-top-color: #16a085; }
        .category-stat-card.operations { border-top-color: #d35400; }
        .category-stat-card.sales { border-top-color: #c0392b; }
        .category-stat-card.marketing { border-top-color: #8e44ad; }
        .category-stat-card.other { border-top-color: #7f8c8d; }

        .category-stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .category-stat-label {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
        }

        .category-stat-active {
            font-size: 0.7rem;
            color: #27ae60;
            margin-top: 0.25rem;
        }

        /* Responsive improvements */
        @media (max-width: 768px) {
            .stats-container,
            .category-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
            
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
                <i class="fas fa-question-circle"></i> Knowledge Base - Questions
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
        <a href="urgency.php"><i class="fas fa-exclamation-triangle"></i> Urgency</a>
        <a href="technicians.php"><i class="fas fa-tools"></i> WhiteList</a>
        <a href="questions.php" class="active"><i class="fas fa-question-circle"></i> Questions</a>
        <a href="history.php"><i class="fas fa-history"></i> History</a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> Conversations Dashboard</a>
        <a href="reportetikets.php"><i class="fas fa-chart-bar"></i> Tickets Dashboard</a>
    </nav>

    <!-- Main Content -->
    <div class="admin-container">
        <!-- Page Header -->
        <div class="page-header">
            <h2 style="font-size: 2rem;">
                <i class="fas fa-question-circle"></i> <?php echo $page_title; ?>
            </h2>
            <a href="questions.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to list
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

        <!-- Search Bar -->
        <div class="search-container">
            <form method="GET" action="questions.php" class="search-form">
                <div class="search-input-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" 
                           name="search" 
                           class="search-input" 
                           placeholder="Search questions, answers or keywords..."
                           value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if(!empty($search_query)): ?>
                    <a href="questions.php" class="clear-search">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
            
            <?php if(!empty($search_query)): ?>
                <div class="search-results-info">
                    <i class="fas fa-info-circle"></i> 
                    Found <strong><?php echo count($questions); ?></strong> questions matching 
                    "<span class="search-highlight"><?php echo htmlspecialchars($search_query); ?></span>"
                </div>
            <?php endif; ?>
        </div>

        <!-- General Statistics -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($questions); ?></div>
                <div class="stat-label">Total Questions</div>
                <div class="stat-subtitle">Across all Departments</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                        $active_count = array_filter($questions, function($q) { 
                            return $q['active'] == 'yes'; 
                        });
                        echo count($active_count);
                    ?>
                </div>
                <div class="stat-label">Active Questions</div>
                <div class="stat-subtitle">Available for users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                        $inactive_count = array_filter($questions, function($q) { 
                            return $q['active'] == 'no'; 
                        });
                        echo count($inactive_count);
                    ?>
                </div>
                <div class="stat-label">Inactive Questions</div>
                <div class="stat-subtitle">Not available</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($categories); ?></div>
                <div class="stat-label">Departments</div>
                <div class="stat-subtitle">Different Departments</div>
            </div>
        </div>

        <!-- Question Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-question-circle"></i> Question Information</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="questions.php">
                    <?php if($action == 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($question['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="question"><i class="fas fa-question"></i> Question *</label>
                            <textarea id="question" name="question" class="form-control" 
                                      placeholder="Enter the complete question..."
                                      required><?php echo htmlspecialchars($question['question']); ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label for="answer"><i class="fas fa-comment-dots"></i> Answer *</label>
                            <textarea id="answer" name="answer" class="form-control" 
                                      placeholder="Provide the complete answer..."
                                      required><?php echo htmlspecialchars($question['answer']); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="category"><i class="fas fa-tag"></i> Department *</label>
                            <select id="category" name="category" class="form-control" required>
                                <?php foreach($categories as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" 
                                        <?php echo ($question['category'] == $value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                                      maxlength="65535"><?php echo htmlspecialchars($question['keywords']); ?></textarea>
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
                            <i class="fas fa-save"></i> 
                            <?php echo $action == 'create' ? 'Create Question' : 'Update Question'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Questions List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Knowledge Base</h3>
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
                                            <?php echo htmlspecialchars($categories[$q['category']] ?? $q['category']); ?>
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
                                            <a href="questions.php?delete=<?php echo $q['id']; ?>" 
                                               class="action-btn delete-btn" 
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this question?')">
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
                        <i class="fas fa-question-circle"></i>
                        <h3>
                            <?php if(!empty($search_query)): ?>
                                No questions found matching your search
                            <?php else: ?>
                                No questions in the knowledge base
                            <?php endif; ?>
                        </h3>
                        <p>
                            <?php if(!empty($search_query)): ?>
                                Try different search terms or <a href="questions.php">clear the search</a>
                            <?php else: ?>
                                Start by adding the first question using the form above.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Minimal footer -->
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

        // Confirmation before deleting
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.delete-btn');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if(!confirm('Are you sure you want to delete this question?')) {
                        e.preventDefault();
                    }
                });
            });
        });

        // Form validation
document.querySelector('form[method="POST"]').addEventListener('submit', function(e) {
            const question = document.getElementById('question').value.trim();
            const answer = document.getElementById('answer').value.trim();
            const category = document.getElementById('category').value;

            if(!question || !answer || !category) {
                e.preventDefault();
                alert('Please complete all required fields (*)');
                return false;
            }

            if(question.length < 5) {
                e.preventDefault();
                alert('Question must be at least 5 characters long');
                return false;
            }

            if(answer.length < 10) {
                e.preventDefault();
                alert('Answer must be at least 10 characters long');
                return false;
            }
        });

        // Auto-generate keywords based on question
        document.getElementById('question').addEventListener('blur', function() {
            const question = this.value.trim().toLowerCase();
            const keywordsTextarea = document.getElementById('keywords');
            
            if(question && !keywordsTextarea.value.trim()) {
                // Extract keywords (remove common English words)
                const commonWords = ['what', 'how', 'when', 'where', 'why', 'which', 'who', 'whom', 'whose', 'for', 'with', 'without', 'about', 'between', 'toward', 'towards', 'until', 'till', 'since', 'during', 'through', 'throughout', 'except', 'besides', 'also', 'too', 'as', 'well', 'so', 'then', 'now', 'before', 'after', 'if', 'whether', 'yes', 'no', 'already', 'yet', 'still', 'always', 'never', 'ever', 'either', 'neither', 'perhaps', 'maybe', 'may', 'might', 'can', 'could', 'shall', 'should', 'will', 'would', 'must', 'ought', 'have', 'has', 'had', 'do', 'does', 'did', 'am', 'is', 'are', 'was', 'were', 'be', 'being', 'been', 'get', 'got', 'gotten', 'make', 'made', 'take', 'took', 'taken', 'come', 'came', 'go', 'went', 'gone', 'see', 'saw', 'seen', 'know', 'knew', 'known', 'think', 'thought', 'feel', 'felt', 'say', 'said', 'tell', 'told', 'ask', 'asked', 'give', 'gave', 'given', 'put', 'set', 'let', 'leave', 'left', 'keep', 'kept', 'hold', 'held', 'bring', 'brought', 'buy', 'bought', 'sell', 'sold', 'pay', 'paid', 'cost', 'run', 'ran', 'begin', 'began', 'begun', 'become', 'became', 'seem', 'appear', 'look', 'find', 'found', 'use', 'used', 'try', 'tried', 'call', 'called', 'need', 'needed', 'want', 'wanted', 'help', 'helped', 'work', 'worked', 'play', 'played', 'turn', 'turned', 'start', 'started', 'show', 'showed', 'shown', 'hear', 'heard', 'live', 'lived', 'believe', 'believed', 'mean', 'meant', 'change', 'changed', 'move', 'moved', 'follow', 'followed', 'stop', 'stopped', 'create', 'created', 'speak', 'spoke', 'spoken', 'read', 'write', 'wrote', 'written', 'provide', 'provided', 'serve', 'served', 'sit', 'sat', 'stand', 'stood', 'lose', 'lost', 'add', 'added', 'continue', 'continued', 'decide', 'decided', 'open', 'opened', 'walk', 'walked', 'win', 'won', 'offer', 'offered', 'remember', 'remembered', 'love', 'loved', 'consider', 'considered', 'appear', 'appeared', 'wait', 'waited', 'serve', 'served', 'die', 'died', 'send', 'sent', 'expect', 'expected', 'build', 'built', 'stay', 'stayed', 'fall', 'fell', 'fallen', 'cut', 'cut', 'reach', 'reached', 'kill', 'killed', 'remain', 'remained', 'suggest', 'suggested', 'raise', 'raised', 'pass', 'passed', 'sell', 'sold', 'require', 'required', 'report', 'reported', 'learn', 'learned', 'lead', 'led', 'understand', 'understood', 'watch', 'watched', 'agree', 'agreed', 'allow', 'allowed', 'meet', 'met', 'include', 'included', 'cover', 'covered', 'develop', 'developed', 'thank', 'thanked', 'receive', 'received', 'return', 'returned', 'draw', 'drew', 'drawn', 'explain', 'explained', 'hope', 'hoped', 'enter', 'entered', 'occur', 'occurred', 'realize', 'realized', 'face', 'faced', 'break', 'broke', 'broken', 'support', 'supported', 'identify', 'identified', 'design', 'designed', 'complete', 'completed', 'mention', 'mentioned', 'state', 'stated', 'prepare', 'prepared', 'argue', 'argued', 'establish', 'established', 'prove', 'proved', 'proven', 'claim', 'claimed', 'choose', 'chose', 'chosen', 'deal', 'dealt', 'apply', 'applied', 'improve', 'improved', 'refer', 'referred', 'act', 'acted', 'achieve', 'achieved', 'seek', 'sought', 'plan', 'planned', 'accept', 'accepted', 'recognize', 'recognized', 'indicate', 'indicated', 'remember', 'remembered', 'assume', 'assumed', 'notice', 'noticed', 'wonder', 'wondered', 'obtain', 'obtained', 'describe', 'described', 'base', 'based', 'destroy', 'destroyed', 'enjoy', 'enjoyed', 'involve', 'involved', 'contain', 'contained', 'arise', 'arose', 'arisen', 'represent', 'represented', 'solve', 'solved', 'rise', 'rose', 'risen', 'define', 'defined', 'determine', 'determined', 'encourage', 'encouraged', 'protect', 'protected', 'manage', 'managed', 'publish', 'published', 'catch', 'caught', 'pick', 'picked', 'wear', 'wore', 'worn', 'eat', 'ate', 'eaten', 'drink', 'drank', 'drunk', 'drive', 'drove', 'driven', 'fly', 'flew', 'flown', 'throw', 'threw', 'thrown', 'swim', 'swam', 'swum', 'sing', 'sang', 'sung', 'dance', 'danced', 'sleep', 'slept', 'wake', 'woke', 'woken', 'lie', 'lay', 'lain', 'sit', 'sat', 'stand', 'stood', 'run', 'ran', 'walk', 'walked', 'jump', 'jumped', 'climb', 'climbed', 'fall', 'fell', 'crawl', 'crawled', 'roll', 'rolled', 'slide', 'slid', 'spin', 'spun', 'turn', 'turned', 'bend', 'bent', 'stretch', 'stretched', 'lift', 'lifted', 'carry', 'carried', 'push', 'pushed', 'pull', 'pulled', 'hold', 'held', 'touch', 'touched', 'feel', 'felt', 'smell', 'smelled', 'taste', 'tasted', 'hear', 'heard', 'see', 'saw', 'watch', 'watched', 'look', 'looked', 'notice', 'noticed', 'observe', 'observed', 'recognize', 'recognized', 'identify', 'identified', 'discover', 'discovered', 'find', 'found', 'search', 'searched', 'explore', 'explored', 'investigate', 'investigated', 'study', 'studied', 'learn', 'learned', 'teach', 'taught', 'instruct', 'instructed', 'educate', 'educated', 'train', 'trained', 'practice', 'practiced', 'exercise', 'exercised', 'play', 'played', 'game', 'gamed', 'compete', 'competed', 'win', 'won', 'lose', 'lost', 'score', 'scored', 'beat', 'beat', 'defeat', 'defeated', 'succeed', 'succeeded', 'fail', 'failed', 'achieve', 'achieved', 'accomplish', 'accomplished', 'complete', 'completed', 'finish', 'finished', 'end', 'ended', 'stop', 'stopped', 'start', 'started', 'begin', 'began', 'commence', 'commenced', 'initiate', 'initiated', 'launch', 'launched', 'create', 'created', 'make', 'made', 'build', 'built', 'construct', 'constructed', 'develop', 'developed', 'produce', 'produced', 'generate', 'generated', 'form', 'formed', 'shape', 'shaped', 'design', 'designed', 'plan', 'planned', 'organize', 'organized', 'arrange', 'arranged', 'prepare', 'prepared', 'set', 'set', 'fix', 'fixed', 'repair', 'repaired', 'maintain', 'maintained', 'preserve', 'preserved', 'protect', 'protected', 'defend', 'defended', 'guard', 'guarded', 'secure', 'secured', 'save', 'saved', 'rescue', 'rescued', 'help', 'helped', 'assist', 'assisted', 'support', 'supported', 'aid', 'aided', 'serve', 'served', 'benefit', 'benefited', 'improve', 'improved', 'enhance', 'enhanced', 'better', 'bettered', 'progress', 'progressed', 'advance', 'advanced', 'develop', 'developed', 'grow', 'grew', 'grown', 'increase', 'increased', 'expand', 'expanded', 'extend', 'extended', 'enlarge', 'enlarged', 'rise', 'rose', 'raise', 'raised', 'lift', 'lifted', 'elevate', 'elevated', 'promote', 'promoted', 'boost', 'boosted', 'strengthen', 'strengthened', 'reinforce', 'reinforced', 'fortify', 'fortified', 'build', 'built', 'construct', 'constructed', 'create', 'created', 'make', 'made', 'form', 'formed', 'establish', 'established', 'found', 'founded', 'set', 'set', 'install', 'installed', 'place', 'placed', 'put', 'put', 'position', 'positioned', 'locate', 'located', 'situate', 'situated', 'arrange', 'arranged', 'organize', 'organized', 'order', 'ordered', 'classify', 'classified', 'sort', 'sorted', 'group', 'grouped', 'categorize', 'categorized', 'label', 'labeled', 'name', 'named', 'call', 'called', 'title', 'titled', 'term', 'termed', 'describe', 'described', 'define', 'defined', 'explain', 'explained', 'clarify', 'clarified', 'illustrate', 'illustrated', 'demonstrate', 'demonstrated', 'show', 'showed', 'prove', 'proved', 'confirm', 'confirmed', 'verify', 'verified', 'validate', 'validated', 'authenticate', 'authenticated', 'certify', 'certified', 'approve', 'approved', 'authorize', 'authorized', 'permit', 'permitted', 'allow', 'allowed', 'let', 'let', 'enable', 'enabled', 'empower', 'empowered', 'entitle', 'entitled', 'qualify', 'qualified', 'deserve', 'deserved', 'merit', 'merited', 'earn', 'earned', 'gain', 'gained', 'acquire', 'acquired', 'obtain', 'obtained', 'get', 'got', 'gotten', 'receive', 'received', 'accept', 'accepted', 'take', 'took', 'taken', 'collect', 'collected', 'gather', 'gathered', 'accumulate', 'accumulated', 'amass', 'amassed', 'save', 'saved', 'store', 'stored', 'keep', 'kept', 'hold', 'held', 'preserve', 'preserved', 'maintain', 'maintained', 'retain', 'retained', 'possess', 'possessed', 'own', 'owned', 'have', 'had', 'hold', 'held', 'bear', 'bore', 'born', 'carry', 'carried', 'bring', 'brought', 'take', 'took', 'fetch', 'fetched', 'get', 'got', 'grab', 'grabbed', 'seize', 'seized', 'capture', 'captured', 'catch', 'caught', 'trap', 'trapped', 'arrest', 'arrested', 'detain', 'detained', 'stop', 'stopped', 'halt', 'halted', 'cease', 'ceased', 'end', 'ended', 'finish', 'finished', 'complete', 'completed', 'conclude', 'concluded', 'terminate', 'terminated', 'close', 'closed', 'shut', 'shut', 'seal', 'sealed', 'lock', 'locked', 'secure', 'secured', 'fasten', 'fastened', 'attach', 'attached', 'connect', 'connected', 'join', 'joined', 'link', 'linked', 'unite', 'united', 'combine', 'combined', 'merge', 'merged', 'mix', 'mixed', 'blend', 'blended', 'integrate', 'integrated', 'incorporate', 'incorporated', 'include', 'included', 'contain', 'contained', 'hold', 'held', 'accommodate', 'accommodated', 'house', 'housed', 'shelter', 'sheltered', 'protect', 'protected', 'defend', 'defended', 'guard', 'guarded', 'shield', 'shielded', 'cover', 'covered', 'hide', 'hid', 'hidden', 'conceal', 'concealed', 'disguise', 'disguised', 'mask', 'masked', 'camouflage', 'camouflaged', 'secret', 'secreted', 'bury', 'buried', 'store', 'stored', 'save', 'saved', 'keep', 'kept', 'preserve', 'preserved', 'maintain', 'maintained', 'retain', 'retained', 'hold', 'held', 'possess', 'possessed', 'own', 'owned', 'have', 'had'];
                
                const words = question.split(/\s+/)
                    .filter(word => word.length > 3 && !commonWords.includes(word.toLowerCase()))
                    .slice(0, 10); // Maximum 10 keywords
                
                if(words.length > 0) {
                    keywordsTextarea.value = words.join(', ');
                }
            }
        });

        // Focus search input when page loads if there's a search query
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if(searchInput && searchInput.value) {
                searchInput.focus();
                searchInput.select();
            }
        });
    </script>
</body>
</html>