<?php
// ==============================================================================
// Subjects Management API - Synapse AI Backend
// Route: GET/POST /auth/subjects.php or /subjects.php
// Manages student active curriculum subjects in MySQL `user_subjects`
// ==============================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dbPath = file_exists(__DIR__ . '/config/db.php') ? __DIR__ . '/config/db.php' : __DIR__ . '/../config/db.php';
require_once $dbPath;

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'list';
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

    // 1. ADD / REMOVE SUBJECT (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'add' || $action === 'add_subject' || $action === 'remove' || $action === 'remove_subject') {
        $subjectId = trim($input['subject_id'] ?? $_POST['subject_id'] ?? $_GET['subject_id'] ?? '');

        if (empty($subjectId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Subject ID is required.']);
            exit();
        }

        $isRemove = ($action === 'remove' || $action === 'remove_subject' || ($input['remove'] ?? false));

        if ($isRemove) {
            $del = $pdo->prepare("DELETE FROM user_subjects WHERE user_id = :uid AND subject_id = :sid");
            $del->execute(['uid' => $uid, 'sid' => $subjectId]);
            $msg = "Subject '$subjectId' removed successfully.";
        } else {
            $ins = $pdo->prepare("
                INSERT INTO user_subjects (user_id, subject_id, created_at)
                VALUES (:uid, :sid, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP
            ");
            $ins->execute(['uid' => $uid, 'sid' => $subjectId]);
            $msg = "Subject '$subjectId' added successfully.";
        }

        // Return current active subjects
        $stmt = $pdo->prepare("SELECT subject_id FROM user_subjects WHERE user_id = :uid ORDER BY id ASC");
        $stmt->execute(['uid' => $uid]);
        $activeSubjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => $msg,
            'subject_id' => $subjectId,
            'is_added' => !$isRemove,
            'active_subjects' => $activeSubjects
        ]);
        exit();
    }

    // 2. GET ACTIVE SUBJECTS (GET)
    $stmt = $pdo->prepare("SELECT subject_id, created_at FROM user_subjects WHERE user_id = :uid ORDER BY id ASC");
    $stmt->execute(['uid' => $uid]);
    $rows = $stmt->fetchAll();

    $activeList = array_map(function($r) {
        return [
            'subject_id' => $r['subject_id'],
            'added_at' => $r['created_at']
        ];
    }, $rows);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'user_id' => $uid,
        'count' => count($activeList),
        'subjects' => $activeList
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Subjects operation failed: ' . $e->getMessage()]);
}

