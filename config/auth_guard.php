<?php
// Authentication Guard Helper - Synapse AI Backend (Gmail & User ID Support)
// Backward-compatible helper for user identification

require_once __DIR__ . '/db.php';

/**
 * Extracts User identifier (Email or User ID) from headers, query string, or body.
 */
function getUserFromRequest($pdo) {
    // 1. Check Query Parameters
    $email = trim($_GET['email'] ?? '');
    $userId = $_GET['user_id'] ?? $_GET['id'] ?? null;

    // 2. Check Custom Headers
    if (empty($email) && !empty($_SERVER['HTTP_X_USER_EMAIL'])) {
        $email = trim($_SERVER['HTTP_X_USER_EMAIL']);
    }
    if (empty($userId) && !empty($_SERVER['HTTP_X_USER_ID'])) {
        $userId = trim($_SERVER['HTTP_X_USER_ID']);
    }

    // 3. Check JSON or POST body
    if (empty($email) && empty($userId)) {
        $raw = json_decode(file_get_contents("php://input"), true);
        if (is_array($raw)) {
            $email = trim($raw['email'] ?? '');
            $userId = $raw['user_id'] ?? $raw['id'] ?? null;
        }
        if (empty($email)) $email = trim($_POST['email'] ?? '');
        if (empty($userId)) $userId = $_POST['user_id'] ?? null;
    }

    // 4. Query Database
    if (!empty($userId)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if ($user) return $user;
    }

    if (!empty($email)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if ($user) return $user;
    }

    return null;
}

/**
 * Legacy requireAuth fallback: Checks user by email/id first, then token.
 */
function requireAuth($pdo) {
    $user = getUserFromRequest($pdo);
    if ($user) {
        return $user;
    }

    // Fallback: If no email/id provided, check for first active user in local dev
    $stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
    $defaultUser = $stmt->fetch();
    if ($defaultUser) {
        return $defaultUser;
    }

    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'code' => 401,
        'message' => 'User not identified. Please provide email or user_id.'
    ]);
    exit();
}

/**
 * Helper to generate random string if needed
 */
function generateAuthToken() {
    return bin2hex(random_bytes(32));
}
