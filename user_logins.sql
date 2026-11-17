-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 03, 2026 at 09:13 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `synapse_ai`
--

-- --------------------------------------------------------

--
-- Table structure for table `user_logins`
--

CREATE TABLE `user_logins` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(50) DEFAULT 'SUCCESS'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_logins`
--

INSERT INTO `user_logins` (`id`, `user_id`, `email`, `ip_address`, `login_time`, `status`) VALUES
(1, 1, 'kishore@gmail.com', '::1', '2026-10-29 04:51:11', 'SUCCESS'),
(2, 1, 'kishore@gmail.com', '::1', '2026-10-29 04:54:20', 'SUCCESS'),
(3, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-29 04:54:39', 'SUCCESS'),
(4, 5, 'srisai07@gmail.com', '172.23.49.104', '2026-10-29 04:57:45', 'SUCCESS'),
(5, 4, 'kishore31@gmail.com', '172.23.49.104', '2026-10-29 05:07:00', 'SUCCESS'),
(6, 1, 'kishore@gmail.com', '::1', '2026-10-29 05:13:42', 'SUCCESS'),
(7, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-29 05:15:52', 'SUCCESS'),
(8, 6, 'yuvadhankishore@gmail.com', '172.23.49.104', '2026-10-29 05:21:11', 'SUCCESS'),
(9, 4, 'kishore31@gmail.com', '172.23.49.104', '2026-10-29 06:43:20', 'SUCCESS'),
(10, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-29 07:09:07', 'SUCCESS'),
(11, 4, 'kishore31@gmail.com', '172.23.49.104', '2026-10-29 07:12:27', 'SUCCESS'),
(12, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-29 07:51:54', 'SUCCESS'),
(13, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-29 08:12:35', 'SUCCESS'),
(14, 7, 'kishore0031@gmail.com', '::1', '2026-10-30 03:13:19', 'SUCCESS'),
(15, 7, 'kishore0031@gmail.com', '172.23.49.104', '2026-10-30 03:16:33', 'SUCCESS'),
(16, 1, 'kishore@gmail.com', '::1', '2026-10-30 03:19:23', 'SUCCESS'),
(17, 7, 'kishore0031@gmail.com', '::1', '2026-10-30 03:20:14', 'SUCCESS'),
(18, 6, 'yuvadhankishore@gmail.com', '172.23.49.104', '2026-10-30 03:23:27', 'SUCCESS'),
(19, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-30 04:32:20', 'SUCCESS'),
(20, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-30 05:02:08', 'SUCCESS'),
(21, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-30 07:28:19', 'SUCCESS'),
(22, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-30 08:33:46', 'SUCCESS'),
(23, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-30 09:05:49', 'SUCCESS'),
(24, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-30 09:30:53', 'SUCCESS'),
(25, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-31 04:57:33', 'SUCCESS'),
(26, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-31 07:30:58', 'SUCCESS'),
(27, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-31 07:36:20', 'SUCCESS'),
(28, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-31 07:58:17', 'SUCCESS'),
(29, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-31 08:41:07', 'SUCCESS'),
(30, 4, 'kishore31@gmail.com', '172.23.49.104', '2026-10-31 08:50:45', 'SUCCESS'),
(31, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-31 09:00:21', 'SUCCESS'),
(32, 1, 'kishore@gmail.com', '127.0.0.1', '2026-10-31 09:00:43', 'SUCCESS'),
(33, 4, 'kishore31@gmail.com', '172.23.49.234', '2026-11-03 02:35:28', 'SUCCESS'),
(34, 4, 'kishore31@gmail.com', '172.23.49.234', '2026-11-03 03:47:27', 'SUCCESS'),
(35, 1, 'kishore@gmail.com', '127.0.0.1', '2026-11-03 04:01:42', 'SUCCESS'),
(36, 1, 'kishore@gmail.com', '127.0.0.1', '2026-11-03 05:19:22', 'SUCCESS'),
(37, 4, 'kishore31@gmail.com', '172.23.49.234', '2026-11-03 05:24:36', 'SUCCESS'),
(38, 1, 'kishore@gmail.com', '127.0.0.1', '2026-11-03 07:01:45', 'SUCCESS'),
(39, 4, 'kishore31@gmail.com', '172.23.49.234', '2026-11-03 07:06:10', 'SUCCESS'),
(40, 1, 'kishore@gmail.com', '127.0.0.1', '2026-11-03 07:29:54', 'SUCCESS');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `user_logins`
--
ALTER TABLE `user_logins`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_logins_uid` (`user_id`),
  ADD KEY `idx_user_logins_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `user_logins`
--
ALTER TABLE `user_logins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `user_logins`
--
ALTER TABLE `user_logins`
  ADD CONSTRAINT `fk_user_logins_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
