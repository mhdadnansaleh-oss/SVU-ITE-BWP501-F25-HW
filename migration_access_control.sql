-- ============================================================
-- Migration: roles, account activation and registration settings
-- For an EXISTING database. Back it up first, then run ONCE:
--     mysql -u root -p < migration_access_control.sql
-- (Fresh installs do not need this file - use database.sql.)
-- ============================================================

USE student_registration_system;

-- 1) users: role + activation flag.
--    Existing accounts keep working (is_active = 1) and start as "staff".
ALTER TABLE users
    ADD COLUMN role      ENUM('admin','staff') NOT NULL DEFAULT 'staff' AFTER password,
    ADD COLUMN is_active TINYINT(1)            NOT NULL DEFAULT 1       AFTER role;

-- 2) settings: small key/value table (holds the registration mode).
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(50)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public registration starts CLOSED (closed | approval | open).
-- An admin can change it later from admin_users.php.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('registration_mode', 'closed');

-- 3) Make sure somebody can administer the system: promote the OLDEST account.
--    Prefer a specific person? Use this instead:
--      UPDATE users SET role = 'admin' WHERE username = 'your_username';
UPDATE users SET role = 'admin' ORDER BY id ASC LIMIT 1;

-- 4) Optional: faster dashboard queries / date sorting on large tables.
-- ALTER TABLE students ADD INDEX idx_students_created_at (created_at);
