<?php
require_once __DIR__ . '/config.php';
requireAuth('doctor');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$doctorId = $_SESSION['doctor_id'] ?? 0;

switch ($action) {
    case 'get_dashboard': getDoctorDashboard($doctorId); break;
    case 'get_patients': getPatients($doctorId); break;
    case 'search_patient': searchPatient($doctorId); break;
    case 'get_patient_detail': getPatientDetail($doctorId); break;
    case 'get_appointments': getDoctorAppointments($doctorId); break;
    case 'update_appointment': updateAppointment($doctorId); break;
    case 'write_prescription': writePrescription($doctorId); break;
    case 'update_status': updateDoctorStatus($doctorId); break;
    case 'get_status': getDoctorStatus($doctorId); break;
    case 'mark_activity': markActivity($doctorId); break;
    case 'get_prescription': getPrescription($doctorId); break;
    default: jsonResponse(['success' => false, 'message' => 'Invalid action']);
}

function getDoctorDashboard($doctorId) {
    $db = getDB();

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE doctor_id = ? AND appointment_date = CURDATE() AND status IN ('confirmed','pending')");
    $stmt->execute([$doctorId]);
    $todayCount = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE doctor_id = ? AND status = 'completed' AND appointment_date = CURDATE()");
    $stmt->execute([$doctorId]);
    $completedToday = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(DISTINCT patient_id) as cnt FROM appointments WHERE doctor_id = ?");
    $stmt->execute([$doctorId]);
    $totalPatients = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM prescriptions WHERE doctor_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$doctorId]);
    $rxToday = $stmt->fetch()['cnt'];

    // Today's appointments with patient info
    $stmt = $db->prepare("
        SELECT a.*, u.name as patient_name, p.patient_code, p.blood_group, p.dob
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE a.doctor_id = ? AND a.appointment_date = CURDATE()
        ORDER BY a.appointment_time
    ");
    $stmt->execute([$doctorId]);
    $todayAppointments = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM doctor_status WHERE doctor_id = ?");
    $stmt->execute([$doctorId]);
    $status = $stmt->fetch();

    jsonResponse([
        'success' => true,
        'data' => [
            'today_appointments' => $todayCount,
            'completed_today' => $completedToday,
            'total_patients' => $totalPatients,
            'rx_today' => $rxToday,
            'appointments' => $todayAppointments,
            'status' => $status['status'] ?? 'available'
        ]
    ]);
}

function getPatients($doctorId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT DISTINCT p.id, p.patient_code, u.name, u.phone, u.email, p.gender, p.blood_group, p.dob,
            (SELECT COUNT(*) FROM appointments a2 WHERE a2.patient_id = p.id AND a2.doctor_id = ?) as visit_count,
            (SELECT MAX(appointment_date) FROM appointments a3 WHERE a3.patient_id = p.id AND a3.doctor_id = ?) as last_visit
        FROM patients p
        JOIN users u ON p.user_id = u.id
        JOIN appointments a ON p.id = a.patient_id
        WHERE a.doctor_id = ?
        ORDER BY last_visit DESC
    ");
    $stmt->execute([$doctorId, $doctorId, $doctorId]);
    jsonResponse(['success' => true, 'patients' => $stmt->fetchAll()]);
}

