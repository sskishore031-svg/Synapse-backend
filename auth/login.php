<?php
// Login Endpoint - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Accept JSON payload or POST form-data
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? null;
$identifier = strtolower(trim($input['email'] ?? $input['emailOrPhone'] ?? $input['phone'] ?? $input['identifier'] ?? $_POST['email'] ?? $_POST['identifier'] ?? ''));
$password = $input['password'] ?? $_POST['password'] ?? '';

if ((empty($userId) && empty($identifier)) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Please provide user_id (or email) and password.'
    ]);
    exit();
}

try {
    // 1. Fetch user from users and user_profiles (Core Tables 1 & 2)
    $phoneCol = "";
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
        if ($checkCol) {
            $phoneCol = ", u.phone";
        }
    } catch (Exception $e) {}

    $profileImageCol = "";
    try {
        $checkImg = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'")->fetch();
        if ($checkImg) {
            $profileImageCol = ", u.profile_image";
        }
    } catch (Exception $e) {}

    $sql = "
        SELECT 
            u.id, u.name, u.email $phoneCol $profileImageCol, u.password_hash, u.created_at,
            p.selected_interests, p.avatar_url
        FROM users u
        LEFT JOIN user_profiles p ON u.id = p.user_id
    ";

    if (!empty($userId)) {
        $stmt = $pdo->prepare($sql . " WHERE u.id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
    } else {
        $stmt = $pdo->prepare($sql . " WHERE u.email = :email LIMIT 1");
        $stmt->execute(['email' => $identifier]);
    }
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'Invalid email or password.'
        ]);
        exit();
    }

    // 2. Parse selected interests
    $interests = ['Computer Science', 'Artificial Intelligence', 'Mathematics'];
    if (!empty($user['selected_interests'])) {
        $decoded = json_decode($user['selected_interests'], true);
        $interests = is_array($decoded) ? $decoded : array_map('trim', explode(',', $user['selected_interests']));
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $rawAvatar = !empty($user['profile_image']) ? $user['profile_image'] : (!empty($user['avatar_url']) ? $user['avatar_url'] : null);
    $avatarUrl = !empty($rawAvatar) 
        ? (str_starts_with($rawAvatar, 'http') ? $rawAvatar : $protocol . $host . "/Synapse_backend/" . ltrim($rawAvatar, '/'))
        : null;

    // 3. Generate auth token and record session in user_sessions (Core Table 3)
    $authToken = bin2hex(random_bytes(32));
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $sessionStmt = $pdo->prepare("
        INSERT INTO user_sessions (user_id, auth_token, ip_address, last_login_at) 
        VALUES (:uid, :token, :ip, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE auth_token = :token_update, ip_address = :ip_update, last_login_at = CURRENT_TIMESTAMP
    ");
    $sessionStmt->execute([
        'uid' => $user['id'],
        'token' => $authToken,
        'ip' => $ipAddress,
        'token_update' => $authToken,
        'ip_update' => $ipAddress
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Login successful!',
        'token' => $authToken,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => !empty($user['phone']) ? $user['phone'] : '',
            'course' => 'B.Tech Computer Science · Year 3',
            'preferred_language' => 'English',
            'interests' => $interests,
            'selected_interests' => $interests,
            'study_goal' => '25 min / day',
            'notifications_enabled' => true,
            'avatar_url' => $avatarUrl,
            'profile_photo' => $rawAvatar ?: $avatarUrl,
            'profile_image' => $rawAvatar ?: $avatarUrl,
            'photo_url' => $avatarUrl,
            'user_streak' => '1 Day Streak 🔥',
            'created_at' => $user['created_at']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Server error during login: ' . $e->getMessage()
    ]);
}
