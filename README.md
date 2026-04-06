# 🏥 MedCare — Unified Hospital Management System

A complete, production-ready Hospital Management System with role-based access for Patients, Doctors, and Admins.

---

## 📁 FOLDER STRUCTURE

```
hospital/
├── index.html                  ← Role Selection Landing Page (Patient/Doctor/Admin)
├── patient-login.html          ← Patient Login + Register + OTP
├── doctor-login.html           ← Doctor Login
├── admin-login.html            ← Admin Login
├── patient-dashboard.html      ← Full Patient Dashboard
├── doctor-dashboard.html       ← Full Doctor Dashboard
├── admin-dashboard.html        ← Full Admin Dashboard
│
├── css/
│   └── dashboard.css           ← Shared dark-theme dashboard styles
│
├── php/
│   ├── config.php              ← DB config, session, helpers, email/SMS
│   ├── auth.php                ← Login, Register, OTP, Logout
│   ├── patient_api.php         ← Patient APIs
│   ├── doctor_api.php          ← Doctor APIs
│   └── admin_api.php           ← Admin APIs
│
└── database.sql                ← Complete DB schema + sample data
```

---

## 🚀 QUICK SETUP

### Step 1 — Requirements
- PHP 7.4+ (PHP 8.x recommended)
- MySQL 5.7+ or MariaDB 10.x
- Apache/Nginx with mod_rewrite
- **OR** use XAMPP / WAMP / MAMP (easiest for local)

### Step 2 — Place Files
Copy the entire `hospital/` folder to:
- **XAMPP**: `C:/xampp/htdocs/hospital/`
- **WAMP**: `C:/wamp/www/hospital/`
- **Linux**: `/var/www/html/hospital/`

### Step 3 — Create Database
1. Open phpMyAdmin → `http://localhost/phpmyadmin`
2. Create a new database: `hospital_db`
3. Import `database.sql`
4. All tables and sample data will be created automatically

### Step 4 — Configure Database
Edit `php/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // Your MySQL username
define('DB_PASS', '');            // Your MySQL password
define('DB_NAME', 'hospital_db');
define('BASE_URL', 'http://localhost/hospital');
```

### Step 5 — Access the App
Open: `http://localhost/hospital/`

---

## 🔑 DEMO LOGIN CREDENTIALS

All passwords for demo accounts: **`password`**

### 👤 Patients
| Name | Email | Password |
|------|-------|----------|
| Suresh Reddy | suresh@gmail.com | password |
| Lakshmi Devi | lakshmi@gmail.com | password |
| Venkat Rao | venkat@gmail.com | password |

### 👨‍⚕️ Doctors
| Name | Email | Password |
|------|-------|----------|
| Dr. Rajesh Kumar | rajesh@hospital.com | password |
| Dr. Priya Sharma | priya@hospital.com | password |
| Dr. Anil Verma | anil@hospital.com | password |

### ⚙️ Admin
| Email | Password |
|-------|----------|
| admin@hospital.com | password |

> **Note:** Passwords in DB are hashed with bcrypt. The demo uses `password_verify()`. For your own users, always hash passwords with `password_hash($password, PASSWORD_DEFAULT)`.

---

## 📧 EMAIL CONFIGURATION (Free)

For real email delivery, configure Gmail SMTP in `php/config.php`:

1. Enable 2FA on your Gmail account
2. Generate an App Password: Google Account → Security → App Passwords
3. Update config:
```php
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'youremail@gmail.com');
define('MAIL_PASS', 'your_app_password_here');
```

For production, install **PHPMailer**:
```bash
composer require phpmailer/phpmailer
```

Then update `sendEmail()` function in `config.php` to use PHPMailer.

---

## 📱 SMS CONFIGURATION (Free Tiers)

### Option 1: Textbelt (1 free SMS/day)
Already configured in `config.php`. Works out of the box.
```php
define('SMS_API_KEY', 'textbelt');
define('SMS_API_URL', 'https://textbelt.com/text');
```

### Option 2: Fast2SMS (India — free credits)
1. Register at https://fast2sms.com
2. Get free API key
3. Update config.php with Fast2SMS endpoint

### Option 3: MSG91 (India — free trial)
1. Register at https://msg91.com
2. Get free trial credits
3. Update sendSMS() function with MSG91 API

---

## ✨ FEATURES

### 🏠 Landing Page
- Beautiful role selection screen with 3 large cards
- Animated gradients and particle effects
- Auto-redirect if already logged in

