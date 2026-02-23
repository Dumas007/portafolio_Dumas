<?php
// test_connection.php - Verificador completo del sistema
echo '<!DOCTYPE html>
<html>
<head>
    <title>🧪 Verificador del Sistema - Chatbot Pedidos</title>
    <style>
        :root {
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Segoe UI", system-ui, sans-serif;
        }
        
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
        
        .test-section:last-child {
            border-bottom: none;
        }
        
        .test-section h2 {
            color: #4f46e5;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
        }
        
        .test-item {
            padding: 15px;
            margin: 10px 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
        }
        
        .test-item:hover {
            transform: translateX(5px);
        }
        
        .status-icon {
            font-size: 24px;
            width: 40px;
            text-align: center;
        }
        
        .success { background: #d1fae5; color: #065f46; border-left: 4px solid var(--success); }
        .error { background: #fee2e2; color: #7f1d1d; border-left: 4px solid var(--error); }
        .warning { background: #fef3c7; color: #92400e; border-left: 4px solid var(--warning); }
        .info { background: #dbeafe; color: #1e40af; border-left: 4px solid var(--info); }
        
        .details {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-family: monospace;
            font-size: 14px;
            overflow-x: auto;
        }
        
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
        }
        
        .btn-primary {
            background: #4f46e5;
            color: white;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .summary {
            background: #f8fafc;
            padding: 20px;
            border-radius: 15px;
            margin: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .container {
                border-radius: 10px;
            }
            
            .header h1 {
                font-size: 24px;
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-vial"></i>
                Verificador del Sistema - Chatbot Pedidos
            </h1>
            <p>Comprobación completa de todos los componentes</p>
        </div>';
        
// 1. VERIFICAR PHP
echo '<div class="test-section">
        <h2><i class="fas fa-code"></i> 1. Configuración PHP</h2>';
        
echo '<div class="test-item success">
        <div class="status-icon">✅</div>
        <div>
            <strong>Versión PHP:</strong> ' . phpversion() . '
        </div>
      </div>';
      
echo '<div class="test-item success">
        <div class="status-icon">✅</div>
        <div>
            <strong>SAPI:</strong> ' . php_sapi_name() . '
        </div>
      </div>';
      
// Verificar extensiones
$extensions = ['curl', 'json', 'openssl', 'mbstring', 'zip'];
foreach ($extensions as $ext) {
    $status = extension_loaded($ext) ? 'success' : 'error';
    $icon = extension_loaded($ext) ? '✅' : '❌';
    echo '<div class="test-item ' . $status . '">
            <div class="status-icon">' . $icon . '</div>
            <div>
                <strong>Extensión ' . $ext . ':</strong> ' . 
                (extension_loaded($ext) ? 'Cargada' : 'No cargada') . '
            </div>
          </div>';
}

echo '</div>';

// 2. VERIFICAR COMPOSER Y DEPENDENCIAS
echo '<div class="test-section">
        <h2><i class="fas fa-box"></i> 2. Dependencias Composer</h2>';

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    echo '<div class="test-item success">
            <div class="status-icon">✅</div>
            <div>
                <strong>Composer Autoload:</strong> Encontrado
            </div>
          </div>';
    
    // Verificar Google API
    require_once $autoloadPath;
    
    if (class_exists('Google_Client')) {
        echo '<div class="test-item success">
                <div class="status-icon">✅</div>
                <div>
                    <strong>Google API Client:</strong> Cargado correctamente
                </div>
              </div>';
    } else {
        echo '<div class="test-item error">
                <div class="status-icon">❌</div>
                <div>
                    <strong>Google API Client:</strong> No disponible
                </div>
                <div class="details">
                    Ejecuta: composer require google/apiclient:^2.12
                </div>
              </div>';
    }
} else {
    echo '<div class="test-item error">
            <div class="status-icon">❌</div>
            <div>
                <strong>Composer Autoload:</strong> No encontrado
            </div>
            <div class="details">
                Ejecuta en CMD: composer require google/apiclient:^2.12
            </div>
          </div>';
}

echo '</div>';

// 3. VERIFICAR CREDENCIALES
echo '<div class="test-section">
        <h2><i class="fas fa-key"></i> 3. Credenciales Google</h2>';

$credsPath = __DIR__ . '/credentials.json';
if (file_exists($credsPath)) {
    echo '<div class="test-item success">
            <div class="status-icon">✅</div>
            <div>
                <strong>Archivo credentials.json:</strong> Encontrado
            </div>
          </div>';
    
    try {
        $creds = json_decode(file_get_contents($credsPath), true);
        if ($creds && isset($creds['client_email'])) {
            echo '<div class="test-item info">
                    <div class="status-icon">ℹ️</div>
                    <div>
                        <strong>Email de servicio:</strong> ' . htmlspecialchars($creds['client_email']) . '
                    </div>
                  </div>';
            
            echo '<div class="test-item warning">
                    <div class="status-icon">⚠️</div>
                    <div>
                        <strong>IMPORTANTE:</strong> Comparte tu Google Sheet con este email
                    </div>
                  </div>';
        }
    } catch (Exception $e) {
        echo '<div class="test-item error">
                <div class="status-icon">❌</div>
                <div>
                    <strong>Error leyendo credentials.json:</strong> ' . htmlspecialchars($e->getMessage()) . '
                </div>
              </div>';
    }
} else {
    echo '<div class="test-item error">
            <div class="status-icon">❌</div>
            <div>
                <strong>Archivo credentials.json:</strong> No encontrado
            </div>
            <div class="details">
                1. Ve a <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a><br>
                2. Crea proyecto y habilita Google Sheets API<br>
                3. Crea cuenta de servicio<br>
                4. Descarga JSON como credentials.json
            </div>
          </div>';
}

echo '</div>';

// 4. PRUEBA DE CONEXIÓN CON GOOGLE SHEETS
echo '<div class="test-section">
        <h2><i class="fas fa-sync-alt"></i> 4. Prueba de Conexión</h2>';

if (file_exists($autoloadPath) && file_exists($credsPath)) {
    try {
        require_once $autoloadPath;
        
        $client = new Google_Client();
        $client->setAuthConfig($credsPath);
        $client->setScopes(['https://www.googleapis.com/auth/spreadsheets.readonly']);
        
        $service = new Google_Service_Sheets($client);
        $spreadsheetId = '19ybA0QIGoUAb82hTQeXk4kIyUM0YqiGvMgFmaXo746g';
        
        $spreadsheet = $service->spreadsheets->get($spreadsheetId);
        
        echo '<div class="test-item success">
                <div class="status-icon">✅</div>
                <div>
                    <strong>Conexión Google Sheets:</strong> EXITOSA
                </div>
                <div class="details">
                    <strong>Título:</strong> ' . htmlspecialchars($spreadsheet->getProperties()->getTitle()) . '<br>
                    <strong>ID:</strong> ' . $spreadsheetId . '
                </div>
              </div>';
        
        // Mostrar hojas disponibles
        $sheets = $spreadsheet->getSheets();
        echo '<div class="test-item info">
                <div class="status-icon">📑</div>
                <div>
                    <strong>Hojas disponibles:</strong> ' . count($sheets) . '
                </div>
              </div>';
        
        foreach ($sheets as $sheet) {
            echo '<div class="test-item info">
                    <div class="status-icon">📄</div>
                    <div>
                        <strong>Hoja:</strong> ' . htmlspecialchars($sheet->getProperties()->getTitle()) . '
                    </div>
                  </div>';
        }
        
        // Probar leer datos
        $range = 'Sheet1!A2:I';
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();
        
        echo '<div class="test-item ' . (!empty($values) ? 'success' : 'warning') . '">
                <div class="status-icon">' . (!empty($values) ? '✅' : '⚠️') . '</div>
                <div>
                    <strong>Datos en Sheet1:</strong> ' . count($values) . ' filas encontradas
                </div>
              </div>';
        
    } catch (Google_Service_Exception $e) {
        echo '<div class="test-item error">
                <div class="status-icon">❌</div>
                <div>
                    <strong>Error Google Sheets:</strong> ' . htmlspecialchars($e->getMessage()) . '
                </div>
                <div class="details">';
        
        if (strpos($e->getMessage(), 'PERMISSION_DENIED') !== false) {
            echo '<strong>SOLUCIÓN:</strong> Comparte tu Google Sheet con el email del credentials.json<br>';
            if (isset($creds['client_email'])) {
                echo '<strong>Email a compartir:</strong> ' . htmlspecialchars($creds['client_email']);
            }
        }
        
        echo '</div></div>';
    } catch (Exception $e) {
        echo '<div class="test-item error">
                <div class="status-icon">❌</div>
                <div>
                    <strong>Error general:</strong> ' . htmlspecialchars($e->getMessage()) . '
                </div>
              </div>';
    }
}

echo '</div>';

// 5. PRUEBA DE API
echo '<div class="test-section">
        <h2><i class="fas fa-plug"></i> 5. Prueba de API</h2>';

echo '<div class="test-item info">
        <div class="status-icon">🔗</div>
        <div>
            <strong>Endpoint API:</strong> api.php
        </div>
        <div class="details">
            <a href="api.php?email=egeovanny@gmail.com" target="_blank">Probar api.php?email=egeovanny@gmail.com</a>
        </div>
      </div>';

echo '<div class="test-item info">
        <div class="status-icon">🤖</div>
        <div>
            <strong>Chatbot Principal:</strong>
        </div>
        <div class="details">
            <a href="/chatbot-pedidos/?email=egeovanny@gmail.com" target="_blank">Abrir Chatbot</a>
        </div>
      </div>';

echo '</div>';

// 6. RESUMEN Y ACCIONES
echo '<div class="test-section">
        <h2><i class="fas fa-clipboard-check"></i> 6. Resumen y Acciones</h2>
        
        <div class="summary">
            <div>
                <h3 style="color: #4f46e5; margin-bottom: 10px;">Estado del Sistema</h3>';
                
$checks = [
    'PHP' => phpversion() >= '7.4',
    'Extensiones' => extension_loaded('curl') && extension_loaded('json'),
    'Composer' => file_exists($autoloadPath),
    'Google API' => class_exists('Google_Client'),
    'Credenciales' => file_exists($credsPath),
    'Conexión Google' => isset($spreadsheet) ? true : false
];

$totalChecks = count($checks);
$passedChecks = count(array_filter($checks));

echo '<p><strong>' . $passedChecks . ' / ' . $totalChecks . '</strong> verificaciones aprobadas</p>';

echo '</div>
      <div style="text-align: center;">
        <div style="font-size: 48px; color: ' . ($passedChecks == $totalChecks ? 'var(--success)' : 'var(--warning)') . ';">
            ' . ($passedChecks == $totalChecks ? '✅' : '⚠️') . '
        </div>
      </div>
    </div>
    
    <div class="action-buttons">
        <a href="/chatbot-pedidos/" class="btn btn-primary">
            <i class="fas fa-home"></i> Ir al Inicio
        </a>
        
        <a href="/chatbot-pedidos/?email=egeovanny@gmail.com" class="btn btn-success">
            <i class="fas fa-robot"></i> Probar Chatbot
        </a>
        
        <a href="api.php?email=egeovanny@gmail.com" class="btn btn-primary">
            <i class="fas fa-code"></i> Ver API
        </a>
        
        <button onclick="location.reload()" class="btn btn-primary">
            <i class="fas fa-redo"></i> Re-ejecutar Pruebas
        </button>
    </div>
    
    <div style="margin-top: 30px; padding: 20px; background: #f8fafc; border-radius: 10px;">
        <h4 style="color: #4f46e5; margin-bottom: 10px;">📋 Si hay problemas:</h4>
        <ol style="margin-left: 20px; color: #6b7280;">
            <li>Verifica que Apache esté ejecutándose en XAMPP</li>
            <li>Ejecuta en CMD: <code>composer require google/apiclient:^2.12</code></li>
            <li>Asegúrate de que credentials.json esté en la carpeta</li>
            <li>Comparte tu Google Sheet con el email del credentials.json</li>
            <li>Reinicia Apache si hiciste cambios en php.ini</li>
        </ol>
    </div>
    
    </div>';

echo '</div></div></body></html>';
?>