-- ==============================================================================
-- Synapse AI - Complete Relational Database Schema (All Screenshot Modules)
-- Database Name: synapse_ai
-- Character Set: utf8mb4 (supports emojis and full Unicode)
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `synapse_ai` 
  DEFAULT CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `synapse_ai`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- MODULE 1: AUTHENTICATION & SECURITY
-- ------------------------------------------------------------------------------

-- 1. Table: users
-- Matches: '1. User Registration', '2. Login by User ID', '3. Login by Phone'
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `phone` VARCHAR(25) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table: password_resets
-- Matches: '4. Reset Password (Direct In-App)' (Security Audit History)
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `token` VARCHAR(255) DEFAULT 'direct_reset',
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pwd_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- MODULE 2: USER PROFILE & PREFERENCES
-- ------------------------------------------------------------------------------

-- 3. Table: user_profiles
-- Matches: '1. Fetch User Profile', '2. Update Profile Details', '4. Edit Personal Info', '5. Remove Photo'
DROP TABLE IF EXISTS `user_profiles`;
CREATE TABLE `user_profiles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `name` VARCHAR(255) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `course` VARCHAR(255) DEFAULT 'B.Tech Computer Science · Year 3',
  `interests` TEXT DEFAULT NULL,
  `profile_photo` VARCHAR(500) DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_profiles_user_id` (`user_id`),
  CONSTRAINT `fk_user_profiles_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Table: user_interests
-- Matches: '3. Profile Setup / Onboarding (Make it yours)'
DROP TABLE IF EXISTS `user_interests`;
CREATE TABLE `user_interests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `interest` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_interests_user_id` (`user_id`),
  CONSTRAINT `fk_user_interests_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Table: user_settings
-- Matches: '6. Update Study Goals & Settings'
DROP TABLE IF EXISTS `user_settings`;
CREATE TABLE `user_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `preferred_language` VARCHAR(50) DEFAULT 'English',
  `study_goal` VARCHAR(100) DEFAULT '25 min / day',
  `notifications_enabled` TINYINT(1) DEFAULT 1,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_settings_user_id` (`user_id`),
  CONSTRAINT `fk_user_settings_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- MODULE 3: LEARNING PROGRESS & NOTIFICATIONS
-- ------------------------------------------------------------------------------

-- 6. Table: user_achievements
-- Matches: '1. Get User Badges & Achievements'
DROP TABLE IF EXISTS `user_achievements`;
CREATE TABLE `user_achievements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `badge_icon` VARCHAR(50) DEFAULT '🏅',
  `is_unlocked` TINYINT(1) DEFAULT 1,
  `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_achievements_user_id` (`user_id`),
  CONSTRAINT `fk_user_achievements_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Table: user_notifications
-- Matches: '2. Get User Notifications'
DROP TABLE IF EXISTS `user_notifications`;
CREATE TABLE `user_notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) DEFAULT 'info',
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notifications_user_id` (`user_id`),
  CONSTRAINT `fk_user_notifications_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- SEED DATA (Pre-loaded Test Account - User ID 1)
-- ==============================================================================

-- 1. Core User
INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password`) VALUES
(1, 'Demo User', 'demo@example.com', '+1 202-555-0147', '$2y$10$xrcHIZWhSuPg.TMAfxEWteTlPcwgGg1qkdAswa.DIMF2X7gXvWfBa');

-- 2. User Profile
INSERT INTO `user_profiles` (`id`, `user_id`, `name`, `bio`, `course`, `interests`, `profile_photo`) VALUES
(1, 1, 'Demo User', 'Senior Computer Science Student & AI Developer', 'B.Tech Computer Science · Year 3', '["Computer Science", "Artificial Intelligence", "Mathematics"]', NULL);

-- 3. User Interests (Onboarding Tags)
INSERT INTO `user_interests` (`user_id`, `interest`) VALUES
(1, 'Computer Science'),
(1, 'Artificial Intelligence'),
(1, 'Mathematics');

-- 4. User Settings
INSERT INTO `user_settings` (`id`, `user_id`, `preferred_language`, `study_goal`, `notifications_enabled`) VALUES
(1, 1, 'English', '25 min / day', 1);

-- 5. User Achievements (8 Unlocked Badges)
INSERT INTO `user_achievements` (`user_id`, `title`, `description`, `badge_icon`, `is_unlocked`) VALUES
(1, 'Quick Learner', 'Completed your first 5 AI lessons', '⚡', 1),
(1, '7-Day Streak', 'Studied consecutively for 7 days', '🔥', 1),
(1, 'Quiz Master', 'Scored 100% on 3 assessments', '🎯', 1),
(1, 'Night Owl', 'Completed study sessions past 10 PM', '🦉', 1),
(1, 'Problem Solver', 'Solved 20 coding challenges', '🧩', 1),
(1, 'AI Explorer', 'Generated 50 AI summaries and notes', '🤖', 1),
(1, 'Consistent Scholar', 'Reached 30 hours of total study time', '📚', 1),
(1, 'Early Bird', 'Completed morning study sessions', '🌅', 1);

-- 6. User Notifications
INSERT INTO `user_notifications` (`user_id`, `title`, `message`, `type`, `is_read`) VALUES
(1, 'Daily Goal Achieved! 🎯', 'You hit your 25-minute study target today. Keep up the momentum!', 'goal', 0),
(1, 'New AI Summary Ready 🤖', 'Your summary for Data Structures & Algorithms is ready to review.', 'content', 0),
(1, 'Streak Reminder 🔥', 'Study for 10 minutes to maintain your active learning streak.', 'reminder', 1);

-- 7. Password Reset Audit Log
INSERT INTO `password_resets` (`email`, `token`, `expires_at`, `used`) VALUES
('demo@example.com', 'direct_in_app_reset', NOW() + INTERVAL 1 HOUR, 1);
