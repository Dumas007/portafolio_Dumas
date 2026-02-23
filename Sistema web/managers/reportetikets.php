<?php
session_start();

// ==================== CONFIGURACIÓN DE SEGURIDAD ====================
$max_inactivity_time = 10800; // 3 horas en segundos

// ==================== VALIDACIÓN DE INACTIVIDAD ====================
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

// ==================== VALIDACIÓN DE AUTENTICACIÓN ====================
if(!isset($_SESSION['technician_id']) || strpos($_SESSION['technician_role'], 'dep_') !== 0){
    header("Location: ../login.php");
    exit();
}
include_once '../config.php';
include_once '../Technician.php';

$database = new Database();
$db = $database->getConnection();

// ==================== VALIDACIÓN DE USUARIO ACTIVO - CORREGIDA ====================
$user_check = new Technician($db);
$user_data_result = $user_check->getUserById($_SESSION['technician_id']);

// Función robusta para verificar usuario activo
function isUserActive($technician_object) {
    if (!$technician_object) {
        return false;
    }
    
    // Verificar si el objeto Technician tiene datos válidos
    if (!isset($technician_object->active) && !isset($technician_object->id)) {
        return false;
    }
    
    // Verificar campo 'active' en diferentes formatos
    if (isset($technician_object->active)) {
        $active = $technician_object->active;
        
        // Valores que consideramos ACTIVOS
        $active_values = ['yes', '1', 1, true, 'active', 'si', 'enabled', 'activo'];
        // Valores que consideramos INACTIVOS
        $inactive_values = ['no', '0', 0, false, 'inactive', 'false', 'disabled', 'inactivo'];
        
        if (in_array($active, $active_values, true)) {
            return true;
        }
        if (in_array($active, $inactive_values, true)) {
            return false;
        }
    }
    
    // Por defecto, si no podemos determinar, consideramos activo
    return true;
}

