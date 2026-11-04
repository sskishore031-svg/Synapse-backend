<?php
// ==============================================================================
// Quiz Management API - Synapse AI Backend (5 Core Tables: quiz_records)
// Route: GET/POST /auth/quiz.php or /quiz.php
// ==============================================================================

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
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'history';
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

    // 1. SUBMIT / RECORD QUIZ
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'submit' || $action === 'record') {
        $subjectId = trim($input['subject_id'] ?? $_POST['subject_id'] ?? 'mathematics');
        $score = (int)($input['score'] ?? $input['correct_answers'] ?? $_POST['score'] ?? 0);
        $totalQuestions = max(1, (int)($input['total_questions'] ?? $_POST['total_questions'] ?? 10));

        $stmt = $pdo->prepare("
            INSERT INTO quiz_records (user_id, subject_id, score, total_questions, completed_at) 
            VALUES (:uid, :sid, :score, :total, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'uid' => $uid,
            'sid' => $subjectId,
            'score' => $score,
            'total' => $totalQuestions
        ]);

        $recordId = (int)$pdo->lastInsertId();
        $percentage = (int)round(($score * 100.0) / $totalQuestions);

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'Quiz attempt recorded successfully!',
            'record' => [
                'id' => $recordId,
                'user_id' => $uid,
                'subject_id' => $subjectId,
                'score' => $score,
                'total_questions' => $totalQuestions,
                'score_percentage' => $percentage,
                'completed_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // 2. FETCH HISTORY / ATTEMPTS
    $stmt = $pdo->prepare("
        SELECT id, user_id, subject_id, score, total_questions, completed_at
        FROM quiz_records
        WHERE user_id = :uid
        ORDER BY id DESC
    ");
    $stmt->execute(['uid' => $uid]);
    $records = $stmt->fetchAll();

    $formattedRecords = array_map(function($r) {
        $tot = max(1, (int)$r['total_questions']);
        $sc = (int)$r['score'];
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'subject_id' => $r['subject_id'],
            'score' => $sc,
            'total_questions' => $tot,
            'score_percentage' => (int)round(($sc * 100.0) / $tot),
            'completed_at' => $r['completed_at']
        ];
    }, $records);

    $avgScore = 0;
    if (!empty($formattedRecords)) {
        $avgScore = (int)round(array_sum(array_column($formattedRecords, 'score_percentage')) / count($formattedRecords));
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'total_attempts' => count($formattedRecords),
        'average_score' => $avgScore,
        'records' => $formattedRecords
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Quiz operation failed: ' . $e->getMessage()]);
}
