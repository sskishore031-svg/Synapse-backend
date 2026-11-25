<?php
// ==============================================================================
// Quiz Submission API Endpoint - Synapse AI Backend
// Route: POST /auth/submit_quiz.php or /submit_quiz.php
// Records quiz attempt, updates user lesson progress and streak
// ==============================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dbPath = file_exists(__DIR__ . '/config/db.php') ? __DIR__ . '/config/db.php' : __DIR__ . '/../config/db.php';
require_once $dbPath;

$rawInput = file_get_contents("php://input");
$input = (!empty($rawInput)) ? (json_decode($rawInput, true) ?? []) : [];

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
    $subjectId = trim($input['subject_id'] ?? $_POST['subject_id'] ?? 'python');
    $chapterId = (int)($input['chapter_id'] ?? $_GET['chapter_id'] ?? $_POST['chapter_id'] ?? 1);
    $score = (int)($input['score'] ?? $input['correct_answers'] ?? $_POST['score'] ?? 0);
    $totalQuestions = max(1, (int)($input['total_questions'] ?? $_POST['total_questions'] ?? 5));
    $percentage = (int)round(($score * 100.0) / $totalQuestions);

    // 1. Insert into quiz_records
    $stmt = $pdo->prepare("
        INSERT INTO quiz_records (user_id, subject_id, chapter_id, score, total_questions, completed_at)
        VALUES (:uid, :sid, :cid, :score, :total, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
        'uid' => $uid,
        'sid' => $subjectId,
        'cid' => $chapterId > 0 ? $chapterId : null,
        'score' => $score,
        'total' => $totalQuestions
    ]);
    $recordId = (int)$pdo->lastInsertId();

    // 2. Mark chapter quiz completed in user_chapter_completions / progress table
    try {
        $chk = $pdo->prepare("SELECT id FROM user_chapter_completions WHERE user_id = :uid AND chapter_id = :cid LIMIT 1");
        $chk->execute(['uid' => $uid, 'cid' => $chapterId]);
        if (!$chk->fetch()) {
            $insC = $pdo->prepare("INSERT INTO user_chapter_completions (user_id, chapter_id, completed_at) VALUES (:uid, :cid, CURRENT_TIMESTAMP)");
            $insC->execute(['uid' => $uid, 'cid' => $chapterId]);
        }
    } catch (Exception $e) {}

    // 3. Mark progress in user_progress table if exists
    try {
        $prog = $pdo->prepare("
            INSERT INTO user_progress (user_id, subject_id, chapter_id, is_completed, quiz_score, completed_at)
            VALUES (:uid, :sid, :cid, 1, :score, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE is_completed = 1, quiz_score = GREATEST(quiz_score, :score2), completed_at = CURRENT_TIMESTAMP
        ");
        $prog->execute([
            'uid' => $uid,
            'sid' => $subjectId,
            'cid' => $chapterId,
            'score' => $score,
            'score2' => $score
        ]);
    } catch (Exception $e) {}

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Quiz attempt recorded successfully!',
        'data' => [
            'id' => $recordId,
            'user_id' => $uid,
            'subject_id' => $subjectId,
            'chapter_id' => $chapterId,
            'score' => $score,
            'total_questions' => $totalQuestions,
            'score_percentage' => $percentage,
            'quiz_completed' => true,
            'completed_at' => date('Y-m-d H:i:s')
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Quiz submission failed: ' . $e->getMessage()]);
}
