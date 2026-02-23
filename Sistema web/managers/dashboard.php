<?php
session_start();

// Configurar tiempo máximo de inactividad (3 horas)
$inactivity_timeout = 10800; // 10800 segundos = 3 horas

// Verificar si existe el timestamp de última actividad
if (isset($_SESSION['last_activity'])) {
    // Calcular el tiempo transcurrido desde la última actividad
    $elapsed_time = time() - $_SESSION['last_activity'];
    
    // Si ha pasado más tiempo del permitido, cerrar sesión
    if ($elapsed_time > $inactivity_timeout) {
        session_unset();
        session_destroy();
        header("Location: ../login.php?timeout=1");
        exit();
    }
}

// Actualizar el timestamp de última actividad
$_SESSION['last_activity'] = time();

// Verificar autenticación y rol de técnico
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

// VERIFICACIÓN CORREGIDA DE DATOS FACIALES - CONSULTA DIRECTA MEJORADA
$query_facial_check = "SELECT facial_data FROM technicians WHERE id = :id AND facial_data IS NOT NULL AND facial_data != 'NULL' AND facial_data != '' AND facial_data != '[]'";
$stmt_facial_check = $db->prepare($query_facial_check);
$stmt_facial_check->bindParam(':id', $_SESSION['technician_id']);
$stmt_facial_check->execute();

$facial_data_result = $stmt_facial_check->fetch(PDO::FETCH_ASSOC);
$has_facial_data = ($facial_data_result && !empty($facial_data_result['facial_data']));

// Actualizar sesión
$_SESSION['has_facial_data'] = $has_facial_data;

// DEBUG: Verificar estado facial
error_log("Facial data check for user " . $_SESSION['technician_id'] . ": " . ($has_facial_data ? "REGISTERED" : "NOT REGISTERED"));
if ($facial_data_result) {
    error_log("Facial data length: " . strlen($facial_data_result['facial_data']));
}

// DEBUG TEMPORAL - Ver todos los técnicos con facial data
$query_all_facial = "SELECT id, name, facial_data, LENGTH(facial_data) as data_length FROM technicians WHERE facial_data IS NOT NULL AND facial_data != 'NULL'";
$stmt_all_facial = $db->prepare($query_all_facial);
$stmt_all_facial->execute();
$all_with_facial = $stmt_all_facial->fetchAll(PDO::FETCH_ASSOC);

error_log("=== USUARIOS CON FACIAL DATA EN DB ===");
foreach ($all_with_facial as $user) {
    $has_data = !empty($user['facial_data']) && $user['facial_data'] != '[]';
    error_log("ID: " . $user['id'] . " - " . $user['name'] . " - Data Length: " . $user['data_length'] . " - Presente: " . ($has_data ? "SÍ" : "NO"));
}
error_log("Total con facial data: " . count($all_with_facial));

// Contador para mostrar popup cada 10 logins
if(!isset($_SESSION['login_count'])) {
    $_SESSION['login_count'] = 1;
} else {
    $_SESSION['login_count']++;
}

$show_popup = !$has_facial_data && ($_SESSION['login_count'] % 10 == 0);

// Obtener datos de la sesión
$current_user_id = $_SESSION['technician_id'];
$current_user_role = $_SESSION['technician_role'];

// NORMALIZACIÓN - Si el rol es 'dep_sale' pero en tickets es 'dep_sales'
if ($current_user_role == 'dep_sale') {
    $user_department = 'dep_sales';
} else {
    $user_department = $current_user_role;
}

// DEBUG: Ver qué estamos recibiendo
error_log("Dashboard - Technician Role: " . $current_user_role);
error_log("Dashboard - User Department (normalizado): " . $user_department);

// Obtener estadísticas básicas FILTRADAS POR ROL DEL TÉCNICO (NO por department)
$stats = [];

// Técnicos del mismo rol (departamento)
$query_technicians = "SELECT COUNT(*) as total FROM technicians WHERE role = :role";
$stmt_technicians = $db->prepare($query_technicians);
$stmt_technicians->bindParam(':role', $user_department);
$stmt_technicians->execute();
$stats['total_technicians'] = $stmt_technicians->fetchColumn();

