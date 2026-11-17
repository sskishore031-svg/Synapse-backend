-- ==============================================================================
-- SYNAPSE AI - 5 CORE DATABASE TABLES CONSOLIDATION MIGRATION
-- Database: synapse_ai
-- Consolidates database schema to strictly 5 essential tables:
--   1. users
--   2. user_profiles
--   3. user_sessions
--   4. study_notes
--   5. quiz_records
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. TABLE: users
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users_temp` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing users into users_temp if users exists
INSERT IGNORE INTO `users_temp` (`id`, `name`, `email`, `password_hash`, `created_at`)
SELECT 
  `id`, 
  `name`, 
  `email`, 
  COALESCE(
    CASE 
      WHEN LENGTH(`password`) > 30 THEN `password`
      ELSE '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
    END, 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
  ) AS `password_hash`,
  `created_at`
FROM `users`;

DROP TABLE IF EXISTS `users`;
RENAME TABLE `users_temp` TO `users`;

-- ------------------------------------------------------------------------------
-- 2. TABLE: user_profiles
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_profiles_temp` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `selected_interests` TEXT DEFAULT NULL,
  `avatar_url` VARCHAR(500) DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_profiles_uid` (`user_id`),
  CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing user_profiles if exists
INSERT IGNORE INTO `user_profiles_temp` (`user_id`, `selected_interests`, `avatar_url`, `updated_at`)
SELECT 
  p.`user_id`,
  COALESCE(p.`interests`, '["Computer Science", "Artificial Intelligence", "Mathematics"]'),
  p.`profile_photo`,
  p.`updated_at`
FROM `user_profiles` p
JOIN `users` u ON p.`user_id` = u.`id`;

-- For any users missing a profile record, seed default
INSERT IGNORE INTO `user_profiles_temp` (`user_id`, `selected_interests`, `avatar_url`)
SELECT 
  u.`id`, 
  '["Computer Science", "Artificial Intelligence", "Mathematics"]', 
  NULL
FROM `users` u
LEFT JOIN `user_profiles_temp` p ON u.`id` = p.`user_id`
WHERE p.`id` IS NULL;

DROP TABLE IF EXISTS `user_profiles`;
RENAME TABLE `user_profiles_temp` TO `user_profiles`;

-- ------------------------------------------------------------------------------
-- 3. TABLE: user_sessions
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `auth_token` VARCHAR(255) NOT NULL,
  `last_login_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  INDEX `idx_sessions_uid` (`user_id`),
  INDEX `idx_sessions_token` (`auth_token`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed sessions from existing users or user_logins if any
INSERT INTO `user_sessions` (`user_id`, `auth_token`, `last_login_at`, `ip_address`)
SELECT 
  u.`id`,
  MD5(CONCAT(u.`email`, UNIX_TIMESTAMP(), u.`id`)),
  u.`created_at`,
  '127.0.0.1'
FROM `users` u;

-- ------------------------------------------------------------------------------
-- 4. TABLE: study_notes
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `study_notes`;
CREATE TABLE `study_notes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `subject_id` VARCHAR(100) NOT NULL,
  `chapter_title` VARCHAR(255) NOT NULL,
  `note_content` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notes_user_subject` (`user_id`, `subject_id`),
  CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing notes from user_notes if table exists
INSERT INTO `study_notes` (`user_id`, `subject_id`, `chapter_title`, `note_content`, `created_at`)
SELECT 
  n.`user_id`,
  COALESCE(CAST(n.`subject_id` AS CHAR), 'mathematics'),
  n.`title`,
  n.`content`,
  n.`created_at`
FROM `user_notes` n
JOIN `users` u ON n.`user_id` = u.`id`;

-- ------------------------------------------------------------------------------
-- 5. TABLE: quiz_records
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `quiz_records`;
CREATE TABLE `quiz_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `subject_id` VARCHAR(100) NOT NULL,
  `score` INT NOT NULL,
  `total_questions` INT NOT NULL,
  `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_quiz_user_subject` (`user_id`, `subject_id`),
  CONSTRAINT `fk_quiz_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing attempts from user_quiz_attempts if table exists
INSERT INTO `quiz_records` (`user_id`, `subject_id`, `score`, `total_questions`, `completed_at`)
SELECT 
  qa.`user_id`,
  COALESCE(CAST(qa.`subject_id` AS CHAR), 'mathematics'),
  COALESCE(qa.`correct_answers`, ROUND(qa.`score_percentage` * 0.1)),
  COALESCE(qa.`total_questions`, 10),
  qa.`created_at`
FROM `user_quiz_attempts` qa
JOIN `users` u ON qa.`user_id` = u.`id`;

-- ------------------------------------------------------------------------------
-- DROP ALL REDUNDANT TABLES
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `chapter_lessons`;
DROP TABLE IF EXISTS `chapter_quizzes`;
DROP TABLE IF EXISTS `chapters`;
DROP TABLE IF EXISTS `flashcards`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `user_achievements`;
DROP TABLE IF EXISTS `user_chapter_progress`;
DROP TABLE IF EXISTS `user_flashcard_progress`;
DROP TABLE IF EXISTS `user_interests`;
DROP TABLE IF EXISTS `user_learning_activity`;
DROP TABLE IF EXISTS `user_lesson_bookmarks`;
DROP TABLE IF EXISTS `user_logins`;
DROP TABLE IF EXISTS `user_notes`;
DROP TABLE IF EXISTS `user_notifications`;
DROP TABLE IF EXISTS `user_quiz_attempts`;
DROP TABLE IF EXISTS `user_settings`;
DROP TABLE IF EXISTS `user_tutor_chats`;

SET FOREIGN_KEY_CHECKS = 1;

-- Show remaining tables
SHOW TABLES;

