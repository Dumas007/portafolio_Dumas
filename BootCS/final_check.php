<?php
// test_exact.php - Prueba EXACTA de lo que hace api.php
require_once __DIR__ . '/vendor/autoload.php';

echo "<h1>🧪 Prueba EXACTA de conexión</h1>";

try {
    // 1. EXACTAMENTE lo mismo que api.php
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->setScopes(['https://www.googleapis.com/auth/spreadsheets.readonly']);
    
    // 2. EXACTAMENTE el mismo servicio
    $service = new Google_Service_Sheets($client);
    $spreadsheetId = '19ybA0QIGoUAb82hTQeXk4kIyUM0YqiGvMgFmaXo746g';
    
    // 3. EXACTAMENTE el mismo rango que api.php
    $range = 'Sheet1!A2:I';
    echo "<p>📋 <strong>Rango usado:</strong> $range</p>";
    
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $values = $response->getValues();
    
    if (empty($values)) {
        echo "<p style='color: red'>❌ ERROR: No hay datos en el rango $range</p>";
        echo "<p>Sugerencia: ¿Usas 'Sheet1' exactamente así? ¿Con mayúscula S?</p>";
    } else {
        echo "<p style='color: green'>✅ CONEXIÓN EXITOSA! Encontrados " . count($values) . " registros</p>";
        
        // Mostrar todos los emails encontrados
        echo "<h3>📧 Emails encontrados en columna A:</h3>";
        echo "<ul>";
        foreach ($values as $i => $row) {
            $email = $row[0] ?? '[vacío]';
            echo "<li>Fila " . ($i + 2) . ": <strong>" . htmlspecialchars($email) . "</strong></li>";
        }
        echo "</ul>";
        
        // Buscar específicamente
        echo "<h3>🔍 Buscando 'egeovanny@gmail.com':</h3>";
        $found = false;
        foreach ($values as $i => $row) {
            if (isset($row[0]) && strtolower(trim($row[0])) === 'egeovanny@gmail.com') {
                echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px;'>";
                echo "<p style='color: green'>✅ ¡ENCONTRADO en fila " . ($i + 2) . "!</p>";
                echo "<pre>" . print_r($row, true) . "</pre>";
                echo "</div>";
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            echo "<p style='color: orange'>⚠️ NO encontrado. Emails disponibles arriba.</p>";
            echo "<p><strong>Posible problema:</strong> ¿El email tiene espacios? ¿Está exactamente igual?</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<h3 style='color: red'>❌ ERROR:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    
    // Verificar si es error de permisos
    if (strpos($e->getMessage(), 'PERMISSION_DENIED') !== false) {
        echo "<div style='background: #f8d7da; padding: 20px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>🔐 ERROR DE PERMISOS:</h4>";
        echo "<p><strong>SOLUCIÓN:</strong></p>";
        echo "<ol>";
        echo "<li>Abre tu Google Sheet</li>";
        echo "<li>Haz clic en <strong>'Compartir'</strong> (arriba derecha)</li>";
        echo "<li>Añade: <code>id-l-yro-sheets@verdant-cascade-454417-j7.iam.gserviceaccount.com</code></li>";
        echo "<li>Dale permiso <strong>'Editor'</strong></li>";
        echo "<li>Espera 2 minutos y prueba de nuevo</li>";
        echo "</ol>";
        echo "</div>";
    }
}
?>