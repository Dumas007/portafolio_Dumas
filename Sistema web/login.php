<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once 'config.php';
include_once 'Technician.php';

// ---- OpenAI helper MEJORADO ----
$OPENAI_API_KEY = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null);
$OPENAI_API_KEY = "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";

function openaiEnhancedAntiSpoof($imageBase64, $apiKey) {
    if (empty($apiKey)) return ['success' => false, 'error' => 'OpenAI API key missing'];
    $cleanBase64 = preg_replace('/^data:image\/\w+;base64,/', '', $imageBase64);

    $payload = [
        "model" => "gpt-4o-mini",
        "messages" => [
            [
                "role" => "user",
                "content" => [
                    [
                        "type" => "text", 
                        "text" => "Eres un sistema de verificación facial. Analiza esta imagen y determina si es un rostro REAL en vivo o una suplantación.

IMPORTANTE: Sé PRAGMÁTICO y EVITA FALSOS POSITIVOS. Considera que:
- Las cámaras de teléfonos pueden tener calidad variable
- La iluminación no siempre es perfecta
- Las personas pueden tener expresiones neutras

SEVERIDAD ALTA (rechazar como suplantación):
- Bordes claros de pantalla/monitor visible
- Reflejos de pantalla en los ojos
- Patrones de impresión (puntos, líneas)
- Máscaras evidentes o rostros artificiales

SEVERIDAD MEDIA (evaluar con cuidado):
- Calidad de imagen moderada
- Iluminación no ideal  
- Sombras naturales
- Textura de piel visible pero no perfecta

SEVERIDAD BAJA (permitir como real):
- Pequeñas variaciones en calidad
- Iluminación normal de interior
- Rostro natural con detalles humanos
- Expresiones faciales normales

Responde EXCLUSIVAMENTE con este JSON:
{\"real\": true/false, \"confidence\": 0.0-1.0, \"spoof_type\": \"none/live/screen/photo/print/mask/video\", \"reason\": \"explicación breve\"}

EN CASO DE DUDA, prefiere 'real': true para evitar bloquear usuarios legítimos."
                    ],
                    [
                        "type" => "image_url",
                        "image_url" => [
                            "url" => "data:image/jpeg;base64," . $cleanBase64,
                            "detail" => "high"
                        ]
                    ]
                ]
            ]
        ],
        "max_tokens" => 500,
        "temperature" => 0.1
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("OpenAI HTTP Code: $httpCode");
    
    if ($resp === false) {
        return ['success' => false, 'error' => 'cURL error: ' . $curlErr];
    }
    
    $decoded = json_decode($resp, true);
    if ($decoded === null) {
        error_log("OpenAI JSON decode failed");
        return ['success' => false, 'error' => 'Invalid JSON from OpenAI', 'http_code' => $httpCode];
    }
    
    if(isset($decoded['choices'][0]['message']['content'])) {
        error_log("OpenAI Enhanced Response: " . $decoded['choices'][0]['message']['content']);
    }
    
    return ['success' => true, 'response' => $decoded];
}

// Función helper para tipos de suplantación
function getSpoofTypeReadable($spoofType) {
    $types = [
        'screen' => 'Pantalla/Display',
        'photo' => 'Foto estática', 
        'print' => 'Impresión',
        'mask' => 'Máscara',
        'video' => 'Video grabado',
        'live' => 'Rostro real',
        'none' => 'Sin suplantación',
        'unknown' => 'Tipo desconocido'
    ];
    return $types[$spoofType] ?? 'Tipo desconocido';
}

// ----------------- Conexión DB y objetos -----------------
$database = new Database();
$db = $database->getConnection();
$technician = new Technician($db);

$message = '';
$message_type = '';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting
$rate_limit_key = 'login_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$facial_limit_key = 'facial_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$max_attempts = 8;
$max_facial_attempts = 5; // Aumentado para permitir más intentos

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['attempts' => 0, 'first_attempt' => time()];
}
if (!isset($_SESSION[$facial_limit_key])) {
    $_SESSION[$facial_limit_key] = ['attempts' => 0, 'first_attempt' => time()];
}

$rate_data = $_SESSION[$rate_limit_key];
$facial_data = $_SESSION[$facial_limit_key];

// Reset rate limiting después de 15 minutos
if (time() - $rate_data['first_attempt'] > 900) {
    $_SESSION[$rate_limit_key] = ['attempts' => 0, 'first_attempt' => time()];
    $rate_data = $_SESSION[$rate_limit_key];
}
if (time() - $facial_data['first_attempt'] > 900) {
    $_SESSION[$facial_limit_key] = ['attempts' => 0, 'first_attempt' => time()];
    $facial_data = $_SESSION[$facial_limit_key];
}

if($rate_data['attempts'] >= $max_attempts){
    $message = "Demasiados intentos fallidos. Por favor espera 15 minutos.";
    $message_type = 'error';
}

// ----------------- Procesar login -----------------
if ($_POST && isset($_POST['login_type']) && $_SESSION[$rate_limit_key]['attempts'] < $max_attempts) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Error de seguridad. Por favor recarga la página.";
        $message_type = 'error';
    } else {
        try {
            $error_occurred = false;
            $email = '';

            // ===== LOGIN POR PASSWORD =====
            if($_POST['login_type'] == 'password'){
                $email = trim($_POST['email']);
                $password = trim($_POST['password']);

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { 
                    $error_occurred = true; 
                    $message = "Email inválido.";
                } elseif(empty($password)) { 
                    $error_occurred = true; 
                    $message = "Contraseña requerida.";
                } elseif(!$technician->loginWithPassword($email, $password)) { 
                    $error_occurred = true; 
                    $message = "Email o contraseña incorrectos.";
                }
                
                if(!$error_occurred){
                    $_SESSION[$rate_limit_key]['attempts'] = 0;
                    $_SESSION['technician_id'] = $technician->id;
                    $_SESSION['technician_name'] = $technician->name;
                    $_SESSION['technician_email'] = $technician->email;
                    $_SESSION['technician_role'] = $technician->role;
                    $_SESSION['technician_phone'] = $technician->phone;
                    $_SESSION['technician_active'] = $technician->active;
                    $_SESSION['has_facial_data'] = !empty($technician->facial_data);

                    if($technician->role == 'Admin') {
                        header("Location: admin/dashboard.php");
                    } elseif(strpos($technician->role, 'dep_') === 0) {
                        header("Location: managers/dashboard.php");
                    } else {
                        header("Location: dashboard.php");
                    }
                    exit();
                } else {
                    $_SESSION[$rate_limit_key]['attempts']++;
                    $message_type = 'error';
                }
            }

            // ===== LOGIN FACIAL - VERSIÓN MEJORADA Y MÁS PERMISIVA =====
            elseif($_POST['login_type'] == 'facial'){
                // Verificar límite específico para facial
                if($facial_data['attempts'] >= $max_facial_attempts){
                    $error_occurred = true;
                    $message = "Demasiados intentos de reconocimiento facial fallidos. Usa contraseña o espera 15 minutos.";
                } elseif(empty($_POST['facial_data']) || empty($_POST['email_facial'])){
                    $error_occurred = true; 
                    $message = "Debes ingresar email y capturar tu rostro.";
                } else {
                    $facial_data_post = json_decode($_POST['facial_data'], true);
                    $email = trim($_POST['email_facial']);
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { 
                        $error_occurred = true; 
                        $message = "Email inválido."; 
                    }

                    if(!$error_occurred){
                        // Verificar email en DB
                        $checkStmt = $db->prepare("SELECT * FROM technicians WHERE email = :email AND active = 'yes'");
                        $checkStmt->bindParam(":email", $email);
                        $checkStmt->execute();
                        
                        if($checkStmt->rowCount() == 0){ 
                            $error_occurred = true; 
                            $message = "Email no registrado o cuenta inactiva."; 
                        } else {
                            $userData = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            
                            // DETECCIÓN ANTI-SPOOFING MEJORADA - MÁS PERMISIVA
                            $openaiCheck = true; // Por defecto permitir
                            $analysis = null;
                            $spoofType = 'unknown';
                            
                            if(!empty($facial_data_post['frame']) && $OPENAI_API_KEY){
                                $openaiResp = openaiEnhancedAntiSpoof($facial_data_post['frame'], $OPENAI_API_KEY);
                                
                                if($openaiResp['success']){ 
                                    $decoded = $openaiResp['response'];
                                    $rawText = null;
                                    
                                    if(isset($decoded['choices'][0]['message']['content'])) {
                                        $rawText = $decoded['choices'][0]['message']['content'];
                                    }
                                    
                                    if($rawText !== null){ 
                                        error_log("OpenAI Enhanced Raw Text: " . $rawText);
                                        
                                        $analysis = null;
                                        if(preg_match('/\{(?:[^{}]|(?R))*\}/', $rawText, $matches)) {
                                            $analysis = json_decode($matches[0], true);
                                        }
                                        
                                        if($analysis === null) {
                                            $analysis = json_decode(trim($rawText), true);
                                        }
                                        
                                        // LÓGICA MÁS PERMISIVA - SOLO RECHAZAR SI ES MUY EVIDENTE
                                        if($analysis && isset($analysis['real'])) {
                                            $confidence = isset($analysis['confidence']) ? $analysis['confidence'] : 0;
                                            $spoofType = isset($analysis['spoof_type']) ? $analysis['spoof_type'] : 'unknown';
                                            
                                            // Solo rechazar si hay ALTA confianza de suplantación (>80%) Y es tipo claro de spoof
                                            $clearSpoofTypes = ['screen', 'print', 'mask'];
                                            
                                            if($analysis['real'] === false && $confidence > 0.8 && in_array($spoofType, $clearSpoofTypes)) {
                                                $openaiCheck = false;
                                                error_log("Clear spoof detected: " . $spoofType . " with confidence: " . $confidence);
                                            } else {
                                                // En cualquier otro caso, permitir (incluyendo dudas, baja confianza, o tipos no claros)
                                                $openaiCheck = true;
                                                error_log("Allowing - Real: " . ($analysis['real'] ? 'true' : 'false') . ", Confidence: " . $confidence . ", Type: " . $spoofType);
                                            }
                                        } else {
                                            // Si no puede analizar, permitir por defecto
                                            $openaiCheck = true;
                                            error_log("Analysis failed, allowing by default");
                                        }
                                    } else {
                                        // Si no hay respuesta, permitir
                                        $openaiCheck = true;
                                        error_log("No OpenAI response, allowing");
                                    }
                                } else {
                                    // Si falla la API, permitir
                                    error_log("OpenAI API failed but allowing: " . $openaiResp['error']);
                                    $openaiCheck = true;
                                }
                            } else {
                                // Si no hay frame o API key, permitir
                                error_log("No frame or API key, allowing");
                                $openaiCheck = true;
                            }
                            
                            // VERIFICAR ROSTRO (si pasó anti-spoofing o hay duda)
                            if($openaiCheck && isset($facial_data_post['descriptor'])){
                                $descriptorJson = json_encode($facial_data_post['descriptor']);
                                
                                if($technician->loginWithFace($email, $descriptorJson)){
                                    // LOGIN EXITOSO
                                    $_SESSION[$rate_limit_key]['attempts'] = 0;
                                    $_SESSION[$facial_limit_key]['attempts'] = 0;
                                    session_regenerate_id(true);
                                    $_SESSION['technician_id'] = $technician->id;
                                    $_SESSION['technician_name'] = $technician->name;
                                    $_SESSION['technician_email'] = $technician->email;
                                    $_SESSION['technician_role'] = $technician->role;
                                    $_SESSION['technician_phone'] = $technician->phone;
                                    $_SESSION['technician_active'] = $technician->active;
                                    $_SESSION['has_facial_data'] = !empty($technician->facial_data);

                                    if($technician->role == 'Admin') {
                                        header("Location: admin/dashboard.php");
                                    } elseif(strpos($technician->role, 'dep_') === 0) {
                                        header("Location: managers/dashboard.php");
                                    } else {
                                        header("Location: dashboard.php");
                                    }
                                    exit();
                                } else {
                                    $error_occurred = true;
                                    $message = "El rostro no coincide con este email.";
                                }
                            } elseif(!$openaiCheck) {
                                $error_occurred = true;
                                $confidence = isset($analysis['confidence']) ? round($analysis['confidence'] * 100) : 'N/A';
                                $reason = $analysis['reason'] ?? 'Posible suplantación detectada';
                                $spoofTypeReadable = getSpoofTypeReadable($spoofType);
                                
                                $message = "⚠️ Verificación de seguridad: $reason";
                                if($spoofType !== 'unknown' && $spoofType !== 'live' && $spoofType !== 'none') {
                                    $message .= " (Tipo: $spoofTypeReadable)";
                                }
                                $message .= "<br><small>💡 <strong>Solución:</strong> Mejora la iluminación, acerca tu rostro a la cámara y asegúrate de no usar fotos o pantallas.</small>";
                                
                                $_SESSION[$facial_limit_key]['attempts']++;
                            } else {
                                $error_occurred = true;
                                $message = "Error en datos faciales. Intenta nuevamente.";
                            }
                        }
                    }
                }
                
                if($error_occurred){
                    $_SESSION[$rate_limit_key]['attempts']++;
                    $message_type = 'error';
                }
            }

        } catch(Exception $e){
            $message = "Error del sistema: " . $e->getMessage();
            $message_type = 'error';
            error_log("Login error: " . $e->getMessage());
            $_SESSION[$rate_limit_key]['attempts']++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Technician System</title>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Keep all CSS styles the same as before */
        :root {
            --red-primary: #dc2626;
            --red-dark: #b91c1c;
            --red-light: #fecaca;
            --red-bg: #fef2f2;
            --black: #000000;
            --white: #ffffff;
            --gray-light: #f3f4f6;
            --gray: #9ca3af;
            --gray-dark: #4b5563;
            --green: #10b981;
            --green-light: #d1fae5;
            --blue: #2563eb;
            --blue-light: #dbeafe;
            --orange: #f59e0b;
            --orange-light: #fef3c7;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--black) 0%, var(--red-dark) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            width: 100%;
            max-width: 480px;
            animation: slideUp 0.5s ease-out;
            border: 2px solid var(--red-primary);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-header {
            background: linear-gradient(135deg, var(--red-primary), var(--black));
            color: var(--white);
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }

        .login-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 0.95rem;
            position: relative;
        }

        .login-body {
            padding: 30px;
            background: var(--white);
        }

        .login-tabs {
            display: flex;
            background: var(--gray-light);
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 25px;
            border: 1px solid var(--gray);
        }

        .login-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: var(--black);
            background: transparent;
        }

        .login-tab.active {
            background: var(--red-primary);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
        }

        .login-tab:hover:not(.active) {
            background: var(--red-light);
            color: var(--red-dark);
        }

        .login-method {
            display: none;
        }

        .login-method.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--black);
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--gray);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--red-primary);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .password-container {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-dark);
            cursor: pointer;
            padding: 5px;
            z-index: 2;
        }

        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 10px 0;
        }

        .btn-primary {
            background: var(--red-primary);
            color: var(--white);
            border: 2px solid var(--red-primary);
        }

        .btn-primary:hover {
            background: var(--red-dark);
            border-color: var(--red-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.4);
        }

        .btn-secondary {
            background: var(--black);
            color: var(--white);
            border: 2px solid var(--black);
        }

        .btn:disabled {
            background: var(--gray);
            border-color: var(--gray);
            color: var(--gray-dark);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .video-container {
            position: relative;
            width: 100%;
            margin: 20px 0;
            border-radius: 12px;
            overflow: hidden;
            background: var(--black);
            border: 2px solid var(--red-primary);
        }

        video {
            width: 100%;
            height: 250px;
            object-fit: cover;
            display: block;
        }

        .loading {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid var(--red-primary);
            color: var(--red-dark);
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .message {
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            font-size: 0.9rem;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .success {
            background: rgba(16, 185, 129, 0.15);
            border-color: var(--green);
            color: var(--green);
        }

        .error {
            background: rgba(220, 38, 38, 0.15);
            border-color: var(--red-primary);
            color: var(--red-primary);
            font-weight: 500;
        }

        .rate-limit-warning {
            background: var(--red-light);
            color: var(--red-primary);
            padding: 12px;
            border-radius: 8px;
            margin: 10px 0;
            border: 1px solid var(--red-primary);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .attempts-counter {
            font-size: 0.8rem;
            color: var(--gray-dark);
            text-align: center;
            margin: 10px 0;
        }

        .security-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--red-primary);
            color: var(--white);
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--red-primary);
            font-size: 1.2rem;
            border: 2px solid var(--red-primary);
        }

        .login-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid var(--gray-light);
        }

        .feature-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--red-bg);
            color: var(--red-dark);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 10px;
            border: 1px solid var(--red-light);
            font-weight: 500;
        }

        .liveness-instruction {
            background: var(--orange-light);
            border: 1px solid var(--orange);
            color: var(--orange);
            padding: 12px;
            border-radius: 8px;
            margin: 10px 0;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .face-guide {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 180px;
            height: 220px;
            border: 2px dashed var(--red-primary);
            border-radius: 45%;
            pointer-events: none;
        }

        .email-check-status {
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .email-check-success {
            background: var(--green-light);
            color: var(--green);
            border: 1px solid var(--green);
        }

        .email-check-error {
            background: var(--red-light);
            color: var(--red-primary);
            border: 1px solid var(--red-primary);
        }

        .facial-attempts-warning {
            background: var(--orange-light);
            border: 1px solid var(--orange);
            color: var(--orange);
            padding: 12px;
            border-radius: 8px;
            margin: 10px 0;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .quality-tips {
            background: var(--blue-light);
            border: 1px solid var(--blue);
            color: var(--blue);
            padding: 12px;
            border-radius: 8px;
            margin: 10px 0;
            font-size: 0.9rem;
        }

        .quality-tips ul {
            margin: 8px 0 0 20px;
        }

        .quality-tips li {
            margin-bottom: 4px;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                border-radius: 15px;
            }
            
            .login-header {
                padding: 25px 20px;
            }
            
            .login-body {
                padding: 25px 20px;
            }
            
            .login-header h1 {
                font-size: 1.5rem;
            }
            
            video {
                height: 200px;
            }
            
            .login-tabs {
                flex-direction: column;
                gap: 5px;
            }
        }

        /* Loading animation */
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i> AI SECURE
            </div>
            <div class="brand">
                <div class="brand-icon">
                    <i class="fas fa-tools"></i>
                </div>
            </div>
            <h1>Login STT-GPT</h1>
            <p>Chat Bot with AI - Smart Face Verification</p>
        </div>

        <div class="login-body">
            <?php if($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type == 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if($rate_data['attempts'] >= $max_attempts): ?>
                <div class="rate-limit-warning">
                    <i class="fas fa-clock"></i>
                    Too many failed attempts. Please wait 15 minutes.
                </div>
            <?php elseif($rate_data['attempts'] > 0): ?>
                <div class="attempts-counter">
                    Total failed attempts: <?php echo $rate_data['attempts']; ?> of <?php echo $max_attempts; ?>
                </div>
            <?php endif; ?>

            <?php if($facial_data['attempts'] >= $max_facial_attempts): ?>
                <div class="facial-attempts-warning">
                    <i class="fas fa-camera-slash"></i>
                    Facial recognition limit exceeded. Use password or wait 15 minutes.
                </div>
            <?php elseif($facial_data['attempts'] > 0): ?>
                <div class="attempts-counter">
                    Failed facial attempts: <?php echo $facial_data['attempts']; ?> of <?php echo $max_facial_attempts; ?>
                </div>
            <?php endif; ?>

            <div class="login-tabs">
                <div class="login-tab active" data-tab="password">
                    <i class="fas fa-key"></i> Password Login
                </div>
                <div class="login-tab" data-tab="facial">
                    <i class="fas fa-camera"></i> Face ID
                </div>
            </div>

            <!-- Method 1: Password login -->
            <div class="login-method active" id="password-method">
                <form method="post">
                    <input type="hidden" name="login_type" value="password">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> EMAIL
                        </label>
                        <input type="email" name="email" class="form-input" placeholder="email@sttlg.com" required 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-lock"></i> PASSWORD
                        </label>
                        <div class="password-container">
                            <input type="password" name="password" id="password" class="form-input" placeholder="Enter your password" required>
                            <button type="button" class="toggle-password" onclick="togglePassword()">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary pulse" <?php echo $rate_data['attempts'] >= $max_attempts ? 'disabled' : ''; ?>>
                        <i class="fas fa-sign-in-alt"></i> LOG IN
                    </button>
                </form>
            </div>

            <!-- Method 2: Facial login -->
            <div class="login-method" id="facial-method">
                <form id="facialLoginForm" method="post">
                    <input type="hidden" name="login_type" value="facial">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="facial_data" id="facialData">
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> EMAIL
                        </label>
                        <input type="email" name="email_facial" id="emailFacial" class="form-input" placeholder="email@sttlg.com" required>
                        <div id="emailCheckStatus" class="email-check-status" style="display: none;"></div>
                    </div>

                    <div id="loadingStatus" class="loading">
                        <div class="spinner"></div>
                       LOADING FACIAL VERIFICATION...
                    </div>

                    <div class="video-container">
                        <video id="video" autoplay muted></video>
                        <div class="face-guide"></div>
                    </div>

                    <div class="quality-tips">
                        <strong>💡 For better recognition:</strong>
                        <ul>
                            <li>Good frontal lighting</li>
                            <li>Face centered in the oval</li>
                            <li>Remove sunglasses</li>
                            <li>Look directly at the camera</li>
                        </ul>
                    </div>
                    
                    <button type="button" id="recognizeBtn" class="btn btn-secondary" disabled>
                        <i class="fas fa-camera"></i> CAPTURE FACE
                    </button>
                    
                    <button type="submit" id="facialLoginBtn" class="btn btn-primary" disabled 
                        <?php echo ($rate_data['attempts'] >= $max_attempts || $facial_data['attempts'] >= $max_facial_attempts) ? 'disabled' : ''; ?>>
                        <i class="fas fa-check-circle"></i> FACE LOGIN
                    </button>

                    <div class="feature-badge">
                        <i class="fas fa-robot"></i> SMART VERIFICATION ACTIVATED
                    </div>
                </form>
            </div>

            <div class="login-footer">
                <p>
                    <a style="color: var(--gray-dark); text-decoration: none;">
                         powered by Erick Dumas
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script>
        let facialDescriptor = null;
        let modelsLoaded = false;
        let isProcessing = false;

        // Tab system
        document.querySelectorAll('.login-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.login-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.login-method').forEach(m => m.classList.remove('active'));
                
                tab.classList.add('active');
                const tabId = tab.getAttribute('data-tab');
                document.getElementById(`${tabId}-method`).classList.add('active');
                
                if(tabId === 'facial') {
                    resetFacialRecognition();
                }
            });
        });

        // Load models
        async function loadModels() {
            try {
                await faceapi.nets.tinyFaceDetector.loadFromUri('./models');
                await faceapi.nets.faceLandmark68Net.loadFromUri('./models');
                await faceapi.nets.faceRecognitionNet.loadFromUri('./models');
                
                modelsLoaded = true;
                document.getElementById('loadingStatus').style.display = 'none';
                document.getElementById('recognizeBtn').disabled = false;
                
            } catch (error) {
                console.error('Error loading models:', error);
                document.getElementById('loadingStatus').innerHTML = 
                    '<i class="fas fa-exclamation-triangle"></i> Error loading facial recognition';
            }
        }

        // Start camera with better quality
        async function startVideo() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: 'user',
                        frameRate: { ideal: 30 }
                    } 
                });
                document.getElementById('video').srcObject = stream;
            } catch(err) {
                alert('❌ Error: Allow camera access for facial verification');
            }
        }

        // Reset facial recognition
        function resetFacialRecognition() {
            facialDescriptor = null;
            document.getElementById('facialData').value = '';
            document.getElementById('facialLoginBtn').disabled = true;
            document.getElementById('recognizeBtn').innerHTML = '<i class="fas fa-camera"></i> CAPTURE FACE';
            document.getElementById('recognizeBtn').disabled = !modelsLoaded;
            document.getElementById('recognizeBtn').style.background = '';
            document.getElementById('emailCheckStatus').style.display = 'none';
            isProcessing = false;
        }

        // Capture face with improved verification
        document.getElementById('recognizeBtn').addEventListener('click', async () => {
            if (isProcessing) return;
            
            if (!modelsLoaded) {
                alert('Recognition system still loading...');
                return;
            }

            const email = document.getElementById('emailFacial').value;
            if (!email) {
                alert('Enter your email first');
                return;
            }

            // Validate email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                alert('Please enter a valid email address.');
                return;
            }

            isProcessing = true;
            const recognizeBtn = document.getElementById('recognizeBtn');
            recognizeBtn.innerHTML = '<div class="spinner"></div> ANALYZING FACE...';
            recognizeBtn.disabled = true;

            try {
                // Wait for camera to be ready
                await new Promise(resolve => setTimeout(resolve, 800));
                
                // Verify that a face is visible with good quality
                const detection = await faceapi.detectSingleFace(
                    document.getElementById('video'), 
                    new faceapi.TinyFaceDetectorOptions({ 
                        scoreThreshold: 0.5, // Less strict
                        inputSize: 416 
                    })
                );

                if (!detection) {
                    alert('❌ No face clearly detected\n• Ensure good lighting\n• Look directly at the camera\n• Move closer until your face fills the oval');
                    recognizeBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURE FACE';
                    recognizeBtn.disabled = false;
                    isProcessing = false;
                    return;
                }

                // Verify face size (more permissive)
                const box = detection.box;
                const minFaceSize = 120; // Reduced to be more permissive
                if (box.width < minFaceSize || box.height < minFaceSize) {
                    alert('❌ Move a little closer\n• Your face should be larger on screen\n• Fill the guide oval with your face');
                    recognizeBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURE FACE';
                    recognizeBtn.disabled = false;
                    isProcessing = false;
                    return;
                }

                // Capture complete facial descriptor
                const fullDetection = await faceapi
                    .detectSingleFace(document.getElementById('video'), new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                if (fullDetection) {
                    // Capture image in GOOD quality (balanced)
                    const canvas = document.createElement('canvas');
                    const video = document.getElementById('video');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    // Balanced quality (0.8 instead of 1.0 to avoid very large files)
                    const imageBase64 = canvas.toDataURL('image/jpeg', 0.8);

                    // Prepare data to send
                    facialDescriptor = Array.from(fullDetection.descriptor);
                    const facialData = {
                        descriptor: facialDescriptor,
                        frame: imageBase64,
                        timestamp: new Date().toISOString(),
                        face_size: { width: box.width, height: box.height },
                        detection_score: detection.score
                    };

                    document.getElementById('facialData').value = JSON.stringify(facialData);
                    document.getElementById('facialLoginBtn').disabled = false;
                    
                    recognizeBtn.innerHTML = '<i class="fas fa-check"></i> FACE CAPTURED';
                    recognizeBtn.style.background = 'var(--green)';
                    
                    // Show success message
                    document.getElementById('emailCheckStatus').innerHTML = 
                        '<i class="fas fa-shield-check"></i> ✅ Face captured successfully. Click "FACE LOGIN"';
                    document.getElementById('emailCheckStatus').className = 'email-check-status email-check-success';
                    document.getElementById('emailCheckStatus').style.display = 'block';
                    
                } else {
                    alert('Error in facial capture. Try in a better lit area.');
                    recognizeBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURE FACE';
                    recognizeBtn.disabled = false;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('System error. Reload the page and try again.');
                recognizeBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURE FACE';
                recognizeBtn.disabled = false;
            }
            
            isProcessing = false;
        });

        // Function to show/hide password
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.toggle-password i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Initialize
        window.addEventListener('load', function() {
            loadModels();
            startVideo();
        });

        // Validate facial form before submitting
        document.getElementById('facialLoginForm').addEventListener('submit', function(e) {
            if (!facialDescriptor) {
                e.preventDefault();
                alert('You must capture your face first.');
                return;
            }

            // Show processing message
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<div class="spinner"></div> VERIFYING...';
                submitBtn.disabled = true;
            }
        });

        // Prevent multiple submissions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<div class="spinner"></div> PROCESSING...';
                }
            });
        });
    </script>
</body>
</html>