### 👤 Patient Dashboard
- ✅ View upcoming & past appointments
- ✅ Book appointments with doctor availability check
- ✅ View lab reports with detailed results
- ✅ View & print prescriptions (printable PDF format)
- ✅ "Notify Me" when doctor becomes available
- ✅ Receive real-time notifications (appointment, emergency, lab)
- ✅ Privacy toggle — allow/block doctor access
- ✅ OTP login via phone or email
- ✅ Patient registration

### 👨‍⚕️ Doctor Dashboard
- ✅ Today's appointment queue with start/complete controls
- ✅ Smart status: auto-BUSY when consulting or writing prescription
- ✅ Manual status update: Available / Busy / Emergency
- ✅ Emergency: automatically postpones appointments + notifies patients
- ✅ Search patients by name, ID, phone
- ✅ View complete patient history (appointments, lab reports, prescriptions)
- ✅ Write digital prescriptions with multi-medicine support
- ✅ Printable prescription generator

### ⚙️ Admin Dashboard
- ✅ Full system stats overview
- ✅ Real-time doctor status monitoring (auto-refreshes every 15s)
- ✅ Override any doctor's status (Available/Busy/Emergency/Offline)
- ✅ Emergency override — bulk postpones appointments + sends notifications
- ✅ Add new doctors with full profile
- ✅ Enable/disable doctors and patients
- ✅ View all appointments with filters
- ✅ Add lab reports manually
- ✅ Activity logs with module filtering
- ✅ Module management (Lab, Pharmacy, Reception)

---

## 🔴 SMART STATUS SYSTEM

```
Doctor Status Auto-Detection:
├── Patient checked-in → BUSY (appointment.status = 'active')
├── Doctor starts consultation → BUSY
├── Doctor writing prescription → BUSY (activity heartbeat)
└── No active appointments → AVAILABLE

Admin Override:
└── Can set any status at any time → overrides auto-detection
```

---

## 🚨 EMERGENCY HANDLING FLOW

```
Doctor → Set Emergency
    ↓
System finds all today's upcoming appointments
    ↓
Marks all as POSTPONED
    ↓
Sends notification in-app to each patient
    ↓
Sends email to each affected patient
    ↓
Sends SMS to each affected patient (if configured)

When Doctor → Available again
    ↓
System finds all "notify me" requests for this doctor
    ↓
Sends notification: "Doctor is now available"
```

---

## 🔒 SECURITY

- Password hashing with PHP `password_hash()` (bcrypt)
- PHP session-based authentication
- Role-Based Access Control (RBAC) — every API checks role
- Input sanitization with `htmlspecialchars()` and `strip_tags()`
- PDO prepared statements (prevents SQL injection)
- CORS headers configured

---

## 📊 DATABASE SCHEMA

| Table | Description |
|-------|-------------|
| `users` | All users with role: patient/doctor/admin |
| `patients` | Patient profiles linked to users |
| `doctors` | Doctor profiles with specialization/department |
| `doctor_status` | Real-time doctor status tracking |
| `appointments` | All appointments with status tracking |
| `lab_reports` | Lab test results |
| `prescriptions` | Digital prescriptions with medicines JSON |
| `pharmacy_records` | Dispensed medicines tracking |
| `notifications` | In-app notifications for all users |
| `activity_logs` | System-wide activity audit log |
| `notify_me_requests` | Patient notification requests |

---

## 🆓 FREE APIS USED

| Service | Purpose | Free Tier |
|---------|---------|-----------|
| Textbelt | SMS notifications | 1 SMS/day |
| Gmail SMTP | Email notifications | Free |
| PHP `mail()` | Fallback email | Free |
| Google Fonts | Typography | Free |

---

## 🔧 TROUBLESHOOTING

**White screen / blank page:**
- Enable PHP errors: add `ini_set('display_errors', 1);` at top of config.php
- Check Apache error log

**Database connection failed:**
- Verify DB credentials in `php/config.php`
- Make sure MySQL service is running

**Login not working:**
- Import `database.sql` fresh
- Clear browser cookies/session
- Check that password in DB matches (demo: `password`)

**Email not sending:**
- PHP `mail()` requires a mail server locally
- Use PHPMailer with Gmail SMTP for reliable delivery

---

## 📱 RESPONSIVE

The app works on:
- 💻 Desktop browsers
- 📱 Mobile (sidebar collapses)
- 🖨️ Print (prescriptions print cleanly)

---

## 👨‍💻 TECH STACK

| Layer | Technology |
|-------|-----------|
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Backend | PHP 8.x |
| Database | MySQL / MariaDB |
| Fonts | Google Fonts (Playfair Display + DM Sans) |
| Auth | PHP Sessions + bcrypt |
| Notifications | In-app + Email + SMS |

---

*MedCare Hospital Management System — Built for Nellore, Andhra Pradesh 🇮🇳*
