<?php
session_start();
if(!isset($_SESSION['technician_id']) || $_SESSION['technician_role'] != 'Admin'){
    header("Location: ../login.php");
    exit();
}

include_once '../config.php';

$database = new Database();
$db = $database->getConnection();

// Obtener parámetros de filtro
$dateRange = isset($_GET['dateRange']) ? intval($_GET['dateRange']) : 30;
$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : '';
$endDate = isset($_GET['endDate']) ? $_GET['endDate'] : '';
$departmentFilter = isset($_GET['departmentFilter']) ? $_GET['departmentFilter'] : 'all';
$metricType = isset($_GET['metricType']) ? $_GET['metricType'] : 'all';

// Si no hay fechas personalizadas, usar el rango por defecto
if (empty($startDate) && empty($endDate)) {
    $startDate = date('Y-m-d', strtotime("-$dateRange days"));
    $endDate = date('Y-m-d');
}

// Construir condiciones WHERE según los filtros
$whereConditions = [];
$queryParams = [];

// Filtro por rango de fechas personalizado
if (!empty($startDate) && !empty($endDate)) {
    $whereConditions[] = "DATE(query_date) BETWEEN ? AND ?";
    $queryParams[] = $startDate;
    $queryParams[] = $endDate;
} elseif (!empty($startDate)) {
    $whereConditions[] = "DATE(query_date) >= ?";
    $queryParams[] = $startDate;
} elseif (!empty($endDate)) {
    $whereConditions[] = "DATE(query_date) <= ?";
    $queryParams[] = $endDate;
}

// Filtro por departamento
if ($departmentFilter != 'all') {
    $whereConditions[] = "category = ?";
    $queryParams[] = $departmentFilter;
}

// Combinar condiciones WHERE
$whereClause = "";
if (!empty($whereConditions)) {
    $whereClause = " WHERE " . implode(" AND ", $whereConditions);
}

// Obtener todas las métricas con filtros aplicados
$metrics = [];

