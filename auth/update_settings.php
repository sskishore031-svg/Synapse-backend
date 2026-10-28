<?php
// Settings Update Endpoint - Synapse AI Backend (Gmail / User ID Based, Modular Tables)
// Corresponds to Notifications, Study Goals & Language Settings
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($input['email'] ?? $_POST['email'] ?? $_GET['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

try {
    // 1. Locate user
    $sql = "
        SELECT u.id, u.email, s.study_goal, s.preferred_language, s.notifications_enabled 
        FROM users u 
        LEFT JOIN user_settings s ON u.id = s.user_id
    ";

    if (!empty($userId)) {
        $stmt = $pdo->prepare($sql . " WHERE u.id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare($sql . " WHERE u.email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
    } else {
        $stmt = $pdo->query($sql . " ORDER BY u.id ASC LIMIT 1");
    }
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found. Please provide registered email or user_id.'
        ]);
        exit();
    }

    $uid = (int)$user['id'];
    $studyGoal = isset($input['study_goal']) ? trim($input['study_goal']) : (isset($_POST['study_goal']) ? trim($_POST['study_goal']) : ($user['study_goal'] ?? '25 min / day'));
    $language = isset($input['preferred_language']) ? trim($input['preferred_language']) : (isset($_POST['preferred_language']) ? trim($_POST['preferred_language']) : ($user['preferred_language'] ?? 'English'));
    
    $notifications = isset($input['notifications_enabled']) 
        ? ($input['notifications_enabled'] ? 1 : 0) 
        : (isset($_POST['notifications_enabled']) ? ($_POST['notifications_enabled'] ? 1 : 0) : ($user['notifications_enabled'] ?? 1));

    // 2. Upsert user_settings
    $updateStmt = $pdo->prepare("
        INSERT INTO user_settings (user_id, study_goal, preferred_language, notifications_enabled)
        VALUES (:uid, :goal, :lang, :notif)
        ON DUPLICATE KEY UPDATE 
            study_goal = VALUES(study_goal),
            preferred_language = VALUES(preferred_language),
            notifications_enabled = VALUES(notifications_enabled)
    ");
    $updateStmt->execute([
        'uid' => $uid,
        'goal' => $studyGoal,
        'lang' => $language,
        'notif' => $notifications
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Settings updated successfully.',
        'settings' => [
            'user_id' => $uid,
            'email' => $user['email'],
            'study_goal' => $studyGoal,
            'preferred_language' => $language,
            'notifications_enabled' => (bool)$notifications
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to update settings: ' . $e->getMessage()
    ]);
}
