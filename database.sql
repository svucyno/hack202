-- ============================================================
-- UNIFIED HOSPITAL MANAGEMENT SYSTEM - DATABASE SCHEMA
-- ============================================================

CREATE DATABASE IF NOT EXISTS hospital_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hospital_db;

-- ============================================================
-- USERS TABLE (all roles)
-- ============================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    phone VARCHAR(20) UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('patient','doctor','admin') NOT NULL DEFAULT 'patient',
    otp VARCHAR(10),
    otp_expiry DATETIME,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- PATIENTS TABLE
-- ============================================================
CREATE TABLE patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    patient_code VARCHAR(20) UNIQUE NOT NULL,
    dob DATE,
    gender ENUM('male','female','other'),
    blood_group VARCHAR(5),
    address TEXT,
    emergency_contact VARCHAR(20),
    medical_history TEXT,
    allergies TEXT,
    insurance_id VARCHAR(50),
    privacy_allow_doctor TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- DOCTORS TABLE
-- ============================================================
CREATE TABLE doctors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    doctor_code VARCHAR(20) UNIQUE NOT NULL,
    specialization VARCHAR(100),
    qualification VARCHAR(200),
    department VARCHAR(100),
    room_number VARCHAR(20),
    consultation_fee DECIMAL(10,2) DEFAULT 0.00,
    experience_years INT DEFAULT 0,
    bio TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- DOCTOR STATUS TABLE
-- ============================================================
CREATE TABLE doctor_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    status ENUM('available','busy','emergency','offline') DEFAULT 'available',
    status_note VARCHAR(255),
    auto_detected TINYINT(1) DEFAULT 0,
    admin_override TINYINT(1) DEFAULT 0,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
);

-- ============================================================
-- APPOINTMENTS TABLE
-- ============================================================
CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_code VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status ENUM('pending','confirmed','active','completed','cancelled','postponed','no_show') DEFAULT 'pending',
    type ENUM('consultation','follow_up','emergency','lab_review') DEFAULT 'consultation',
    reason TEXT,
    notes TEXT,
    postpone_reason TEXT,
    notification_sent TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id)
);

-- ============================================================
-- LAB REPORTS TABLE
-- ============================================================
CREATE TABLE lab_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT,
    test_name VARCHAR(200) NOT NULL,
    test_category VARCHAR(100),
    result TEXT,
    normal_range VARCHAR(200),
    status ENUM('pending','in_progress','completed','reviewed') DEFAULT 'pending',
    technician_name VARCHAR(100),
    report_date DATE,
    remarks TEXT,
    file_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id)
);

-- ============================================================
-- PRESCRIPTIONS TABLE
-- ============================================================
CREATE TABLE prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_code VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    appointment_id INT,
    diagnosis TEXT,
    medicines JSON,
    instructions TEXT,
    follow_up_date DATE,
    status ENUM('active','dispensed','expired','cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id),
    FOREIGN KEY (appointment_id) REFERENCES appointments(id)
);

-- ============================================================
-- PHARMACY RECORDS TABLE
-- ============================================================
CREATE TABLE pharmacy_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    patient_id INT NOT NULL,
    dispensed_by VARCHAR(100),
    dispensed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    medicines_dispensed JSON,
    total_amount DECIMAL(10,2),
    payment_status ENUM('pending','paid','insurance') DEFAULT 'pending',
    notes TEXT,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id)
);

-- ============================================================
-- NOTIFICATIONS TABLE
-- ============================================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('appointment','doctor_status','lab_report','prescription','emergency','general') DEFAULT 'general',
    is_read TINYINT(1) DEFAULT 0,
    email_sent TINYINT(1) DEFAULT 0,
    sms_sent TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- ACTIVITY LOGS TABLE
-- ============================================================
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(200) NOT NULL,
    module VARCHAR(50),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- NOTIFY ME TABLE (patient requests notification)
-- ============================================================
CREATE TABLE notify_me_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notified_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id)
);

-- ============================================================
-- SAMPLE DATA
-- ============================================================