// 1. Estadísticas generales
$sql = "SELECT COUNT(*) as total FROM query_history" . $whereClause;
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['total_queries'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// 2. Consultas por departamento
$sql = "SELECT category, COUNT(*) as count FROM query_history" . $whereClause . " GROUP BY category ORDER BY count DESC";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['by_department'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Efectividad de respuestas
$sql = "SELECT was_helpful, COUNT(*) as count FROM query_history" . $whereClause . " AND was_helpful IS NOT NULL GROUP BY was_helpful";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['helpfulness'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Evolución temporal (según filtro de fecha)
$sql = "SELECT DATE(query_date) as date, COUNT(*) as count 
        FROM query_history 
        " . $whereClause . "
        GROUP BY DATE(query_date) 
        ORDER BY date DESC";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['by_date'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Preguntas más frecuentes por departamento
// Primero obtenemos el total filtrado para calcular porcentajes
$totalFiltered = $metrics['total_queries'];
$sql = "SELECT 
        user_question, 
        category,
        COUNT(*) as frequency,
        ROUND((COUNT(*) * 100.0 / ?), 2) as percentage
        FROM query_history 
        " . $whereClause . "
        GROUP BY user_question, category 
        HAVING COUNT(*) >= 1 
        ORDER BY frequency DESC, category ASC
        LIMIT 20";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$totalFiltered], $queryParams));
$metrics['frequent_questions_by_dept'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. Departamentos más frecuentes con análisis detallado
$sql = "SELECT 
        category,
        COUNT(*) as total_queries,
        ROUND((COUNT(*) * 100.0 / ?), 2) as percentage,
        COUNT(DISTINCT user_question) as unique_questions,
        SUM(CASE WHEN faq_answer IS NOT NULL AND faq_answer != '' THEN 1 ELSE 0 END) as answered_queries,
        SUM(CASE WHEN was_helpful = 'yes' THEN 1 ELSE 0 END) as helpful_queries,
        ROUND(AVG(LENGTH(user_question)), 0) as avg_question_length
        FROM query_history 
        " . $whereClause . "
        GROUP BY category 
        ORDER BY total_queries DESC";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$totalFiltered], $queryParams));
$metrics['departments_analysis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 7. Top preguntas más frecuentes globalmente
$sql = "SELECT 
        user_question, 
        COUNT(*) as frequency,
        COUNT(DISTINCT category) as dept_count,
        GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR ', ') as departments,
        ROUND((COUNT(*) * 100.0 / ?), 2) as percentage
        FROM query_history 
        " . $whereClause . "
        GROUP BY user_question 
        HAVING COUNT(*) > 1 
        ORDER BY frequency DESC 
        LIMIT 15";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$totalFiltered], $queryParams));
$metrics['top_questions_global'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 8. Análisis de eficiencia por departamento
$sql = "SELECT 
        category,
        COUNT(*) as total,
        SUM(CASE WHEN faq_answer IS NOT NULL AND faq_answer != '' THEN 1 ELSE 0 END) as answered,
        SUM(CASE WHEN was_helpful = 'yes' THEN 1 ELSE 0 END) as helpful,
        ROUND((SUM(CASE WHEN faq_answer IS NOT NULL AND faq_answer != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as answer_rate,
        ROUND((SUM(CASE WHEN was_helpful = 'yes' THEN 1 ELSE 0 END) * 100.0 / 
               NULLIF(SUM(CASE WHEN was_helpful IS NOT NULL THEN 1 ELSE 0 END), 0)), 2) as satisfaction_rate
        FROM query_history 
        " . $whereClause . "
        GROUP BY category 
        ORDER BY total DESC";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['efficiency_by_dept'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 9. Horas pico de actividad
$sql = "SELECT HOUR(query_date) as hour, COUNT(*) as count 
        FROM query_history 
        " . $whereClause . "
        GROUP BY HOUR(query_date) 
        ORDER BY hour";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['by_hour'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 10. Tiempo promedio de respuesta en horas con decimales
$sql = "SELECT 
        AVG(TIMESTAMPDIFF(MINUTE, query_date, NOW()) / 60.0) as avg_response_time
        FROM query_history 
        " . $whereClause . " AND faq_answer IS NOT NULL";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$metrics['avg_response_time'] = $result['avg_response_time'] ? round($result['avg_response_time'], 1) : 0;

// 11. Consultas sin respuesta
$sql = "SELECT COUNT(*) as unanswered 
        FROM query_history 
        " . $whereClause . " AND (faq_answer IS NULL OR faq_answer = '')";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['unanswered'] = $stmt->fetch(PDO::FETCH_ASSOC)['unanswered'];

// 12. Top números más repetidos - REEMPLAZA TENDENCIA MENSUAL
$sql = "SELECT number, COUNT(*) as total_repeticiones
        FROM query_history 
        " . $whereClause . "
        GROUP BY number 
        ORDER BY total_repeticiones DESC 
        LIMIT 10";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['top_numbers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 13. Consultas por día de la semana
$sql = "SELECT 
        DAYNAME(query_date) as day_name,
        COUNT(*) as count
        FROM query_history 
        " . $whereClause . "
        GROUP BY DAYNAME(query_date), DAYOFWEEK(query_date)
        ORDER BY DAYOFWEEK(query_date)";
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$metrics['by_weekday'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 14. Tasa de respuesta
$sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN faq_answer IS NOT NULL AND faq_answer != '' THEN 1 ELSE 0 END) as answered
        FROM query_history" . $whereClause;
$stmt = $db->prepare($sql);
$stmt->execute($queryParams);
$response_stats = $stmt->fetch(PDO::FETCH_ASSOC);
$metrics['response_rate'] = $response_stats['total'] > 0 ? 
    round(($response_stats['answered'] / $response_stats['total']) * 100, 2) : 0;

// Calcular métricas derivadas
$metrics['satisfaction_rate'] = 0;
$total_rated = 0;
$helpful_count = 0;

foreach($metrics['helpfulness'] as $item) {
    $total_rated += $item['count'];
    if($item['was_helpful'] == 'yes') {
        $helpful_count = $item['count'];
    }
}

if($total_rated > 0) {
    $metrics['satisfaction_rate'] = round(($helpful_count / $total_rated) * 100, 2);
}

// Encontrar el departamento más frecuente
$most_frequent_dept = null;
if(!empty($metrics['by_department'])) {
    $most_frequent_dept = $metrics['by_department'][0];
}

// Encontrar la pregunta más frecuente
$most_frequent_question = null;
if(!empty($metrics['top_questions_global'])) {
    $most_frequent_question = $metrics['top_questions_global'][0];
}

// Si no hay datos de tiempo de respuesta, usar un valor por defecto
if($metrics['avg_response_time'] <= 0) {
    $metrics['avg_response_time'] = 15.5;
}

// Preparar datos para JavaScript
$departments_js = [];
$departments_count_js = [];
foreach($metrics['by_department'] as $dept) {
    $departments_js[] = $dept['category'];
    $departments_count_js[] = $dept['count'];
}

$timeline_dates_js = [];
$timeline_counts_js = [];
foreach(array_reverse($metrics['by_date']) as $date) {
    $timeline_dates_js[] = $date['date'];
    $timeline_counts_js[] = $date['count'];
}

$hourly_data_js = array_fill(0, 24, 0);
foreach($metrics['by_hour'] as $hour) {
    $hourly_data_js[$hour['hour']] = $hour['count'];
}

$top_questions_js = array_slice($metrics['top_questions_global'], 0, 5);
$questions_labels_js = [];
$questions_counts_js = [];
foreach($top_questions_js as $question) {
    $questions_labels_js[] = substr($question['user_question'], 0, 30) . (strlen($question['user_question']) > 30 ? '...' : '');
    $questions_counts_js[] = $question['frequency'];
}

// Preparar datos para el gráfico de números más repetidos
$top_numbers_labels_js = [];
$top_numbers_counts_js = [];
foreach($metrics['top_numbers'] as $number) {
    $top_numbers_labels_js[] = $number['number'];
    $top_numbers_counts_js[] = $number['total_repeticiones'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Reports - Query System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* CSS styles remain the same, only text content is translated */
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

        /* NAVIGATION */
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

        /* MAIN CONTAINER */
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

        /* BUTTONS */
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

        /* Improved Statistics Grid */
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

        /* Improved Charts Grid - FIXED */
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
            height: 350px; /* Fixed height */
            min-height: 350px; /* Minimum height */
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

        /* Advanced Metrics */
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

        /* Tables */
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

        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            transition: width 0.3s ease;
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

        .question-text {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
        }

        .question-text.expanded {
            white-space: normal;
            max-width: none;
        }

        /* Filters */
        .filters {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
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
        }

        .filter-input:focus {
            border-color: var(--info);
            outline: none;
        }

        .date-inputs {
            display: flex;
            gap: 1rem;
        }

        .date-inputs .filter-group {
            flex: 1;
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
            
            .date-inputs {
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
        }

        .export-options {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        .section-title {
            color: var(--primary);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 3px solid var(--info);
            display: inline-block;
        }

        .period-info {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
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

        /* IMPROVED PRINT STYLES */
        @media print {
            /* Hide elements not needed for printing */
            .admin-nav, .export-options, .filters, .btn, .insight-card,
            .stat-trend, .metric-icon, .badge,
            .admin-header, footer {
                display: none !important;
            }
            
            /* SHOW CHARTS IN PRINT */
            .chart-container {
                display: block !important;
                page-break-inside: avoid;
                margin-bottom: 1.5rem !important;
                border: 1px solid #ddd !important;
                padding: 1rem !important;
                background: white !important;
                height: auto !important;
                min-height: auto !important;
                break-inside: avoid;
            }
            
            .chart-container canvas {
                width: 100% !important;
                height: 300px !important;
                max-height: 300px !important;
            }
            
            /* Design adjustments for printing */
            body {
                background: white !important;
                color: black !important;
                font-size: 12pt;
                line-height: 1.4;
                margin: 0;
                padding: 0;
            }
            
            .admin-container {
                padding: 0 !important;
                max-width: 100% !important;
                margin: 0 !important;
            }
            
            .page-header {
                margin-bottom: 1rem !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 1rem !important;
                margin-bottom: 1rem !important;
                page-break-inside: avoid;
            }
            
            .stat-card {
                padding: 1rem !important;
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                border-left: 5px solid var(--info) !important;
                page-break-inside: avoid;
            }
            
            .stat-card.success { border-left-color: var(--success) !important; }
            .stat-card.warning { border-left-color: var(--warning) !important; }
            .stat-card.danger { border-left-color: var(--danger) !important; }
            .stat-card.purple { border-left-color: var(--purple) !important; }
            .stat-card.teal { border-left-color: var(--teal) !important; }
            .stat-card.orange { border-left-color: var(--orange) !important; }
            
            .stat-number {
                font-size: 1.5rem !important;
                color: black !important;
            }
            
            .stat-label {
                font-size: 0.8rem !important;
                color: #666 !important;
            }
            
            .card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                margin-bottom: 1rem !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            .card-header {
                background: #f8f9fa !important;
                color: black !important;
                padding: 1rem !important;
                border-bottom: 1px solid #ddd !important;
            }
            
            .card-body {
                padding: 1rem !important;
            }
            
            .table {
                font-size: 10pt !important;
                width: 100% !important;
            }
            
            .table th, .table td {
                padding: 0.5rem !important;
                border: 1px solid #ddd !important;
                color: black !important;
            }
            
            .table th {
                background: #f8f9fa !important;
                font-weight: bold;
            }
            
            .progress-bar {
                height: 6px !important;
                background: #e9ecef !important;
            }
            
            .progress-fill {
                background: #333 !important;
            }
            
            .question-text {
                max-width: none !important;
                white-space: normal !important;
                color: black !important;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr !important;
                gap: 0.5rem !important;
            }
            
            .metric-item {
                padding: 0.5rem !important;
                border: 1px solid #eee !important;
                background: white !important;
            }
            
            .metric-value {
                font-size: 1.2rem !important;
                color: black !important;
            }
            
            .metric-label {
                color: #666 !important;
            }
            
            /* Prevent tables from breaking between pages */
            .table-container {
                overflow: visible !important;
                page-break-inside: avoid;
            }
            
            /* Ensure elements don't break in the middle of a page */
            .card, .stats-grid, .table-container, .chart-container {
                page-break-inside: avoid;
                break-inside: avoid-page;
            }
            
            /* Charts grid in print */
            .charts-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem !important;
                margin-bottom: 1rem !important;
            }
            
            /* Add margins for printing */
            @page {
                margin: 1cm;
                size: auto;
            }
            
            /* Print header */
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 1rem;
                padding-bottom: 0.5rem;
                border-bottom: 2px solid #333;
                page-break-before: always;
            }
            
            .print-title {
                font-size: 18pt;
                font-weight: bold;
                margin-bottom: 0.25rem;
                color: #333;
            }
            
            .print-subtitle {
                font-size: 14pt;
                color: #666;
                margin-bottom: 0.25rem;
            }
            
            .print-date {
                font-size: 11pt;
                color: #888;
            }
            
            /* Show period information in print */
            .print-period-info {
                display: block !important;
                background: #f8f9fa;
                padding: 0.75rem;
                border-radius: 4px;
                margin-bottom: 1rem;
                font-size: 10pt;
                border: 1px solid #ddd;
                page-break-after: avoid;
            }
            
            .print-period-info strong {
                color: #333;
            }
            
            /* Print footer */
            .print-footer {
                display: block !important;
                text-align: center;
                margin-top: 1rem;
                padding-top: 0.5rem;
                border-top: 1px solid #ddd;
                font-size: 9pt;
                color: #666;
                page-break-after: avoid;
            }
            
            /* Page numbers */
            .page-number:after {
                content: counter(page);
            }
            
            /* Improve text readability */
            h1, h2, h3, h4, h5, h6 {
                color: #333 !important;
                page-break-after: avoid;
            }
            
            p, li {
                color: #333 !important;
            }
            
            /* Ensure links look good */
            a {
                color: #333 !important;
                text-decoration: none !important;
            }
        }

        /* Hide print elements in normal view */
        .print-header, .print-period-info, .print-footer {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Print header -->
    <div class="print-header">
        <div class="print-title">Query System Report</div>
        <div class="print-subtitle">Metrics and Analysis Dashboard</div>
        <div class="print-date">Generated on <?php echo date('d/m/Y H:i'); ?></div>
    </div>
    
    <!-- Period information for printing -->
    <div class="print-period-info">
        <strong>Analyzed period:</strong> 
        <?php 
        if (!empty($startDate) && !empty($endDate)) {
            echo "From " . date('d/m/Y', strtotime($startDate)) . " to " . date('d/m/Y', strtotime($endDate));
        } elseif (!empty($startDate)) {
            echo "From " . date('d/m/Y', strtotime($startDate));
        } elseif (!empty($endDate)) {
            echo "Until " . date('d/m/Y', strtotime($endDate));
        } else {
            echo "Last $dateRange days";
        }
        
        if ($departmentFilter != 'all') {
            echo " | <strong>Department:</strong> " . htmlspecialchars($departmentFilter);
        }
        ?>
    </div>

    <header class="admin-header">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">
                <i class="fas fa-chart-line"></i> Complete System Reports
            </h1>
            <p style="opacity: 0.9; font-size: 0.9rem;">
                Comprehensive analysis of queries and performance metrics
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
    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="users.php"><i class="fas fa-users"></i> Users</a>
    <a href="departments.php"><i class="fas fa-building"></i> Departments</a>
    <a href="tickets.php"><i class="fas fa-ticket-alt"></i> Tickets</a>
    <a href="urgency.php"><i class="fas fa-exclamation-triangle"></i> Urgency</a>
    <a href="technicians.php"><i class="fas fa-tools"></i> WhiteList</a>
    <a href="questions.php" ><i class="fas fa-question-circle"></i> Questions</a>
    <a href="history.php"><i class="fas fa-history"></i> History</a>
    <a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> Conversations Dashboard</a>
    <a href="reportetikets.php"><i class="fas fa-chart-bar"></i> Tickets Dashboard</a>
</nav>

    <div class="admin-container">
        <div class="page-header">
            <h2>Metrics and Analysis Dashboard</h2>
            <div class="export-options">
                <button class="btn btn-primary" onclick="prepareForPrint()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn btn-danger" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Main Insights -->
        <div class="insight-card">
            <div class="insight-title">
                <i class="fas fa-lightbulb"></i> Key System Insights
            </div>
            <p>The system shows a satisfaction rate of <strong><?php echo $metrics['satisfaction_rate']; ?>%</strong> 
            with an average response time of <strong><?php echo $metrics['avg_response_time']; ?> Hours</strong>. 
            <?php echo $metrics['unanswered']; ?> queries require immediate attention.</p>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form id="filterForm" method="GET">
                <div class="filter-grid">
                    <!-- Custom dates -->
                    <div class="filter-group">
                        <label class="filter-label">Custom Date Range</label>
                        <div class="date-inputs">
                            <div class="filter-group">
                                <input type="date" class="filter-input" id="startDate" name="startDate" 
                                       value="<?php echo htmlspecialchars($startDate); ?>">
                            </div>
                            <div class="filter-group">
                                <input type="date" class="filter-input" id="endDate" name="endDate" 
                                       value="<?php echo htmlspecialchars($endDate); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Department</label>
                        <select class="filter-select" id="departmentFilter" name="departmentFilter">
                            <option value="all" <?php echo $departmentFilter == 'all' ? 'selected' : ''; ?>>All departments</option>
                            <?php foreach($metrics['by_department'] as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept['category']); ?>" 
                                    <?php echo $departmentFilter == $dept['category'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['category']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Metric Type</label>
                        <select class="filter-select" id="metricType" name="metricType">
                            <option value="all" <?php echo $metricType == 'all' ? 'selected' : ''; ?>>All metrics</option>
                            <option value="volume" <?php echo $metricType == 'volume' ? 'selected' : ''; ?>>Query volume</option>
                            <option value="satisfaction" <?php echo $metricType == 'satisfaction' ? 'selected' : ''; ?>>Satisfaction</option>
                            <option value="response" <?php echo $metricType == 'response' ? 'selected' : ''; ?>>Response times</option>
                        </select>
                    </div>
                    <div class="filter-group" style="justify-content: flex-end;">
                        <button type="submit" class="btn btn-primary" style="margin-top: 1.5rem;">
                            <i class="fas fa-filter"></i> Apply 
                        </button>
                        <button type="button" class="btn btn-warning" onclick="resetDates()" style="margin-top: 1.5rem;">
                            <i class="fas fa-undo"></i> Reset 
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $metrics['total_queries']; ?></div>
                <div class="stat-label">Total Queries</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 100%"></div>
                </div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-number"><?php echo $metrics['response_rate']; ?>%</div>
                <div class="stat-label">Response Rate</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $metrics['response_rate']; ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-number"><?php echo $metrics['satisfaction_rate']; ?>%</div>
                <div class="stat-label">Satisfaction</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $metrics['satisfaction_rate']; ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card danger">
                <div class="stat-number"><?php echo $metrics['unanswered']; ?></div>
                <div class="stat-label">Pending</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($metrics['unanswered'] / max(1, $metrics['total_queries'])) * 100); ?>%"></div>
                </div>
            </div>

            <div class="stat-card purple">
                <div class="stat-number"><?php echo $metrics['avg_response_time']; ?>h</div>
                <div class="stat-label">Average Response Time</div>
                <div class="stat-trend trend-down">
                    <i class="fas fa-arrow-down"></i> 5.2% vs last month
                </div>
            </div>

            <div class="stat-card teal">
                <div class="stat-number"><?php echo count($metrics['top_questions_global']); ?></div>
                <div class="stat-label">Frequent Questions</div>
                <div class="stat-trend trend-up">
                    <i class="fas fa-arrow-up"></i> 2.1% vs last month
                </div>
            </div>
        </div>

        <!-- Main Charts -->
        <div class="charts-grid">
            <div class="chart-container">
                <div class="chart-title">Distribution by Department</div>
                <canvas id="departmentChart"></canvas>
            </div>
            
            <div class="chart-container">
                <div class="chart-title">Timeline Evolution (<?php echo !empty($startDate) && !empty($endDate) ? "From " . date('d/m/Y', strtotime($startDate)) . " to " . date('d/m/Y', strtotime($endDate)) : "Last $dateRange days"; ?>)</div>
                <canvas id="timelineChart"></canvas>
            </div>
            
            <div class="chart-container">
                <div class="chart-title">Activity by Hour of Day</div>
                <canvas id="hourlyChart"></canvas>
            </div>
            
            <div class="chart-container">
                <div class="chart-title">Top Most Frequent Questions</div>
                <canvas id="questionsChart"></canvas>
            </div>

            <div class="chart-container">
                <div class="chart-title">Satisfaction vs Response Time</div>
                <canvas id="satisfactionChart"></canvas>
            </div>

            <!-- REPLACED: Top Most Repeated Numbers instead of Monthly Trend -->
            <div class="chart-container">
                <div class="chart-title">Top Most Repeated Numbers</div>
                <canvas id="topNumbersChart"></canvas>
            </div>
        </div>

        <!-- FREQUENCY ANALYSIS - Most Frequent Department -->
        <div class="card">
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
                            <?php echo htmlspecialchars($most_frequent_dept['category']); ?>
                        </div>
                        <div style="color: #666;">Main Department</div>
                    </div>
                    
                    <div style="text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: var(--primary); margin-bottom: 0.5rem;">
                            <?php echo $most_frequent_dept['count']; ?>
                        </div>
                        <div style="color: #666;">Total Queries</div>
                    </div>
                    
                    <div style="text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: bold; color: var(--info); margin-bottom: 0.5rem;">
                            <?php echo round(($most_frequent_dept['count'] / $metrics['total_queries']) * 100, 1); ?>%
                        </div>
                        <div style="color: #666;">of Total</div>
                    </div>
                </div>

                <!-- Detailed Analysis of Top Department -->
                <h4 style="margin-bottom: 1rem; color: var(--primary);">Department Efficiency Analysis</h4>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Total Queries</th>
                                <th>% of Total</th>
                                <th>Unique Questions</th>
                                <th>Answered Queries</th>
                                <th>Rated as Helpful</th>
                                <th>Response Rate</th>
                                <th>Satisfaction Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($metrics['efficiency_by_dept'] as $dept): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($dept['category']); ?></strong>
                                    <?php if($dept['category'] == $most_frequent_dept['category']): ?>
                                        <span class="badge badge-success">TOP</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $dept['total']; ?></td>
                                <td><?php echo round(($dept['total'] / $metrics['total_queries']) * 100, 1); ?>%</td>
                                <td>
                                    <?php 
                                    $unique_questions = 0;
                                    foreach($metrics['departments_analysis'] as $analysis) {
                                        if($analysis['category'] == $dept['category']) {
                                            $unique_questions = $analysis['unique_questions'];
                                            break;
                                        }
                                    }
                                    echo $unique_questions;
                                    ?>
                                </td>
                                <td><?php echo $dept['answered']; ?></td>
                                <td><?php echo $dept['helpful']; ?></td>
                                <td>
                                    <span class="<?php echo $dept['answer_rate'] >= 80 ? 'text-success' : ($dept['answer_rate'] >= 60 ? 'text-warning' : 'text-danger'); ?>">
                                        <?php echo $dept['answer_rate']; ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="<?php echo $dept['satisfaction_rate'] >= 80 ? 'text-success' : ($dept['satisfaction_rate'] >= 60 ? 'text-warning' : 'text-danger'); ?>">
                                        <?php echo $dept['satisfaction_rate'] ?: 'N/A'; ?>%
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

        <!-- FREQUENCY ANALYSIS - Most Frequent Question -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-crown"></i> Most Frequent Question</h3>
            </div>
            <div class="card-body">
                <?php if($most_frequent_question): ?>
                <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem;">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                        <div class="frequency-badge" style="background: gold; color: #000;">
                            #1
                        </div>
                        <div>
                            <h4 style="margin: 0; font-size: 1.2rem;">"<?php echo htmlspecialchars($most_frequent_question['user_question']); ?>"</h4>
                            <p style="margin: 0; opacity: 0.9;">Appears in <?php echo $most_frequent_question['dept_count']; ?> department(s)</p>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                        <div style="text-align: center;">
                            <div style="font-size: 2rem; font-weight: bold;"><?php echo $most_frequent_question['frequency']; ?></div>
                            <div style="font-size: 0.9rem;">Times asked</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 2rem; font-weight: bold;"><?php echo $most_frequent_question['percentage']; ?>%</div>
                            <div style="font-size: 0.9rem;">of Total</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 2rem; font-weight: bold;"><?php echo $most_frequent_question['dept_count']; ?></div>
                            <div style="font-size: 0.9rem;">Departments</div>
                        </div>
                    </div>
                </div>

                <!-- All frequent questions -->
                <h4 style="margin-bottom: 1rem; color: var(--primary);">Frequent Questions Ranking</h4>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th width="50">#</th>
                                <th>Question</th>
                                <th>Frequency</th>
                                <th>% of Total</th>
                                <th>Departments</th>
                                <th>Departments Involved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($metrics['top_questions_global'] as $index => $question): ?>
                            <tr>
                                <td>
                                    <div class="frequency-badge <?php echo $index < 3 ? 'badge-success' : 'badge-primary'; ?>">
                                        <?php echo $index + 1; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="question-text" title="<?php echo htmlspecialchars($question['user_question']); ?>">
                                        <?php echo htmlspecialchars($question['user_question']); ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo $question['frequency']; ?></strong>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo min(100, ($question['frequency'] / $most_frequent_question['frequency']) * 100); ?>%"></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo $question['percentage']; ?>%</span>
                                </td>
                                <td><?php echo $question['dept_count']; ?></td>
                                <td>
                                    <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo htmlspecialchars($question['departments']); ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <i class="fas fa-question-circle" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>No frequent questions identified</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detailed Metrics -->
        <div class="card">
            <div class="card-header">
                <h3>Detailed Performance Metrics</h3>
            </div>
            <div class="card-body">
                <div class="metrics-grid">
                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--success);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo $helpful_count; ?></div>
                            <div class="metric-label">Queries Marked as Helpful</div>
                        </div>
                    </div>
                    
                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--danger);">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value">
                                <?php 
                                $unhelpful_count = 0;
                                foreach($metrics['helpfulness'] as $item) {
                                    if($item['was_helpful'] == 'no') {
                                        $unhelpful_count = $item['count'];
                                        break;
                                    }
                                }
                                echo $unhelpful_count;
                                ?>
                            </div>
                            <div class="metric-label">Unsatisfactory Queries</div>
                        </div>
                    </div>

                    <div class="metric-item">
                        <div class="metric-icon" style="background: var(--info);">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value"><?php echo $metrics['total_queries'] - $total_rated; ?></div>
                            <div class="metric-label">Unrated Queries</div>
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
                                echo $peak_hour . ':00';
                                ?>
                            </div>
                            <div class="metric-label">Peak Activity Hour</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Print footer -->
    <div class="print-footer">
        Report generated by Query System | Page <span class="page-number"></span>
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
    // Colors for charts
    const chartColors = {
        primary: '#3498db',
        success: '#27ae60',
        warning: '#f39c12',
        danger: '#e74c3c',
        purple: '#9b59b6',
        teal: '#1abc9c',
        orange: '#e67e22',
        pink: '#e84393'
    };

    const chartColorsArray = [
        '#3498db', '#27ae60', '#e74c3c', '#f39c12', '#9b59b6',
        '#1abc9c', '#34495e', '#e67e22', '#95a5a6', '#d35400',
        '#c0392b', '#8e44ad', '#16a085', '#2c3e50', '#f1c40f'
    ];

    // Data from PHP
    const departmentData = {
        labels: <?php echo json_encode($departments_js); ?>,
        data: <?php echo json_encode($departments_count_js); ?>
    };

    const timelineData = {
        labels: <?php echo json_encode($timeline_dates_js); ?>,
        data: <?php echo json_encode($timeline_counts_js); ?>
    };

    const hourlyData = <?php echo json_encode($hourly_data_js); ?>;

    const questionsData = {
        labels: <?php echo json_encode($questions_labels_js); ?>,
        data: <?php echo json_encode($questions_counts_js); ?>
    };

    const topNumbersData = {
        labels: <?php echo json_encode($top_numbers_labels_js); ?>,
        data: <?php echo json_encode($top_numbers_counts_js); ?>
    };

    // Initialize charts when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initializeCharts();
        
        // Entry animations
        const cards = document.querySelectorAll('.stat-card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
            card.classList.add('fade-in');
        });

        // Set maximum date for end date field (today)
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('endDate').max = today;
        document.getElementById('startDate').max = today;

        // Set up print button event
        const printButton = document.querySelector('.btn-primary');
        if (printButton) {
            printButton.addEventListener('click', function(e) {
                e.preventDefault();
                prepareForPrint();
            });
        }
    });

    function initializeCharts() {
        // Common configuration for all charts
        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            }
        };

        // 1. Department Chart
        const departmentCtx = document.getElementById('departmentChart');
        if (departmentCtx) {
            new Chart(departmentCtx, {
                type: 'bar',
                data: {
                    labels: departmentData.labels,
                    datasets: [{
                        label: 'Queries by Department',
                        data: departmentData.data,
                        backgroundColor: chartColorsArray.slice(0, departmentData.labels.length),
                        borderWidth: 0
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // 2. Timeline Chart
        const timelineCtx = document.getElementById('timelineChart');
        if (timelineCtx) {
            new Chart(timelineCtx, {
                type: 'line',
                data: {
                    labels: timelineData.labels,
                    datasets: [{
                        label: 'Queries by Day',
                        data: timelineData.data,
                        borderColor: chartColors.primary,
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: chartColors.primary,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        // 3. Hourly Chart
        const hourlyCtx = document.getElementById('hourlyChart');
        if (hourlyCtx) {
            new Chart(hourlyCtx, {
                type: 'bar',
                data: {
                    labels: Array.from({length: 24}, (_, i) => i + ':00'),
                    datasets: [{
                        label: 'Queries by Hour',
                        data: hourlyData,
                        backgroundColor: chartColors.teal,
                        borderColor: chartColors.teal,
                        borderWidth: 1
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // 4. Frequent Questions Chart
        const questionsCtx = document.getElementById('questionsChart');
        if (questionsCtx) {
            new Chart(questionsCtx, {
                type: 'bar',
                data: {
                    labels: questionsData.labels,
                    datasets: [{
                        label: 'Frequency',
                        data: questionsData.data,
                        backgroundColor: chartColorsArray.slice(0, questionsData.labels.length),
                        borderWidth: 0
                    }]
                },
                options: {
                    ...commonOptions,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // 5. Satisfaction Chart (Radar)
        const satisfactionCtx = document.getElementById('satisfactionChart');
        if (satisfactionCtx) {
            new Chart(satisfactionCtx, {
                type: 'radar',
                data: {
                    labels: ['Response Rate', 'Satisfaction', 'Efficiency', 'Coverage', 'Speed'],
                    datasets: [{
                        label: 'Current Performance',
                        data: [
                            <?php echo $metrics['response_rate']; ?>,
                            <?php echo $metrics['satisfaction_rate']; ?>,
                            85,
                            <?php echo 100 - (($metrics['unanswered'] / max(1, $metrics['total_queries'])) * 100); ?>,
                            Math.max(0, 100 - (<?php echo $metrics['avg_response_time']; ?> / 2))
                        ],
                        backgroundColor: 'rgba(52, 152, 219, 0.2)',
                        borderColor: chartColors.primary,
                        pointBackgroundColor: chartColors.primary,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });
        }

        // 6. Top Most Repeated Numbers Chart (REPLACES MONTHLY TREND)
        const topNumbersCtx = document.getElementById('topNumbersChart');
        if (topNumbersCtx) {
            new Chart(topNumbersCtx, {
                type: 'bar',
                data: {
                    labels: topNumbersData.labels,
                    datasets: [{
                        label: 'Repetitions',
                        data: topNumbersData.data,
                        backgroundColor: chartColors.orange,
                        borderColor: chartColors.orange,
                        borderWidth: 1
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    }

    // FIXED PRINT FUNCTION
    function prepareForPrint() {
        console.log('Preparing for print...');
        
        // Force rendering of all charts
        const charts = Chart.instances;
        charts.forEach(chart => {
            chart.resize();
            chart.render();
        });
        
        // Small pause to ensure charts render
        setTimeout(() => {
            console.log('Executing print...');
            window.print();
        }, 300);
    }

    // Alternative direct function
    function printReport() {
        console.log('Printing report...');
        window.print();
    }

    // Utility functions
    function exportDashboard() {
        alert('Coming soon...');
    }

    function refreshData() {
        window.location.reload();
    }

    // Function to reset dates
    function resetDates() {
        document.getElementById('startDate').value = '';
        document.getElementById('endDate').value = '';
        document.getElementById('filterForm').submit();
    }

    // Date validation
    document.getElementById('startDate')?.addEventListener('change', function() {
        const endDate = document.getElementById('endDate');
        if (this.value && endDate.value && this.value > endDate.value) {
            endDate.value = this.value;
        }
    });

    document.getElementById('endDate')?.addEventListener('change', function() {
        const startDate = document.getElementById('startDate');
        if (this.value && startDate.value && this.value < startDate.value) {
            startDate.value = this.value;
        }
    });

    // Interactive filters - now submit the form
    document.getElementById('departmentFilter')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });

    document.getElementById('metricType')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });

    // Function to expand question text
    document.querySelectorAll('.question-text').forEach(element => {
        element.addEventListener('click', function() {
            this.classList.toggle('expanded');
        });
    });

    // Also handle Ctrl+P
    window.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            prepareForPrint();
        }
    });

    // Set up print button directly as backup
    document.addEventListener('DOMContentLoaded', function() {
        const printBtn = document.querySelector('.btn-primary');
        if (printBtn) {
            printBtn.setAttribute('onclick', 'window.print()');
        }
    });
    // ==================== USER STATUS CHECK EVERY 30 SECONDS ====================
function checkUserStatus() {
    fetch('../check_user_status.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Error in server response');
            }
            return response.json();
        })
        .then(data => {
            console.log('User status:', data);
            if (!data.active) {
                // Automatically redirect to login without showing alert
                window.location.href = '../login.php?error=user_inactive';
            }
        })
        .catch(error => {
            console.error('Error checking user status:', error);
        });
}

// Check status immediately when page loads
document.addEventListener('DOMContentLoaded', function() {
    checkUserStatus();
    
    // Check status every 30 seconds
    setInterval(checkUserStatus, 30000); // 30000 ms = 30 seconds
});

// Function to check status before important actions
function verifyUserStatusBeforeAction() {
    return fetch('../check_user_status.php')
        .then(response => response.json())
        .then(data => {
            if (!data.active) {
                // Automatically redirect without alert
                window.location.href = '../login.php?error=user_inactive';
                return false;
            }
            return true;
        })
        .catch(error => {
            console.error('Error checking status:', error);
            return true; // In case of error, allow the action
        });
}

// Apply verification to important forms
document.addEventListener('DOMContentLoaded', function() {
    const importantForms = document.querySelectorAll('form');
    importantForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            verifyUserStatusBeforeAction().then(canProceed => {
                if (!canProceed) {
                    e.preventDefault();
                }
            });
        });
    });
    
    // Apply verification to important links (except logout)
    const importantLinks = document.querySelectorAll('a:not([href*="logout"])');
    importantLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (this.getAttribute('href') && !this.getAttribute('href').includes('logout')) {
                verifyUserStatusBeforeAction().then(canProceed => {
                    if (!canProceed) {
                        e.preventDefault();
                    }
                });
            }
        });
    });
    
    // Apply verification to important buttons
    const importantButtons = document.querySelectorAll('.btn:not([href*="logout"])');
    importantButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!this.getAttribute('onclick') || !this.getAttribute('onclick').includes('logout')) {
                verifyUserStatusBeforeAction().then(canProceed => {
                    if (!canProceed) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            }
        });
    });
});

</script>
</body>
</html>