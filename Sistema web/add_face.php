<?php
session_start();

// Configurar tiempo máximo de inactividad (3 horas = 10800 segundos)
$max_inactivity_time = 10800;

// Verificar inactividad
if (isset($_SESSION['last_activity'])) {
    $session_time = time() - $_SESSION['last_activity'];
    if ($session_time > $max_inactivity_time) {
        session_unset();
        session_destroy();
        header("Location: login.php?error=session_expired");
        exit();
    }
}

// Actualizar timestamp de última actividad
$_SESSION['last_activity'] = time();

// Verificar si el usuario está logueado
if(!isset($_SESSION['technician_id']) || !isset($_SESSION['technician_role'])){
    header("Location: login.php");
    exit();
}

// Incluir configuración de base de datos
include_once 'config.php';

$database = new Database();
$db = $database->getConnection();

// VERIFICAR SI EL USUARIO ESTÁ ACTIVO EN LA TABLA TECHNICIANS
$query = "SELECT * FROM technicians WHERE id = :id AND active = 'yes'";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $_SESSION['technician_id']);
$stmt->execute();
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Si el usuario no existe o está inactivo en technicians, cerrar sesión inmediatamente
if (!$user_data) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=user_inactive");
    exit();
}

// Obtener el email del usuario logueado directamente de la base de datos
$user_email = $user_data['email'];

$message = '';
$message_type = '';

