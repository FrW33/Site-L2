<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Content-Type: application/json; charset=UTF-8");

// ======================================================================
// CONFIGURACIÓN GENERAL
// ======================================================================
$LOGIN_IP   = "192.168.100.2"; 
$LOGIN_PORT = 2106;
$GAME_IP    = "192.168.100.2";
$GAME_PORT  = 7777;

$dbHost = "127.0.0.1";
$dbName = "l2journey";
$dbUser = "root";
$dbPass = "4612990";

// ======================================================================
// CONFIGURACIÓN DE SEGURIDAD (ANTI-SPAM)
// ======================================================================
$MAX_ACCOUNTS_PER_IP = 10; // Máximo total de cuentas permitidas por cada IP
$COOLDOWN_MINUTES    = 1;  // Minutos que deben esperar entre la creación de cada cuenta

// Función para obtener la IP real del usuario (incluso si usas Cloudflare)
function getUserIP() {
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) return $_SERVER["HTTP_CF_CONNECTING_IP"];
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ======================================================================
// SECCIÓN 1: CREADOR DE CUENTAS (Se activa solo cuando usas el botón)
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    
    $account = trim($_POST['account'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $userIP = getUserIP();

    // Validar que no nos manden cosas vacías o cortas
    if (strlen($account) < 4 || strlen($account) > 14) {
        echo json_encode(['success' => false, 'message' => 'La cuenta debe tener entre 4 y 14 caracteres.']);
        exit;
    }
    if (strlen($password) < 4 || strlen($password) > 16) {
        echo json_encode(['success' => false, 'message' => 'La contraseña debe tener entre 4 y 16 caracteres.']);
        exit;
    }

    try {
        // Conectamos a la BD para el registro
        $pdoReg = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass);
        $pdoReg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 1. Crear tabla de control de IPs si no existe (Automático)
        $pdoReg->exec("CREATE TABLE IF NOT EXISTS web_registrations (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            ip VARCHAR(45) NOT NULL, 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 2. Verificar Límite Máximo por IP
        $stmtLimit = $pdoReg->prepare("SELECT COUNT(*) FROM web_registrations WHERE ip = ?");
        $stmtLimit->execute([$userIP]);
        $totalAccounts = $stmtLimit->fetchColumn();
        
        if ($totalAccounts >= $MAX_ACCOUNTS_PER_IP) {
            echo json_encode(['success' => false, 'message' => "Esta IP ya alcanzó el límite máximo de $MAX_ACCOUNTS_PER_IP cuentas."]);
            exit;
        }

        // 3. Verificar Tiempo de Espera (Cooldown)
        $stmtCooldown = $pdoReg->prepare("SELECT COUNT(*) FROM web_registrations WHERE ip = ? AND created_at >= NOW() - INTERVAL ? MINUTE");
        $stmtCooldown->execute([$userIP, $COOLDOWN_MINUTES]);
        $recentAccounts = $stmtCooldown->fetchColumn();

        if ($recentAccounts > 0) {
            echo json_encode(['success' => false, 'message' => "Por favor espera $COOLDOWN_MINUTES minuto(s) antes de crear otra cuenta."]);
            exit;
        }

        // 4. Chequear si la cuenta ya existe en l2journey
        $stmt = $pdoReg->prepare("SELECT login FROM accounts WHERE login = ?");
        $stmt->execute([$account]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Ese nombre de cuenta ya está en uso.']);
            exit;
        }

        // 5. Encriptar password estilo Mobius (Base64 + SHA1 Binario)
        $hashedPassword = base64_encode(hash('sha1', $password, true));

        // 6. Insertar la nueva cuenta en la BD
        $stmt = $pdoReg->prepare("INSERT INTO accounts (login, password, email, accessLevel) VALUES (?, ?, ?, 0)");
        
        if ($stmt->execute([$account, $hashedPassword, $email])) {
            // Si la cuenta se creó bien, registramos la IP para el bloqueo de tiempo
            $stmtLog = $pdoReg->prepare("INSERT INTO web_registrations (ip) VALUES (?)");
            $stmtLog->execute([$userIP]);

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al guardar en la Base de Datos.']);
        }
    } catch (PDOException $e) {
        // Mensaje genérico para no exponer errores de MySQL al usuario
        echo json_encode(['success' => false, 'message' => 'Falla de conexión interna con la BD.']);
    }
    
    // Matamos la ejecución aquí para que no mande el estado del servidor abajo
    exit; 
}


// ======================================================================
// SECCIÓN 2: SERVER STATUS (Se activa cuando se carga la web)
// ======================================================================

$loginOnline = false;
$gameOnline = false;

// CHECK DE PUERTOS
if ($socketLogin = @fsockopen($LOGIN_IP, $LOGIN_PORT, $errno, $errstr, 1)) {
    $loginOnline = true;
    fclose($socketLogin);
}
if ($socketGame = @fsockopen($GAME_IP, $GAME_PORT, $errno, $errstr, 1)) {
    $gameOnline = true;
    fclose($socketGame);
}

$serverStatus = ($gameOnline && $loginOnline) ? "Online" : "Offline";

// FUNCIÓN FORMATEADORA CORREGIDA
function formatRemainingTime($raw_data) {
    if (empty($raw_data) || $raw_data == 0) return "No iniciado";
    
    $timestamp = $raw_data;

    if (!is_numeric($timestamp) && strtotime($timestamp) !== false) {
        $timestamp = strtotime($timestamp);
    } else {
        $timestamp = sprintf('%.0f', (float)$timestamp);
        $timestamp = preg_replace('/[^0-9]/', '', $timestamp);

        if (strlen($timestamp) > 10) {
            $timestamp = floor($timestamp / 1000);
        }
    }
    
    // Calculamos directamente contra la fecha real
    $remaining = $timestamp - time();
    
    if ($remaining <= 0) {
        return "Validación / Finalizado"; 
    }

    $days = floor($remaining / 86400);
    $hours = floor(($remaining % 86400) / 3600);
    $minutes = floor(($remaining % 3600) / 60);

    return "{$days}d {$hours}h {$minutes}m";
}

$olympiadTime = "Error DB";
$sevenSignsTime = "Error DB";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true); 

    // LECTURA OLYMPIADAS
    try {
        $stmt = $pdo->query("SELECT olympiad_end FROM olympiad_data LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $olympiadTime = $row ? formatRemainingTime($row['olympiad_end']) : "Sin datos";
    } catch (Exception $e) {
        $olympiadTime = "Error Tabla";
    }

    // LECTURA 7 SIGNOS (Método Infalible Web)
    try {
        $now = time();
        
        // Buscamos el lunes de esta semana a las 18:00 hrs
        $nextReset = strtotime("Monday 18:00:00");
        
        // Si el reloj actual ya superó el lunes a las 18:00 hrs de esta semana, 
        // le decimos que apunte al lunes de la semana que viene.
        if ($now >= $nextReset) {
            $nextReset = strtotime("next Monday 18:00:00");
        }
        
        // Le pasamos la fecha perfecta a tu función formateadora
        $sevenSignsTime = formatRemainingTime($nextReset);
        
    } catch (Exception $e) {
        $sevenSignsTime = "Error Calculando";
    }

} catch (Exception $e) {
    // Si la conexión global a la base de datos falla, entra aquí y no detiene el código.
}

echo json_encode([
    "server" => $serverStatus,
    "olympiads_remaining" => $olympiadTime,
    "seven_signs_remaining" => $sevenSignsTime
]);
?>