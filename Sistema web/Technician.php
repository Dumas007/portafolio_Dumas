<?php
class Technician {
    public $id;
    public $name;
    public $email;
    public $role;
    public $phone;
    public $active;
    public $facial_data;
    
    private $conn;
    private $table_name = "technicians";

    public function __construct($db) {
        $this->conn = $db;
    }

    // ---------------------------------------------------------
    // LOGIN CON CONTRASEÑA EN TEXTO PLANO
    // ---------------------------------------------------------
    public function loginWithPassword($email, $password) {
        try {
            $query = "SELECT id, name, email, password, role, phone, active, facial_data 
                     FROM " . $this->table_name . " 
                     WHERE email = :email AND active = 'yes'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Verificar contraseña en texto plano
                if ($password === $row['password']) {
                    $this->id = $row['id'];
                    $this->name = $row['name'];
                    $this->email = $row['email'];
                    $this->role = $row['role'];
                    $this->phone = $row['phone'];
                    $this->active = $row['active'];
                    $this->facial_data = $row['facial_data'];
                    return true;
                }
            }
            return false;

        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return false;
        }
    }

    // ---------------------------------------------------------
    // LOGIN FACIAL
    // ---------------------------------------------------------
  public function loginWithFace($email, $facial_data) {
    try {
        // 1. Buscar técnico por email
        $query = "SELECT id, name, email, role, phone, active, facial_data 
                  FROM " . $this->table_name . " 
                  WHERE email = :email AND active = 'yes'";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":email", $email);
        $stmt->execute();

        if ($stmt->rowCount() !== 1) {
            return false;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Convertir datos faciales a vectores numéricos
        $storedVector = json_decode($row['facial_data'], true);
        $inputVector = json_decode($facial_data, true);

        if (!is_array($storedVector) || !is_array($inputVector)) {
            return false;
        }

        // ✅ SOLUCIÓN: Aplanar vectores si vienen como array dentro de array
        if (isset($storedVector[0]) && is_array($storedVector[0])) {
            $storedVector = $storedVector[0];
        }

        if (isset($inputVector[0]) && is_array($inputVector[0])) {
            $inputVector = $inputVector[0];
        }

        // Validar que tengan el mismo tamaño
        if (count($storedVector) !== count($inputVector)) {
            return false;
        }

        // 3. Calcular distancia euclidiana
        $distance = 0;
        for ($i = 0; $i < count($storedVector); $i++) {
            $distance += pow(floatval($storedVector[$i]) - floatval($inputVector[$i]), 2);
        }
        $distance = sqrt($distance);

        // 4. Umbral de verificación (seguro y preciso)
        $threshold = 0.55;

        if ($distance <= $threshold) {

            // Login exitoso
            $this->id = $row['id'];
            $this->name = $row['name'];
            $this->email = $row['email'];
            $this->role = $row['role'];
            $this->phone = $row['phone'];
            $this->active = $row['active'];
            $this->facial_data = $row['facial_data'];

            return true;
        }

        return false;

    } catch (Exception $e) {
        error_log("Facial login error: " . $e->getMessage());
        return false;
    }
}

    // ---------------------------------------------------------
    // NUEVO: OBTENER USUARIO POR ID
    // ---------------------------------------------------------
    public function getUserById($id) {
        try {
            $query = "SELECT id, name, email, role, phone, active, facial_data 
                      FROM " . $this->table_name . " 
                      WHERE id = :id LIMIT 1";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() == 1) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                // Actualizar propiedades
                $this->id = $row['id'];
                $this->name = $row['name'];
                $this->email = $row['email'];
                $this->role = $row['role'];
                $this->phone = $row['phone'];
                $this->active = $row['active'];
                $this->facial_data = $row['facial_data'];

                return $row;
            }

            return false;

        } catch (Exception $e) {
            error_log("Get user by ID error: " . $e->getMessage());
            return false;
        }
    }

    // ---------------------------------------------------------
    // VALIDACIONES
    // ---------------------------------------------------------
    public function emailExists($email) {
        try {
            $query = "SELECT id FROM " . $this->table_name . " WHERE email = :email";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Email exists check error: " . $e->getMessage());
            return false;
        }
    }

    public function isActive() {
        return $this->active === 'yes';
    }

    public function hasFacialData() {
        return !empty($this->facial_data);
    }
}
?>
