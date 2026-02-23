<?php
// server.php - Conector a Google Sheets API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Configuración de Google Sheets API
require_once 'vendor/autoload.php'; // Necesitarás instalar la librería de Google API Client

function getClient() {
    $client = new Google_Client();
    $client->setApplicationName('Chatbot Google Sheets');
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
    $client->setAuthConfig('credentials.json'); // Archivo de credenciales
    $client->setAccessType('offline');
    return $client;
}

function searchCustomerByEmail($email) {
    try {
        $client = getClient();
        $service = new Google_Service_Sheets($client);
        
        // ID de tu hoja de cálculo (de la URL)
        $spreadsheetId = 'TU_SPREADSHEET_ID_AQUI';
        
        // Rango de datos a leer (ajusta según tu hoja)
        $range = 'Hoja1!A2:H'; // Asumiendo que A2:H tiene los datos
        
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();
        
        if (empty($values)) {
            return null;
        }
        
        // Buscar por email (asumiendo que el email está en la columna A)
        foreach ($values as $row) {
            if (isset($row[0]) && strtolower(trim($row[0])) === strtolower(trim($email))) {
                // Mapear columnas según tu estructura
                return [
                    'email' => $row[0] ?? '',
                    'phone' => $row[1] ?? '',
                    'order' => $row[2] ?? '',
                    'address' => $row[3] ?? '',
                    'deliveryAddress' => $row[4] ?? '',
                    'pickupAddress' => $row[5] ?? '',
                    'status' => $row[6] ?? '',
                    'date' => $row[7] ?? '',
                    'update' => $row[8] ?? ''
                ];
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Error Google Sheets: ' . $e->getMessage());
        return null;
    }
}

// Manejar solicitud
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = $_GET['email'] ?? '';
    
    if (empty($email)) {
        echo json_encode(['error' => 'Email no proporcionado']);
        exit;
    }
    
    $customerData = searchCustomerByEmail($email);
    
    if ($customerData) {
        echo json_encode(['success' => true, 'data' => $customerData]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Cliente no encontrado']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Para otras operaciones si es necesario
    echo json_encode(['success' => false, 'error' => 'Método no soportado']);
}
?>