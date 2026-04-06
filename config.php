<?php
// ============================================================
// HOSPITAL MANAGEMENT SYSTEM - CONFIG
// ============================================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hospital_db');
define('BASE_URL', 'http://localhost/hospital');
define('APP_NAME', 'MedCare Hospital');

// Email config (use SMTP - free with Gmail)
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'your_email@gmail.com');
define('MAIL_PASS', 'your_app_password');
define('MAIL_FROM', 'noreply@medcare.com');
define('MAIL_FROM_NAME', 'MedCare Hospital');

// SMS config (Textbelt free: 1 SMS/day free, or Fast2SMS free tier)
define('SMS_API_KEY', 'textbelt'); // Use 'textbelt' for free tier (1/day)
define('SMS_API_URL', 'https://textbelt.com/text');

// Session
define('SESSION_LIFETIME', 3600);

// Timezone
date_default_timezone_set('Asia/Kolkata');

session_start();

// DB Connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );
        } catch (PDOException $e) {
            die(json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// Auth check
function requireAuth($role = null) {
    if (!isset($_SESSION['user_id'])) {
        if (isAjax()) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated', 'redirect' => BASE_URL . '/index.html']);
            exit;
        }
        header('Location: ' . BASE_URL . '/index.html');
        exit;
    }
    if ($role && $_SESSION['role'] !== $role) {
        if (isAjax()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        header('Location: ' . BASE_URL . '/index.html');
        exit;
    }
}

function isAjax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function logActivity($user_id, $action, $module = 'general', $details = '') {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, module, details, ip_address) VALUES (?,?,?,?,?)");
        $stmt->execute([$user_id, $action, $module, $details, $ip]);
    } catch (Exception $e) { /* silent fail */ }
}

function sendNotification($user_id, $title, $message, $type = 'general') {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,?)");
        $stmt->execute([$user_id, $title, $message, $type]);
        return true;
    } catch (Exception $e) { return false; }
}

function sendEmail($to, $subject, $body) {
    // Using mail() as fallback (works on most servers)
    // For production, use PHPMailer with Gmail SMTP
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    try {
        return mail($to, $subject, $body, $headers);
    } catch (Exception $e) { return false; }
}

function sendSMS($phone, $message) {
    // Textbelt free API (1 SMS/day free)
    try {
        $data = http_build_query([
            'phone' => '+91' . $phone,
            'message' => $message,
            'key' => SMS_API_KEY
        ]);
        $opts = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => $data]];
        $context = stream_context_create($opts);
        $result = file_get_contents(SMS_API_URL, false, $context);
        return $result !== false;
    } catch (Exception $e) { return false; }
}

function generateCode($prefix, $table, $column) {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM $table");
    $cnt = $stmt->fetch()['cnt'] + 1;
    return $prefix . str_pad($cnt, 4, '0', STR_PAD_LEFT);
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// CORS for API
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
?>
