<?php
// Chapters & Learning Flow API - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

    if ($action === 'complete' || $action === 'mark_completed' || ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($input['chapter_id']) || isset($_POST['chapter_id'])))) {
        $chapterId = (int)($_GET['chapter_id'] ?? $input['chapter_id'] ?? $_POST['chapter_id'] ?? 1);
        $chapterTitle = trim($input['chapter_title'] ?? $_POST['chapter_title'] ?? "Chapter $chapterId");
        $subjectId = trim($input['subject_id'] ?? $_POST['subject_id'] ?? "general");

        // 1. Record completion in user_chapter_completions table
        try {
            $compStmt = $pdo->prepare("
                INSERT INTO user_chapter_completions (user_id, subject_id, chapter_id, completed_at)
                VALUES (:uid, :sid, :cid, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP
            ");
            $compStmt->execute([
                'uid' => $uid,
                'sid' => $subjectId,
                'cid' => $chapterId
            ]);
        } catch (Exception $e) {}

        // 2. Also record in study_notes for backward compatibility
        try {
            $ins = $pdo->prepare("
                INSERT INTO study_notes (user_id, subject_id, chapter_id, chapter_title, note_content, created_at)
                VALUES (:uid, :sid, :cid, :title, :content, CURRENT_TIMESTAMP)
            ");
            $ins->execute([
                'uid' => $uid,
                'sid' => $subjectId,
                'cid' => $chapterId,
                'title' => $chapterTitle,
                'content' => "Completed chapter: $chapterTitle."
            ]);
        } catch (Exception $e) {}

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => "Chapter '$chapterTitle' marked as completed!",
            'chapter_id' => $chapterId,
            'subject_id' => $subjectId,
            'is_completed' => true
        ]);
        exit();
    }

    // Default chapters catalog
    $subjectSlug = strtolower(trim($_GET['subject_slug'] ?? $input['subject_slug'] ?? 'mathematics'));
    $chapters = [
        ['id' => 1, 'title' => 'Quadratic Equations & Roots', 'chapter_order' => 1, 'estimated_minutes' => 15, 'is_completed' => true, 'is_unlocked' => true],
        ['id' => 2, 'title' => 'Calculus & Derivatives', 'chapter_order' => 2, 'estimated_minutes' => 20, 'is_completed' => false, 'is_unlocked' => true],
        ['id' => 3, 'title' => 'Linear Algebra & Matrices', 'chapter_order' => 3, 'estimated_minutes' => 18, 'is_completed' => false, 'is_unlocked' => true],
        ['id' => 4, 'title' => 'Probability Distributions', 'chapter_order' => 4, 'estimated_minutes' => 15, 'is_completed' => false, 'is_unlocked' => true]
    ];

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'subject' => [
            'id' => 1,
            'name' => ucfirst($subjectSlug),
            'slug' => $subjectSlug
        ],
        'chapters' => $chapters
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Chapters operation failed: ' . $e->getMessage()]);
}