-- Admin user (password: Admin@123)
INSERT INTO users (name, email, phone, password, role) VALUES
('Hospital Admin', 'admin@hospital.com', '9000000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- Doctor users (password: Doctor@123)
INSERT INTO users (name, email, phone, password, role) VALUES
('Dr. Rajesh Kumar', 'rajesh@hospital.com', '9000000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'doctor'),
('Dr. Priya Sharma', 'priya@hospital.com', '9000000003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'doctor'),
('Dr. Anil Verma', 'anil@hospital.com', '9000000004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'doctor');

-- Patient users (password: Patient@123)
INSERT INTO users (name, email, phone, password, role) VALUES
('Suresh Reddy', 'suresh@gmail.com', '9876543210', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient'),
('Lakshmi Devi', 'lakshmi@gmail.com', '9876543211', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient'),
('Venkat Rao', 'venkat@gmail.com', '9876543212', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient');

-- Doctors
INSERT INTO doctors (user_id, doctor_code, specialization, qualification, department, room_number, consultation_fee, experience_years) VALUES
(2, 'DOC001', 'Cardiology', 'MBBS, MD Cardiology', 'Cardiology', '201', 500.00, 12),
(3, 'DOC002', 'General Medicine', 'MBBS, MD General Medicine', 'General', '101', 300.00, 8),
(4, 'DOC003', 'Orthopedics', 'MBBS, MS Orthopedics', 'Orthopedics', '301', 450.00, 10);

-- Doctor status
INSERT INTO doctor_status (doctor_id, status) VALUES (1, 'available'), (2, 'available'), (3, 'available');

-- Patients
INSERT INTO patients (user_id, patient_code, dob, gender, blood_group, address, emergency_contact, medical_history, allergies) VALUES
(5, 'PAT001', '1985-06-15', 'male', 'B+', 'Nellore, AP', '9876543299', 'Hypertension', 'Penicillin'),
(6, 'PAT002', '1992-03-22', 'female', 'O+', 'Nellore, AP', '9876543298', 'Diabetes Type 2', 'None'),
(7, 'PAT003', '1978-11-10', 'male', 'A+', 'Nellore, AP', '9876543297', 'None', 'Sulfa drugs');

-- Appointments
INSERT INTO appointments (appointment_code, patient_id, doctor_id, appointment_date, appointment_time, status, type, reason) VALUES
('APT001', 1, 1, CURDATE(), '10:00:00', 'confirmed', 'consultation', 'Chest pain and shortness of breath'),
('APT002', 2, 2, CURDATE(), '11:00:00', 'confirmed', 'follow_up', 'Diabetes follow-up'),
('APT003', 3, 3, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:30:00', 'pending', 'consultation', 'Knee pain');

-- Lab Reports
INSERT INTO lab_reports (report_code, patient_id, doctor_id, test_name, test_category, result, normal_range, status, report_date, remarks) VALUES
('LAB001', 1, 1, 'Complete Blood Count (CBC)', 'Hematology', 'WBC: 8500/μL, RBC: 4.8M/μL, Hemoglobin: 13.5g/dL, Platelets: 250000/μL', 'WBC: 4500-11000, RBC: 4.5-5.5M, Hb: 12-17g/dL', 'completed', CURDATE(), 'Within normal limits'),
('LAB002', 2, 2, 'Blood Glucose (Fasting)', 'Biochemistry', 'Glucose: 185 mg/dL', '70-100 mg/dL', 'reviewed', CURDATE(), 'Elevated - Diabetes monitoring required'),
('LAB003', 1, 1, 'Lipid Profile', 'Biochemistry', 'Total Cholesterol: 210mg/dL, LDL: 130mg/dL, HDL: 45mg/dL', 'TC: <200, LDL:<100, HDL:>40', 'completed', DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Borderline high cholesterol');

-- Prescriptions
INSERT INTO prescriptions (prescription_code, patient_id, doctor_id, appointment_id, diagnosis, medicines, instructions, follow_up_date, status) VALUES
('RX001', 1, 1, 1, 'Hypertension with Dyslipidemia',
 '[{"name":"Amlodipine","dosage":"5mg","frequency":"Once daily","duration":"30 days","instructions":"Take in morning"},{"name":"Atorvastatin","dosage":"10mg","frequency":"Once at night","duration":"30 days","instructions":"Take at bedtime"}]',
 'Low salt diet, regular exercise, avoid stress. Take medications regularly.',
 DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active'),
('RX002', 2, 2, 2, 'Type 2 Diabetes Mellitus',
 '[{"name":"Metformin","dosage":"500mg","frequency":"Twice daily","duration":"30 days","instructions":"Take with meals"},{"name":"Glipizide","dosage":"5mg","frequency":"Once daily","duration":"30 days","instructions":"Take 30 min before breakfast"}]',
 'Low carbohydrate diet, regular blood sugar monitoring. Exercise daily.',
 DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'active');

-- Pharmacy Records
INSERT INTO pharmacy_records (prescription_id, patient_id, dispensed_by, medicines_dispensed, total_amount, payment_status) VALUES
(1, 1, 'Pharmacist Ramu', '[{"name":"Amlodipine 5mg","qty":"30 tabs"},{"name":"Atorvastatin 10mg","qty":"30 tabs"}]', 350.00, 'paid');

-- Notifications
INSERT INTO notifications (user_id, title, message, type) VALUES
(5, 'Appointment Confirmed', 'Your appointment with Dr. Rajesh Kumar on today at 10:00 AM has been confirmed.', 'appointment'),
(6, 'Lab Report Ready', 'Your Blood Glucose report is ready. Please login to view.', 'lab_report'),
(5, 'Prescription Ready', 'Your prescription RX001 has been issued by Dr. Rajesh Kumar.', 'prescription');

-- Activity Logs
INSERT INTO activity_logs (user_id, action, module, details) VALUES
(1, 'System initialized', 'system', 'Hospital Management System started'),
(2, 'Doctor logged in', 'auth', 'Dr. Rajesh Kumar logged in'),
(5, 'Patient logged in', 'auth', 'Patient Suresh Reddy logged in');