$query_active_technicians = "SELECT COUNT(*) as active FROM technicians WHERE active = 'yes' AND role = :role";
$stmt_active_technicians = $db->prepare($query_active_technicians);
$stmt_active_technicians->bindParam(':role', $user_department);
$stmt_active_technicians->execute();
$stats['active_technicians'] = $stmt_active_technicians->fetchColumn();

// CORREGIDO: Consulta para técnicos con datos faciales
$query_facial_technicians = "SELECT COUNT(*) as facial FROM technicians WHERE facial_data IS NOT NULL AND facial_data != 'NULL' AND facial_data != '' AND facial_data != '[]' AND role = :role";
$stmt_facial_technicians = $db->prepare($query_facial_technicians);
$stmt_facial_technicians->bindParam(':role', $user_department);
$stmt_facial_technicians->execute();
$stats['technicians_with_face'] = $stmt_facial_technicians->fetchColumn();

// Tickets del departamento del técnico
$query_tickets = "SELECT COUNT(*) as total FROM tickets WHERE department = :department";
$stmt_tickets = $db->prepare($query_tickets);
$stmt_tickets->bindParam(':department', $user_department);
$stmt_tickets->execute();
$stats['total_tickets'] = $stmt_tickets->fetchColumn();

$query_open_tickets = "SELECT COUNT(*) as open FROM tickets WHERE status = 'open' AND department = :department";
$stmt_open_tickets = $db->prepare($query_open_tickets);
$stmt_open_tickets->bindParam(':department', $user_department);
$stmt_open_tickets->execute();
$stats['open_tickets'] = $stmt_open_tickets->fetchColumn();

// Departamentos - Mostrar solo el departamento del técnico
try {
    $query_departments = "SELECT COUNT(*) as total FROM departments WHERE department_code = :dept_code";
    $stmt_departments = $db->prepare($query_departments);
    $stmt_departments->bindParam(':dept_code', $user_department);
    $stmt_departments->execute();
    $stats['total_departments'] = $stmt_departments->fetchColumn();
} catch (Exception $e) {
    $stats['total_departments'] = 0;
}

// Niveles de urgencia - Mostrar todos (esto es global del sistema)
try {
    $query_urgency = "SELECT COUNT(*) as total FROM urgency_levels";
    $stmt_urgency = $db->prepare($query_urgency);
    $stmt_urgency->execute();
    $stats['total_urgency_levels'] = $stmt_urgency->fetchColumn();
} catch (Exception $e) {
    $stats['total_urgency_levels'] = 0;
}

// Preguntas - Filtrar por categoría que coincida con el rol del técnico
try {
    $query_questions = "SELECT COUNT(*) as total FROM questions WHERE category = :user_department";
    $stmt_questions = $db->prepare($query_questions);
    $stmt_questions->bindParam(':user_department', $user_department);
    $stmt_questions->execute();
    $stats['total_questions'] = $stmt_questions->fetchColumn();
} catch (Exception $e) {
    $stats['total_questions'] = 0;
    error_log("Error en consulta de preguntas (count): " . $e->getMessage());
}

// Preguntas frecuentes - FILTRADAS POR CATEGORÍA QUE COINCIDA CON EL ROL DEL TÉCNICO
try {
    $query_questions = "SELECT id, question, answer, category, active 
                       FROM questions 
                       WHERE category = :user_department 
                       ORDER BY id DESC 
                       LIMIT 5";
    $stmt_questions = $db->prepare($query_questions);
    $stmt_questions->bindParam(':user_department', $user_department);
    $stmt_questions->execute();
    $recent_data['questions'] = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['questions'] = [];
    error_log("Error en consulta de preguntas (recent): " . $e->getMessage());
}

