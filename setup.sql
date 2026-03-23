-- ══════════════════════════════════════════════════════════════
--  BIBLIOTECA — Database Setup (v4)
--  Run this in phpMyAdmin > SQL tab
-- ══════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS biblioteca;
USE biblioteca;

-- ── Allowed Users (email + password + role) ───────────────────
CREATE TABLE IF NOT EXISTS allowed_users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(150) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    name       VARCHAR(100) NOT NULL,
    role       ENUM('admin','user') DEFAULT 'user',
    active_role ENUM('admin','user') DEFAULT 'user',
    is_active  TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Students ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
    id            VARCHAR(20) PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    age           INT NOT NULL,
    college       VARCHAR(100) NOT NULL,
    department    VARCHAR(100),
    employee_type ENUM('Student','Teacher','Staff') DEFAULT 'Student',
    email         VARCHAR(150),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Library Visits ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS visits (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    student_id   VARCHAR(20) NOT NULL,
    visit_reason VARCHAR(100) DEFAULT 'Library Visit',
    entry_time   DATETIME NOT NULL,
    exit_time    DATETIME DEFAULT NULL,
    status       ENUM('active','exited') DEFAULT 'active',
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── Visit Activities ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS visit_activities (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    activity VARCHAR(100) NOT NULL,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE
);

-- ── Activity / Audit Log ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    action     VARCHAR(100) NOT NULL,
    detail     TEXT,
    user_email VARCHAR(150),
    logged_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ══════════════════════════════════════════════════════════════
--  Default Accounts
--  Admin:   jcesperanza@neu.edu.ph / admin123
--  User:    student@neu.edu.ph     / user123
-- ══════════════════════════════════════════════════════════════
INSERT INTO allowed_users (email, password, name, role, active_role) VALUES
('jcesperanza@neu.edu.ph', MD5('admin123'), 'JC Esperanza', 'admin', 'admin'),
('student@neu.edu.ph',     MD5('user123'),  'Sample Student', 'user', 'user')
ON DUPLICATE KEY UPDATE email = email;