if($_POST){
    $email = $_POST['email'];
    $facial_data = $_POST['facial_data'];
    
    // Verificar que el email del POST coincida con el usuario logeado
    if ($email !== $user_email) {
        $message = "Security error: Email mismatch.";
        $message_type = 'error';
    } else {
        // Verify if technician exists
        $query = "SELECT * FROM technicians WHERE email = :email AND active = 'yes'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $existingTechnician = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($existingTechnician){
            // Update facial data
            $updateQuery = "UPDATE technicians SET facial_data = :facial_data WHERE email = :email";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':facial_data', $facial_data);
            $updateStmt->bindParam(':email', $email);
            
            if($updateStmt->execute()){
                $message = "✅ Facial recognition added successfully!";
                $message_type = 'success';
            } else {
                $message = "Error saving facial data.";
                $message_type = 'error';
            }
        } else {
            $message = "Technician not found. Please contact administrator.";
            $message_type = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Face - Technician System</title>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --red-primary: #dc2626;
            --red-dark: #b91c1c;
            --red-light: #fecaca;
            --red-bg: #fef2f2;
            --black: #000000;
            --black-light: #374151;
            --white: #ffffff;
            --gray-light: #f3f4f6;
            --gray: #9ca3af;
            --gray-dark: #4b5563;
            --green-primary: #10b981;
            --green-dark: #059669;
            --green-light: #a7f3d0;
            --green-bg: #ecfdf5;
            --blue: #2563eb;
            --blue-light: #dbeafe;
            --purple: #7c3aed;
            --purple-light: #ddd6fe;
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

        .container {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            width: 100%;
            max-width: 600px;
            animation: slideUp 0.5s ease-out;
            border: 2px solid var(--red-primary);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            background: linear-gradient(135deg, var(--red-primary), var(--black));
            color: var(--white);
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }

        .header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
        }

        .header p {
            opacity: 0.9;
            font-size: 0.95rem;
            position: relative;
        }

        .body {
            padding: 30px;
            background: var(--white);
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

        .email-display {
            background: var(--gray-light);
            padding: 12px 16px;
            border-radius: 12px;
            border: 2px solid var(--gray);
            font-weight: 600;
            color: var(--black);
            margin-top: 8px;
            text-align: center;
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
            margin-bottom: 10px;
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

        .btn-secondary:hover {
            background: var(--black-light);
            border-color: var(--black-light);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .btn-purple {
            background: var(--purple);
            color: var(--white);
            border: 2px solid var(--purple);
        }

        .btn-purple:hover {
            background: #6d28d9;
            border-color: #6d28d9;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.4);
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
            height: 300px;
            object-fit: cover;
            display: block;
        }

        .canvas-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
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
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .success {
            background: rgba(16, 185, 129, 0.1);
            border-color: var(--green-primary);
            color: var(--green-dark);
        }

        .error {
            background: rgba(220, 38, 38, 0.15);
            border-color: var(--red-primary);
            color: var(--red-primary);
            font-weight: 500;
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

        .instructions {
            background: var(--gray-light);
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            font-size: 0.85rem;
            border-left: 4px solid var(--red-primary);
        }

        .instructions ul {
            margin-left: 20px;
            margin-top: 8px;
        }

        .instructions li {
            margin-bottom: 5px;
        }

        .user-info {
            background: var(--blue-light);
            border: 2px solid var(--blue);
            color: var(--blue);
            padding: 12px;
            border-radius: 12px;
            margin: 15px 0;
            text-align: center;
            font-weight: 600;
        }

        .progress-container {
            margin: 20px 0;
            background: var(--gray-light);
            border-radius: 10px;
            height: 10px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--red-primary), var(--purple));
            border-radius: 10px;
            transition: width 0.5s ease;
            width: 0%;
        }

        .capture-status {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
            font-size: 0.8rem;
            color: var(--gray-dark);
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .status-item.active {
            color: var(--green-primary);
            font-weight: 600;
        }

        .face-count {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
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
            to {
                transform: rotate(360deg);
            }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(220, 38, 38, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0);
            }
        }

        .quality-indicator {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .quality-good {
            color: var(--green-primary);
        }

        .quality-poor {
            color: var(--red-primary);
        }

        .multi-capture-instructions {
            background: var(--orange-light);
            border: 2px solid var(--orange);
            color: var(--orange);
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            text-align: center;
            animation: pulse 2s infinite;
        }

        .capture-progress {
            font-weight: bold;
            margin: 10px 0;
            padding: 10px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i> SECURE
            </div>
            <h1>ADD FACE</h1>
            <p>Advanced Biometric Registration</p>
        </div>

        <div class="body">
            <?php if($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type == 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Mostrar información del usuario logeado -->
            <?php if(!empty($user_email)): ?>
                <div class="user-info">
                    <i class="fas fa-user-check"></i> 
                    Registering face for: <?php echo htmlspecialchars($user_email); ?>
                </div>
            <?php endif; ?>

            <div class="instructions">
                <strong><i class="fas fa-info-circle"></i> INSTRUCTIONS:</strong>
                <ul>
                    <li>Your email is automatically filled from your login session</li>
                    <li>Make sure you have good lighting</li>
                    <li>Look directly at the camera</li>
                    <li>Keep your face within the frame</li>
                    <li>This process only takes 30 seconds</li>
                </ul>
            </div>

            <div class="capture-status">
                <div class="status-item" id="model-status">
                    <i class="fas fa-cube"></i> Models
                </div>
                <div class="status-item" id="camera-status">
                    <i class="fas fa-camera"></i> Camera
                </div>
                <div class="status-item" id="face-status">
                    <i class="fas fa-user"></i> Face Detection
                </div>
                <div class="status-item" id="quality-status">
                    <i class="fas fa-star"></i> Quality
                </div>
            </div>

            <div class="progress-container">
                <div class="progress-bar" id="progressBar"></div>
            </div>

            <form method="post">
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-envelope"></i> YOUR REGISTERED EMAIL
                    </label>
                    <div class="email-display">
                        <i class="fas fa-user-circle"></i> 
                        <?php echo htmlspecialchars($user_email); ?>
                    </div>
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($user_email); ?>">
                    <small style="display: block; margin-top: 5px; color: var(--gray-dark); font-size: 0.8rem;">
                        <i class="fas fa-lock"></i> This is your account email from login session
                    </small>
                </div>

                <div id="loadingStatus" class="loading">
                    <div class="spinner"></div>
                    LOADING ADVANCED FACE RECOGNITION SYSTEM...
                </div>

                <div id="multiCaptureInstructions" class="multi-capture-instructions" style="display: none;">
                    <h4><i class="fas fa-images"></i> MULTI-CAPTURE MODE</h4>
                    <p>We'll capture 5 face samples automatically</p>
                    <p>Please move your head slightly between captures</p>
                    <div id="captureProgress" class="capture-progress"></div>
                </div>

                <div class="video-container">
                    <video id="video" autoplay muted></video>
                    <canvas id="overlay" class="canvas-overlay"></canvas>
                    <div class="face-count" id="faceCount">Faces: 0</div>
                    <div class="quality-indicator" id="qualityIndicator">
                        <i class="fas fa-star"></i> Quality: --
                    </div>
                </div>
                
                <button type="button" id="captureBtn" class="btn btn-secondary" disabled>
                    <i class="fas fa-camera"></i> CAPTURE FACE
                </button>

                <button type="button" id="multiCaptureBtn" class="btn btn-purple" disabled>
                    <i class="fas fa-images"></i> MULTIPLE CAPTURES (RECOMMENDED)
                </button>
                
                <button type="submit" id="submitBtn" class="btn btn-primary pulse" disabled>
                    <i class="fas fa-save"></i> SAVE FACIAL RECOGNITION
                </button>
                
                <input type="hidden" name="facial_data" id="facialData">
            </form>
        </div>
    </div>

    <script>
        let facialDescriptor = null;
        let modelsLoaded = false;
        let isCapturing = false;
        let captureCount = 0;
        let allDescriptors = [];
        const maxCaptures = 5;

        // Update progress bar
        function updateProgress(percent) {
            document.getElementById('progressBar').style.width = percent + '%';
        }

        // Update status indicators
        function updateStatus(elementId, isActive) {
            const element = document.getElementById(elementId);
            if (isActive) {
                element.classList.add('active');
                element.innerHTML = element.innerHTML.replace('>', '><i class="fas fa-check"></i> ');
            } else {
                element.classList.remove('active');
            }
        }

        // Update face count display
        function updateFaceCount(count) {
            document.getElementById('faceCount').textContent = `Faces: ${count}`;
        }

        // Update quality indicator
        function updateQualityIndicator(quality) {
            const indicator = document.getElementById('qualityIndicator');
            if (quality === 'good') {
                indicator.innerHTML = '<i class="fas fa-star quality-good"></i> Quality: Good';
            } else if (quality === 'poor') {
                indicator.innerHTML = '<i class="fas fa-star quality-poor"></i> Quality: Poor';
            } else {
                indicator.innerHTML = '<i class="fas fa-star"></i> Quality: --';
            }
        }

        async function loadModels() {
            const loadingStatus = document.getElementById('loadingStatus');
            try {
                loadingStatus.innerHTML = '<div class="spinner"></div> LOADING FACE RECOGNITION MODELS...';
                updateProgress(10);
                
                // Cargar solo los modelos disponibles
                await faceapi.nets.tinyFaceDetector.loadFromUri('./models');
                updateProgress(40);
                await faceapi.nets.faceLandmark68Net.loadFromUri('./models');
                updateProgress(70);
                await faceapi.nets.faceRecognitionNet.loadFromUri('./models');
                
                modelsLoaded = true;
                updateProgress(100);
                updateStatus('model-status', true);
                
                loadingStatus.innerHTML = '<i class="fas fa-check-circle"></i> FACE RECOGNITION SYSTEM READY!';
                setTimeout(() => { 
                    loadingStatus.style.display = 'none'; 
                }, 2000);
                
                document.getElementById('captureBtn').disabled = false;
                document.getElementById('multiCaptureBtn').disabled = false;
                
            } catch (error) {
                console.error('Error loading models:', error);
                
                // Intentar cargar desde CDN como respaldo
                try {
                    loadingStatus.innerHTML = '<div class="spinner"></div> TRYING ALTERNATIVE SOURCE...';
                    
                    await faceapi.nets.tinyFaceDetector.loadFromUri('https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights/');
                    await faceapi.nets.faceLandmark68Net.loadFromUri('https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights/');
                    await faceapi.nets.faceRecognitionNet.loadFromUri('https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights/');
                    
                    modelsLoaded = true;
                    updateStatus('model-status', true);
                    
                    loadingStatus.innerHTML = '<i class="fas fa-check-circle"></i> SYSTEM READY (USING CDN)!';
                    setTimeout(() => { 
                        loadingStatus.style.display = 'none'; 
                    }, 2000);
                    
                    document.getElementById('captureBtn').disabled = false;
                    document.getElementById('multiCaptureBtn').disabled = false;
                    
                } catch (cdnError) {
                    console.error('CDN also failed:', cdnError);
                    loadingStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ERROR: Could not load models';
                    updateStatus('model-status', false);
                }
            }
        }

        async function startVideo() {
            const video = document.getElementById('video');
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        width: { ideal: 640 },
                        height: { ideal: 480 },
                        facingMode: 'user'
                    } 
                });
                video.srcObject = stream;
                updateStatus('camera-status', true);
                
                // Start face detection in real-time
                startRealTimeDetection();
                
            } catch(err) {
                console.error("Error accessing camera: ", err);
                document.getElementById('loadingStatus').innerHTML = '<i class="fas fa-exclamation-triangle"></i> CAMERA ACCESS DENIED';
            }
        }

        function startRealTimeDetection() {
            const video = document.getElementById('video');
            const canvas = document.getElementById('overlay');
            
            // Esperar a que el video esté listo
            video.addEventListener('loadedmetadata', () => {
                const displaySize = { width: video.videoWidth, height: video.videoHeight };
                faceapi.matchDimensions(canvas, displaySize);

                setInterval(async () => {
                    if (!modelsLoaded || isCapturing) return;

                    try {
                        const detections = await faceapi
                            .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ 
                                inputSize: 320, 
                                scoreThreshold: 0.5 
                            }))
                            .withFaceLandmarks()
                            .withFaceDescriptors();

                        updateFaceCount(detections.length);
                        
                        const ctx = canvas.getContext('2d');
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        
                        if (detections.length > 0) {
                            updateStatus('face-status', true);
                            
                            // Check face quality (simplified)
                            const detection = detections[0];
                            const box = detection.detection.box;
                            const faceSize = box.width * box.height;
                            const videoArea = video.videoWidth * video.videoHeight;
                            const faceRatio = faceSize / videoArea;
                            
                            if (faceRatio > 0.1) {
                                updateQualityIndicator('good');
                            } else {
                                updateQualityIndicator('poor');
                            }
                            
                            // Draw detections
                            const resizedDetections = faceapi.resizeResults(detections, displaySize);
                            faceapi.draw.drawDetections(canvas, resizedDetections);
                            faceapi.draw.drawFaceLandmarks(canvas, resizedDetections);
                        } else {
                            updateStatus('face-status', false);
                            updateQualityIndicator('unknown');
                        }
                    } catch (error) {
                        console.error('Error in real-time detection:', error);
                    }
                }, 1000);
            });
        }

        async function captureSingleFace() {
            if (!modelsLoaded || isCapturing) return;

            const video = document.getElementById('video');
            const captureBtn = document.getElementById('captureBtn');
            
            isCapturing = true;
            captureBtn.innerHTML = '<div class="spinner"></div> ANALYZING FACE...';
            captureBtn.disabled = true;

            try {
                const detections = await faceapi
                    .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ 
                        inputSize: 320,
                        scoreThreshold: 0.5 
                    }))
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                
                if (detections.length === 1) {
                    facialDescriptor = Array.from(detections[0].descriptor);
                    allDescriptors = [facialDescriptor];
                    document.getElementById('facialData').value = JSON.stringify(allDescriptors);
                    document.getElementById('submitBtn').disabled = false;
                    
                    captureBtn.innerHTML = '<i class="fas fa-check"></i> FACE CAPTURED';
                    captureBtn.style.background = 'var(--green-primary)';
                    
                } else if (detections.length > 1) {
                    alert('❌ MULTIPLE FACES DETECTED. Please ensure only one person is in frame.');
                    captureBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURE FACE';
                    captureBtn.disabled = false;
                } else {
                    alert('❌ FACE NOT DETECTED. Make sure you have good lighting and look at the camera.');
                    captureBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURE FACE';
                    captureBtn.disabled = false;
                }
            } catch (error) {
                console.error('Error in facial recognition:', error);
                alert('SYSTEM ERROR. Please try again.');
                captureBtn.innerHTML = '<i class="fas fa-camera"></i> CAPTURE FACE';
                captureBtn.disabled = false;
            }
            
            isCapturing = false;
        }

        async function captureMultipleFaces() {
            if (!modelsLoaded || isCapturing) return;

            const video = document.getElementById('video');
            const multiCaptureBtn = document.getElementById('multiCaptureBtn');
            
            isCapturing = true;
            multiCaptureBtn.innerHTML = '<div class="spinner"></div> PREPARING...';
            multiCaptureBtn.disabled = true;

            // Mostrar instrucciones
            document.getElementById('multiCaptureInstructions').style.display = 'block';
            document.getElementById('captureProgress').innerHTML = 'Starting multi-capture process...';

            allDescriptors = [];
            captureCount = 0;

            try {
                for (let i = 0; i < maxCaptures; i++) {
                    // Actualizar progreso
                    const progress = ((i + 1) / maxCaptures) * 100;
                    updateProgress(progress);
                    
                    document.getElementById('captureProgress').innerHTML = 
                        `Capture ${i+1} of ${maxCaptures}...`;
                    
                    multiCaptureBtn.innerHTML = `<div class="spinner"></div> CAPTURING ${i+1}/${maxCaptures}`;

                    // Pequeña pausa entre capturas (sin alert)
                    if (i > 0) {
                        document.getElementById('captureProgress').innerHTML = 
                            `Capture ${i+1} of ${maxCaptures}...<br><small>Please move your head slightly</small>`;
                        
                        await new Promise(resolve => setTimeout(resolve, 2000)); // 2 segundos
                    }

                    // Realizar la captura
                    const detections = await faceapi
                        .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ 
                            inputSize: 320,
                            scoreThreshold: 0.5 
                        }))
                        .withFaceLandmarks()
                        .withFaceDescriptors();
                    
                    if (detections.length === 1) {
                        const descriptor = Array.from(detections[0].descriptor);
                        allDescriptors.push(descriptor);
                        captureCount++;
                        
                        // Feedback visual de éxito
                        document.getElementById('captureProgress').innerHTML = 
                            `✓ Capture ${i+1} of ${maxCaptures} COMPLETED`;
                        
                    } else if (detections.length > 1) {
                        document.getElementById('captureProgress').innerHTML = 
                            `❌ Multiple faces detected. Retrying ${i+1}/${maxCaptures}...`;
                        i--; // Reintentar esta captura
                    } else {
                        document.getElementById('captureProgress').innerHTML = 
                            `❌ No face detected. Retrying ${i+1}/${maxCaptures}...`;
                        i--; // Reintentar esta captura
                    }

                    // Pequeña pausa antes de la siguiente captura
                    await new Promise(resolve => setTimeout(resolve, 1000));
                }

                // Proceso completado
                if (allDescriptors.length > 0) {
                    document.getElementById('facialData').value = JSON.stringify(allDescriptors);
                    document.getElementById('submitBtn').disabled = false;
                    
                    multiCaptureBtn.innerHTML = `<i class="fas fa-check"></i> ${captureCount} CAPTURES COMPLETE`;
                    multiCaptureBtn.style.background = 'var(--green-primary)';
                    
                    document.getElementById('captureProgress').innerHTML = 
                        `✅ Successfully captured ${captureCount} facial samples!`;
                    
                } else {
                    document.getElementById('captureProgress').innerHTML = 
                        '❌ Failed to capture any face samples. Please try again.';
                }

            } catch (error) {
                console.error('Error in multiple capture:', error);
                document.getElementById('captureProgress').innerHTML = 
                    '❌ System error. Please try again.';
            } finally {
                // Limpiar después de 3 segundos
                setTimeout(() => {
                    multiCaptureBtn.innerHTML = '<i class="fas fa-images"></i> MULTIPLE CAPTURES (RECOMMENDED)';
                    multiCaptureBtn.disabled = false;
                    multiCaptureBtn.style.background = '';
                    isCapturing = false;
                    
                    // Ocultar instrucciones después de 5 segundos
                    setTimeout(() => {
                        document.getElementById('multiCaptureInstructions').style.display = 'none';
                    }, 5000);
                }, 3000);
            }
        }

        // Event listeners
        document.getElementById('captureBtn').addEventListener('click', captureSingleFace);
        document.getElementById('multiCaptureBtn').addEventListener('click', captureMultipleFaces);

        // Initialize when page loads
        window.addEventListener('load', function() {
            loadModels().then(() => {
                startVideo();
            });
        });

        // Validate form before submitting
        document.querySelector('form').addEventListener('submit', function(e) {
            if (allDescriptors.length === 0) {
                e.preventDefault();
                alert('You must capture your face first before saving.');
            }
        });
    </script>
</body>
</html>