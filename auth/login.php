<?php
// Login Endpoint - Synapse AI Backend (User ID / Gmail / Phone based, No Tokens, Modular Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Accept JSON payload or POST form-data
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? null;
$identifier = trim($input['email'] ?? $input['emailOrPhone'] ?? $input['phone'] ?? $input['identifier'] ?? $_POST['email'] ?? $_POST['identifier'] ?? '');
$password = $input['password'] ?? $_POST['password'] ?? '';

if ((empty($userId) && empty($identifier)) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Please provide user_id (or email/phone) and password.'
    ]);
    exit();
}

try {
    // Check by user_id OR email OR phone number
    $sql = "
        SELECT 
            u.id, COALESCE(p.name, u.name) AS name, u.email, u.phone, u.password, u.created_at,
            p.bio, p.course, p.interests, p.profile_photo,
            s.preferred_language, s.study_goal, s.notifications_enabled
        FROM users u
        LEFT JOIN user_profiles p ON u.id = p.user_id
        LEFT JOIN user_settings s ON u.id = s.user_id
    ";

    if (!empty($userId)) {
        $stmt = $pdo->prepare($sql . " WHERE u.id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
    } else {
        $stmt = $pdo->prepare($sql . " WHERE u.email = :id_email OR (u.phone IS NOT NULL AND u.phone != '' AND u.phone = :id_phone) LIMIT 1");
        $stmt->execute([
            'id_email' => $identifier,
            'id_phone' => $identifier
        ]);
    }
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid user ID/email/phone or password.'
        ]);
        exit();
    }

    // Parse interests JSON
    $interests = [];
    if (!empty($user['interests'])) {
        $decoded = json_decode($user['interests'], true);
        $interests = is_array($decoded) ? $decoded : array_map('trim', explode(',', $user['interests']));
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $photoUrl = !empty($user['profile_photo']) 
        ? $protocol . $host . "/Synapse_backend/" . ltrim($user['profile_photo'], '/') 
        : null;

    // Record login in user_logins table and update users table
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        $logStmt = $pdo->prepare("
            INSERT INTO user_logins (user_id, email, ip_address, status) 
            VALUES (:uid, :email, :ip, 'SUCCESS')
        ");
        $logStmt->execute([
            'uid' => $user['id'],
            'email' => $user['email'],
            'ip' => $ipAddress
        ]);

        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET last_login = CURRENT_TIMESTAMP 
            WHERE id = :uid
        ");
        $updateStmt->execute([
            'uid' => $user['id']
        ]);
    } catch (Exception $logEx) {
        // Table logging error non-blocking
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful.',
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'] ?? null,
            'bio' => $user['bio'] ?? null,
            'course' => $user['course'] ?? 'B.Tech Computer Science · Year 3',
            'preferred_language' => $user['preferred_language'] ?? 'English',
            'interests' => $interests,
            'study_goal' => $user['study_goal'] ?? '25 min / day',
            'notifications_enabled' => (bool)($user['notifications_enabled'] ?? 1),
            'profile_photo' => $user['profile_photo'] ?? null,
            'photo_url' => $photoUrl,
            'last_login' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error during login: ' . $e->getMessage()
    ]);
}
