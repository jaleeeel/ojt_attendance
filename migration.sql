-- Migration script for Attendance System Upgrade
-- Adds soft delete, modification tracking, and audit logging

USE attendance_system;

-- 1. Update attendance table with soft delete and modification columns
ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL AFTER time_out,
    ADD COLUMN IF NOT EXISTS deletion_reason VARCHAR(255) NULL DEFAULT NULL AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS modification_reason VARCHAR(255) NULL DEFAULT NULL AFTER deletion_reason;

-- 2. Drop the strict unique index to allow soft-deleted records without blocking new entries
SET @drop_index = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'attendance' 
              AND INDEX_NAME = 'uq_employee_attendance_day'
        ),
        'ALTER TABLE attendance DROP INDEX uq_employee_attendance_day',
        'SELECT 1'
    )
);
PREPARE stmt FROM @drop_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Ensure non-unique composite index on (employee_id, attendance_date) exists
SET @add_index = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'attendance' 
              AND INDEX_NAME = 'idx_attendance_employee_date'
        ),
        'ALTER TABLE attendance ADD INDEX idx_attendance_employee_date (employee_id, attendance_date)',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Create attendance_audit_logs table to track modifications
CREATE TABLE IF NOT EXISTS attendance_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    previous_attendance_date DATE NULL,
    new_attendance_date DATE NULL,
    previous_time_in DATETIME NULL,
    new_time_in DATETIME NULL,
    previous_time_out DATETIME NULL,
    new_time_out DATETIME NULL,
    reason TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_attendance FOREIGN KEY (attendance_id)
        REFERENCES attendance(id) ON DELETE CASCADE,
    CONSTRAINT fk_audit_employee FOREIGN KEY (employee_id)
        REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_audit_attendance (attendance_id),
    INDEX idx_audit_employee (employee_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created_at (created_at)
) ENGINE=InnoDB;

