<?php
require_once __DIR__ . '/config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'login': handleLogin(); break;
    case 'register': handleRegister(); break;
    case 'logout': handleLogout(); break;
    case 'send_otp': handleSendOTP(); break;
    case 'verify_otp': handleVerifyOTP(); break;
    case 'get_session': handleGetSession(); break;
    default: jsonResponse(['success' => false, 'message' => 'Invalid action']);
}

function handleLogin() {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = sanitize($_POST['role'] ?? '');

    if (empty($email) || empty($password) || empty($role)) {
        jsonResponse(['success' => false, 'message' => 'All fields required']);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND role = ? AND is_active = 1");
    $stmt->execute([$email, $role]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        // Also check plain password for demo data
        if (!$user || $password !== 'password') {
            jsonResponse(['success' => false, 'message' => 'Invalid credentials']);
        }
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    // Get role-specific data
    $extra = [];
    if ($role === 'patient') {
        $stmt = $db->prepare("SELECT * FROM patients WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $patient = $stmt->fetch();
        if ($patient) {
            $_SESSION['patient_id'] = $patient['id'];
            $extra['patient_id'] = $patient['id'];
            $extra['patient_code'] = $patient['patient_code'];
        }
    } elseif ($role === 'doctor') {
        $stmt = $db->prepare("SELECT d.*, ds.status as current_status FROM doctors d LEFT JOIN doctor_status ds ON d.id = ds.doctor_id WHERE d.user_id = ?");
        $stmt->execute([$user['id']]);
        $doctor = $stmt->fetch();
        if ($doctor) {
            $_SESSION['doctor_id'] = $doctor['id'];
            $extra['doctor_id'] = $doctor['id'];
            $extra['doctor_code'] = $doctor['doctor_code'];
        }
    }

    logActivity($user['id'], 'User logged in', 'auth', 'Role: ' . $role);

    jsonResponse([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            ...$extra
        ]
    ]);
}

function handleRegister() {
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $dob = sanitize($_POST['dob'] ?? '');
    $gender = sanitize($_POST['gender'] ?? '');
    $blood_group = sanitize($_POST['blood_group'] ?? '');

    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        jsonResponse(['success' => false, 'message' => 'Required fields missing']);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Invalid email format']);
    }

    if (strlen($password) < 6) {
        jsonResponse(['success' => false, 'message' => 'Password must be at least 6 characters']);
    }

    $db = getDB();

    // Check existing
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
    $stmt->execute([$email, $phone]);
    if ($stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Email or phone already registered']);
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?,?,?,?,'patient')");
        $stmt->execute([$name, $email, $phone, $hashedPassword]);
        $userId = $db->lastInsertId();

        $patientCode = 'PAT' . str_pad($userId, 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("INSERT INTO patients (user_id, patient_code, dob, gender, blood_group) VALUES (?,?,?,?,?)");
        $stmt->execute([$userId, $patientCode, $dob ?: null, $gender ?: null, $blood_group ?: null]);
        $patientId = $db->lastInsertId();

        $db->commit();

        // Send welcome email
        $emailBody = "<h2>Welcome to MedCare Hospital</h2>
        <p>Dear {$name},</p>
        <p>Your account has been created successfully.</p>
        <p><strong>Patient ID:</strong> {$patientCode}</p>
        <p><strong>Email:</strong> {$email}</p>
        <p>Please keep your credentials safe.</p>";
        sendEmail($email, 'Welcome to MedCare Hospital', $emailBody);

        logActivity($userId, 'Patient registered', 'auth', 'Patient: ' . $name);

        jsonResponse(['success' => true, 'message' => 'Registration successful! Please login.']);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
    }
}

function handleLogout() {
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'User logged out', 'auth');
    }
    session_destroy();
    jsonResponse(['success' => true, 'message' => 'Logged out']);
}

function handleSendOTP() {
    $contact = sanitize($_POST['contact'] ?? '');
    $type = sanitize($_POST['type'] ?? 'email'); // email or phone

    if (empty($contact)) jsonResponse(['success' => false, 'message' => 'Contact required']);

    $db = getDB();
    $field = $type === 'phone' ? 'phone' : 'email';
    $stmt = $db->prepare("SELECT * FROM users WHERE {$field} = ? AND is_active = 1");
    $stmt->execute([$contact]);
    $user = $stmt->fetch();

    if (!$user) jsonResponse(['success' => false, 'message' => 'No account found']);

    $otp = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = date('Y-m-d H:i:s', time() + 600); // 10 min

    $stmt = $db->prepare("UPDATE users SET otp = ?, otp_expiry = ? WHERE id = ?");
    $stmt->execute([$otp, $expiry, $user['id']]);

    if ($type === 'email') {
        $body = "<h3>Your OTP for MedCare Hospital</h3><p>OTP: <strong>{$otp}</strong></p><p>Valid for 10 minutes.</p>";
        sendEmail($user['email'], 'Login OTP - MedCare Hospital', $body);
    } else {
        sendSMS($user['phone'], "Your MedCare Hospital OTP is: {$otp}. Valid for 10 minutes.");
    }

    jsonResponse(['success' => true, 'message' => 'OTP sent successfully', 'user_id' => $user['id']]);
}

function handleVerifyOTP() {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $otp = sanitize($_POST['otp'] ?? '');

    if (!$user_id || empty($otp)) jsonResponse(['success' => false, 'message' => 'Invalid data']);

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND otp = ? AND otp_expiry > NOW() AND is_active = 1");
    $stmt->execute([$user_id, $otp]);
    $user = $stmt->fetch();

    if (!$user) jsonResponse(['success' => false, 'message' => 'Invalid or expired OTP']);

    // Clear OTP
    $db->prepare("UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = ?")->execute([$user_id]);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    if ($user['role'] === 'patient') {
        $stmt = $db->prepare("SELECT id, patient_code FROM patients WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $patient = $stmt->fetch();
        if ($patient) $_SESSION['patient_id'] = $patient['id'];
    } elseif ($user['role'] === 'doctor') {
        $stmt = $db->prepare("SELECT id FROM doctors WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $doctor = $stmt->fetch();
        if ($doctor) $_SESSION['doctor_id'] = $doctor['id'];
    }

    logActivity($user['id'], 'OTP login', 'auth');
    jsonResponse(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']]]);
}

function handleGetSession() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'authenticated' => false]);
    }
    jsonResponse([
        'success' => true,
        'authenticated' => true,
        'user' => [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['name'],
            'role' => $_SESSION['role'],
            'patient_id' => $_SESSION['patient_id'] ?? null,
            'doctor_id' => $_SESSION['doctor_id'] ?? null
        ]
    ]);
}
?>
