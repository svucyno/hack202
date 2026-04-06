<?php
require_once __DIR__ . '/config.php';
requireAuth('admin');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get_dashboard': getAdminDashboard(); break;
    case 'get_doctors': getAllDoctors(); break;
    case 'get_patients': getAllPatients(); break;
    case 'get_appointments': getAllAppointments(); break;
    case 'override_doctor_status': overrideDoctorStatus(); break;
    case 'get_logs': getActivityLogs(); break;
    case 'add_doctor': addDoctor(); break;
    case 'toggle_user': toggleUser(); break;
    case 'get_stats': getStats(); break;
    case 'add_lab_report': addLabReport(); break;
    case 'get_notifications_all': getAllNotifications(); break;
    default: jsonResponse(['success' => false, 'message' => 'Invalid action']);
}

function getAdminDashboard() {
    $db = getDB();

    $counts = [];
    $tables = ['users' => 'total_users', 'patients' => 'total_patients', 'doctors' => 'total_doctors', 'appointments' => 'total_appointments'];
    foreach ($tables as $table => $key) {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM $table");
        $counts[$key] = $stmt->fetch()['cnt'];
    }

    $stmt = $db->query("SELECT COUNT(*) as cnt FROM appointments WHERE appointment_date = CURDATE()");
    $counts['today_appointments'] = $stmt->fetch()['cnt'];

    $stmt = $db->query("SELECT COUNT(*) as cnt FROM prescriptions WHERE DATE(created_at) = CURDATE()");
    $counts['today_prescriptions'] = $stmt->fetch()['cnt'];

    // Doctor statuses
    $stmt = $db->query("
        SELECT d.id, u.name, ds.status, ds.status_note, ds.updated_at, d.specialization, d.department
        FROM doctors d JOIN users u ON d.user_id = u.id
        LEFT JOIN doctor_status ds ON d.id = ds.doctor_id
        WHERE u.is_active = 1
    ");
    $doctorStatuses = $stmt->fetchAll();

    // Recent appointments
    $stmt = $db->query("
        SELECT a.*, pu.name as patient_name, du.name as doctor_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id JOIN users pu ON p.user_id = pu.id
        JOIN doctors d ON a.doctor_id = d.id JOIN users du ON d.user_id = du.id
        ORDER BY a.created_at DESC LIMIT 10
    ");
    $recentAppointments = $stmt->fetchAll();

    // Recent logs
    $stmt = $db->query("SELECT al.*, u.name FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 15");
    $recentLogs = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'data' => [
            ...$counts,
            'doctor_statuses' => $doctorStatuses,
            'recent_appointments' => $recentAppointments,
            'recent_logs' => $recentLogs
        ]
    ]);
}

