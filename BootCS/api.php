<?php
/**
 * API para Chatbot de Pedidos - Por Número de Pedido
 * Versión: 2.0.0
 */

// ============================================
// CONFIGURACIÓN DE CABECERAS
// ============================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Manejar solicitudes OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// CONFIGURACIÓN DEL SISTEMA
// ============================================
define('APP_NAME', 'Chatbot Pedidos API v2');
define('APP_VERSION', '2.0.0');
define('GOOGLE_SHEET_ID', '19ybA0QIGoUAb82hTQeXk4kIyUM0YqiGvMgFmaXo746g');
define('DATA_RANGE', 'Sheet1!A2:J');

// Configuración de columnas - BASADA EN TU GOOGLE SHEET
$COLUMN_MAP = [
    'name' => 0,           // Columna A: Name
    'email' => 1,          // Columna B: Email  
    'phone' => 2,          // Columna C: Phone
    'order' => 3,          // Columna D: Order <-- BUSCAMOS POR ESTA COLUMNA
    'address' => 4,        // Columna E: Address (vacía)
    'deliveryAddress' => 5,// Columna F: Adress Delivery
    'pickupAddress' => 6,  // Columna G: Adress Pick-up
    'status' => 7,         // Columna H: Status
    'date' => 8,           // Columna I: Date
    'update' => 9          // Columna J: Update
];

// ============================================
// FUNCIONES DE UTILIDAD
// ============================================

/**
 * Envía una respuesta JSON estandarizada
 */
