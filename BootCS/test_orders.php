<?php
// test_orders.php - Verificador completo del sistema por pedidos
echo '<!DOCTYPE html>
<html>
<head>
    <title>📦 Verificador de Pedidos - Chatbot</title>
    <style>
        :root { --success: #10b981; --error: #ef4444; --warning: #f59e0b; --info: #3b82f6; }
        
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Segoe UI", sans-serif; }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #1f2937;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(to right, #4f46e5, #7c3aed);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .test-section {
            padding: 30px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .test-section h2 {
            color: #4f46e5;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border: 1px solid #e5e7eb;
        }
        
        th {
            background: #f8fafc;
            color: #4f46e5;
            font-weight: 600;
        }
        
        tr:nth-child(even) {
            background: #f8fafc;
        }
        
        tr:hover {
            background: #e0e7ff;
        }
        
        .success { background: #d1fae5 !important; }
        .error { background: #fee2e2 !important; }
        .warning { background: #fef3c7 !important; }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 25px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            background: #4f46e5;
            color: white;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        @media (max-width: 768px) {
            .container { border-radius: 10px; }
            .header h1 { font-size: 24px; }
            .action-buttons { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-box"></i>
                Verificador de Pedidos - Chatbot
            </h1>
            <p>Sistema de consulta por número de pedido</p>
        </div>';
        
// 1. VERIFICAR CONEXIÓN GOOGLE SHEETS
echo '<div class="test-section">
        <h2><i class="fas fa-sync-alt"></i> 1. Conexión Google Sheets</h2>';
        
try {
    require_once __DIR__ . '/vendor/autoload.php';
    
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->setScopes(['https://www.googleapis.com/auth/spreadsheets.readonly']);
    
    $service = new Google_Service_Sheets($client);
    $spreadsheetId = '19ybA0QIGoUAb82hTQeXk4kIyUM0YqiGvMgFmaXo746g';
    
    // Probar conexión
    $range = 'Sheet1!A2:J';
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $values = $response->getValues();
    
    if (empty($values)) {
        echo '<p style="color: red">❌ No se encontraron datos</p>';
    } else {
        echo '<p style="color: green">✅ Conexión EXITOSA - ' . count($values) . ' pedidos encontrados</p>';
        
        // Mostrar tabla de pedidos
        echo '<table>
                <tr>
                    <th>#</th><th>Pedido</th><th>Cliente</th><th>Email</th><th>Teléfono</th><th>Estado</th><th>Acción</th>
                </tr>';
        
        foreach ($values as $i => $row) {
            $order = $row[3] ?? '[Sin pedido]';
            $name = $row[0] ?? '[Sin nombre]';
            $email = $row[1] ?? '[Sin email]';
            $phone = $row[2] ?? '[Sin teléfono]';
            $status = $row[7] ?? '[Sin estado]';
            
            $rowClass = '';
            if ($status === 'Delivered') $rowClass = 'success';
            elseif ($status === 'Pending') $rowClass = 'warning';
            
            echo "<tr class='$rowClass'>
                    <td>" . ($i + 1) . "</td>
                    <td><strong>$order</strong></td>
                    <td>$name</td>
                    <td>$email</td>
                    <td>$phone</td>
                    <td>$status</td>
                    <td>
                        <a href='api.php?order=" . urlencode($order) . "' target='_blank'>API</a> | 
                        <a href='?order=" . urlencode($order) . "' target='_blank'>Chatbot</a>
                    </td>
                  </tr>";
        }
        
        echo '</table>';
    }
    
} catch (Exception $e) {
    echo '<p style="color: red">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '</div>';

// 2. PROBAR API DIRECTAMENTE
echo '<div class="test-section">
        <h2><i class="fas fa-plug"></i> 2. Pruebas de API</h2>
        
        <div class="action-buttons">
            <a href="api.php?order=0-44541" target="_blank" class="btn">
                <i class="fas fa-code"></i> Probar: 0-44541
            </a>
            <a href="api.php?order=0-44545" target="_blank" class="btn">
                <i class="fas fa-code"></i> Probar: 0-44545
            </a>
            <a href="api.php?order=0-445400" target="_blank" class="btn">
                <i class="fas fa-code"></i> Probar: 0-445400
            </a>
            <a href="api.php?order=0-445444" target="_blank" class="btn">
                <i class="fas fa-code"></i> Probar: 0-445444
            </a>
            <a href="api.php?order=Pe78787" target="_blank" class="btn">
                <i class="fas fa-code"></i> Probar: Pe78787
            </a>
        </div>
      </div>';

// 3. ACCESO DIRECTO AL CHATBOT
echo '<div class="test-section">
        <h2><i class="fas fa-robot"></i> 3. Acceso al Chatbot</h2>
        
        <div class="action-buttons">
            <a href="/chatbot-pedidos/" class="btn">
                <i class="fas fa-home"></i> Ir al Inicio
            </a>
            <a href="/chatbot-pedidos/?order=0-44541" class="btn">
                <i class="fas fa-comments"></i> Chatbot: 0-44541
            </a>
            <a href="/chatbot-pedidos/?order=it@gmail.com" class="btn">
                <i class="fas fa-user"></i> Chatbot: it@gmail.com
            </a>
            <button onclick="location.reload()" class="btn">
                <i class="fas fa-redo"></i> Actualizar Pruebas
            </button>
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: #f0f9ff; border-radius: 8px;">
            <h4 style="color: #0369a1; margin-bottom: 10px;">📝 Instrucciones:</h4>
            <ol style="margin-left: 20px; color: #475569;">
                <li>Usa cualquier número de pedido de la tabla superior</li>
                <li>El chatbot mostrará información del pedido</li>
                <li>Puedes hacer preguntas sobre estado, direcciones, etc.</li>
            </ol>
        </div>
      </div>';

echo '</div></div></body></html>';
?>