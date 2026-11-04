<?php
// Lesson API Endpoint - Synapse AI Backend (5 Core Tables)
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
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'get';
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

    if ($action === 'complete') {
        $chapterId = (int)($_GET['chapter_id'] ?? $input['chapter_id'] ?? 1);
        $chapterTitle = $input['chapter_title'] ?? "Chapter $chapterId Lesson";

        $ins = $pdo->prepare("
            INSERT INTO study_notes (user_id, subject_id, chapter_title, note_content, created_at)
            VALUES (:uid, 'general', :title, :content, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            'uid' => $uid,
            'title' => $chapterTitle,
            'content' => "Lesson completed: $chapterTitle"
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Lesson marked as completed!']);
        exit();
    }

    echo json_encode([
        'status' => 'success',
        'lesson' => [
            'chapter_id' => 1,
            'title' => 'Quadratic Equations & Roots',
            'summary' => 'Master quadratic equations, factoring, and discriminant rules.',
            'part1' => 'Standard quadratic equation form: ax^2 + bx + c = 0.',
            'part2' => 'Quadratic formula: x = (-b +- sqrt(b^2 - 4ac)) / (2a).'
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Lesson error: ' . $e->getMessage()]);
}
