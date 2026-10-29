<?php
// PDO Database Configuration for Synapse AI
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

    // Ensure core users and user_logins tables are automatically created if missing
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(255) NOT NULL,
          `email` VARCHAR(255) NOT NULL UNIQUE,
          `phone` VARCHAR(25) DEFAULT NULL,
          `password` VARCHAR(255) NOT NULL,
          `last_login` TIMESTAMP NULL DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_users_email` (`email`),
          INDEX `idx_users_phone` (`phone`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `user_logins` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `email` VARCHAR(255) NOT NULL,
          `ip_address` VARCHAR(45) DEFAULT NULL,
          `login_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `status` VARCHAR(50) DEFAULT 'SUCCESS',
          INDEX `idx_user_logins_uid` (`user_id`),
          INDEX `idx_user_logins_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}
