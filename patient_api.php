<?php
require_once __DIR__ . '/config.php';
requireAuth('patient');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$patientId = $_SESSION['patient_id'] ?? 0;

switch ($action) {
    case 'get_dashboard': getDashboard($patientId); break;
    case 'get_appointments': getAppointments($patientId); break;
    case 'book_appointment': bookAppointment($patientId); break;
    case 'cancel_appointment': cancelAppointment($patientId); break;
    case 'get_lab_reports': getLabReports($patientId); break;
    case 'get_prescriptions': getPrescriptions($patientId); break;
    case 'get_notifications': getNotifications(); break;
    case 'mark_notification_read': markNotificationRead(); break;
    case 'notify_me': notifyMe($patientId); break;
    case 'toggle_privacy': togglePrivacy($patientId); break;
    case 'get_doctors': getDoctors(); break;
    case 'get_profile': getProfile($patientId); break;
    case 'update_profile': updateProfile($patientId); break;
    default: jsonResponse(['success' => false, 'message' => 'Invalid action']);
}

function getDashboard($patientId) {
    $db = getDB();
    $userId = $_SESSION['user_id'];

    // Summary counts
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE patient_id = ? AND status IN ('pending','confirmed')");
    $stmt->execute([$patientId]);
    $upcomingAppts = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM lab_reports WHERE patient_id = ? AND status = 'completed'");
    $stmt->execute([$patientId]);
    $labReports = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM prescriptions WHERE patient_id = ? AND status = 'active'");
    $stmt->execute([$patientId]);
    $activePrescriptions = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadNotifications = $stmt->fetch()['cnt'];

    // Today's appointments
    $stmt = $db->prepare("
        SELECT a.*, u.name as doctor_name, d.specialization, d.room_number, ds.status as doctor_status
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        LEFT JOIN doctor_status ds ON d.id = ds.doctor_id
        WHERE a.patient_id = ? AND a.appointment_date = CURDATE()
        ORDER BY a.appointment_time
    ");
    $stmt->execute([$patientId]);
    $todayAppointments = $stmt->fetchAll();

    // Recent notifications
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'data' => [
            'upcoming_appointments' => $upcomingAppts,
            'lab_reports' => $labReports,
            'active_prescriptions' => $activePrescriptions,
            'unread_notifications' => $unreadNotifications,
            'today_appointments' => $todayAppointments,
            'notifications' => $notifications
        ]
    ]);
}

function getAppointments($patientId) {
    $db = getDB();
    $filter = $_GET['filter'] ?? 'all';

    $where = "WHERE a.patient_id = ?";
    if ($filter === 'upcoming') $where .= " AND a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed')";
    elseif ($filter === 'past') $where .= " AND (a.appointment_date < CURDATE() OR a.status IN ('completed','cancelled'))";

    $stmt = $db->prepare("
        SELECT a.*, u.name as doctor_name, d.specialization, d.department, d.room_number, ds.status as doctor_status
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        LEFT JOIN doctor_status ds ON d.id = ds.doctor_id
        $where ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute([$patientId]);
    jsonResponse(['success' => true, 'appointments' => $stmt->fetchAll()]);
}

function bookAppointment($patientId) {
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $date = sanitize($_POST['date'] ?? '');
    $time = sanitize($_POST['time'] ?? '');
    $type = sanitize($_POST['type'] ?? 'consultation');
    $reason = sanitize($_POST['reason'] ?? '');

    if (!$doctorId || empty($date) || empty($time)) {
        jsonResponse(['success' => false, 'message' => 'Doctor, date, and time required']);
    }

    // Check for conflicts
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status NOT IN ('cancelled','completed')");
    $stmt->execute([$doctorId, $date, $time]);
    if ($stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => 'This slot is already booked. Please choose another time.']);
    }

    $code = 'APT' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);

    $stmt = $db->prepare("INSERT INTO appointments (appointment_code, patient_id, doctor_id, appointment_date, appointment_time, type, reason, status) VALUES (?,?,?,?,?,?,?,'confirmed')");
    $stmt->execute([$code, $patientId, $doctorId, $date, $time, $type, $reason]);
    $apptId = $db->lastInsertId();

    // Get doctor info
    $stmt = $db->prepare("SELECT u.name, u.email, u.phone FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.id = ?");
    $stmt->execute([$doctorId]);
    $doctor = $stmt->fetch();

    // Notify patient
    $userId = $_SESSION['user_id'];
    sendNotification($userId, 'Appointment Confirmed',
        "Your appointment ({$code}) with Dr. {$doctor['name']} on {$date} at {$time} has been confirmed.",
        'appointment');

    // Send email to patient
    $emailBody = "<h2>Appointment Confirmed - MedCare Hospital</h2>
    <p>Dear {$_SESSION['name']},</p>
    <p>Your appointment has been confirmed:</p>
    <ul>
    <li><strong>Doctor:</strong> Dr. {$doctor['name']}</li>
    <li><strong>Date:</strong> {$date}</li>
    <li><strong>Time:</strong> {$time}</li>
    <li><strong>Appointment ID:</strong> {$code}</li>
    </ul>
    <p>Please arrive 15 minutes before your appointment.</p>";
    sendEmail($_SESSION['email'], 'Appointment Confirmed - MedCare Hospital', $emailBody);

    logActivity($userId, 'Appointment booked', 'appointments', "Code: {$code}");
    jsonResponse(['success' => true, 'message' => 'Appointment booked successfully!', 'code' => $code]);
}