function getAllDoctors() {
    $db = getDB();
    $stmt = $db->query("
        SELECT d.*, u.name, u.email, u.phone, u.is_active, u.created_at as joined,
            ds.status as current_status, ds.status_note, ds.updated_at as status_updated,
            (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id = d.id) as total_appointments
        FROM doctors d JOIN users u ON d.user_id = u.id
        LEFT JOIN doctor_status ds ON d.id = ds.doctor_id
        ORDER BY u.name
    ");
    jsonResponse(['success' => true, 'doctors' => $stmt->fetchAll()]);
}

function getAllPatients() {
    $db = getDB();
    $stmt = $db->query("
        SELECT p.*, u.name, u.email, u.phone, u.is_active, u.created_at as registered,
            (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.id) as total_appointments
        FROM patients p JOIN users u ON p.user_id = u.id
        ORDER BY u.name
    ");
    jsonResponse(['success' => true, 'patients' => $stmt->fetchAll()]);
}

function getAllAppointments() {
    $db = getDB();
    $filter = $_GET['filter'] ?? 'all';
    $date = $_GET['date'] ?? '';

    $where = '';
    $params = [];
    if ($filter === 'today') { $where = "WHERE a.appointment_date = CURDATE()"; }
    elseif ($filter === 'upcoming') { $where = "WHERE a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed')"; }
    elseif ($date) { $where = "WHERE a.appointment_date = ?"; $params[] = $date; }

    $stmt = $db->prepare("
        SELECT a.*, pu.name as patient_name, p.patient_code, du.name as doctor_name, d.specialization
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id JOIN users pu ON p.user_id = pu.id
        JOIN doctors d ON a.doctor_id = d.id JOIN users du ON d.user_id = du.id
        $where ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 100
    ");
    $stmt->execute($params);
    jsonResponse(['success' => true, 'appointments' => $stmt->fetchAll()]);
}

function overrideDoctorStatus() {
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $status = sanitize($_POST['status'] ?? '');
    $note = sanitize($_POST['note'] ?? '');
    $validStatuses = ['available', 'busy', 'emergency', 'offline'];

    if (!$doctorId || !in_array($status, $validStatuses)) {
        jsonResponse(['success' => false, 'message' => 'Invalid data']);
    }

    $db = getDB();
    $result = $db->prepare("UPDATE doctor_status SET status = ?, status_note = ?, admin_override = 1, updated_at = NOW() WHERE doctor_id = ?");
    $result->execute([$status, "Admin override: {$note}", $doctorId]);

    if ($status === 'emergency') {
        // Load doctor function from doctor_api scope
        $stmt = $db->prepare("
            SELECT a.*, u.name as patient_name, u.email, u.phone, pu.id as user_id
            FROM appointments a
            JOIN patients p ON a.patient_id = p.id
            JOIN users u ON p.user_id = u.id
            JOIN users pu ON p.user_id = pu.id
            WHERE a.doctor_id = ? AND a.appointment_date = CURDATE()
            AND a.status IN ('pending','confirmed')
        ");
        $stmt->execute([$doctorId]);
        $appointments = $stmt->fetchAll();
        $stmtD = $db->prepare("SELECT u.name FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.id = ?");
        $stmtD->execute([$doctorId]);
        $doctor = $stmtD->fetch();

        foreach ($appointments as $appt) {
            $db->prepare("UPDATE appointments SET status = 'postponed', postpone_reason = 'Doctor unavailable - Admin declared emergency' WHERE id = ?")->execute([$appt['id']]);
            sendNotification($appt['user_id'], '⚠️ Appointment Postponed',
                "Your appointment with Dr. {$doctor['name']} has been postponed due to an emergency.", 'emergency');
            $emailBody = "<h2 style='color:red'>Appointment Postponed</h2><p>Dear {$appt['patient_name']},</p><p>Your appointment with Dr. {$doctor['name']} has been postponed due to an emergency. We apologize for the inconvenience.</p>";
            sendEmail($appt['email'], 'Appointment Postponed - MedCare Hospital', $emailBody);
        }
    }

    logActivity($_SESSION['user_id'], "Admin override doctor status to {$status}", 'admin', "Doctor ID: {$doctorId}");
    jsonResponse(['success' => true, 'message' => 'Status overridden successfully']);
}

function getActivityLogs() {
    $db = getDB();
    $limit = (int)($_GET['limit'] ?? 50);
    $module = sanitize($_GET['module'] ?? '');
    $where = $module ? "WHERE al.module = ?" : '';
    $params = $module ? [$module] : [];
    $stmt = $db->prepare("SELECT al.*, u.name, u.role FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id $where ORDER BY al.created_at DESC LIMIT $limit");
    $stmt->execute($params);
    jsonResponse(['success' => true, 'logs' => $stmt->fetchAll()]);
}

function addDoctor() {
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? 'Doctor@123';
    $specialization = sanitize($_POST['specialization'] ?? '');
    $department = sanitize($_POST['department'] ?? '');
    $qualification = sanitize($_POST['qualification'] ?? '');
    $room = sanitize($_POST['room_number'] ?? '');
    $fee = (float)($_POST['consultation_fee'] ?? 0);
    $exp = (int)($_POST['experience_years'] ?? 0);

    if (empty($name) || empty($email) || empty($phone)) {
        jsonResponse(['success' => false, 'message' => 'Name, email, phone required']);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
    $stmt->execute([$email, $phone]);
    if ($stmt->fetch()) jsonResponse(['success' => false, 'message' => 'Email or phone already exists']);

    $db->beginTransaction();
    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?,?,?,?,'doctor')");
        $stmt->execute([$name, $email, $phone, $hashed]);
        $userId = $db->lastInsertId();

        $code = 'DOC' . str_pad($userId, 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("INSERT INTO doctors (user_id, doctor_code, specialization, qualification, department, room_number, consultation_fee, experience_years) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$userId, $code, $specialization, $qualification, $department, $room, $fee, $exp]);
        $docId = $db->lastInsertId();

        $db->prepare("INSERT INTO doctor_status (doctor_id, status) VALUES (?, 'available')")->execute([$docId]);
        $db->commit();

        $emailBody = "<h2>Welcome to MedCare Hospital</h2><p>Dear Dr. {$name},</p><p>Your doctor account has been created.</p><p><strong>Email:</strong> {$email}<br><strong>Password:</strong> {$password}<br><strong>Doctor Code:</strong> {$code}</p>";
        sendEmail($email, 'Doctor Account Created - MedCare Hospital', $emailBody);

        logActivity($_SESSION['user_id'], 'Doctor added', 'admin', "Code: {$code}");
        jsonResponse(['success' => true, 'message' => 'Doctor added successfully', 'code' => $code]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

function toggleUser() {
    $userId = (int)($_POST['user_id'] ?? 0);
    $active = (int)($_POST['active'] ?? 1);
    $db = getDB();
    $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$active, $userId]);
    logActivity($_SESSION['user_id'], ($active ? 'User activated' : 'User deactivated'), 'admin', "User ID: {$userId}");
    jsonResponse(['success' => true, 'message' => 'User status updated']);
}

function getStats() {
    $db = getDB();
    $stmt = $db->query("SELECT appointment_date, COUNT(*) as count FROM appointments WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY appointment_date ORDER BY appointment_date");
    $weekly = $stmt->fetchAll();
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM appointments GROUP BY status");
    $byStatus = $stmt->fetchAll();
    $stmt = $db->query("SELECT u.name, d.specialization, COUNT(a.id) as total FROM doctors d JOIN users u ON d.user_id = u.id LEFT JOIN appointments a ON d.id = a.doctor_id GROUP BY d.id ORDER BY total DESC");
    $byDoctor = $stmt->fetchAll();
    jsonResponse(['success' => true, 'weekly' => $weekly, 'by_status' => $byStatus, 'by_doctor' => $byDoctor]);
}

function addLabReport() {
    $patientId = (int)($_POST['patient_id'] ?? 0);
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $testName = sanitize($_POST['test_name'] ?? '');
    $category = sanitize($_POST['category'] ?? '');
    $result = sanitize($_POST['result'] ?? '');
    $normalRange = sanitize($_POST['normal_range'] ?? '');
    $remarks = sanitize($_POST['remarks'] ?? '');
    $technician = sanitize($_POST['technician'] ?? '');

    if (!$patientId || empty($testName)) jsonResponse(['success' => false, 'message' => 'Patient and test name required']);

    $db = getDB();
    $code = 'LAB' . strtoupper(substr(uniqid(), -6));
    $stmt = $db->prepare("INSERT INTO lab_reports (report_code, patient_id, doctor_id, test_name, test_category, result, normal_range, status, technician_name, report_date, remarks) VALUES (?,?,?,?,?,?,?,'completed',?,CURDATE(),?)");
    $stmt->execute([$code, $patientId, $doctorId ?: null, $testName, $category, $result, $normalRange, $technician, $remarks]);

    // Notify patient
    $stmt = $db->prepare("SELECT u.id FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $stmt->execute([$patientId]);
    $pUser = $stmt->fetch();
    if ($pUser) {
        sendNotification($pUser['id'], 'Lab Report Ready', "Your lab report '{$testName}' is ready. Please login to view.", 'lab_report');
    }

    jsonResponse(['success' => true, 'message' => 'Lab report added', 'code' => $code]);
}

function getAllNotifications() {
    $db = getDB();
    $stmt = $db->query("SELECT n.*, u.name, u.role FROM notifications n JOIN users u ON n.user_id = u.id ORDER BY n.created_at DESC LIMIT 50");
    jsonResponse(['success' => true, 'notifications' => $stmt->fetchAll()]);
}
?>
