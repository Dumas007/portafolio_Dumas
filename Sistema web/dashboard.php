<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

// Si el usuario es admin, redirigir al dashboard de admin
if($_SESSION['user_role'] == 'Admin') {
    header("Location: admin/dashboard.php");
    exit();
}
// Si el usuario es Sales, redirigir al dashboard de admin
if($_SESSION['user_role'] == 'dep_sales') {
    header("Location: sales/dashboard.php");
    exit();
}
include_once 'config.php';
include_once 'User.php';

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

// Obtener información del usuario actual
$user->email = $_SESSION['user_email'];
$user_data = null;

// Obtener datos del usuario actual
$query = "SELECT * FROM users WHERE email = :email";
$stmt = $db->prepare($query);
$stmt->bindParam(":email", $_SESSION['user_email']);
$stmt->execute();
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Obtener todos los usuarios para HR
$all_users = [];
if($_SESSION['user_role'] == 'HR') {
    $query_all = "SELECT id, number, name, email, role, active, registration_date FROM users ORDER BY id DESC";
    $stmt_all = $db->prepare($query_all);
    $stmt_all->execute();
    $all_users = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Usuario</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 20px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .welcome { background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745; }
        .info { background: #d1ecf1; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8; }
        .hr-section { background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107; }
        .logout { background: #dc3545; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px; font-weight: bold; }
        .btn { background: #007bff; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px; font-weight: bold; }
        .btn-success { background: #28a745; }
        .btn:hover { background: #0056b3; }
        .btn-success:hover { background: #218838; }
        .logout:hover { background: #c82333; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; background: white; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #343a40; color: white; }
        tr:hover { background-color: #f8f9fa; }
        .status-active { color: #28a745; font-weight: bold; }
        .status-inactive { color: #dc3545; font-weight: bold; }
        .facial-configured { color: #28a745; }
        .facial-not-configured { color: #dc3545; }
    </style>
</head>
<body>
    <div class="container">
        <div class="welcome">
            <h2>¡Bienvenido, <?php echo htmlspecialchars($_SESSION['user_name']); ?>! 👋</h2>
            <p><strong>Rol:</strong> <?php echo htmlspecialchars($_SESSION['user_role']); ?></p>
            <p>Has iniciado sesión exitosamente en el sistema de reconocimiento facial.</p>
        </div>
        
        <?php if($user_data): ?>
        <div class="info">
            <h3>📋 Información de tu cuenta:</h3>
            <p><strong>Número:</strong> <?php echo htmlspecialchars($user_data['number']); ?></p>
            <p><strong>Nombre:</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['user_email']); ?></p>
            <p><strong>Rol:</strong> <?php echo htmlspecialchars($_SESSION['user_role']); ?></p>
            <p><strong>ID de usuario:</strong> <?php echo $_SESSION['user_id']; ?></p>
            <p><strong>Fecha de acceso:</strong> <?php echo date('d/m/Y H:i:s'); ?></p>
            
            <?php if(!empty($user_data['facial_data']) && $user_data['facial_data'] !== 'NULL'): ?>
                <p><strong>Reconocimiento facial:</strong> <span class="facial-configured">✅ Configurado</span></p>
            <?php else: ?>
                <p><strong>Reconocimiento facial:</strong> <span class="facial-not-configured">❌ No configurado</span></p>
                <a href="add_face.php" class="btn btn-success">⚙️ Configurar reconocimiento facial</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if($_SESSION['user_role'] == 'HR'): ?>
        <div class="hr-section">
            <h3>👥 Panel de Recursos Humanos</h3>
            <?php if(count($all_users) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Número</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Fecha Registro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($all_users as $user_row): ?>
                        <tr>
                            <td><?php echo $user_row['id']; ?></td>
                            <td><?php echo htmlspecialchars($user_row['number']); ?></td>
                            <td><?php echo htmlspecialchars($user_row['name']); ?></td>
                            <td><?php echo htmlspecialchars($user_row['email']); ?></td>
                            <td><?php echo htmlspecialchars($user_row['role']); ?></td>
                            <td>
                                <?php if($user_row['active'] == 'yes'): ?>
                                    <span class="status-active">✅ Activo</span>
                                <?php else: ?>
                                    <span class="status-inactive">❌ Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($user_row['registration_date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No hay usuarios registrados en el sistema.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="margin-top: 30px; text-align: center;">
            <?php if($user_data && (empty($user_data['facial_data']) || $user_data['facial_data'] === 'NULL')): ?>
                <a href="add_face.php" class="btn btn-success">👤 Agregar Reconocimiento Facial</a>
            <?php endif; ?>
            <a href="logout.php" class="logout">🚪 Cerrar Sesión</a>
        </div>
    </div>
</body>
</html>