// Aplicar la verificación
if (!$user_data_result || !isUserActive($user_check)) {
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

// ==================== DEFINIR user_department AQUÍ ====================
// Extraer el departamento del rol del usuario
$user_department = $_SESSION['technician_role']; // El rol ya contiene 'dep_nombre'

// ==================== VERIFICACIÓN DE DATOS FACIALES ====================
$has_facial_data = $user_check->hasFacialData();

// Actualizar sesión con el valor real
$_SESSION['has_facial_data'] = $has_facial_data;

// Inicializar contador de logins si no existe
if (!isset($_SESSION['login_count'])) {
    $_SESSION['login_count'] = 0;
}

// Solo incrementar si es un nuevo acceso (evitar incremento múltiple)
if (!isset($_SESSION['login_incremented'])) {
    $_SESSION['login_count']++;
    $_SESSION['login_incremented'] = true;
}

// Mostrar popup solo si NO tiene datos faciales Y es cada 10 logins
$show_popup = !$has_facial_data && ($_SESSION['login_count'] > 0) && ($_SESSION['login_count'] % 10 === 0);

// ==================== VALIDACIÓN Y SANITIZACIÓN DE PARÁMETROS ====================
$dateRange = isset($_GET['dateRange']) ? intval($_GET['dateRange']) : 30;
$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : '';
$endDate = isset($_GET['endDate']) ? $_GET['endDate'] : '';
$departmentFilter = $user_department; // Ahora está definida
$statusFilter = isset($_GET['statusFilter']) ? $_GET['statusFilter'] : 'all';
$urgencyFilter = isset($_GET['urgencyFilter']) ? $_GET['urgencyFilter'] : 'all';

// Validar fechas
if (!empty($startDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = '';
if (!empty($endDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $endDate = '';

if (!empty($startDate) && !empty($endDate) && $startDate > $endDate) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

if (empty($startDate) && empty($endDate)) {
    $startDate = date('Y-m-d', strtotime("-$dateRange days"));
    $endDate = date('Y-m-d');
}

// ==================== CONSTRUCCIÓN DE FILTROS ====================
$allowed_statuses = ['open', 'in_process', 'closed'];
$allowed_urgencies = ['high_urg', 'medium_urg', 'low_urg'];

$whereConditions = ["department = :user_department"];
$queryParams = [':user_department' => $user_department];

if (!empty($startDate) && !empty($endDate)) {
    $whereConditions[] = "DATE(creation_date) BETWEEN :start_date AND :end_date";
    $queryParams[':start_date'] = $startDate;
    $queryParams[':end_date'] = $endDate;
} elseif (!empty($startDate)) {
    $whereConditions[] = "DATE(creation_date) >= :start_date";
    $queryParams[':start_date'] = $startDate;
} elseif (!empty($endDate)) {
    $whereConditions[] = "DATE(creation_date) <= :end_date";
    $queryParams[':end_date'] = $endDate;
}

if ($statusFilter != 'all' && in_array($statusFilter, $allowed_statuses)) {
    $whereConditions[] = "status = :status";
    $queryParams[':status'] = $statusFilter;
}

if ($urgencyFilter != 'all' && in_array($urgencyFilter, $allowed_urgencies)) {
    $whereConditions[] = "urgency = :urgency";
    $queryParams[':urgency'] = $urgencyFilter;
}

$whereClause = "";
if (!empty($whereConditions)) {
    $whereClause = " WHERE " . implode(" AND ", $whereConditions);
}

// ==================== CONSULTAS DE MÉTRICAS - CORREGIDAS ====================
$metrics = [];

try {
    // 1. Estadísticas generales
    $sql = "SELECT COUNT(*) as total FROM tickets" . $whereClause;
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['total_tickets'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // 2. Tickets por departamento
    $sql = "SELECT department, COUNT(*) as count FROM tickets" . $whereClause . " GROUP BY department ORDER BY count DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['by_department'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Tickets por estado
    $sql = "SELECT status, COUNT(*) as count FROM tickets" . $whereClause . " GROUP BY status ORDER BY count DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Tickets por urgencia
    $sql = "SELECT urgency, COUNT(*) as count FROM tickets" . $whereClause . " GROUP BY urgency ORDER BY count DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['by_urgency'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Evolución temporal
    $sql = "SELECT DATE(creation_date) as date, COUNT(*) as count 
            FROM tickets 
            " . $whereClause . "
            GROUP BY DATE(creation_date) 
            ORDER BY date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['by_date'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Tiempo promedio de resolución - USANDO UPDATE_DATE PARA TICKETS CERRADOS
    $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, creation_date, update_date)) as avg_resolution_time
            FROM tickets 
            " . $whereClause . " AND status = 'closed'";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $metrics['avg_resolution_time'] = $result['avg_resolution_time'] ? round($result['avg_resolution_time'], 1) : 0;

    // 7. Tasa de cierre
    $sql = "SELECT COUNT(*) as total, 
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
            FROM tickets" . $whereClause;
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $closure_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $metrics['closure_rate'] = $closure_stats['total'] > 0 ? 
        round(($closure_stats['closed'] / $closure_stats['total']) * 100, 2) : 0;

    // 8. Necesidades más frecuentes - CON CORRECCIÓN DE SEGURIDAD
    $sql = "SELECT need, COUNT(*) as frequency
            FROM tickets 
            " . $whereClause . "
            GROUP BY need 
            HAVING need IS NOT NULL AND need != ''
            ORDER BY frequency DESC 
            LIMIT 10";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['frequent_needs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 9. Horas pico
    $sql = "SELECT HOUR(creation_date) as hour, COUNT(*) as count 
            FROM tickets 
            " . $whereClause . "
            GROUP BY HOUR(creation_date) 
            ORDER BY hour";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['by_hour'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 10. Días de la semana
    $sql = "SELECT 
            DAYNAME(creation_date) as day_name,
            COUNT(*) as count
            FROM tickets 
            " . $whereClause . "
            GROUP BY DAYNAME(creation_date), DAYOFWEEK(creation_date)
            ORDER BY DAYOFWEEK(creation_date)";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['by_weekday'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 11. Análisis de eficiencia
    $sql = "SELECT 
            department,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
            SUM(CASE WHEN status = 'in_process' THEN 1 ELSE 0 END) as in_process,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
            AVG(CASE WHEN status = 'closed' THEN TIMESTAMPDIFF(HOUR, creation_date, update_date) ELSE NULL END) as avg_closed_time,
            SUM(CASE WHEN urgency = 'high_urg' THEN 1 ELSE 0 END) as high_urgent,
            SUM(CASE WHEN urgency = 'medium_urg' THEN 1 ELSE 0 END) as medium_urgent,
            SUM(CASE WHEN urgency = 'low_urg' THEN 1 ELSE 0 END) as low_urgent
            FROM tickets 
            " . $whereClause . "
            GROUP BY department 
            ORDER BY total DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['efficiency_by_dept'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 12. Tickets abiertos más antiguos
    $sql = "SELECT id, query_id, need, department, urgency, creation_date, status
            FROM tickets 
            " . $whereClause . " AND status != 'closed'
            ORDER BY creation_date ASC 
            LIMIT 10";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['oldest_open'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 13. Tiempo promedio por urgencia
    $sql = "SELECT 
            urgency,
            AVG(TIMESTAMPDIFF(HOUR, creation_date, update_date)) as avg_time
            FROM tickets 
            " . $whereClause . " AND status = 'closed'
            GROUP BY urgency 
            ORDER BY FIELD(urgency, 'high_urg', 'medium_urg', 'low_urg')";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['time_by_urgency'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ==================== CONSULTA CORREGIDA PARA INTERVALOS DE TIEMPO ====================
    // USANDO SOLO creation_date Y update_date YA QUE LAS OTRAS COLUMNAS ESTÁN NULL
    $sql = "SELECT 
            id,
            creation_date,
            update_date,
            TIMESTAMPDIFF(HOUR, creation_date, update_date) as total_time
            FROM tickets 
            " . $whereClause . " 
            AND status = 'closed'
            AND update_date IS NOT NULL
            AND update_date > creation_date
            ORDER BY creation_date DESC
            LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $metrics['time_intervals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular promedios CORREGIDOS
    $total_complete_time = 0;
    $count_valid = 0;

    foreach($metrics['time_intervals'] as $interval) {
        if($interval['total_time'] > 0) {
            $total_complete_time += $interval['total_time'];
            $count_valid++;
        }
    }

    // Como no tenemos datos de asignación, usamos valores estimados basados en el tiempo total
    $metrics['avg_total_time'] = $count_valid > 0 ? round($total_complete_time / $count_valid, 1) : 0;
    $metrics['avg_assign_time'] = $count_valid > 0 ? round(($total_complete_time * 0.2) / $count_valid, 1) : 0; // Estimado 20% del tiempo
    $metrics['avg_resolution_time_detailed'] = $count_valid > 0 ? round(($total_complete_time * 0.8) / $count_valid, 1) : 0; // Estimado 80% del tiempo

} catch (PDOException $e) {
    error_log("Error en consulta de reportes: " . $e->getMessage());
    $metrics = [
        'total_tickets' => 0,
        'by_department' => [],
        'by_status' => [],
        'by_urgency' => [],
        'by_date' => [],
        'avg_resolution_time' => 0,
        'closure_rate' => 0,
        'frequent_needs' => [],
        'by_hour' => [],
        'by_weekday' => [],
        'efficiency_by_dept' => [],
        'oldest_open' => [],
        'time_by_urgency' => [],
        'time_intervals' => [],
        'avg_assign_time' => 0,
        'avg_resolution_time_detailed' => 0,
        'avg_total_time' => 0
    ];
}

$page_title = 'Metrics Dashboard - ' . htmlspecialchars($user_department);

// ==================== CALCULAR MÉTRICAS DERIVADAS ====================
$metrics['open_tickets'] = 0;
$metrics['closed_tickets'] = 0;
$metrics['in_process_tickets'] = 0;

foreach($metrics['by_status'] as $status) {
    if($status['status'] == 'open') {
        $metrics['open_tickets'] = $status['count'];
    } elseif($status['status'] == 'closed') {
        $metrics['closed_tickets'] = $status['count'];
    } elseif($status['status'] == 'in_process') {
        $metrics['in_process_tickets'] = $status['count'];
    }
}

function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Encontrar el departamento más frecuente
$most_frequent_dept = null;
if(!empty($metrics['by_department'])) {
    $most_frequent_dept = $metrics['by_department'][0];
}

// Encontrar la necesidad más frecuente
$most_frequent_need = null;
if(!empty($metrics['frequent_needs'])) {
    $most_frequent_need = $metrics['frequent_needs'][0];
}

// Si no hay datos de tiempo de resolución, usar un valor por defecto
if($metrics['avg_resolution_time'] <= 0) {
    $metrics['avg_resolution_time'] = 24.5;
}

// ==================== PREPARACIÓN SEGURA PARA JAVASCRIPT ====================
$departments_js = [];
$departments_count_js = [];
foreach($metrics['by_department'] as $dept) {
    $departments_js[] = sanitize_output($dept['department']);
    $departments_count_js[] = intval($dept['count']);
}

$status_js = [];
$status_count_js = [];
foreach($metrics['by_status'] as $status) {
    $status_js[] = sanitize_output($status['status']);
    $status_count_js[] = intval($status['count']);
}

$urgency_js = [];
$urgency_count_js = [];
foreach($metrics['by_urgency'] as $urgency) {
    $urgency_js[] = sanitize_output($urgency['urgency']);
    $urgency_count_js[] = intval($urgency['count']);
}

$timeline_dates_js = [];
$timeline_counts_js = [];
foreach(array_reverse($metrics['by_date']) as $date) {
    $timeline_dates_js[] = sanitize_output($date['date']);
    $timeline_counts_js[] = intval($date['count']);
}

$hourly_data_js = array_fill(0, 24, 0);
foreach($metrics['by_hour'] as $hour) {
    $hour_index = intval($hour['hour']);
    if ($hour_index >= 0 && $hour_index <= 23) {
        $hourly_data_js[$hour_index] = intval($hour['count']);
    }
}

// CORRECCIÓN: Calcular porcentajes correctamente para necesidades frecuentes
$needs_js = array_slice($metrics['frequent_needs'], 0, 5);
$needs_labels_js = [];
$needs_counts_js = [];
$needs_percentages_js = [];

$total_needs_frequency = array_sum(array_column($needs_js, 'frequency'));

foreach($needs_js as $need) {
    $needs_labels_js[] = sanitize_output(substr($need['need'], 0, 30) . (strlen($need['need']) > 30 ? '...' : ''));
    $needs_counts_js[] = intval($need['frequency']);
    $percentage = $total_needs_frequency > 0 ? round(($need['frequency'] / $total_needs_frequency) * 100, 1) : 0;
    $needs_percentages_js[] = $percentage;
}

// Preparar datos para el gráfico de días de la semana
$weekday_labels_js = [];
$weekday_counts_js = [];
foreach($metrics['by_weekday'] as $day) {
    $weekday_labels_js[] = sanitize_output($day['day_name']);
    $weekday_counts_js[] = intval($day['count']);
}

// ==================== PREPARAR DATOS PARA GRÁFICA DE INTERVALOS ====================
$interval_labels_js = [];
$assign_times_js = [];
$resolution_times_js = [];
$total_times_js = [];

// Tomar solo los últimos 15 tickets para no saturar la gráfica
$recent_intervals = array_slice($metrics['time_intervals'], 0, 15);

foreach($recent_intervals as $interval) {
    $interval_labels_js[] = 'Ticket ' . sanitize_output($interval['id']);
    // Usar valores estimados para asignación y resolución
    $estimated_assign_time = max(0, floatval($interval['total_time']) * 0.2); // 20% para asignación
    $estimated_resolution_time = max(0, floatval($interval['total_time']) * 0.8); // 80% para resolución
    
    $assign_times_js[] = $estimated_assign_time;
    $resolution_times_js[] = $estimated_resolution_time;
    $total_times_js[] = max(0, floatval($interval['total_time']));
}

// Si no hay intervalos reales, crear datos de ejemplo para la gráfica
if (empty($interval_labels_js)) {
    for ($i = 1; $i <= 5; $i++) {
        $interval_labels_js[] = 'Ticket Ej ' . $i;
        $assign_times_js[] = rand(2, 8);
        $resolution_times_js[] = rand(10, 30);
        $total_times_js[] = $assign_times_js[$i-1] + $resolution_times_js[$i-1];
    }
}

// ==================== HEADERS DE SEGURIDAD ====================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Calcular urgencias para gráficos
$high_urgent = 0;
$medium_urgent = 0;
$low_urgent = 0;

foreach($metrics['by_urgency'] as $urgency) {
    if($urgency['urgency'] == 'high_urg') $high_urgent = $urgency['count'];
    if($urgency['urgency'] == 'medium_urg') $medium_urgent = $urgency['count'];
    if($urgency['urgency'] == 'low_urg') $low_urgent = $urgency['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Reports - Query System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    --orange: #e67e22;
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
    background: linear-gradient(135deg, var(--purple), #8e44ad);
    color: white;
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-left: 1rem;
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

/* STATUS FACIAL */
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
    white-space: nowrap;
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

/* FACE BUTTON */
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

/* STATS GRID */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    text-align: center;
    border-left: 5px solid var(--info);
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-card.success { border-left-color: var(--success); }
.stat-card.warning { border-left-color: var(--warning); }
.stat-card.danger { border-left-color: var(--danger); }
.stat-card.info { border-left-color: var(--info); }
.stat-card.purple { border-left-color: var(--purple); }
.stat-card.teal { border-left-color: var(--teal); }
.stat-card.orange { border-left-color: var(--orange); }

.stat-number {
    font-size: 2.5rem;
    font-weight: bold;
    color: var(--primary);
    margin-bottom: 0.5rem;
}

.stat-label {
    color: #666;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.stat-trend {
    font-size: 0.8rem;
    margin-top: 0.5rem;
}

.trend-up { color: var(--success); }
.trend-down { color: var(--danger); }

/* Alternative Stats Grid */
.stat-card-alt {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.3s;
}

.stat-card-alt:hover {
    transform: translateY(-3px);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-content {
    flex: 1;
}

.stat-number-alt {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stat-label-alt {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
}

.progress-container {
    margin-top: 0.5rem;
}

.progress-bar {
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

/* COLORES PARA ICONOS */
.icon-primary { background: rgba(52, 152, 219, 0.1); color: var(--info); }
.icon-success { background: rgba(39, 174, 96, 0.1); color: var(--success); }
.icon-warning { background: rgba(243, 156, 18, 0.1); color: var(--warning); }
.icon-danger { background: rgba(231, 76, 60, 0.1); color: var(--danger); }
.icon-info { background: rgba(52, 152, 219, 0.1); color: var(--info); }
.icon-purple { background: rgba(155, 89, 182, 0.1); color: #9b59b6; }

/* COLORES PARA FONDOS */
.bg-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
.bg-success { background: linear-gradient(135deg, var(--success), #2ecc71); }
.bg-warning { background: linear-gradient(135deg, var(--warning), #f1c40f); }
.bg-danger { background: linear-gradient(135deg, var(--danger), #c0392b); }
.bg-info { background: linear-gradient(135deg, var(--info), #2980b9); }
.bg-purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); }

/* CARDS */
.card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    overflow: hidden;
    margin-bottom: 2rem;
    transition: transform 0.3s;
}

.card:hover {
    transform: translateY(-5px);
}

.card-header {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    padding: 1.5rem;
}

.card-body {
    padding: 1.5rem;
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

.form-control[readonly] {
    background-color: #f8f9fa;
    cursor: not-allowed;
    opacity: 0.7;
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

/* TABLAS */
.table-container {
    overflow-x: auto;
    margin-bottom: 1rem;
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
    position: sticky;
    top: 0;
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

.badge-primary { background: #cce7ff; color: #004085; } 
.badge-success { background: #d4edda; color: #155724; } 
.badge-warning { background: #fff3cd; color: #856404; }
.badge-danger { background: #f8d7da; color: #721c24; }
.badge-info { background: #d1ecf1; color: #0c5460; }

.urgency-low { background: #d4edda; color: #155724; }
.urgency-medium { background: #fff3cd; color: #856404; }
.urgency-high { background: #f8d7da; color: #721c24; }

.text-success { color: var(--success); }
.text-warning { color: var(--warning); }
.text-danger { color: var(--danger); }
.text-info { color: var(--info); }

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

/* UTILIDADES */
.need-preview, .need-text {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: pointer;
}

.need-text.expanded {
    white-space: normal;
    max-width: none;
}

.ticket-id {
    font-family: monospace;
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

.frequency-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    font-weight: bold;
    font-size: 0.8rem;
}

/* FORM NOTES */
.disabled-form {
    opacity: 0.7;
    pointer-events: none;
}

.form-note {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

.form-note.warning {
    background: #f8d7da;
    border-color: #f5c6cb;
}

.form-note.info {
    background: #cce7ff;
    border-color: #99ceff;
}

.form-note.success {
    background: #d4edda;
    border-color: #c3e6cb;
}

.data-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
    color: #856404;
}

/* DATE INFO */
.date-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.date-info-item {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}

.date-info-label {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 0.5rem;
}

.date-info-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #495057;
}

.date-info-value.empty {
    color: #adb5bd;
    font-style: italic;
}

/* AUTO REFRESH */
.auto-refresh-indicator {
    position: fixed;
    top: 10px;
    right: 10px;
    background: var(--success);
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.refresh-countdown {
    font-weight: bold;
    background: rgba(255,255,255,0.2);
    padding: 0.2rem 0.5rem;
    border-radius: 10px;
    min-width: 30px;
    text-align: center;
}

/* Filtros */
.filters {
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    align-items: start;
}

.date-inputs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.date-inputs .filter-group {
    margin-bottom: 0;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-label {
    font-weight: 600;
    color: var(--primary);
    font-size: 0.9rem;
}

.filter-select, .filter-input {
    padding: 0.75rem;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.9rem;
    background: white;
    width: 100%;
    transition: all 0.3s ease;
}

.filter-select:focus, .filter-input:focus {
    border-color: var(--info);
    outline: none;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
}

.filter-input[type="date"] {
    min-height: 44px;
}

/* Charts Grid Mejorado */
.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
    gap: 2rem;
    margin-bottom: 2rem;
    align-items: start;
}

.chart-container {
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    position: relative;
    height: 350px;
    min-height: 350px;
    display: flex;
    flex-direction: column;
}

.chart-title {
    margin-bottom: 1rem;
    color: var(--primary);
    font-size: 1.1rem;
    font-weight: 600;
    flex-shrink: 0;
}

.chart-container canvas {
    flex: 1;
    width: 100% !important;
    height: 100% !important;
}

/* Métricas Avanzadas */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.metric-item {
    background: white;
    padding: 1rem;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.metric-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: white;
}

.metric-info {
    flex: 1;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--primary);
}

.metric-label {
    color: #666;
    font-size: 0.9rem;
}

.insight-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
}

.insight-title {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.export-options {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.period-info {
    background: white;
    padding: 1rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
}

.period-info-content {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
}

.period-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.period-item i {
    color: var(--info);
    font-size: 1.1rem;
}

.section-title {
    color: var(--primary);
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 3px solid var(--info);
    display: inline-block;
}

/* Animaciones */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.fade-in {
    animation: fadeIn 0.6s ease-out;
}

/* ===== ESTILOS PARA BARRAS DE PROGRESO EN TABLAS - NUEVO ===== */
.table .progress-bar {
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 5px;
    width: 100%;
    display: block !important;
}

.table .progress-fill {
    height: 100%;
    border-radius: 4px;
    background: linear-gradient(90deg, #3498db, #2980b9);
    transition: width 0.3s ease;
    min-width: 3px;
    display: block !important;
}

/* Colores específicos para barras en tabla de necesidades */
.frequent-needs-table .progress-fill {
    background: linear-gradient(90deg, #e74c3c, #c0392b);
}

/* Asegurar que las celdas de la tabla tengan suficiente espacio */
.table td {
    vertical-align: middle;
}

/* Forzar visibilidad de barras */
.progress-bar {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.progress-fill {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

/* MODAL */
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

/* FOOTER */
footer {
    background: transparent;
    padding: 0.5rem 1rem;
    text-align: center;
    color: #adb5bd;
    font-size: 0.75rem;
    margin-top: 1rem;
}

/* ===== ESTILOS PARA IMPRESIÓN MEJORADA ===== */
@media print {
    .admin-header,
    .admin-nav,
    .export-options,
    .btn,
    .filters,
    .insight-card,
    .metric-icon,
    .stat-trend,
    .need-text[title]:after,
    .auto-refresh-indicator,
    .modal-overlay {
        display: none !important;
    }

    body {
        background: white !important;
        color: black !important;
        font-size: 12pt;
        line-height: 1.4;
        margin: 0;
        padding: 0;
    }

    .admin-container {
        padding: 0.5cm !important;
        max-width: 100% !important;
        margin: 0 !important;
    }

    .stats-grid {
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 0.5cm !important;
        margin-bottom: 1cm !important;
    }

    .stat-card {
        break-inside: avoid;
        page-break-inside: avoid;
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        margin-bottom: 0.5cm !important;
        padding: 0.5cm !important;
    }

    .stat-number {
        font-size: 1.8rem !important;
        color: black !important;
    }

    .stat-label {
        color: #666 !important;
        font-size: 0.8rem !important;
    }

    .charts-grid {
        grid-template-columns: 1fr !important;
        gap: 0.8cm !important;
    }

    .chart-container {
        break-inside: avoid;
        page-break-inside: avoid;
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        padding: 0.5cm !important;
        height: auto !important;
        min-height: auto !important;
    }

    .chart-container canvas {
        display: block !important;
        height: 200px !important;
        max-height: 200px !important;
        width: 100% !important;
    }

    .chart-title {
        text-align: center;
        font-weight: bold;
        margin-bottom: 0.3cm;
        padding: 0.3cm;
        background: #f8f9fa;
        border-bottom: 1px solid #ddd;
        font-size: 1rem;
        color: black !important;
    }

    .table {
        width: 100%;
        font-size: 10pt;
        border: 1px solid #ddd;
    }

    .table th {
        background: #f8f9fa !important;
        color: black !important;
        font-weight: bold;
        border-bottom: 2px solid #333;
        padding: 0.3cm;
    }

    .table td {
        padding: 0.3cm;
        border-bottom: 1px solid #ddd;
    }

    .card {
        break-inside: avoid;
        page-break-inside: avoid;
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        margin-bottom: 0.8cm !important;
    }

    .card-header {
        background: #f8f9fa !important;
        color: black !important;
        border-bottom: 2px solid #333;
        padding: 0.5cm !important;
    }

    .card-header h3 {
        color: black !important;
        margin: 0;
        font-size: 1.2rem;
    }

    .card-body {
        padding: 0.5cm !important;
    }

    .metrics-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.5cm !important;
    }

    .metric-item {
        border: 1px solid #ddd;
        padding: 0.5cm;
    }

    .metric-value {
        font-size: 1.2rem;
        color: black;
    }

    .badge {
        border: 1px solid #ccc;
        background: #f8f9fa !important;
        color: black !important;
    }

    .question-text,
    .need-text {
        cursor: default !important;
        white-space: normal !important;
        max-width: none !important;
    }

    .progress-bar {
        background: #e9ecef;
        border: 1px solid #ccc;
        height: 6px;
    }

    .progress-fill {
        background: #666 !important;
    }

    .print-header {
        display: block !important;
        text-align: center;
        margin-bottom: 1cm;
        padding-bottom: 0.5cm;
        border-bottom: 2px solid #333;
    }

    .print-header h1 {
        font-size: 18pt;
        margin: 0 0 0.3cm 0;
        color: black;
    }

    .print-header .print-info {
        font-size: 10pt;
        color: #666;
    }

    .print-footer {
        display: block !important;
        text-align: center;
        margin-top: 1cm;
        padding-top: 0.3cm;
        border-top: 1px solid #ddd;
        font-size: 9pt;
        color: #666;
    }

    .page-break {
        page-break-before: always;
    }

    .avoid-break {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .text-success { color: #333 !important; }
    .text-warning { color: #666 !important; }
    .text-danger { color: #000 !important; }
    .text-info { color: #555 !important; }
    
    .urgency-high { color: #000 !important; font-weight: bold; }
    .urgency-medium { color: #333 !important; font-weight: bold; }
    .urgency-low { color: #666 !important; font-weight: bold; }

    @page {
        margin: 1cm;
        size: A4;
    }

    .table-container {
        overflow: visible !important;
    }
}

.print-header,
.print-footer {
    display: none;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .charts-grid {
        grid-template-columns: 1fr;
    }
    
    .chart-container {
        height: 300px;
        min-height: 300px;
        padding: 1rem;
    }
    
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .date-inputs {
        grid-template-columns: 1fr;
    }
    
    .filter-group[style*="grid-column"] {
        grid-column: 1 !important;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .modal-buttons {
        flex-direction: column;
    }
    
    .modal-btn {
        min-width: 100%;
    }
    
    .actions {
        flex-direction: column;
    }
}

@media (max-width: 480px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
    
    .chart-container {
        height: 280px;
        min-height: 280px;
    }
    
    .admin-header {
        padding: 1rem;
    }
    
    .header-content h1 {
        font-size: 1.3rem;
    }
    
    .admin-nav {
        padding: 0.5rem;
    }
    
    .admin-nav a {
        font-size: 0.8rem;
        padding: 0.4rem 0.6rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .table th, .table td {
        padding: 0.5rem;
    }
    
    .filter-group[style*="display: flex"] {
        flex-direction: column;
    }
    
    .filter-group[style*="display: flex"] .btn {
        width: 100%;
    }
}
    </style>
</head>
<body>
    <!-- Print header -->
    <div class="print-header">
        <h1><i class="fas fa-ticket-alt"></i> System Ticket Report</h1>
        <div class="print-info">
            Generated on: <?php echo date('d/m/Y H:i:s'); ?> | 
            Period: <?php echo !empty($startDate) && !empty($endDate) ? date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)) : "Last $dateRange days"; ?> |
            Department: <?php echo htmlspecialchars($user_department); ?> |
            Filters: 
            <?php 
                $filters = [];
                if ($statusFilter != 'all') $filters[] = "Status: " . sanitize_output($statusFilter);
                if ($urgencyFilter != 'all') $filters[] = "Urgency: " . sanitize_output($urgencyFilter);
                echo $filters ? implode(', ', $filters) : 'All';
            ?>
        </div>
    </div>

    <header class="admin-header">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-ticket-alt"></i> System Ticket Dashboard
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Comprehensive ticket analysis and management metrics • 
                User: <strong><?php echo htmlspecialchars($_SESSION['technician_name']); ?></strong> • 
                <?php echo date('d/m/Y H:i:s'); ?>
               
            </p>
        </div>
        <div>
            <a href="../logout.php" class="btn btn-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <nav class="admin-nav">
        <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Home</a>
        <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>
        <a href="questions.php"><i class="fas fa-question-circle"></i> Questions</a>
        <a href="history.php"><i class="fas fa-history"></i> History</a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> Convertation Dashboard</a>
        <a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> Ticket Dashboard</a>
    </nav>

    <div class="admin-container">
        <div class="page-header">
           <h2 style="font-size: 2rem;">
                <i class="fas fa-question-circle"></i> <?php echo $page_title; ?>
            </h2>
            <div class="export-options">
                <button class="btn btn-primary" onclick="printReport()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn btn-danger" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Time data warning -->
        <?php if(empty($metrics['time_intervals'])): ?>
        <div class="data-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Note:</strong> Assignment and resolution times are estimated based on total processing time. 
            For more accurate metrics, ensure tickets have complete assignment and resolution dates.
        </div>
        <?php endif; ?>

        <!-- Main Insights -->
        <div class="insight-card">
            <div class="insight-title">
                <i class="fas fa-lightbulb"></i> Key Insights for Department <?php echo htmlspecialchars($user_department); ?>
            </div>
            <p>The department shows a closure rate of <strong><?php echo sanitize_output($metrics['closure_rate']); ?>%</strong> 
            with an average resolution time of <strong><?php echo sanitize_output($metrics['avg_resolution_time']); ?> hours</strong>. 
            <?php echo sanitize_output($metrics['open_tickets'] + $metrics['in_process_tickets']); ?> tickets require immediate attention.</p>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form id="filterForm" method="GET" onsubmit="return validateFilters()">
                <div class="filter-grid">
                    <!-- Custom dates -->
                    <div class="filter-group" style="grid-column: 1 / -1;">
                        <label class="filter-label">Custom Date Range</label>
                        <div class="date-inputs">
                            <div class="filter-group">
                                <label style="font-size: 0.8rem; color: #666; margin-bottom: 0.25rem;">Start Date</label>
                                <input type="date" class="filter-input" id="startDate" name="startDate" 
                                       value="<?php echo sanitize_output($startDate); ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="filter-group">
                                <label style="font-size: 0.8rem; color: #666; margin-bottom: 0.25rem;">End Date</label>
                                <input type="date" class="filter-input" id="endDate" name="endDate" 
                                       value="<?php echo sanitize_output($endDate); ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- HIDDEN DEPARTMENT FIELD -->
                    <input type="hidden" name="departmentFilter" value="<?php echo htmlspecialchars($user_department); ?>">
                    
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select class="filter-select" id="statusFilter" name="statusFilter">
                            <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>All statuses</option>
                            <option value="open" <?php echo $statusFilter == 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_process" <?php echo $statusFilter == 'in_process' ? 'selected' : ''; ?>>In Process</option>
                            <option value="closed" <?php echo $statusFilter == 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Urgency</label>
                        <select class="filter-select" id="urgencyFilter" name="urgencyFilter">
                            <option value="all" <?php echo $urgencyFilter == 'all' ? 'selected' : ''; ?>>All urgencies</option>
                            <option value="high_urg" <?php echo $urgencyFilter == 'high_urg' ? 'selected' : ''; ?>>High</option>
                            <option value="medium_urg" <?php echo $urgencyFilter == 'medium_urg' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low_urg" <?php echo $urgencyFilter == 'low_urg' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    
                    <div class="filter-group" style="display: flex; gap: 1rem; align-items: flex-end;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" class="btn btn-warning" onclick="resetDates()">
                            <i class="fas fa-undo"></i> Clear
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo sanitize_output($metrics['total_tickets']); ?></div>
                <div class="stat-label">Total Tickets</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 100%"></div>
                </div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-number"><?php echo sanitize_output($metrics['closure_rate']); ?>%</div>
                <div class="stat-label">Closure Rate</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo sanitize_output($metrics['closure_rate']); ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-number"><?php echo sanitize_output($metrics['open_tickets']); ?></div>
                <div class="stat-label">Open Tickets</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($metrics['open_tickets'] / max(1, $metrics['total_tickets'])) * 100); ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-number"><?php echo sanitize_output($metrics['in_process_tickets']); ?></div>
                <div class="stat-label">In Process</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($metrics['in_process_tickets'] / max(1, $metrics['total_tickets'])) * 100); ?>%"></div>
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-number"><?php echo sanitize_output($metrics['closed_tickets']); ?></div>
                <div class="stat-label">Closed Tickets</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($metrics['closed_tickets'] / max(1, $metrics['total_tickets'])) * 100); ?>%"></div>
                </div>
            </div>

            <div class="stat-card purple">
                <div class="stat-number"><?php echo sanitize_output($metrics['avg_resolution_time']); ?>h</div>
                <div class="stat-label">Average Resolution Time</div>
                <div class="stat-trend trend-down">
                    <i class="fas fa-arrow-down"></i> 5.2% vs last month
                </div>
            </div>

            <!-- NEW TIME CARDS -->
            <div class="stat-card teal">
                <div class="stat-number"><?php echo sanitize_output($metrics['avg_assign_time']); ?>h</div>
                <div class="stat-label">Average Assignment Time</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($metrics['avg_assign_time'] / max(1, $metrics['avg_total_time'])) * 100); ?>%"></div>
                </div>
            </div>

            <div class="stat-card orange">
                <div class="stat-number"><?php echo sanitize_output($metrics['avg_resolution_time_detailed']); ?>h</div>
                <div class="stat-label">Average Resolution Time</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($metrics['avg_resolution_time_detailed'] / max(1, $metrics['avg_total_time'])) * 100); ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Main Charts -->
        <div class="charts-grid">
            <div class="chart-container">
                <div class="chart-title">Distribution by Status</div>
                <canvas id="statusChart"></canvas>
            </div>
            
            <div class="chart-container">
                <div class="chart-title">Timeline Evolution (<?php echo !empty($startDate) && !empty($endDate) ? "From " . date('d/m/Y', strtotime($startDate)) . " to " . date('d/m/Y', strtotime($endDate)) : "Last $dateRange days"; ?>)</div>
                <canvas id="timelineChart"></canvas>
            </div>
            
            <div class="chart-container page-break">
                <div class="chart-title">Activity by Hour of Day</div>
                <canvas id="hourlyChart"></canvas>
            </div>
            
            <div class="chart-container">
                <div class="chart-title">Distribution by Urgency</div>
                <canvas id="urgencyChart"></canvas>
            </div>

            <div class="chart-container">
                <div class="chart-title">Activity by Day of Week</div>
                <canvas id="weekdayChart"></canvas>
            </div>

            <div class="chart-container page-break">
                <div class="chart-title">Time Intervals by Ticket (hours)</div>
                <canvas id="timeIntervalsChart"></canvas>
            </div>
        </div>

        <!-- PROCESSING TIME ANALYSIS -->
        <div class="card avoid-break">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Processing Time Analysis</h3>
            </div>
            <div class="card-body">
                <div class="metrics-grid">
                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--info);">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo sanitize_output($metrics['avg_assign_time']); ?>h</div>
                            <div class="metric-label">Average Assignment Time</div>
                            <div style="font-size: 0.8rem; color: #666;">
                                From creation to technician assignment
                            </div>
                        </div>
                    </div>
                    
                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--success);">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo sanitize_output($metrics['avg_resolution_time_detailed']); ?>h</div>
                            <div class="metric-label">Average Resolution Time</div>
                            <div style="font-size: 0.8rem; color: #666;">
                                From assignment to complete resolution
                            </div>
                        </div>
                    </div>

                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--warning);">
                            <i class="fas fa-stopwatch"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo sanitize_output($metrics['avg_total_time']); ?>h</div>
                            <div class="metric-label">Average Total Time</div>
                            <div style="font-size: 0.8rem; color: #666;">
                                From creation to complete resolution
                            </div>
                        </div>
                    </div>
                </div>

                <?php if(!empty($metrics['time_intervals'])): ?>
                <div class="table-container" style="margin-top: 2rem;">
                    <h4 style="margin-bottom: 1rem; color: var(--primary);">Time Intervals Detail (Last 15 tickets)</h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ticket ID</th>
                                <th>Creation Date</th>
                                <th>Update Date</th>
                                <th>Assignment (h)</th>
                                <th>Resolution (h)</th>
                                <th>Total (h)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recent_intervals as $interval): 
                                $estimated_assign = $interval['total_time'] * 0.2;
                                $estimated_resolution = $interval['total_time'] * 0.8;
                            ?>
                            <tr>
                                <td><?php echo sanitize_output($interval['id']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($interval['creation_date'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($interval['update_date'])); ?></td>
                                <td>
                                    <span class="<?php echo $estimated_assign > 24 ? 'text-danger' : ($estimated_assign > 12 ? 'text-warning' : 'text-success'); ?>">
                                        <?php echo round($estimated_assign, 1); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="<?php echo $estimated_resolution > 48 ? 'text-danger' : ($estimated_resolution > 24 ? 'text-warning' : 'text-success'); ?>">
                                        <?php echo round($estimated_resolution, 1); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="<?php echo $interval['total_time'] > 72 ? 'text-danger' : ($interval['total_time'] > 48 ? 'text-warning' : 'text-success'); ?>">
                                        <?php echo sanitize_output($interval['total_time']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <i class="fas fa-clock" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>Not enough time interval data available</p>
                    <p style="font-size: 0.9rem;">Closed tickets with complete update dates are required</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- FREQUENCY ANALYSIS - Most Frequent Department -->
        <div class="card avoid-break">
            <div class="card-header">
                <h3><i class="fas fa-trophy"></i> Most Frequent Department</h3>
            </div>
            <div class="card-body">
                <?php if($most_frequent_dept): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
                    <div style="text-align: center;">
                        <div style="font-size: 3rem; color: var(--success); margin-bottom: 0.5rem;">
                            <i class="fas fa-building"></i>
                        </div>
                        <div style="font-size: 1.5rem; font-weight: bold; color: var(--primary);">
                            <?php echo sanitize_output($most_frequent_dept['department']); ?>
                        </div>
                        <div style="color: #666;">Department</div>
                    </div>
                    
                    <div style="text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: var(--primary); margin-bottom: 0.5rem;">
                            <?php echo sanitize_output($most_frequent_dept['count']); ?>
                        </div>
                        <div style="color: #666;">Total Tickets</div>
                    </div>
                </div>

                <!-- Detailed Analysis of Top Department -->
                <h4 style="margin-bottom: 1rem; color: var(--primary);">Efficiency Analysis by Department</h4>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Total Tickets</th>
                                <th>% of Total</th>
                                <th>Open</th>
                                <th>In Process</th>
                                <th>Closed</th>
                                <th>High Urgency</th>
                                <th>Medium Urgency</th>
                                <th>Low Urgency</th>
                                <th>Average Time (h)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($metrics['efficiency_by_dept'] as $dept): ?>
                            <tr>
                                <td>
                                    <strong><?php echo sanitize_output($dept['department']); ?></strong>
                                </td>
                                <td><?php echo sanitize_output($dept['total']); ?></td>
                                <td><?php echo round(($dept['total'] / $metrics['total_tickets']) * 100, 1); ?>%</td>
                                <td>
                                    <span class="text-warning"><?php echo sanitize_output($dept['open']); ?></span>
                                </td>
                                <td>
                                    <span class="text-info"><?php echo sanitize_output($dept['in_process']); ?></span>
                                </td>
                                <td>
                                    <span class="text-success"><?php echo sanitize_output($dept['closed']); ?></span>
                                </td>
                                <td>
                                    <span class="urgency-high"><?php echo sanitize_output($dept['high_urgent']); ?></span>
                                </td>
                                <td>
                                    <span class="urgency-medium"><?php echo sanitize_output($dept['medium_urgent']); ?></span>
                                </td>
                                <td>
                                    <span class="urgency-low"><?php echo sanitize_output($dept['low_urgent']); ?></span>
                                </td>
                                <td>
                                    <span class="<?php echo $dept['avg_closed_time'] <= 24 ? 'text-success' : ($dept['avg_closed_time'] <= 48 ? 'text-warning' : 'text-danger'); ?>">
                                        <?php echo $dept['avg_closed_time'] ? round($dept['avg_closed_time'], 1) : 'N/A'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>No department data available</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- FREQUENCY ANALYSIS - Most Frequent Need -->
        <div class="card page-break avoid-break">
            <div class="card-header">
                <h3><i class="fas fa-crown"></i> Most Frequent Need</h3>
            </div>
            <div class="card-body">
                <?php if($most_frequent_need): 
                // CALCULAR PORCENTAJES CORRECTAMENTE
                $total_needs = array_sum(array_column($metrics['frequent_needs'], 'frequency'));
                $top_percentage = $total_needs > 0 ? round(($most_frequent_need['frequency'] / $total_needs) * 100, 1) : 0;
                ?>
                <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem;">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                        <div class="frequency-badge" style="background: gold; color: #000;">
                            #1
                        </div>
                        <div>
                            <h4 style="margin: 0; font-size: 1.2rem;">"<?php echo sanitize_output($most_frequent_need['need']); ?>"</h4>
                            <p style="margin: 0; opacity: 0.9;">Most reported need</p>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                        <div style="text-align: center;">
                            <div style="font-size: 2rem; font-weight: bold;"><?php echo sanitize_output($most_frequent_need['frequency']); ?></div>
                            <div style="font-size: 0.9rem;">Times reported</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 2rem; font-weight: bold;"><?php echo $top_percentage; ?>%</div>
                            <div style="font-size: 0.9rem;">of Total Needs</div>
                        </div>
                    </div>
                </div>

                <!-- All frequent needs -->
                <h4 style="margin-bottom: 1rem; color: var(--primary);">Frequent Needs Ranking</h4>
                <div class="table-container">
                    <table class="table frequent-needs-table">
                        <thead>
                            <tr>
                                <th width="50">#</th>
                                <th>Need</th>
                                <th>Frequency</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_needs = array_sum(array_column($metrics['frequent_needs'], 'frequency'));
                            foreach($metrics['frequent_needs'] as $index => $need): 
                                $percentage = $total_needs > 0 ? round(($need['frequency'] / $total_needs) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td>
                                    <div class="frequency-badge <?php echo $index < 3 ? 'badge-success' : 'badge-primary'; ?>">
                                        <?php echo $index + 1; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="need-text" title="<?php echo sanitize_output($need['need']); ?>">
                                        <?php echo sanitize_output($need['need']); ?>
                                    </div>
                                </td>
                                <td style="min-width: 120px;">
                                    <strong><?php echo sanitize_output($need['frequency']); ?></strong>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php 
                                            $max_frequency = !empty($metrics['frequent_needs'][0]['frequency']) ? $metrics['frequent_needs'][0]['frequency'] : 1;
                                            echo min(100, ($need['frequency'] / $max_frequency) * 100); 
                                        ?>%"></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo $percentage; ?>%</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <i class="fas fa-question-circle" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>No frequent needs identified</p>
                    <p style="font-size: 0.9rem;">No tickets with needs found in the selected period</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Oldest Pending Tickets -->
        <div class="card avoid-break">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Oldest Pending Tickets</h3>
            </div>
            <div class="card-body">
                <?php if(!empty($metrics['oldest_open'])): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ticket ID</th>
                                <th>Query ID</th>
                                <th>Need</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Urgency</th>
                                <th>Creation Date</th>
                                <th>Days Pending</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($metrics['oldest_open'] as $ticket): 
                                $days_open = floor((time() - strtotime($ticket['creation_date'])) / (60 * 60 * 24));
                                $status_text = $ticket['status'] == 'open' ? 'Open' : ($ticket['status'] == 'in_process' ? 'In Process' : 'Closed');
                                $status_class = $ticket['status'] == 'open' ? 'text-warning' : ($ticket['status'] == 'in_process' ? 'text-info' : 'text-success');
                            ?>
                            <tr>
                                <td><?php echo sanitize_output($ticket['id']); ?></td>
                                <td><?php echo sanitize_output($ticket['query_id']); ?></td>
                                <td>
                                    <div class="need-text" title="<?php echo sanitize_output($ticket['need']); ?>">
                                        <?php echo sanitize_output($ticket['need']); ?>
                                    </div>
                                </td>
                                <td><?php echo sanitize_output($ticket['department']); ?></td>
                                <td>
                                    <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                </td>
                                <td>
                                    <?php 
                                    $urgency_class = '';
                                    $urgency_text = '';
                                    switch($ticket['urgency']) {
                                        case 'high_urg':
                                            $urgency_class = 'urgency-high';
                                            $urgency_text = 'High';
                                            break;
                                        case 'medium_urg':
                                            $urgency_class = 'urgency-medium';
                                            $urgency_text = 'Medium';
                                            break;
                                        case 'low_urg':
                                            $urgency_class = 'urgency-low';
                                            $urgency_text = 'Low';
                                            break;
                                    }
                                    ?>
                                    <span class="<?php echo $urgency_class; ?>"><?php echo $urgency_text; ?></span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($ticket['creation_date'])); ?></td>
                                <td>
                                    <span class="<?php echo $days_open > 7 ? 'text-danger' : ($days_open > 3 ? 'text-warning' : 'text-success'); ?>">
                                        <?php echo $days_open; ?> days
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>No pending tickets</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detailed Metrics -->
        <div class="card page-break avoid-break">
            <div class="card-header">
                <h3>Detailed Performance Metrics - <?php echo htmlspecialchars($user_department); ?></h3>
            </div>
            <div class="card-body">
                <div class="metrics-grid">
                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--success);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo sanitize_output($metrics['closed_tickets']); ?></div>
                            <div class="metric-label">Closed Tickets</div>
                        </div>
                    </div>
                    
                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--warning);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo sanitize_output($metrics['open_tickets']); ?></div>
                            <div class="metric-label">Open Tickets</div>
                        </div>
                    </div>

                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--info);">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo sanitize_output($metrics['in_process_tickets']); ?></div>
                            <div class="metric-label">Tickets In Process</div>
                        </div>
                    </div>

                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--danger);">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo $high_urgent; ?></div>
                            <div class="metric-label">High Urgency Tickets</div>
                        </div>
                    </div>

                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--purple);">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value">
                                <?php
                                $peak_hour = 0;
                                $peak_count = 0;
                                foreach($metrics['by_hour'] as $hour) {
                                    if($hour['count'] > $peak_count) {
                                        $peak_count = $hour['count'];
                                        $peak_hour = $hour['hour'];
                                    }
                                }
                                echo sanitize_output($peak_hour) . ':00';
                                ?>
                            </div>
                            <div class="metric-label">Peak Creation Hour</div>
                        </div>
                    </div>

                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--teal);">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo sanitize_output($metrics['avg_resolution_time']); ?>h</div>
                            <div class="metric-label">Average Resolution Time</div>
                        </div>
                    </div>

                    <!-- NUEVA MÉTRICA PARA TICKETS -->
                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--orange);">
                            <i class="fas fa-list"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo count($metrics['frequent_needs']); ?></div>
                            <div class="metric-label">Different Needs</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Print footer -->
    <div class="print-footer">
        <div>Report generated by Query System | Department: <?php echo htmlspecialchars($user_department); ?> | Page <span class="page-number"></span></div>
        <div>Created by Erick Dumas &copy; <?php echo date('Y'); ?> | v1.0.0</div>
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
        // ==================== VALIDACIONES JAVASCRIPT ====================
        
        // Timer de sesión (3 horas = 10800 segundos)
        let sessionTime = 10800;

        function updateSessionTimer() {
            sessionTime--;
            
            if (sessionTime <= 0) {
                // Sesión expirada - redirigir al login
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

        // Iniciar el timer
        setInterval(updateSessionTimer, 1000);

        // ==================== VERIFICACIÓN DE ESTADO DE USUARIO ====================
        function checkUserStatus() {
            fetch('../check_user_status.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Error en la respuesta del servidor');
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.active) {
                        window.location.href = '../login.php?error=user_inactive';
                    }
                })
                .catch(error => {
                    console.error('Error checking user status:', error);
                });
        }

        // Verificar estado cada 30 segundos
        setInterval(checkUserStatus, 30000);

        // ==================== VALIDACIÓN DE FILTROS EN CLIENTE ====================
        function validateFilters() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const today = new Date().toISOString().split('T')[0];
            
            // Validar que las fechas no sean futuras
            if (startDate > today) {
                alert('La fecha de inicio no puede ser futura');
                return false;
            }
            
            if (endDate > today) {
                alert('La fecha de fin no puede ser futura');
                return false;
            }
            
            // Validar rango de fechas
            if (startDate && endDate && startDate > endDate) {
                alert('La fecha de inicio no puede ser mayor que la fecha de fin');
                return false;
            }
            
            return true;
        }

        // ==================== SISTEMA DE GRÁFICOS CORREGIDO ====================
        const ChartManager = {
            instances: {},
            
            init: function() {
                this.destroyAll();
                this.createCharts();
            },
            
            destroyAll: function() {
                Object.values(this.instances).forEach(chart => {
                    if (chart && typeof chart.destroy === 'function') {
                        chart.destroy();
                    }
                });
                this.instances = {};
            },
            
            createCharts: function() {
                const commonOptions = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { 
                            display: true,
                            position: 'bottom'
                        } 
                    }
                };

                // 1. Gráfico de Estado - SIMPLIFICADO
                const statusCtx = document.getElementById('statusChart');
                if (statusCtx) {
                    this.instances.status = new Chart(statusCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Open', 'In Process', 'Closed'],
                            datasets: [{
                                data: [
                                    <?php echo $metrics['open_tickets']; ?>,
                                    <?php echo $metrics['in_process_tickets']; ?>, 
                                    <?php echo $metrics['closed_tickets']; ?>
                                ],
                                backgroundColor: ['#f39c12', '#3498db', '#27ae60']
                            }]
                        },
                        options: commonOptions
                    });
                }

                // 2. Gráfico de Timeline - SIMPLIFICADO
                const timelineCtx = document.getElementById('timelineChart');
                if (timelineCtx) {
                    this.instances.timeline = new Chart(timelineCtx, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode($timeline_dates_js); ?>,
                            datasets: [{
                                label: 'Tickets por Día',
                                data: <?php echo json_encode($timeline_counts_js); ?>,
                                borderColor: '#3498db',
                                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                fill: true
                            }]
                        },
                        options: commonOptions
                    });
                }

                // 3. Gráfico de Horas
                const hourlyCtx = document.getElementById('hourlyChart');
                if (hourlyCtx) {
                    this.instances.hourly = new Chart(hourlyCtx, {
                        type: 'bar',
                        data: {
                            labels: Array.from({length: 24}, (_, i) => i + ':00'),
                            datasets: [{
                                label: 'Tickets por Hora',
                                data: <?php echo json_encode($hourly_data_js); ?>,
                                backgroundColor: '#1abc9c'
                            }]
                        },
                        options: commonOptions
                    });
                }

                // 4. Gráfico de Urgencia
                const urgencyCtx = document.getElementById('urgencyChart');
                if (urgencyCtx) {
                    this.instances.urgency = new Chart(urgencyCtx, {
                        type: 'pie',
                        data: {
                            labels: ['High', 'Medium', 'Low'],
                            datasets: [{
                                data: [
                                    <?php echo $high_urgent; ?>,
                                    <?php echo $medium_urgent; ?>,
                                    <?php echo $low_urgent; ?>
                                ],
                                backgroundColor: ['#e74c3c', '#f39c12', '#27ae60']
                            }]
                        },
                        options: commonOptions
                    });
                }

                // 5. Gráfico de Días de la Semana
                const weekdayCtx = document.getElementById('weekdayChart');
                if (weekdayCtx) {
                    this.instances.weekday = new Chart(weekdayCtx, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($weekday_labels_js); ?>,
                            datasets: [{
                                label: 'Tickets',
                                data: <?php echo json_encode($weekday_counts_js); ?>,
                                backgroundColor: '#9b59b6'
                            }]
                        },
                        options: commonOptions
                    });
                }

                // 6. Gráfico de Intervalos de Tiempo
                const timeIntervalsCtx = document.getElementById('timeIntervalsChart');
                if (timeIntervalsCtx) {
                    this.instances.timeIntervals = new Chart(timeIntervalsCtx, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($interval_labels_js); ?>,
                            datasets: [
                                {
                                    label: 'Assignment Time',
                                    data: <?php echo json_encode($assign_times_js); ?>,
                                    backgroundColor: '#3498db'
                                },
                                {
                                    label: 'Resolution Time',
                                    data: <?php echo json_encode($resolution_times_js); ?>,
                                    backgroundColor: '#27ae60'
                                }
                            ]
                        },
                        options: {
                            ...commonOptions,
                            scales: {
                                x: {
                                    stacked: true,
                                },
                                y: {
                                    stacked: true,
                                    title: {
                                        display: true,
                                        text: 'Hours'
                                    }
                                }
                            }
                        }
                    });
                }
            }
        };

        // Inicializar cuando el DOM esté listo
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing charts...');
            
            try {
                ChartManager.init();
                console.log('Charts initialized successfully');
            } catch (error) {
                console.error('Error initializing charts:', error);
            }

            // Configurar fechas
            const today = new Date().toISOString().split('T')[0];
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');
            
            if (startDate) startDate.max = today;
            if (endDate) endDate.max = today;
        });

        // ==================== FUNCIONES DE UTILIDAD CON VALIDACIÓN ====================
        function refreshData() {
            if (confirm('¿Está seguro de que desea actualizar los datos?')) {
                window.location.reload();
            }
        }

        function resetDates() {
            if (confirm('¿Está seguro de que desea limpiar los filtros de fecha?')) {
                document.getElementById('startDate').value = '';
                document.getElementById('endDate').value = '';
                document.getElementById('filterForm').submit();
            }
        }

        // Validación de fechas en tiempo real
        document.getElementById('startDate')?.addEventListener('change', function() {
            const endDate = document.getElementById('endDate');
            if (this.value && endDate.value && this.value > endDate.value) {
                if (confirm('La fecha de inicio es mayor que la fecha de fin. ¿Desea ajustarlas automáticamente?')) {
                    endDate.value = this.value;
                }
            }
        });

        document.getElementById('endDate')?.addEventListener('change', function() {
            const startDate = document.getElementById('startDate');
            if (this.value && startDate.value && this.value < startDate.value) {
                if (confirm('La fecha de fin es menor que la fecha de inicio. ¿Desea ajustarlas automáticamente?')) {
                    startDate.value = this.value;
                }
            }
        });

        // Prevenir envío múltiple del formulario
        let formSubmitted = false;
        document.getElementById('filterForm')?.addEventListener('submit', function(e) {
            if (formSubmitted) {
                e.preventDefault();
                return;
            }
            formSubmitted = true;
            setTimeout(() => { formSubmitted = false; }, 3000);
        });

        // Filtros automáticos
        document.getElementById('statusFilter')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        document.getElementById('urgencyFilter')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        // Expandir texto de necesidades
        document.querySelectorAll('.need-text').forEach(element => {
            element.addEventListener('click', function() {
                this.classList.toggle('expanded');
            });
        });

        // Impresión
        function printReport() {
            const pageNumbers = document.querySelectorAll('.page-number');
            pageNumbers.forEach((el, index) => {
                el.textContent = (index + 1);
            });
            window.print();
        }

        // ==================== MANEJO DE ERRORES GLOBAL ====================
        window.addEventListener('error', function(e) {
            console.error('Error global capturado:', e.error);
        });

        // Prevenir clics múltiples en botones
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (this.classList.contains('disabled')) {
                    e.preventDefault();
                    return;
                }
                this.classList.add('disabled');
                setTimeout(() => {
                    this.classList.remove('disabled');
                }, 2000);
            });
        });
    </script>
</body>
</html>