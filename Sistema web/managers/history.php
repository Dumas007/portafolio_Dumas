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
if(!isset($_SESSION['technician_id']) || strpos($_SESSION['technician_role'], 'dep_') !== 0){
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

// Obtener el área del usuario logueado
$user_area = $_SESSION['technician_role']; // dep_nombre_area

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
$page_title = 'Conversations';

// Procesar formulario - ELIMINADO PARA EVITAR AGREGAR
if($_POST){
    $_SESSION['error_message'] = "No tiene permisos para agregar o modificar registros";
    header("Location: history.php");
    exit();
}

// Editar registro - ELIMINADO PARA EVITAR MODIFICACIONES
if(isset($_GET['edit']) && !empty($_GET['edit'])){
    $_SESSION['error_message'] = "No tiene permisos para editar registros";
    header("Location: history.php");
    exit();
}

// Eliminar registro - ELIMINADO PARA EVITAR ELIMINACIONES
if(isset($_GET['delete']) && !empty($_GET['delete'])){
    $_SESSION['error_message'] = "No tiene permisos para eliminar registros";
    header("Location: history.php");
    exit();
}

// Obtener lista de registros FILTRADO POR ÁREA DEL USUARIO
$query_sql = "SELECT * FROM query_history 
              WHERE category = :user_area 
              ORDER BY query_date DESC";
$stmt = $db->prepare($query_sql);
$stmt->bindParam(':user_area', $user_area);
$stmt->execute();
$query_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Query History Management <?php echo $_SESSION['technician_role']; ?></title>
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
            .admin-nav {
                padding: 1rem 2rem;
                gap: 1.5rem;
            }
            
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
            text-align: center;
            justify-content: center;
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

        /* TABLAS */
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

        /* BADGES */
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

        /* INFO BOXES */
        .area-info {
            background: #e8f4fd;
            border-left: 4px solid var(--info);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .read-only-message {
            background: #fff3cd;
            border-left: 4px solid var(--warning);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            text-align: center;
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
    </style>
</head>
<body>
    <!-- Header -->
    <header class="admin-header">
        <div class="header-content">
            <h1>
                <i class="fas fa-history"></i> Query History
                <span class="department-badge">
                    <i class="fas fa-building"></i> <?php echo strtoupper($user_area); ?>
                </span>
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Welcome, <strong><?php echo htmlspecialchars($_SESSION['technician_name']); ?></strong> • 
                Department: <strong><?php echo htmlspecialchars($user_area); ?></strong> • 
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
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
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

        <div class="area-info">
            <h3><i class="fas fa-info-circle"></i> Area Information</h3>
            <p>You are viewing only records from the area: <strong><?php echo htmlspecialchars($user_area); ?></strong></p>
        </div>

       

        <div class="card">
            <div class="card-header">
                <h3>Query Records - Area <?php echo htmlspecialchars($user_area); ?></h3>
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
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No records in your area</h3>
                        <p>No query records found for area <?php echo htmlspecialchars($user_area); ?>.</p>
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

        document.addEventListener('DOMContentLoaded', function() {
            // Mejorar experiencia en móviles
            if (window.innerWidth < 768) {
                // Ajustar el alto de textareas en móviles
                const textareas = document.querySelectorAll('textarea');
                textareas.forEach(textarea => {
                    textarea.style.minHeight = '120px';
                });
            }

            // Prevenir zoom en inputs en iOS
            document.addEventListener('touchstart', function() {}, {passive: true});
        });

        // Manejar redimensionamiento de ventana
        window.addEventListener('resize', function() {
            // Puedes agregar lógica adicional si es necesario
        });
    </script>
</body>
</html>