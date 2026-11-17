<?php
// ==============================================================================
// Learning Plan API - Synapse AI Backend
// Route: GET/POST /auth/learning_plan.php or /learning_plan.php
// Manages daily learning tasks in MySQL `learning_plan_tasks`
// ==============================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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

    // 1. ADD TASK (POST)
    if ($action === 'add' || (!empty($input['title']) && $action !== 'delete' && $action !== 'toggle')) {
        $title = trim($input['title'] ?? $_POST['title'] ?? '');
        $category = trim($input['category'] ?? $_POST['category'] ?? 'Lesson');
        $duration = trim($input['duration'] ?? $_POST['duration'] ?? '15 min');

        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Task title cannot be empty.']);
            exit();
        }

        $ins = $pdo->prepare("
            INSERT INTO learning_plan_tasks (user_id, title, category, duration, is_completed, created_at)
            VALUES (:uid, :title, :cat, :dur, 0, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            'uid' => $uid,
            'title' => $title,
            'cat' => $category,
            'dur' => $duration
        ]);
        $newTaskId = (int)$pdo->lastInsertId();

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'Task added to learning plan!',
            'task' => [
                'id' => $newTaskId,
                'user_id' => $uid,
                'title' => $title,
                'category' => $category,
                'duration' => $duration,
                'is_completed' => false,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // 2. TOGGLE / COMPLETE TASK (POST)
    if ($action === 'toggle' || $action === 'complete') {
        $taskId = (int)($input['task_id'] ?? $input['id'] ?? $_POST['task_id'] ?? $_GET['task_id'] ?? 0);
        $isCompleted = (int)($input['is_completed'] ?? $_POST['is_completed'] ?? 1);

        $upd = $pdo->prepare("UPDATE learning_plan_tasks SET is_completed = :comp WHERE id = :id AND user_id = :uid");
        $upd->execute(['comp' => $isCompleted ? 1 : 0, 'id' => $taskId, 'uid' => $uid]);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'Task completion updated.',
            'task_id' => $taskId,
            'is_completed' => (bool)$isCompleted
        ]);
        exit();
    }

    // 3. DELETE TASK (DELETE / POST)
    if ($action === 'delete' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $taskId = (int)($input['task_id'] ?? $input['id'] ?? $_POST['task_id'] ?? $_GET['task_id'] ?? 0);
        $del = $pdo->prepare("DELETE FROM learning_plan_tasks WHERE id = :id AND user_id = :uid");
        $del->execute(['id' => $taskId, 'uid' => $uid]);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'Task deleted successfully.',
            'task_id' => $taskId
        ]);
        exit();
    }

    // 4. LIST TASKS (GET)
    $stmt = $pdo->prepare("SELECT id, user_id, title, category, duration, is_completed, created_at FROM learning_plan_tasks WHERE user_id = :uid ORDER BY id ASC");
    $stmt->execute(['uid' => $uid]);
    $tasks = $stmt->fetchAll();

    $formattedTasks = array_map(function($t) {
        return [
            'id' => (int)$t['id'],
            'user_id' => (int)$t['user_id'],
            'title' => $t['title'],
            'category' => $t['category'],
            'duration' => $t['duration'],
            'is_completed' => (bool)$t['is_completed'],
            'created_at' => $t['created_at']
        ];
    }, $tasks);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'user_id' => $uid,
        'count' => count($formattedTasks),
        'tasks' => $formattedTasks
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Learning plan operation failed: ' . $e->getMessage()]);
}

