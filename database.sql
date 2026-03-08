-- =============================================
-- الحلقات الأسرية - حلقة تحفيظ القرآن الأسرية
-- Database Schema
-- =============================================

CREATE DATABASE IF NOT EXISTS `family_halqat_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `family_halqat_db`;

-- جدول المستخدمين
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'student') NOT NULL DEFAULT 'student',
    `parent_id` INT NULL,
    `avatar_color` VARCHAR(7) NOT NULL DEFAULT '#0d9488',
    `points` INT NOT NULL DEFAULT 0,
    `streak_days` INT NOT NULL DEFAULT 0,
    `last_activity` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول الواجبات
CREATE TABLE IF NOT EXISTS `assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `assigned_by` INT NOT NULL,
    `surah_name` VARCHAR(50) NOT NULL,
    `from_ayah` INT NOT NULL DEFAULT 1,
    `to_ayah` INT NOT NULL DEFAULT 1,
    `type` ENUM('حفظ', 'تلاوة', 'مراجعة') NOT NULL DEFAULT 'حفظ',
    `status` ENUM('pending', 'done', 'reviewed') NOT NULL DEFAULT 'pending',
    `points_awarded` INT NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `due_date` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_student_status` (`student_id`, `status`),
    INDEX `idx_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول سجل النشاط
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_activity` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول حماية التسجيل (Rate Limiting)
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 1,
    `last_attempt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ip_action` (`ip_address`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


