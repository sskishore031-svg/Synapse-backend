<?php
// PDO Database Configuration for Synapse AI (5 Core Tables)
$host = 'localhost';
$dbname = 'synapse_ai';
$username = 'root';
$password = ''; // Default XAMPP MySQL password is empty

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Ensure strictly the 5 core tables exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(255) NOT NULL,
          `email` VARCHAR(255) NOT NULL UNIQUE,
          `phone` VARCHAR(20) DEFAULT NULL,
          `password_hash` VARCHAR(255) NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_users_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `user_profiles` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL UNIQUE,
          `selected_interests` TEXT DEFAULT NULL,
          `avatar_url` VARCHAR(500) DEFAULT NULL,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_user_profiles_uid` (`user_id`),
          CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `user_sessions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `auth_token` VARCHAR(255) NOT NULL,
          `last_login_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `ip_address` VARCHAR(45) DEFAULT NULL,
          INDEX `idx_sessions_uid` (`user_id`),
          INDEX `idx_sessions_token` (`auth_token`),
          CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `study_notes` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `subject_id` VARCHAR(100) NOT NULL,
          `chapter_title` VARCHAR(255) NOT NULL,
          `note_content` LONGTEXT NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_notes_user_subject` (`user_id`, `subject_id`),
          CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `quiz_records` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `subject_id` VARCHAR(100) NOT NULL,
          `chapter_id` INT DEFAULT NULL,
          `score` INT NOT NULL,
          `total_questions` INT NOT NULL,
          `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_quiz_user_subject` (`user_id`, `subject_id`),
          CONSTRAINT `fk_quiz_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `user_subjects` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `subject_id` VARCHAR(100) NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uq_user_subject` (`user_id`, `subject_id`),
          CONSTRAINT `fk_us_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `user_chapter_completions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `subject_id` VARCHAR(100) NOT NULL,
          `chapter_id` INT NOT NULL,
          `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uq_user_chapter` (`user_id`, `chapter_id`),
          CONSTRAINT `fk_ucc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `learning_plan_tasks` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `category` VARCHAR(100) DEFAULT 'Lesson',
          `duration` VARCHAR(50) DEFAULT '15 min',
          `is_completed` TINYINT(1) DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_lpt_user` (`user_id`),
          CONSTRAINT `fk_lpt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `user_achievements` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `badge_id` INT NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uq_user_badge` (`user_id`, `badge_id`),
          CONSTRAINT `fk_ach_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL AFTER `email`"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone_number` VARCHAR(20) DEFAULT NULL AFTER `phone`"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `user_profiles` ADD COLUMN `course` VARCHAR(255) DEFAULT 'B.Tech Computer Science · Year 3'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `user_profiles` ADD COLUMN `study_goal` VARCHAR(100) DEFAULT '25 min / day'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `user_profiles` ADD COLUMN `bio` TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `quiz_records` ADD COLUMN `chapter_id` INT DEFAULT NULL AFTER `subject_id`"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `study_notes` ADD COLUMN `chapter_id` INT DEFAULT NULL AFTER `subject_id`"); } catch (Exception $e) {}
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}
