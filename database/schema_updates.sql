-- ==============================================================================
-- Synapse AI - Schema Updates for Authentication and Profile Modules
-- Database: synapse_ai
-- Description: Applies missing columns, tables, and constraints for Auth & Profile
-- ==============================================================================

USE `synapse_ai`;

-- 1. Add missing profile and authentication columns to `users` table
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(25) DEFAULT NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `auth_token` VARCHAR(64) DEFAULT NULL AFTER `password`,
  ADD COLUMN IF NOT EXISTS `course` VARCHAR(255) DEFAULT 'B.Tech Computer Science · Year 3' AFTER `bio`,
  ADD COLUMN IF NOT EXISTS `preferred_language` VARCHAR(50) DEFAULT 'English' AFTER `course`,
  ADD COLUMN IF NOT EXISTS `interests` TEXT DEFAULT NULL AFTER `preferred_language`,
  ADD COLUMN IF NOT EXISTS `study_goal` VARCHAR(100) DEFAULT '25 min / day' AFTER `interests`,
  ADD COLUMN IF NOT EXISTS `notifications_enabled` TINYINT(1) DEFAULT 1 AFTER `study_goal`,
  ADD INDEX IF NOT EXISTS `idx_users_email` (`email`),
  ADD INDEX IF NOT EXISTS `idx_users_phone` (`phone`),
  ADD INDEX IF NOT EXISTS `idx_users_token` (`auth_token`);

-- 2. Create User Achievements table (powers AchievementsScreen & Profile badge counter)
CREATE TABLE IF NOT EXISTS `user_achievements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `badge_icon` VARCHAR(50) DEFAULT '🏅',
  `is_unlocked` TINYINT(1) DEFAULT 1,
  `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_achievements_user_id` (`user_id`),
  CONSTRAINT `fk_user_achievements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Create User Notifications table (powers NotificationsSettings & NotificationCenterPreview)
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) DEFAULT 'info',
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notifications_user_id` (`user_id`),
  CONSTRAINT `fk_user_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Seed default test user with known credentials for testing
-- Test credentials:
-- Email: demo@example.com
-- Password: password123
UPDATE `users`
SET 
  `password` = '$2y$10$xrcHIZWhSuPg.TMAfxEWteTlPcwgGg1qkdAswa.DIMF2X7gXvWfBa',
  `phone` = IFNULL(`phone`, '+1 202-555-0147'),
  `bio` = IFNULL(`bio`, 'Senior Computer Science Student & Developer'),
  `course` = IFNULL(`course`, 'B.Tech Computer Science · Year 3'),
  `preferred_language` = IFNULL(`preferred_language`, 'English'),
  `interests` = IFNULL(`interests`, '[\"Computer Science\", \"Artificial Intelligence\", \"Mathematics\"]'),
  `study_goal` = IFNULL(`study_goal`, '25 min / day'),
  `notifications_enabled` = IFNULL(`notifications_enabled`, 1)
WHERE `email` = 'demo@example.com';

