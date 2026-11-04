<?php
// Notifications Endpoint - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

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
        $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id DESC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit();
    }

    $uid = (int)$user['id'];

    $sampleNotifs = [
        ['id' => 1, 'title' => 'Welcome to SYNAPSE AI! 🚀', 'message' => 'Your on-device neural study assistant is initialized and ready.', 'type' => 'welcome', 'is_read' => false, 'created_at' => date('Y-m-d H:i:s')],
        ['id' => 2, 'title' => 'Daily Goal Ready 🎯', 'message' => 'Keep your streak alive: Complete a 10-question quiz or generate chapter notes.', 'type' => 'reminder', 'is_read' => false, 'created_at' => date('Y-m-d H:i:s', time() - 3600)],
        ['id' => 3, 'title' => 'Personalized Curriculum Active 🤖', 'message' => 'Your selected interests have been mapped to recommended courses.', 'type' => 'system', 'is_read' => true, 'created_at' => date('Y-m-d H:i:s', time() - 86400)]
    ];

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'user_id' => $uid,
        'email' => $user['email'],
        'unread_count' => 2,
        'notifications' => $sampleNotifs
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error fetching notifications: ' . $e->getMessage()]);
}
