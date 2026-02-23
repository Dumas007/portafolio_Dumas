<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once 'config.php';
include_once 'Technician.php';

$database = new Database();
$db = $database->getConnection();
$technician = new Technician($db);

// Verificar si el usuario está logueado
if (!isset($_SESSION['technician_id'])) {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = '';

if ($_POST && isset($_POST['facial_data'])) {
    $facial_data = $_POST['facial_data'];
    $email = $_SESSION['technician_email'];
    
    // Validar datos faciales
    $decoded_data = json_decode($facial_data, true);
    if (!isset($decoded_data['landmarks']) || !is_array($decoded_data['landmarks'])) {
        $message = "Invalid facial data. Please try again.";
        $message_type = 'error';
    } else {
        // Actualizar datos faciales en la base de datos
        if ($technician->updateFacialData($email, $facial_data)) {
            $message = "Facial data updated successfully!";
            $message_type = 'success';
        } else {
            $message = "Error updating facial data. Please try again.";
            $message_type = 'error';
        }
    }
}

// Obtener información del usuario
$technician->getUserById($_SESSION['technician_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Re-register Face</title>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/control_utils/control_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/drawing_utils/drawing_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js" crossorigin="anonymous"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .video-container { width: 640px; height: 480px; border: 2px solid #333; margin: 20px 0; }
        video { width: 100%; height: 100%; }
        canvas { position: absolute; top: 0; left: 0; }
        .btn { padding: 10px 20px; margin: 5px; cursor: pointer; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Re-register Your Face</h1>
    
    <?php if($message): ?>
        <div class="<?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <p><strong>User:</strong> <?php echo htmlspecialchars($technician->name); ?> (<?php echo htmlspecialchars($technician->email); ?>)</p>
    
    <div class="video-container">
        <video id="video" autoplay muted></video>
        <canvas id="canvas"></canvas>
    </div>

    <button id="startBtn" class="btn">Start Camera</button>
    <button id="captureBtn" class="btn" disabled>Capture Face</button>
    
    <form id="faceForm" method="post" style="display: none;">
        <input type="hidden" name="facial_data" id="facialData">
        <button type="submit" class="btn">Save Face Data</button>
    </form>

    <script>
        let faceMesh = null;
        let camera = null;
        let capturedData = null;

        document.getElementById('startBtn').addEventListener('click', async () => {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { width: 640, height: 480 } 
                });
                
                const video = document.getElementById('video');
                video.srcObject = stream;
                
                // Inicializar FaceMesh
                faceMesh = new FaceMesh({
                    locateFile: (file) => {
                        return `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}`;
                    }
                });

                faceMesh.setOptions({
                    maxNumFaces: 1,
                    refineLandmarks: true,
                    minDetectionConfidence: 0.5,
                    minTrackingConfidence: 0.5
                });

                faceMesh.onResults(onResults);
                
                camera = new Camera(video, {
                    onFrame: async () => {
                        await faceMesh.send({image: video});
                    },
                    width: 640,
                    height: 480
                });
                
                camera.start();
                document.getElementById('captureBtn').disabled = false;
                
            } catch (error) {
                console.error('Error starting camera:', error);
                alert('Error starting camera: ' + error.message);
            }
        });

        function onResults(results) {
            const canvas = document.getElementById('canvas');
            const ctx = canvas.getContext('2d');
            
            if (canvas.width !== 640 || canvas.height !== 480) {
                canvas.width = 640;
                canvas.height = 480;
            }
            
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            if (results.image) {
                ctx.drawImage(results.image, 0, 0, canvas.width, canvas.height);
            }

            if (results.multiFaceLandmarks && results.multiFaceLandmarks.length > 0) {
                const landmarks = results.multiFaceLandmarks[0];
                
                // Dibujar landmarks
                ctx.fillStyle = '#FF0000';
                landmarks.forEach(landmark => {
                    const x = landmark.x * canvas.width;
                    const y = landmark.y * canvas.height;
                    ctx.beginPath();
                    ctx.arc(x, y, 2, 0, 2 * Math.PI);
                    ctx.fill();
                });
            }
        }

        document.getElementById('captureBtn').addEventListener('click', () => {
            const video = document.getElementById('video');
            faceMesh.send({image: video}).then(results => {
                if (results.multiFaceLandmarks && results.multiFaceLandmarks.length > 0) {
                    const landmarks = results.multiFaceLandmarks[0];
                    
                    capturedData = {
                        landmarks: landmarks.map(landmark => ({
                            x: landmark.x,
                            y: landmark.y,
                            z: landmark.z || 0
                        })),
                        quality_metrics: {
                            quality_score: 85,
                            lighting_score: 80,
                            stability_score: 90
                        },
                        timestamp: Date.now()
                    };
                    
                    document.getElementById('facialData').value = JSON.stringify(capturedData);
                    document.getElementById('faceForm').style.display = 'block';
                    alert('Face captured successfully! Click "Save Face Data" to update.');
                } else {
                    alert('No face detected. Please try again.');
                }
            });
        });
    </script>
</body>
</html>