// Historial de consultas - Filtrar solo por departamento/categoría (todo el departamento)
try {
    $query_history = "SELECT COUNT(*) as total FROM query_history WHERE category = :department";
    $stmt_history = $db->prepare($query_history);
    $stmt_history->bindParam(':department', $user_department);
    $stmt_history->execute();
    $stats['total_query_history'] = $stmt_history->fetchColumn();
} catch (Exception $e) {
    $stats['total_query_history'] = 0;
    error_log("Error en consulta de historial (count): " . $e->getMessage());
}

// Historial de consultas - FILTRADO SOLO POR DEPARTAMENTO/CATEGORÍA (todo el departamento)
try {
    $query_history = "SELECT id, query_type, user_id, executed_at, result_count, category 
                     FROM query_history 
                     WHERE category = :department 
                     ORDER BY executed_at DESC 
                     LIMIT 5";
    $stmt_history = $db->prepare($query_history);
    $stmt_history->bindParam(':department', $user_department);
    $stmt_history->execute();
    $recent_data['query_history'] = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['query_history'] = [];
    error_log("Error en consulta de historial (recent): " . $e->getMessage());
}

// Obtener datos recientes de cada tabla FILTRADOS POR DEPARTAMENTO DEL TÉCNICO
$recent_data = [];

// Técnicos recientes del mismo rol (departamento) - CORREGIDO: usar role en lugar de department
try {
    $query_recent_technicians = "SELECT id, name, email, phone, active, update_date FROM technicians WHERE role = :role ORDER BY update_date DESC LIMIT 5";
    $stmt_recent_technicians = $db->prepare($query_recent_technicians);
    $stmt_recent_technicians->bindParam(':role', $user_department);
    $stmt_recent_technicians->execute();
    $recent_data['technicians'] = $stmt_recent_technicians->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['technicians'] = [];
}

// Tickets recientes - FILTRADOS POR department DEL TÉCNICO
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
                            WHERE department = :department
                            ORDER BY creation_date DESC 
                            LIMIT 5";
    $stmt_recent_tickets = $db->prepare($query_recent_tickets);
    $stmt_recent_tickets->bindParam(':department', $user_department);
    $stmt_recent_tickets->execute();
    $recent_data['tickets'] = $stmt_recent_tickets->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['tickets'] = [];
    error_log("Error en consulta de tickets: " . $e->getMessage());
}

// Departamentos - Mostrar solo el departamento del técnico
try {
    $query_departments = "SELECT id, department_code, department_name, active, keywords FROM departments WHERE department_code = :dept_code ORDER BY department_name LIMIT 5";
    $stmt_departments = $db->prepare($query_departments);
    $stmt_departments->bindParam(':dept_code', $user_department);
    $stmt_departments->execute();
    $recent_data['departments'] = $stmt_departments->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['departments'] = [];
}

// Niveles de urgencia - Mostrar todos (global del sistema)
try {
    $query_urgency_levels = "SELECT id, urgency_name, description, level, active FROM urgency_levels ORDER BY level DESC LIMIT 5";
    $stmt_urgency_levels = $db->prepare($query_urgency_levels);
    $stmt_urgency_levels->execute();
    $recent_data['urgency_levels'] = $stmt_urgency_levels->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['urgency_levels'] = [];
}

/// Debug corregido
error_log("User department: '" . $user_department . "'");

// Consulta para contar el total de preguntas
try {
    $count_query = "SELECT COUNT(*) as total FROM questions";
    $count_stmt = $db->query($count_query);
    $total_questions = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    error_log("Total questions in DB: " . $total_questions);
} catch (Exception $e) {
    error_log("Error counting questions: " . $e->getMessage());
}

// Tu consulta original
try {
    $query_questions = "SELECT id, question, answer, category, active 
                       FROM questions 
                       WHERE category = :user_department 
                       ORDER BY id DESC 
                       LIMIT 5";
    $stmt_questions = $db->prepare($query_questions);
    $stmt_questions->bindParam(':user_department', $user_department);
    $stmt_questions->execute();
    $recent_data['questions'] = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: ver cuántos resultados obtuviste
    error_log("Questions found for department '" . $user_department . "': " . count($recent_data['questions']));
    
} catch (Exception $e) {
    $recent_data['questions'] = [];
    error_log("Error en consulta de preguntas (recent): " . $e->getMessage());
}

