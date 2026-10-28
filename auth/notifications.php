<?php
// Notifications Endpoint - Synapse AI Backend (Gmail / User ID Based, No Tokens)
// Corresponds to NotificationCenterPreviewScreen and Profile Notifications
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Accept user_id or email from query parameters, POST body, JSON payload, or header
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_GET['email'] ?? $input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

try {
    // Locate user
    $user = null;
    if (!empty($userId)) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
    } else {
        // Fallback to first user in database
        $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found. Please provide registered email or user_id.'
        ]);
        exit();
    }

    $uid = (int)$user['id'];

    // Seed default sample notifications if none exist
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :uid");
    $checkStmt->execute(['uid' => $uid]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count === 0) {
        $sampleNotifs = [
            ['title' => 'Daily Goal Achieved! 🎯', 'msg' => 'You hit your 25-minute study target today. Keep up the momentum!', 'type' => 'goal'],
            ['title' => 'New AI Summary Ready 🤖', 'msg' => 'Your summary for Data Structures & Algorithms is ready to review.', 'type' => 'content'],
            ['title' => 'Streak Reminder 🔥', 'msg' => 'Study for 10 minutes to maintain your active learning streak.', 'type' => 'reminder']
        ];
        $insert = $pdo->prepare("INSERT INTO user_notifications (user_id, title, message, type) VALUES (:uid, :t, :m, :type)");
        foreach ($sampleNotifs as $n) {
            $insert->execute(['uid' => $uid, 't' => $n['title'], 'm' => $n['msg'], 'type' => $n['type']]);
        }
    }

    $fetch = $pdo->prepare("SELECT id, title, message, type, is_read, created_at FROM user_notifications WHERE user_id = :uid ORDER BY created_at DESC");
    $fetch->execute(['uid' => $uid]);
    $notifs = $fetch->fetchAll();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'user_id' => $uid,
        'email' => $user['email'],
        'unread_count' => count(array_filter($notifs, fn($n) => !$n['is_read'])),
        'notifications' => array_map(function($n) {
            return [
                'id' => (int)$n['id'],
                'title' => $n['title'],
                'message' => $n['message'],
                'type' => $n['type'],
                'is_read' => (bool)$n['is_read'],
                'created_at' => $n['created_at']
            ];
        }, $notifs)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error fetching notifications: ' . $e->getMessage()
    ]);
}
