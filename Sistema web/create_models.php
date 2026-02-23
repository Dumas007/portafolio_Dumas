<?php
// Script para descargar automáticamente los modelos de face-api.js
echo "Descargando modelos de face-api.js...\n";

// Crear directorio de modelos
if (!is_dir('models')) {
    mkdir('models', 0755, true);
    echo "✓ Carpeta 'models' creada\n";
}

// Lista de archivos a descargar
$files = [
    // Tiny Face Detector
    'tiny_face_detector_model-weights_manifest.json',
    'tiny_face_detector_model-shard1',
    
    // Face Landmark 68 Model
    'face_landmark_68_model-weights_manifest.json', 
    'face_landmark_68_model-shard1',
    
    // Face Recognition Model
    'face_recognition_model-weights_manifest.json',
    'face_recognition_model-shard1',
    'face_recognition_model-shard2'
];

$baseUrl = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/';

foreach ($files as $file) {
    $url = $baseUrl . $file;
    $localPath = 'models/' . $file;
    
    echo "Descargando: $file... ";
    
    $content = @file_get_contents($url);
    if ($content !== false) {
        file_put_contents($localPath, $content);
        echo "✓ OK\n";
    } else {
        echo "✗ ERROR\n";
    }
}

echo "\n¡Descarga completada! Verifica que todos los archivos estén en la carpeta 'models/'\n";
?>