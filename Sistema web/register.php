<?php
session_start();
include_once 'config.php';
include_once 'Technician.php';

$database = new Database();
$db = $database->getConnection();
$technician = new Technician($db);

$message = '';
$message_type = '';

// Departamentos basados en tu imagen
$departments = [
    'dep_it' => 'IT Department',
    'dep_backoffice' => 'Back Office', 
    'dep_finance' => 'Finance',
    'dep_marketing' => 'Marketing',
    'dep_sales' => 'Sales',
    'dep_hr' => 'Human Resources'
];

if($_POST){
    try {
        $technician->name = $_POST['name'];
        $technician->email = $_POST['email'];
        $technician->role = $_POST['role'];
        $technician->phone = $_POST['phone'];
        $technician->facial_data = $_POST['facial_data'];
        $password = $_POST['password'];
        
        // Validaciones
        if(empty($_POST['facial_data']) || $_POST['facial_data'] === 'null') {
            $message = "Debes capturar tu rostro antes de registrarte.";
            $message_type = 'error';
        } elseif($technician->emailExists()) {
            $message = "El email ya está registrado.";
            $message_type = 'error';
        } elseif(empty($_POST['phone'])) {
            $message = "El teléfono es obligatorio.";
            $message_type = 'error';
        } elseif(empty($_POST['name'])) {
            $message = "El nombre es obligatorio.";
            $message_type = 'error';
        } elseif(empty($password) || strlen($password) < 6) {
            $message = "La contraseña es obligatoria y debe tener al menos 6 caracteres.";
            $message_type = 'error';
        } else {
            // REGISTRO DIRECTO SIN HASH
            $query = "INSERT INTO technicians 
                      SET name=:name, email=:email, role=:role, phone=:phone, 
                          facial_data=:facial_data, password=:password, active='yes',
                          registration_date=NOW(), update_date=NOW()";
            
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(":name", $technician->name);
            $stmt->bindParam(":email", $technician->email);
            $stmt->bindParam(":role", $technician->role);
            $stmt->bindParam(":phone", $technician->phone);
            $stmt->bindParam(":facial_data", $technician->facial_data);
            $stmt->bindParam(":password", $password); // Contraseña en texto plano
            
            if($stmt->execute()) {
                $message = "✅ Técnico registrado exitosamente! Ya puedes iniciar sesión.";
                $message_type = 'success';
                $_POST = array();
            } else{
                $message = "❌ Error al registrar técnico. Intenta nuevamente.";
                $message_type = 'error';
            }
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
        error_log("Error en registro: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Técnicos - Sistema Biométrico</title>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --blue-primary: #2563eb;
            --blue-dark: #1d4ed8;
            --blue-light: #dbeafe;
            --blue-bg: #eff6ff;
            --black: #000000;
            --black-light: #374151;
            --white: #ffffff;
            --gray-light: #f3f4f6;
            --gray: #9ca3af;
            --gray-dark: #4b5563;
            --green: #10b981;
            --green-dark: #059669;
            --green-light: #d1fae5;
            --red: #dc2626;
            --red-light: #fecaca;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--black) 0%, var(--blue-dark) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .register-container {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
            animation: slideUp 0.5s ease-out;
            border: 2px solid var(--blue-primary);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .register-header {
            background: linear-gradient(135deg, var(--blue-primary), var(--black));
            color: var(--white);
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .register-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }

        .register-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
        }

        .register-body {
            padding: 30px;
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
            border-color: var(--blue-primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
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

        .password-strength {
            margin-top: 5px;
            font-size: 0.8rem;
            padding: 5px;
            border-radius: 5px;
            text-align: center;
            font-weight: 500;
        }
        
        .strength-weak { background: var(--red-light); color: var(--red); }
        .strength-medium { background: #fef3c7; color: #d97706; }
        .strength-strong { background: var(--green-light); color: var(--green-dark); }

        select.form-input {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
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
            background: var(--blue-primary);
            color: var(--white);
            border: 2px solid var(--blue-primary);
        }

        .btn-primary:hover {
            background: var(--blue-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--green);
            color: var(--white);
            border: 2px solid var(--green);
        }

        .btn:disabled {
            background: var(--gray);
            cursor: not-allowed;
            transform: none;
        }

        .video-container {
            position: relative;
            width: 100%;
            margin: 20px 0;
            border-radius: 12px;
            overflow: hidden;
            background: var(--black);
            border: 2px solid var(--blue-primary);
        }

        video {
            width: 100%;
            height: 250px;
            object-fit: cover;
        }

        .loading {
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid var(--blue-primary);
            color: var(--blue-dark);
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            text-align: center;
        }

        .message {
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            border-left: 4px solid;
        }

        .success {
            background: rgba(16, 185, 129, 0.15);
            border-color: var(--green);
            color: var(--green-dark);
        }

        .error {
            background: rgba(239, 68, 68, 0.15);
            border-color: #dc2626;
            color: #dc2626;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 480px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .security-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--blue-primary);
            color: var(--white);
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
        }

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
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i> SEGURO
            </div>
            <h1>REGISTRO DE TÉCNICO</h1>
            <p>Sistema Biométrico de Acceso</p>
        </div>

        <div class="register-body">
            <?php if($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type == 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form id="registerForm" method="post">
                <input type="hidden" name="facial_data" id="facialData">

                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-user"></i> NOMBRE COMPLETO
                    </label>
                    <input type="text" name="name" class="form-input" placeholder="Ingrese su nombre completo" required
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> EMAIL
                        </label>
                        <input type="email" name="email" class="form-input" placeholder="tecnico@empresa.com" required
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-phone"></i> TELÉFONO
                        </label>
                        <input type="text" name="phone" class="form-input" placeholder="50212345678" required
                               value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                    </div>
                </div>

                <!-- NUEVO CAMPO PASSWORD -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-lock"></i> CONTRASEÑA
                    </label>
                    <div class="password-container">
                        <input type="password" name="password" id="password" class="form-input" 
                               placeholder="Mínimo 6 caracteres" required
                               oninput="checkPasswordStrength(this.value)">
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="passwordStrength" class="password-strength"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-building"></i> DEPARTAMENTO
                    </label>
                    <select name="role" class="form-input" required>
                        <option value="">SELECCIONAR DEPARTAMENTO</option>
                        <?php foreach($departments as $key => $department): ?>
                            <option value="<?php echo $key; ?>" 
                                <?php echo (isset($_POST['role']) && $_POST['role'] == $key) ? 'selected' : ''; ?>>
                                <?php echo $department; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="loadingStatus" class="loading">
                    <div class="spinner"></div>
                    INICIANDO SISTEMA DE RECONOCIMIENTO...
                </div>

                <div class="video-container">
                    <video id="video" autoplay muted></video>
                </div>
                
                <button type="button" id="captureBtn" class="btn btn-primary" disabled>
                    <i class="fas fa-camera"></i> CAPTURAR ROSTRO
                </button>
                
                <button type="submit" id="registerBtn" class="btn btn-success" disabled>
                    <i class="fas fa-user-plus"></i> REGISTRAR TÉCNICO
                </button>
            </form>

            <div style="text-align: center; margin-top: 20px;">
                <p>¿Ya tienes cuenta? <a href="login.php" style="color: var(--blue-primary);">INICIA SESIÓN AQUÍ</a></p>
            </div>
        </div>
    </div>

    <script>
        let facialDescriptor = null;
        let modelsLoaded = false;

        async function loadModels() {
            const loadingStatus = document.getElementById('loadingStatus');
            try {
                await faceapi.nets.tinyFaceDetector.loadFromUri('./models');
                await faceapi.nets.faceLandmark68Net.loadFromUri('./models');
                await faceapi.nets.faceRecognitionNet.loadFromUri('./models');
                
                modelsLoaded = true;
                loadingStatus.innerHTML = '<i class="fas fa-check-circle"></i> SISTEMA LISTO!';
                setTimeout(() => { 
                    loadingStatus.style.display = 'none'; 
                }, 2000);
                
                document.getElementById('captureBtn').disabled = false;
                
            } catch (error) {
                console.error('Error cargando modelos:', error);
                loadingStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ERROR: Recarga la página';
            }
        }

        async function startVideo() {
            const video = document.getElementById('video');
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: 'user' } 
                });
                video.srcObject = stream;
            } catch(err) {
                console.error("Error al acceder a la cámara: ", err);
                alert("Permite el acceso a la cámara para continuar.");
            }
        }

        // Función para mostrar/ocultar contraseña
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
        
        // Función para verificar fortaleza de contraseña
        function checkPasswordStrength(password) {
            const strengthDiv = document.getElementById('passwordStrength');
            let strength = '';
            let className = '';
            
            if (password.length === 0) {
                strengthDiv.innerHTML = '';
                strengthDiv.className = 'password-strength';
                return;
            }
            
            if (password.length < 6) {
                strength = 'Débil - Mínimo 6 caracteres';
                className = 'strength-weak';
            } else if (password.length < 8) {
                strength = 'Media';
                className = 'strength-medium';
            } else {
                // Verificar si tiene números y letras
                const hasNumbers = /\d/.test(password);
                const hasLetters = /[a-zA-Z]/.test(password);
                const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
                
                if (hasNumbers && hasLetters && hasSpecial) {
                    strength = 'Fuerte';
                    className = 'strength-strong';
                } else if (hasNumbers && hasLetters) {
                    strength = 'Buena';
                    className = 'strength-medium';
                } else {
                    strength = 'Media';
                    className = 'strength-medium';
                }
            }
            
            strengthDiv.innerHTML = `<i class="fas fa-shield-alt"></i> ${strength}`;
            strengthDiv.className = `password-strength ${className}`;
        }

        document.getElementById('captureBtn').addEventListener('click', async () => {
            if (!modelsLoaded) {
                alert('Sistema aún cargando. Por favor espera.');
                return;
            }

            const video = document.getElementById('video');
            const captureBtn = document.getElementById('captureBtn');
            
            captureBtn.innerHTML = '<div class="spinner"></div> ANALIZANDO...';
            captureBtn.disabled = true;

            try {
                const detections = await faceapi
                    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptor();
                
                if (detections) {
                    facialDescriptor = Array.from(detections.descriptor);
                    document.getElementById('facialData').value = JSON.stringify(facialDescriptor);
                    document.getElementById('registerBtn').disabled = false;
                    
                    captureBtn.innerHTML = '<i class="fas fa-check"></i> ROSTRO CAPTURADO';
                    captureBtn.disabled = true;
                    
                } else {
                    alert('❌ ROSTRO NO DETECTADO. Asegúrate de tener buena iluminación.');
                    captureBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURAR ROSTRO';
                    captureBtn.disabled = false;
                }
            } catch (error) {
                console.error('Error en reconocimiento facial:', error);
                alert('ERROR EN EL SISTEMA. Intenta de nuevo.');
                captureBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURAR ROSTRO';
                captureBtn.disabled = false;
            }
        });

        window.addEventListener('load', function() {
            loadModels().then(() => {
                startVideo();
            });
        });

        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const facialData = document.getElementById('facialData').value;
            
            if (!facialData || facialData === 'null') {
                e.preventDefault();
                alert('❌ Debes verificar tu rostro antes de registrarte.');
                return;
            }
        });
    </script>
</body>
</html>