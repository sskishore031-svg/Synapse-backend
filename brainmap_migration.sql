-- ==============================================================================
-- Synapse AI - BrainMap Learning Analytics & Progress Migration
-- Database: synapse_ai
-- ==============================================================================

USE `synapse_ai`;

-- 1. Subjects Table
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `icon_symbol` VARCHAR(50) DEFAULT '📚',
  `color_hex` VARCHAR(20) DEFAULT '#3B82F6',
  `display_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Chapters Table
CREATE TABLE IF NOT EXISTS `chapters` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `subject_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `chapter_order` INT NOT NULL DEFAULT 1,
  `estimated_minutes` INT DEFAULT 15,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_chapters_subject` (`subject_id`),
  CONSTRAINT `fk_chapters_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. User Chapter Progress Table
CREATE TABLE IF NOT EXISTS `user_chapter_progress` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `chapter_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `is_completed` TINYINT(1) DEFAULT 0,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_chapter` (`user_id`, `chapter_id`),
  INDEX `idx_ucp_user` (`user_id`),
  INDEX `idx_ucp_subject` (`subject_id`),
  CONSTRAINT `fk_ucp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ucp_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ucp_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. User Quiz Attempts Table
CREATE TABLE IF NOT EXISTS `user_quiz_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `quiz_title` VARCHAR(255) NOT NULL,
  `score_percentage` INT NOT NULL,
  `correct_answers` INT DEFAULT 0,
  `total_questions` INT DEFAULT 10,
  `time_taken_seconds` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_uqa_user` (`user_id`),
  INDEX `idx_uqa_subject` (`subject_id`),
  CONSTRAINT `fk_uqa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uqa_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. User Learning Activity History Table
CREATE TABLE IF NOT EXISTS `user_learning_activity` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `activity_type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `subtitle` VARCHAR(255) DEFAULT NULL,
  `subject_name` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ula_user` (`user_id`),
  CONSTRAINT `fk_ula_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Default Subjects
INSERT IGNORE INTO `subjects` (`id`, `name`, `slug`, `icon_symbol`, `color_hex`, `display_order`) VALUES
(1, 'Mathematics', 'mathematics', '∑', '#3B82F6', 1),
(2, 'Computer Science', 'computer-science', '</>', '#10B981', 2),
(3, 'Data Science', 'data-science', '📈', '#8B5CF6', 3),
(4, 'Artificial Intelligence', 'artificial-intelligence', '🧠', '#2563EB', 4),
(5, 'Physics', 'physics', '⚛', '#D97706', 5),
(6, 'Chemistry', 'chemistry', '🧪', '#EF4444', 6);

-- Seed Default Chapters (5 per subject = 30 total chapters)
-- Subject 1: Mathematics
INSERT IGNORE INTO `chapters` (`id`, `subject_id`, `title`, `chapter_order`, `estimated_minutes`) VALUES
(1, 1, 'Quadratic Equations', 1, 15),
(2, 1, 'Differential Calculus', 2, 20),
(3, 1, 'Linear Algebra & Matrices', 3, 25),
(4, 1, 'Integral Calculus', 4, 20),
(5, 1, 'Probability & Statistics', 5, 15),
-- Subject 2: Computer Science
(6, 2, 'Introduction to Algorithms', 1, 15),
(7, 2, 'Data Structures & Arrays', 2, 20),
(8, 2, 'Sorting & Searching Algorithms', 3, 25),
(9, 2, 'Graph Theory & Trees', 4, 30),
(10, 2, 'Dynamic Programming', 5, 30),
-- Subject 3: Data Science
(11, 3, 'Exploratory Data Analysis', 1, 15),
(12, 3, 'Data Visualization', 2, 15),
(13, 3, 'Statistical Inference', 3, 20),
(14, 3, 'Linear & Logistic Regression', 4, 25),
(15, 3, 'Feature Engineering', 5, 20),
-- Subject 4: Artificial Intelligence
(16, 4, 'Introduction to AI', 1, 10),
(17, 4, 'Neural Network Basics', 2, 15),
(18, 4, 'Gradient Descent', 3, 15),
(19, 4, 'Backpropagation', 4, 20),
(20, 4, 'Model Evaluation & Metrics', 5, 15),
-- Subject 5: Physics
(21, 5, 'Mechanical Waves', 1, 15),
(22, 5, 'Optics & Wave Motion', 2, 20),
(23, 5, 'Thermodynamics & Heat', 3, 20),
(24, 5, 'Electromagnetism', 4, 25),
(25, 5, 'Quantum Mechanics Basics', 5, 30),
-- Subject 6: Chemistry
(26, 6, 'Chemical Bonding Basics', 1, 15),
(27, 6, 'Molecular Orbital Theory', 2, 20),
(28, 6, 'Organic Reaction Mechanisms', 3, 25),
(29, 6, 'Thermodynamics & Equilibrium', 4, 20),
(30, 6, 'Electrochemistry', 5, 20);