function searchPatient($doctorId) {
    $query = sanitize($_GET['query'] ?? '');
    if (strlen($query) < 2) jsonResponse(['success' => true, 'patients' => []]);

    $db = getDB();
    $q = "%{$query}%";
    $stmt = $db->prepare("
        SELECT p.id, p.patient_code, u.name, u.phone, u.email, p.gender, p.blood_group, p.dob
        FROM patients p JOIN users u ON p.user_id = u.id
        WHERE (u.name LIKE ? OR p.patient_code LIKE ? OR u.phone LIKE ?)
        AND p.privacy_allow_doctor = 1
        LIMIT 10
    ");
    $stmt->execute([$q, $q, $q]);
    jsonResponse(['success' => true, 'patients' => $stmt->fetchAll()]);
}

function getPatientDetail($doctorId) {
    $patientId = (int)($_GET['patient_id'] ?? 0);
    if (!$patientId) jsonResponse(['success' => false, 'message' => 'Patient ID required']);

    $db = getDB();

    // Check privacy
    $stmt = $db->prepare("SELECT privacy_allow_doctor FROM patients WHERE id = ?");
    $stmt->execute([$patientId]);
    $priv = $stmt->fetch();
    if (!$priv || !$priv['privacy_allow_doctor']) {
        jsonResponse(['success' => false, 'message' => 'Patient has restricted access to their data']);
    }

    // Patient info
    $stmt = $db->prepare("SELECT p.*, u.name, u.email, u.phone FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();

    // Appointments
    $stmt = $db->prepare("SELECT * FROM appointments WHERE patient_id = ? ORDER BY appointment_date DESC, appointment_time DESC LIMIT 20");
    $stmt->execute([$patientId]);
    $appointments = $stmt->fetchAll();

    // Lab reports
    $stmt = $db->prepare("SELECT * FROM lab_reports WHERE patient_id = ? ORDER BY created_at DESC");
    $stmt->execute([$patientId]);
    $labReports = $stmt->fetchAll();

    // Prescriptions
    $stmt = $db->prepare("SELECT p.*, u.name as doctor_name FROM prescriptions p JOIN doctors d ON p.doctor_id = d.id JOIN users u ON d.user_id = u.id WHERE p.patient_id = ? ORDER BY p.created_at DESC");
    $stmt->execute([$patientId]);
    $prescriptions = $stmt->fetchAll();
    foreach ($prescriptions as &$rx) {
        $rx['medicines'] = json_decode($rx['medicines'], true) ?? [];
    }

    jsonResponse([
        'success' => true,
        'patient' => $patient,
        'appointments' => $appointments,
        'lab_reports' => $labReports,
        'prescriptions' => $prescriptions
    ]);
}

function getDoctorAppointments($doctorId) {
    $db = getDB();
    $date = $_GET['date'] ?? date('Y-m-d');
    $stmt = $db->prepare("
        SELECT a.*, u.name as patient_name, p.patient_code, p.blood_group, p.allergies, u.phone as patient_phone
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE a.doctor_id = ? AND a.appointment_date = ?
        ORDER BY a.appointment_time
    ");
    $stmt->execute([$doctorId, $date]);
    jsonResponse(['success' => true, 'appointments' => $stmt->fetchAll()]);
}

function updateAppointment($doctorId) {
    $apptId = (int)($_POST['appointment_id'] ?? 0);
    $status = sanitize($_POST['status'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM appointments WHERE id = ? AND doctor_id = ?");
    $stmt->execute([$apptId, $doctorId]);
    $appt = $stmt->fetch();
    if (!$appt) jsonResponse(['success' => false, 'message' => 'Not found']);

    $db->prepare("UPDATE appointments SET status = ?, notes = ? WHERE id = ?")->execute([$status, $notes, $apptId]);

    // Auto update doctor status
    if ($status === 'active') {
        $db->prepare("UPDATE doctor_status SET status = 'busy', auto_detected = 1 WHERE doctor_id = ?")->execute([$doctorId]);
    } elseif ($status === 'completed') {
        // Check if more appointments
        $stmt = $db->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND status = 'active'");
        $stmt->execute([$doctorId]);
        if (!$stmt->fetch()) {
            $db->prepare("UPDATE doctor_status SET status = 'available', auto_detected = 1 WHERE doctor_id = ?")->execute([$doctorId]);
            notifyWaitingPatients($doctorId);
        }
    }

    // Notify patient
    $stmt = $db->prepare("SELECT u.user_id FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $stmt->execute([$appt['patient_id']]);
    $pUser = $stmt->fetch();
    if ($pUser) {
        $msgs = ['active' => 'Your appointment is now active. Doctor is seeing you shortly.', 'completed' => 'Your appointment has been completed.', 'cancelled' => 'Your appointment has been cancelled.'];
        if (isset($msgs[$status])) {
            sendNotification($pUser['user_id'], 'Appointment Update', $msgs[$status], 'appointment');
        }
    }

    jsonResponse(['success' => true, 'message' => 'Appointment updated']);
}

function writePrescription($doctorId) {
    $patientId = (int)($_POST['patient_id'] ?? 0);
    $apptId = (int)($_POST['appointment_id'] ?? 0) ?: null;
    $diagnosis = sanitize($_POST['diagnosis'] ?? '');
    $medicines = $_POST['medicines'] ?? '[]';
    $instructions = sanitize($_POST['instructions'] ?? '');
    $followUp = sanitize($_POST['follow_up_date'] ?? '');

    if (!$patientId || empty($diagnosis)) {
        jsonResponse(['success' => false, 'message' => 'Patient and diagnosis required']);
    }

    // Auto mark doctor as busy while writing
    $db = getDB();
    $db->prepare("UPDATE doctor_status SET status = 'busy', auto_detected = 1 WHERE doctor_id = ?")->execute([$doctorId]);

    $code = 'RX' . strtoupper(substr(uniqid(), -6));
    $stmt = $db->prepare("INSERT INTO prescriptions (prescription_code, patient_id, doctor_id, appointment_id, diagnosis, medicines, instructions, follow_up_date) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$code, $patientId, $doctorId, $apptId, $diagnosis, $medicines, $instructions, $followUp ?: null]);
    $rxId = $db->lastInsertId();

    // Notify patient
    $stmt = $db->prepare("SELECT u.id as user_id, u.email, u.name FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $stmt->execute([$patientId]);
    $pUser = $stmt->fetch();
    if ($pUser) {
        sendNotification($pUser['user_id'], 'New Prescription', "Dr. has issued prescription {$code}. Login to view and download.", 'prescription');
        $emailBody = "<h2>New Prescription - MedCare Hospital</h2><p>Dear {$pUser['name']},</p><p>A new prescription ({$code}) has been issued for you. Please login to view and download.</p><p><strong>Diagnosis:</strong> {$diagnosis}</p>";
        sendEmail($pUser['email'], 'New Prescription - MedCare Hospital', $emailBody);
    }

    logActivity($_SESSION['user_id'], 'Prescription written', 'prescriptions', "Code: {$code}");

    // Reset status
    $db->prepare("UPDATE doctor_status SET status = 'available', auto_detected = 1 WHERE doctor_id = ? AND admin_override = 0")->execute([$doctorId]);

    jsonResponse(['success' => true, 'message' => 'Prescription saved!', 'prescription_id' => $rxId, 'code' => $code]);
}

function updateDoctorStatus($doctorId) {
    $status = sanitize($_POST['status'] ?? '');
    $note = sanitize($_POST['note'] ?? '');
    $validStatuses = ['available', 'busy', 'emergency', 'offline'];

    if (!in_array($status, $validStatuses)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status']);
    }

    $db = getDB();
    $db->prepare("UPDATE doctor_status SET status = ?, status_note = ?, auto_detected = 0 WHERE doctor_id = ?")->execute([$status, $note, $doctorId]);

    if ($status === 'emergency') {
        handleEmergency($doctorId, $note);
    } elseif ($status === 'available') {
        notifyWaitingPatients($doctorId);
    }

    logActivity($_SESSION['user_id'], "Status updated to {$status}", 'doctor_status');
    jsonResponse(['success' => true, 'message' => 'Status updated', 'status' => $status]);
}

function handleEmergency($doctorId, $reason = '') {
    $db = getDB();
    // Find today's pending appointments
    $stmt = $db->prepare("
        SELECT a.*, u.name as patient_name, u.email as patient_email, u.phone as patient_phone, pu.id as user_id
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        JOIN users pu ON p.user_id = pu.id
        WHERE a.doctor_id = ? AND a.appointment_date = CURDATE()
        AND a.status IN ('pending','confirmed') AND a.appointment_time > CURTIME()
    ");
    $stmt->execute([$doctorId]);
    $appointments = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT u.name FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.id = ?");
    $stmt->execute([$doctorId]);
    $doctor = $stmt->fetch();

    foreach ($appointments as $appt) {
        $db->prepare("UPDATE appointments SET status = 'postponed', postpone_reason = 'Doctor unavailable due to emergency' WHERE id = ?")->execute([$appt['id']]);
        sendNotification($appt['user_id'], '⚠️ Appointment Postponed',
            "Your appointment at {$appt['appointment_time']} with Dr. {$doctor['name']} has been postponed due to an emergency. We apologize for the inconvenience.",
            'emergency');
        $emailBody = "<h2 style='color:red'>Appointment Postponed - Emergency</h2>
        <p>Dear {$appt['patient_name']},</p>
        <p>We regret to inform you that your appointment scheduled for <strong>{$appt['appointment_date']} at {$appt['appointment_time']}</strong> with <strong>Dr. {$doctor['name']}</strong> has been postponed due to a medical emergency.</p>
        <p>We will notify you as soon as the doctor is available.</p>
        <p>We sincerely apologize for any inconvenience caused.</p>
        <p>For urgent assistance, please contact our reception.</p>";
        sendEmail($appt['patient_email'], 'URGENT: Appointment Postponed - MedCare Hospital', $emailBody);
        if ($appt['patient_phone']) {
            sendSMS($appt['patient_phone'], "MedCare: Your appointment at {$appt['appointment_time']} with Dr. {$doctor['name']} is postponed due to emergency. Sorry for inconvenience.");
        }
    }
}

function notifyWaitingPatients($doctorId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT nmr.patient_id, p.id as pid, u.user_id as user_id, u.name, pu.email
        FROM notify_me_requests nmr
        JOIN patients p ON nmr.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        JOIN users pu ON p.user_id = pu.id
        WHERE nmr.doctor_id = ? AND nmr.is_active = 1 AND nmr.notified_at IS NULL
    ");
    $stmt->execute([$doctorId]);
    $waiting = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT u.name FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.id = ?");
    $stmt->execute([$doctorId]);
    $doctor = $stmt->fetch();

    foreach ($waiting as $w) {
        sendNotification($w['user_id'], '✅ Doctor Available',
            "Dr. {$doctor['name']} is now available. You can book an appointment.",
            'doctor_status');
        $db->prepare("UPDATE notify_me_requests SET notified_at = NOW(), is_active = 0 WHERE patient_id = ? AND doctor_id = ?")->execute([$w['patient_id'], $doctorId]);
    }
}

function getDoctorStatus($doctorId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM doctor_status WHERE doctor_id = ?");
    $stmt->execute([$doctorId]);
    jsonResponse(['success' => true, 'status' => $stmt->fetch()]);
}

function markActivity($doctorId) {
    $db = getDB();
    $db->prepare("UPDATE doctor_status SET last_activity = NOW(), status = 'busy', auto_detected = 1 WHERE doctor_id = ? AND admin_override = 0")->execute([$doctorId]);
    jsonResponse(['success' => true]);
}

function getPrescription($doctorId) {
    $rxId = (int)($_GET['id'] ?? 0);
    $db = getDB();
    $stmt = $db->prepare("
        SELECT p.*, u.name as patient_name, pu.name as doctor_name, d.specialization, d.qualification, d.room_number,
            pat.patient_code, pat.dob, pat.blood_group, pat.allergies
        FROM prescriptions p
        JOIN patients pat ON p.patient_id = pat.id
        JOIN users u ON pat.user_id = u.id
        JOIN doctors d ON p.doctor_id = d.id
        JOIN users pu ON d.user_id = pu.id
        WHERE p.id = ? AND p.doctor_id = ?
    ");
    $stmt->execute([$rxId, $doctorId]);
    $rx = $stmt->fetch();
    if ($rx) $rx['medicines'] = json_decode($rx['medicines'], true) ?? [];
    jsonResponse(['success' => true, 'prescription' => $rx]);
}
?>
