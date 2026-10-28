-- phpMyAdmin SQL Dump
-- Database: `synapse_ai`
-- 

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE DATABASE IF NOT EXISTS `synapse_ai` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `synapse_ai`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(25) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `password_resets`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) DEFAULT 'direct_reset',
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `user_profiles`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_profiles`;
CREATE TABLE `user_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `course` varchar(255) DEFAULT 'B.Tech Computer Science · Year 3',
  `interests` text DEFAULT NULL,
  `profile_photo` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_user_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `user_interests`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_interests`;
CREATE TABLE `user_interests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `interest` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_user_interests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `user_settings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_settings`;
CREATE TABLE `user_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `preferred_language` varchar(50) DEFAULT 'English',
  `study_goal` varchar(100) DEFAULT '25 min / day',
  `notifications_enabled` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `user_achievements`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_achievements`;
CREATE TABLE `user_achievements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `badge_icon` varchar(50) DEFAULT '🏅',
  `is_unlocked` tinyint(1) DEFAULT 1,
  `unlocked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_user_achievements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `user_notifications`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_notifications`;
CREATE TABLE `user_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_user_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- Dumping data for table `users`
-- --------------------------------------------------------

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password`) VALUES
(1, 'Demo User', 'demo@example.com', '+1 202-555-0147', '$2y$10$xrcHIZWhSuPg.TMAfxEWteTlPcwgGg1qkdAswa.DIMF2X7gXvWfBa');

-- --------------------------------------------------------
-- Dumping data for table `user_profiles`
-- --------------------------------------------------------

INSERT INTO `user_profiles` (`id`, `user_id`, `name`, `bio`, `course`, `interests`, `profile_photo`) VALUES
(1, 1, 'Demo User', 'Senior Computer Science Student & AI Developer', 'B.Tech Computer Science · Year 3', '["Computer Science", "Artificial Intelligence", "Mathematics"]', NULL);

-- --------------------------------------------------------
-- Dumping data for table `user_interests`
-- --------------------------------------------------------

INSERT INTO `user_interests` (`id`, `user_id`, `interest`) VALUES
(1, 1, 'Computer Science'),
(2, 1, 'Artificial Intelligence'),
(3, 1, 'Mathematics');

-- --------------------------------------------------------
-- Dumping data for table `user_settings`
-- --------------------------------------------------------

INSERT INTO `user_settings` (`id`, `user_id`, `preferred_language`, `study_goal`, `notifications_enabled`) VALUES
(1, 1, 'English', '25 min / day', 1);

-- --------------------------------------------------------
-- Dumping data for table `user_achievements`
-- --------------------------------------------------------

INSERT INTO `user_achievements` (`id`, `user_id`, `title`, `description`, `badge_icon`, `is_unlocked`) VALUES
(1, 1, 'Quick Learner', 'Completed your first 5 AI lessons', '⚡', 1),
(2, 1, '7-Day Streak', 'Studied consecutively for 7 days', '🔥', 1),
(3, 1, 'Quiz Master', 'Scored 100% on 3 assessments', '🎯', 1),
(4, 1, 'Night Owl', 'Completed study sessions past 10 PM', '🦉', 1),
(5, 1, 'Problem Solver', 'Solved 20 coding challenges', '🧩', 1),
(6, 1, 'AI Explorer', 'Generated 50 AI summaries and notes', '🤖', 1),
(7, 1, 'Consistent Scholar', 'Reached 30 hours of total study time', '📚', 1),
(8, 1, 'Early Bird', 'Completed morning study sessions', '🌅', 1);

-- --------------------------------------------------------
-- Dumping data for table `user_notifications`
-- --------------------------------------------------------

INSERT INTO `user_notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`) VALUES
(1, 1, 'Daily Goal Achieved! 🎯', 'You hit your 25-minute study target today. Keep up the momentum!', 'goal', 0),
(2, 1, 'New AI Summary Ready 🤖', 'Your summary for Data Structures & Algorithms is ready to review.', 'content', 0),
(3, 1, 'Streak Reminder 🔥', 'Study for 10 minutes to maintain your active learning streak.', 'reminder', 1);

-- --------------------------------------------------------
-- Dumping data for table `password_resets`
-- --------------------------------------------------------

INSERT INTO `password_resets` (`id`, `email`, `token`, `expires_at`, `used`) VALUES
(1, 'demo@example.com', 'direct_in_app_reset', NOW() + INTERVAL 1 HOUR, 1);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