function sendResponse($success, $data = [], $error = '', $httpCode = 200) {
    http_response_code($httpCode);
    
    $response = [
        'success' => $success,
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => APP_VERSION
    ];
    
    if ($success) {
        $response['data'] = $data;
    } else {
        $response['error'] = $error;
        if (!empty($data)) {
            $response['details'] = $data;
        }
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Valida el número de pedido
 */
function validateOrderNumber($order) {
    if (empty($order)) {
        return [false, 'El número de pedido no puede estar vacío'];
    }
    
    $order = trim($order);
    if (strlen($order) < 2) {
        return [false, 'Número de pedido demasiado corto'];
    }
    
    return [true, $order];
}

/**
 * Normaliza los datos del cliente
 */
function normalizeCustomerData($row, $columnMap) {
    return [
        'name' => $row[$columnMap['name']] ?? '',
        'email' => $row[$columnMap['email']] ?? '',
        'phone' => $row[$columnMap['phone']] ?? '',
        'order' => $row[$columnMap['order']] ?? '',
        'address' => $row[$columnMap['address']] ?? '',
        'deliveryAddress' => $row[$columnMap['deliveryAddress']] ?? '',
        'pickupAddress' => $row[$columnMap['pickupAddress']] ?? '',
        'status' => $row[$columnMap['status']] ?? '',
        'date' => $row[$columnMap['date']] ?? '',
        'update' => $row[$columnMap['update']] ?? '',
        'queryTimestamp' => date('Y-m-d H:i:s')
    ];
}

// ============================================
// VERIFICACIÓN DE DEPENDENCIAS
// ============================================

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    sendResponse(false, [
        'missing_dependency' => 'Composer',
        'solution' => 'Ejecutar: composer require google/apiclient:^2.12'
    ], 'Dependencias no instaladas', 503);
}

require_once $autoloadPath;

$credentialsPath = __DIR__ . '/credentials.json';
if (!file_exists($credentialsPath)) {
    sendResponse(false, [
        'missing_file' => 'credentials.json',
        'solution' => 'Descarga el JSON desde Google Cloud Console'
    ], 'Credenciales de Google no encontradas', 503);
}

// ============================================
// PROCESAMIENTO DE LA SOLICITUD
// ============================================

try {
    // Obtener y validar NÚMERO DE PEDIDO
    $orderNumber = $_GET['order'] ?? '';
    
    list($isValid, $result) = validateOrderNumber($orderNumber);
    
    if (!$isValid) {
        sendResponse(false, [
            'parameter' => 'order',
            'provided' => $orderNumber,
            'example' => 'http://localhost/chatbot-pedidos/api.php?order=0-44541'
        ], $result, 400);
    }
    
    $searchOrder = $result;
    
    // ============================================
    // CONEXIÓN CON GOOGLE SHEETS
    // ============================================
    
    $client = new Google_Client();
    $client->setApplicationName(APP_NAME);
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
    $client->setAuthConfig($credentialsPath);
    $client->setAccessType('offline');
    
    $service = new Google_Service_Sheets($client);
    
    // Obtener datos
    $response = $service->spreadsheets_values->get(GOOGLE_SHEET_ID, DATA_RANGE);
    $rows = $response->getValues();
    
    if (empty($rows)) {
        sendResponse(false, [
            'sheet_id' => GOOGLE_SHEET_ID,
            'range' => DATA_RANGE,
            'rows_found' => 0
        ], 'No se encontraron datos en la hoja de cálculo', 404);
    }
    
    // ============================================
    // BÚSQUEDA POR NÚMERO DE PEDIDO
    // ============================================
    
    $customerData = null;
    $totalRows = count($rows);
    
    foreach ($rows as $index => $row) {
        if (isset($row[$COLUMN_MAP['order']])) {
            $rowOrder = trim($row[$COLUMN_MAP['order']]);
            
            // Normalizar para búsqueda (quitar espacios, minúsculas)
            $rowOrderNormalized = strtolower(str_replace(' ', '', $rowOrder));
            $searchOrderNormalized = strtolower(str_replace(' ', '', $searchOrder));
            
            if ($rowOrderNormalized === $searchOrderNormalized) {
                $customerData = normalizeCustomerData($row, $COLUMN_MAP);
                $customerData['row_index'] = $index + 2;
                break;
            }
        }
    }
    
    // ============================================
    // RESPUESTA FINAL
    // ============================================
    
    if ($customerData) {
        sendResponse(true, [
            'customer' => $customerData,
            'search_info' => [
                'order_searched' => $searchOrder,
                'total_rows_scanned' => $totalRows,
                'found_at_row' => $customerData['row_index']
            ],
            'sheet_info' => [
                'id' => GOOGLE_SHEET_ID,
                'range' => DATA_RANGE
            ]
        ]);
    } else {
        // Extraer números de pedido disponibles
        $availableOrders = [];
        foreach ($rows as $row) {
            if (isset($row[$COLUMN_MAP['order']])) {
                $order = trim($row[$COLUMN_MAP['order']]);
                if ($order) {
                    $availableOrders[] = $order;
                }
            }
        }
        
        sendResponse(false, [
            'order_searched' => $searchOrder,
            'total_rows_scanned' => $totalRows,
            'available_orders' => array_slice(array_unique($availableOrders), 0, 10)
        ], 'Pedido no encontrado en la base de datos', 404);
    }
    
} catch (Google_Service_Exception $e) {
    $errorMessage = $e->getMessage();
    
    $errorDetails = [
        'service_error' => true,
        'error_type' => 'Google_Service_Exception'
    ];
    
    if (strpos($errorMessage, 'PERMISSION_DENIED') !== false) {
        $errorMessage = 'Acceso denegado al Google Sheet';
        $errorDetails['solution'] = 'Comparte tu Google Sheet con el email del credentials.json';
        
        try {
            $creds = json_decode(file_get_contents($credentialsPath), true);
            if (isset($creds['client_email'])) {
                $errorDetails['share_with'] = $creds['client_email'];
            }
        } catch (Exception $e) {}
    }
    
    sendResponse(false, $errorDetails, $errorMessage, 403);
    
} catch (Exception $e) {
    sendResponse(false, [
        'error_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 'Error interno del servidor: ' . $e->getMessage(), 500);
}
?>