// Historial de consultas - FILTRADO SOLO POR DEPARTAMENTO
try {
    $query_history = "SELECT id, number, user_question, faq_question_id, faq_answer, category, query_date, was_helpful 
                     FROM query_history 
                     WHERE category = :department 
                     ORDER BY query_date DESC 
                     LIMit 5";
    $stmt_history = $db->prepare($query_history);
    $stmt_history->bindParam(':department', $user_department);
    $stmt_history->execute();
    $recent_data['query_history'] = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_data['query_history'] = [];
    error_log("Error en consulta de historial (recent): " . $e->getMessage());
}

// Obtener el nombre del departamento para mostrar en el título
$department_name = "Departamento";
try {
    $query_dept_name = "SELECT department_name FROM departments WHERE department_code = :dept_code";
    $stmt_dept_name = $db->prepare($query_dept_name);
    $stmt_dept_name->bindParam(':dept_code', $user_department);
    $stmt_dept_name->execute();
    $dept_result = $stmt_dept_name->fetch(PDO::FETCH_ASSOC);
    if ($dept_result) {
        $department_name = $dept_result['department_name'];
    } else {
        // Si no encuentra con el código normalizado, intentar con el rol original
        $query_dept_name_fallback = "SELECT department_name FROM departments WHERE department_code = :dept_code_fallback";
        $stmt_dept_name_fallback = $db->prepare($query_dept_name_fallback);
        $fallback_dept = $current_user_role;
        $stmt_dept_name_fallback->bindParam(':dept_code_fallback', $fallback_dept);
        $stmt_dept_name_fallback->execute();
        $dept_result_fallback = $stmt_dept_name_fallback->fetch(PDO::FETCH_ASSOC);
        if ($dept_result_fallback) {
            $department_name = $dept_result_fallback['department_name'];
        }
    }
} catch (Exception $e) {
    // Mantener el valor por defecto
}

// Procesar refresh del estado facial
if (isset($_POST['refresh_facial_status'])) {
    // Forzar nueva verificación
    $query_refresh = "SELECT facial_data FROM technicians WHERE id = :id AND facial_data IS NOT NULL AND facial_data != 'NULL' AND facial_data != '' AND facial_data != '[]'";
    $stmt_refresh = $db->prepare($query_refresh);
    $stmt_refresh->bindParam(':id', $_SESSION['technician_id']);
    $stmt_refresh->execute();
    
    $result = $stmt_refresh->fetch(PDO::FETCH_ASSOC);
    $new_status = ($result && !empty($result['facial_data']));
    
    $_SESSION['has_facial_data'] = $new_status;
    $has_facial_data = $new_status;
    
    // Recargar la página
    header("Location: dashboard.php");
    exit();
}

