<?php
// ==============================================================================
// Notes Management API - Synapse AI Backend (5 Core Tables: study_notes)
// Route: GET/POST /auth/notes.php or /notes.php
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
    // Authenticate user
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

    // 1. SAVE / CREATE NOTE
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'save' || $action === 'create' || !empty($input['note_content']) || !empty($input['content']) || !empty($_POST['note_content']) || !empty($_POST['content']))) {
        $subjectId = trim($input['subject_id'] ?? $_POST['subject_id'] ?? 'general');
        $title = trim($input['chapter_title'] ?? $input['title'] ?? $_POST['chapter_title'] ?? $_POST['title'] ?? 'Study Note');
        $content = trim($input['note_content'] ?? $input['content'] ?? $_POST['note_content'] ?? $_POST['content'] ?? '');

        if (empty($content)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Note content cannot be empty.']);
            exit();
        }

        $ins = $pdo->prepare("
            INSERT INTO study_notes (user_id, subject_id, chapter_title, note_content, created_at) 
            VALUES (:uid, :sid, :title, :content, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            'uid' => $uid,
            'sid' => $subjectId,
            'title' => $title,
            'content' => $content
        ]);

        $noteId = (int)$pdo->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'Note saved successfully!',
            'note' => [
                'id' => $noteId,
                'user_id' => $uid,
                'subject_id' => $subjectId,
                'chapter_title' => $title,
                'note_content' => $content,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // 2. DELETE NOTE
    if ($action === 'delete' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $noteId = (int)($input['note_id'] ?? $input['id'] ?? $_GET['note_id'] ?? $_GET['id'] ?? 0);
        $del = $pdo->prepare("DELETE FROM study_notes WHERE id = :id AND user_id = :uid");
        $del->execute(['id' => $noteId, 'uid' => $uid]);

        echo json_encode(['status' => 'success', 'message' => 'Note deleted successfully.']);
        exit();
    }

    // 3. LIST NOTES / HOME SUMMARY
    $subjectFilter = trim($_GET['subject_id'] ?? $input['subject_id'] ?? '');
    if (!empty($subjectFilter)) {
        $stmt = $pdo->prepare("SELECT * FROM study_notes WHERE user_id = :uid AND subject_id = :sid ORDER BY id DESC");
        $stmt->execute(['uid' => $uid, 'sid' => $subjectFilter]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM study_notes WHERE user_id = :uid ORDER BY id DESC");
        $stmt->execute(['uid' => $uid]);
    }
    $notes = $stmt->fetchAll();

    $formattedNotes = array_map(function($n) {
        return [
            'id' => (int)$n['id'],
            'user_id' => (int)$n['user_id'],
            'subject_id' => $n['subject_id'],
            'title' => $n['chapter_title'],
            'chapter_title' => $n['chapter_title'],
            'content' => $n['note_content'],
            'note_content' => $n['note_content'],
            'created_at' => $n['created_at']
        ];
    }, $notes);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'count' => count($formattedNotes),
        'notes' => $formattedNotes,
        'summary' => [
            'total_notes' => count($formattedNotes),
            'saved_notes' => count($formattedNotes),
            'downloaded_notes' => 0
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Notes operation failed: ' . $e->getMessage()]);
}