function cancelAppointment($patientId) {
    $apptId = (int)($_POST['appointment_id'] ?? 0);
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM appointments WHERE id = ? AND patient_id = ?");
    $stmt->execute([$apptId, $patientId]);
    $appt = $stmt->fetch();

    if (!$appt) jsonResponse(['success' => false, 'message' => 'Appointment not found']);

    $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$apptId]);
    sendNotification($_SESSION['user_id'], 'Appointment Cancelled', "Your appointment {$appt['appointment_code']} has been cancelled.", 'appointment');
    logActivity($_SESSION['user_id'], 'Appointment cancelled', 'appointments', "ID: {$apptId}");
    jsonResponse(['success' => true, 'message' => 'Appointment cancelled']);
}

function getLabReports($patientId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT lr.*, u.name as doctor_name
        FROM lab_reports lr
        LEFT JOIN doctors d ON lr.doctor_id = d.id
        LEFT JOIN users u ON d.user_id = u.id
        WHERE lr.patient_id = ?
        ORDER BY lr.created_at DESC
    ");
    $stmt->execute([$patientId]);
    jsonResponse(['success' => true, 'reports' => $stmt->fetchAll()]);
}

function getPrescriptions($patientId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT p.*, u.name as doctor_name, d.specialization
        FROM prescriptions p
        JOIN doctors doc ON p.doctor_id = doc.id
        JOIN users u ON doc.user_id = u.id
        LEFT JOIN doctors d ON p.doctor_id = d.id
        WHERE p.patient_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$patientId]);
    $prescriptions = $stmt->fetchAll();
    // Decode medicines JSON
    foreach ($prescriptions as &$rx) {
        $rx['medicines'] = json_decode($rx['medicines'], true) ?? [];
    }
    jsonResponse(['success' => true, 'prescriptions' => $prescriptions]);
}

function getNotifications() {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$_SESSION['user_id']]);
    jsonResponse(['success' => true, 'notifications' => $stmt->fetchAll()]);
}

function markNotificationRead() {
    $id = (int)($_POST['id'] ?? 0);
    $db = getDB();
    if ($id === 0) {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$_SESSION['user_id']]);
    } else {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $_SESSION['user_id']]);
    }
    jsonResponse(['success' => true]);
}

function notifyMe($patientId) {
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    if (!$doctorId) jsonResponse(['success' => false, 'message' => 'Doctor ID required']);
    $db = getDB();
    // Remove existing
    $db->prepare("DELETE FROM notify_me_requests WHERE patient_id = ? AND doctor_id = ?")->execute([$patientId, $doctorId]);
    $db->prepare("INSERT INTO notify_me_requests (patient_id, doctor_id) VALUES (?,?)")->execute([$patientId, $doctorId]);
    jsonResponse(['success' => true, 'message' => "You'll be notified when the doctor is available"]);
}

function togglePrivacy($patientId) {
    $allow = (int)($_POST['allow'] ?? 1);
    $db = getDB();
    $db->prepare("UPDATE patients SET privacy_allow_doctor = ? WHERE id = ?")->execute([$allow, $patientId]);
    jsonResponse(['success' => true, 'message' => 'Privacy settings updated']);
}

function getDoctors() {
    $db = getDB();
    $stmt = $db->query("
        SELECT d.id, u.name, d.specialization, d.department, d.room_number, d.consultation_fee, d.experience_years, ds.status as current_status
        FROM doctors d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN doctor_status ds ON d.id = ds.doctor_id
        WHERE u.is_active = 1
    ");
    jsonResponse(['success' => true, 'doctors' => $stmt->fetchAll()]);
}

function getProfile($patientId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.name, u.email, u.phone, p.*
        FROM users u JOIN patients p ON u.id = p.user_id
        WHERE p.id = ?
    ");
    $stmt->execute([$patientId]);
    jsonResponse(['success' => true, 'profile' => $stmt->fetch()]);
}

function updateProfile($patientId) {
    $db = getDB();
    $address = sanitize($_POST['address'] ?? '');
    $emergency = sanitize($_POST['emergency_contact'] ?? '');
    $allergies = sanitize($_POST['allergies'] ?? '');
    $db->prepare("UPDATE patients SET address = ?, emergency_contact = ?, allergies = ? WHERE id = ?")->execute([$address, $emergency, $allergies, $patientId]);
    jsonResponse(['success' => true, 'message' => 'Profile updated']);
}
?>