// DEBUG FINAL - Verificar resultados
error_log("=== RESULTADOS FINALES DASHBOARD TÉCNICO ===");
error_log("Technician Role: " . $current_user_role);
error_log("User Department: " . $user_department);
error_log("Facial Data Status: " . ($has_facial_data ? "REGISTERED" : "NOT REGISTERED"));
error_log("Total técnicos: " . $stats['total_technicians']);
error_log("Total tickets: " . $stats['total_tickets']);
error_log("Tickets abiertos: " . $stats['open_tickets']);
error_log("Total preguntas: " . $stats['total_questions']);
error_log("Total historial: " . $stats['total_query_history']);
error_log("Departamento nombre: " . $department_name);
error_log("Tickets recientes encontrados: " . count($recent_data['tickets']));
error_log("Técnicos recientes encontrados: " . count($recent_data['technicians']));
error_log("Historial reciente encontrados: " . count($recent_data['query_history']));
error_log("Preguntas recientes encontradas: " . count($recent_data['questions']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard <?php echo htmlspecialchars($department_name); ?> - Integral System</title>
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

        /* Estilos para el Modal/Popup */
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

        /* Resto de tus estilos existentes */
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

        /* Mejoras para tablas en móviles */
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
                    <i class="fas fa-times"></i> Remind Me Later
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <header class="admin-header">
        <div class="header-content">
            <h1>
                <i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($department_name); ?> Panel
                <span class="department-badge">
                    <i class="fas fa-building"></i> <?php echo strtoupper($current_user_role); ?>
                </span>
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Welcome, <strong><?php echo htmlspecialchars($_SESSION['technician_name']); ?></strong> • 
                Role: <?php echo htmlspecialchars($current_user_role); ?> • 
                <?php echo date('m/d/Y H:i:s'); ?>
              
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
  
        <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>

        <a href="questions.php"><i class="fas fa-question-circle"></i> Questions</a>
        <a href="history.php"><i class="fas fa-history"></i> History</a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> Convertations Dashboard</a>
        <a href="reportetikets.php"><i class="fas fa-chart-bar"></i> Tickets Dashboard</a>
    </nav>

    <!-- Main Content -->
    <div class="admin-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h2 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-tachometer-alt"></i> <?php echo htmlspecialchars($department_name); ?> Department Dashboard
            </h2>
           <p style="font-size: 1rem; color: #666;">
    Complete summary of all tables and statistics for the <?php echo htmlspecialchars($department_name); ?> department
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
            <div class="stat-card technicians">
                <div class="stat-number"><?php echo $stats['total_technicians']; ?></div>
                <div class="stat-label"><i class="fas fa-users"></i> TECHNICIANS</div>
                <div class="stat-subtext"><?php echo $stats['active_technicians']; ?> active • <?php echo $stats['technicians_with_face']; ?> with facial recognition</div>
            </div>
            
            <div class="stat-card tickets">
                <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                <div class="stat-label"><i class="fas fa-ticket-alt"></i> TICKETS</div>
                <div class="stat-subtext"><?php echo $stats['open_tickets']; ?> open tickets</div>
            </div>
            
            <div class="stat-card departments">
                <div class="stat-number"><?php echo $stats['total_departments']; ?></div>
                <div class="stat-label"><i class="fas fa-building"></i> MY DEPARTMENT</div>
                <div class="stat-subtext"><?php echo htmlspecialchars($department_name); ?></div>
            </div>
            
            <div class="stat-card questions">
                <div class="stat-number"><?php echo $stats['total_questions']; ?></div>
                <div class="stat-label"><i class="fas fa-question-circle"></i> QUESTIONS</div>
                <div class="stat-subtext">Department knowledge base</div>
            </div>
            
            <div class="stat-card history">
                <div class="stat-number"><?php echo $stats['total_query_history']; ?></div>
                <div class="stat-label"><i class="fas fa-history"></i> MY HISTORY</div>
                <div class="stat-subtext">My query records</div>
            </div>
        </div>

        <!-- Main Dashboard Grid - NOW WITH 4 TABLES -->
        <div class="dashboard-grid">
            <!-- Recent Department Technicians -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($department_name); ?> Technicians</h3>
                   
                </div>
                <div class="card-body">
                    <?php if(count($recent_data['technicians']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table mobile-friendly-table">
                                <thead>
                                    <tr>
                                        <th>Technician</th>
                                        <th>Contact</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_data['technicians'] as $tech): ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($tech['name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars($tech['name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: #666;">ID: <?php echo $tech['id']; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.8rem;">
                                                <div><?php echo htmlspecialchars($tech['email']); ?></div>
                                            </div>
                                        </td>
                                        <td style="font-size: 0.8rem;"><?php echo htmlspecialchars($tech['phone']); ?></td>
                                        <td>
                                            <?php if($tech['active'] == 'yes' || $tech['active'] == 1): ?>
                                                <span class="badge badge-success">ACTIVE</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">INACTIVE</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No technicians in <?php echo htmlspecialchars($department_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Department Tickets -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-ticket-alt"></i> <?php echo htmlspecialchars($department_name); ?> Tickets</h3>
                   
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
                                                <?php echo date('m/d H:i', strtotime($ticket['creation_date'])); ?>
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
                            <p>No tickets in <?php echo htmlspecialchars($department_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Department FAQ Questions -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-question-circle"></i> <?php echo htmlspecialchars($department_name); ?> Questions</h3>
                 
                </div>
                <div class="card-body">
                    <?php if(count($recent_data['questions']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table mobile-friendly-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Question</th>
                                        <th>Answer</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_data['questions'] as $question): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; font-size: 0.85rem;">#<?php echo $question['id']; ?></div>
                                        </td>
                                        <td>
                                            <div class="question-text" title="<?php echo htmlspecialchars($question['question']); ?>">
                                                <?php echo htmlspecialchars(substr($question['question'], 0, 50)); ?>
                                                <?php if(strlen($question['question']) > 50): ?>...<?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="answer-text" title="<?php echo htmlspecialchars($question['answer']); ?>">
                                                <?php echo htmlspecialchars(substr($question['answer'], 0, 60)); ?>
                                                <?php if(strlen($question['answer']) > 60): ?>...<?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-info" style="font-size: 0.65rem;">
                                                <?php echo htmlspecialchars($question['category']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if($question['active'] == 'yes' || $question['active'] == 1): ?>
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
                            <i class="fas fa-question-circle"></i>
                            <p>No questions in <?php echo htmlspecialchars($department_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

           <!-- My Query History -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> My History</h3>
      
    </div>
    <div class="card-body">
        <?php if(count($recent_data['query_history']) > 0): ?>
            <div class="table-responsive">
                <table class="table mobile-friendly-table">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Question</th>
                            <th>FAQ Answer</th>
                            <th>Category</th>
                            <th>Was Helpful?</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_data['query_history'] as $history): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars($history['number']); ?></div>
                            </td>
                            <td>
                                <div class="question-text" title="<?php echo htmlspecialchars($history['user_question']); ?>">
                                    <?php echo htmlspecialchars(substr($history['user_question'], 0, 30)); ?>
                                    <?php if(strlen($history['user_question']) > 30): ?>...<?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if(!empty($history['faq_answer'])): ?>
                                    <div class="answer-text" title="<?php echo htmlspecialchars($history['faq_answer']); ?>">
                                        <?php echo htmlspecialchars(substr($history['faq_answer'], 0, 30)); ?>
                                        <?php if(strlen($history['faq_answer']) > 30): ?>...<?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #888; font-size: 0.7rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-info" style="font-size: 0.65rem;">
                                    <?php echo htmlspecialchars($history['category']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if($history['was_helpful'] == 'yes'): ?>
                                    <span class="badge badge-success" style="font-size: 0.65rem;">YES</span>
                                <?php elseif($history['was_helpful'] == 'no'): ?>
                                    <span class="badge badge-danger" style="font-size: 0.65rem;">NO</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary" style="font-size: 0.65rem;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size: 0.7rem; color: #666;">
                                    <?php echo date('m/d H:i', strtotime($history['query_date'])); ?>
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
                <p>No query history</p>
            </div>
        <?php endif; ?>
    </div>
  </div>
</div>

        <!-- System Health -->
        <div class="system-health">
            <h3 style="margin-bottom: 1rem; color: var(--primary); font-size: 1.2rem;">
                <i class="fas fa-heartbeat"></i> System Status - <?php echo htmlspecialchars($department_name); ?>
            </h3>
            <div class="health-grid">
                <div class="health-item <?php echo $stats['total_technicians'] > 0 ? 'good' : 'danger'; ?>">
                    <i class="fas fa-users fa-2x" style="color: <?php echo $stats['total_technicians'] > 0 ? 'var(--success)' : 'var(--danger)'; ?>;"></i>
                    <div style="margin-top: 0.5rem; font-weight: 600; font-size: 0.9rem;">Technicians</div>
                    <div style="font-size: 0.8rem; color: #666;"><?php echo $stats['total_technicians'] > 0 ? 'Operational' : 'Critical'; ?></div>
                </div>
                <div class="health-item <?php echo $stats['total_departments'] > 0 ? 'good' : 'warning'; ?>">
                    <i class="fas fa-building fa-2x" style="color: <?php echo $stats['total_departments'] > 0 ? 'var(--success)' : 'var(--warning)'; ?>;"></i>
                    <div style="margin-top: 0.5rem; font-weight: 600; font-size: 0.9rem;">Department</div>
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

    <!-- Footer -->
    <footer style="
        background: transparent;
        padding: 0.5rem 1rem;
        text-align: center;
        color: #adb5bd;
        font-size: 0.75rem;
        margin-top: 1rem;
    ">
        <div>Created by Erick Dumas &copy; <?php echo date('Y'); ?> | v1.0.0 | Department: <?php echo htmlspecialchars($department_name); ?></div>
    </footer>

    <script>
        // Inactivity timeout configuration (3 hours)
        const INACTIVITY_TIMEOUT = 10800000; // 10800000 ms = 3 hours
        const WARNING_TIME = 300000; // 5 minutes before expiration
        let inactivityTimer;
        let warningTimer;
        let lastActivity = Date.now();

        function resetInactivityTimer() {
            lastActivity = Date.now();
            clearTimeout(inactivityTimer);
            clearTimeout(warningTimer);
            updateTimerDisplay();
            
            // Hide warning if visible
            const timerElement = document.getElementById('inactivityTimer');
            timerElement.classList.remove('warning');
            timerElement.innerHTML = '<i class="fas fa-clock"></i> Session active';

            // Set warning timer
            warningTimer = setTimeout(showWarning, INACTIVITY_TIMEOUT - WARNING_TIME);
            
            // Set logout timer
            inactivityTimer = setTimeout(logoutDueToInactivity, INACTIVITY_TIMEOUT);
        }

        function showWarning() {
            const timerElement = document.getElementById('inactivityTimer');
            timerElement.classList.add('warning');
            
            // Update every second for countdown
            const countdownInterval = setInterval(() => {
                const timeLeft = INACTIVITY_TIMEOUT - (Date.now() - lastActivity);
                if (timeLeft <= 0) {
                    clearInterval(countdownInterval);
                    return;
                }
                
                const minutes = Math.floor(timeLeft / 60000);
                const seconds = Math.floor((timeLeft % 60000) / 1000);
                timerElement.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Session expires in ${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                if (timeLeft <= 0) {
                    clearInterval(countdownInterval);
                }
            }, 1000);
        }

        function updateTimerDisplay() {
            const timeLeft = INACTIVITY_TIMEOUT - (Date.now() - lastActivity);
            const hours = Math.floor(timeLeft / 3600000);
            const minutes = Math.floor((timeLeft % 3600000) / 60000);
            
            const timerElement = document.getElementById('inactivityTimer');
            if (!timerElement.classList.contains('warning')) {
                timerElement.innerHTML = `<i class="fas fa-clock"></i> ${hours}h ${minutes}m`;
            }
        }

        function logoutDueToInactivity() {
            alert('Your session has expired due to inactivity (3 hours). You will be redirected to login.');
            window.location.href = '../logout.php?timeout=1';
        }

        // Events that will reset the timer
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click', 'keydown'];
        events.forEach(event => {
            document.addEventListener(event, resetInactivityTimer, true);
        });

        // Start timers
        resetInactivityTimer();

        // Periodic user status verification (every 2 minutes)
        setInterval(() => {
            fetch('../check_user_status.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.active) {
                        alert('Your account has been deactivated. You will be redirected to login.');
                        window.location.href = '../logout.php?inactive=1';
                    }
                })
                .catch(error => console.error('Error checking status:', error));
        }, 120000); // 2 minutes

        // Update timer display every minute
        setInterval(updateTimerDisplay, 60000);

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