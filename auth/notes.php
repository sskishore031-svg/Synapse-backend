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

function getSubjectDisplayName($subjectId) {
    $sid = strtolower(trim((string)$subjectId));
    if ($sid === '1001' || $sid === '101' || $sid === '10101' || str_contains($sid, 'python')) return 'Python & Coding Logic';
    if ($sid === '1002' || $sid === '102' || $sid === '10201' || str_contains($sid, 'sql') || str_contains($sid, 'database')) return 'SQL & Database Management';
    if ($sid === '1003' || $sid === '103' || $sid === '10301' || str_contains($sid, 'ai') || str_contains($sid, 'tech')) return 'AI & Modern Tech Concepts';
    if ($sid === '1004' || $sid === '104' || $sid === '10401' || str_contains($sid, 'aptitude') || str_contains($sid, 'quant')) return 'Aptitude & Interview Prep';
    if ($sid === '1005' || $sid === '105' || $sid === '10501' || str_contains($sid, 'dsa') || str_contains($sid, 'data structure') || str_contains($sid, 'algorithm')) return 'Data Structures & Algorithms';
    if ($sid === '1006' || $sid === '106' || $sid === '10601' || str_contains($sid, 'java') || str_contains($sid, 'oops')) return 'Java & OOPs Mastery';
    if ($sid === '1007' || $sid === '107' || $sid === '10701' || str_contains($sid, 'os') || str_contains($sid, 'network')) return 'OS & Computer Networks';
    if ($sid === '1008' || $sid === '108' || $sid === '10801' || str_contains($sid, 'android') || str_contains($sid, 'compose')) return 'Android & App Architecture';
    if ($sid === '1' || str_contains($sid, 'math')) return 'Mathematics';
    if ($sid === '2' || str_contains($sid, 'computer')) return 'Computer Science';
    if ($sid === '3' || str_contains($sid, 'data science')) return 'Data Science';
    if ($sid === '4') return 'Artificial Intelligence';
    if ($sid === '5' || str_contains($sid, 'physics')) return 'Physics';
    if ($sid === '6' || str_contains($sid, 'chemistry')) return 'Chemistry';
    if (str_contains($sid, 'general') || $sid === 'common') return 'General Notes';
    if (!empty($subjectId) && !is_numeric($subjectId)) return ucwords(str_replace(['_', '-'], ' ', $subjectId));
    return 'General Notes';
}

function getSubjectIcon($subjectId) {
    $sid = strtolower(trim((string)$subjectId));
    if ($sid === '1001' || $sid === '101' || $sid === '10101' || str_contains($sid, 'python')) return '🐍';
    if ($sid === '1002' || $sid === '102' || $sid === '10201' || str_contains($sid, 'sql') || str_contains($sid, 'database')) return '🗄️';
    if ($sid === '1003' || $sid === '103' || $sid === '10301' || str_contains($sid, 'ai') || str_contains($sid, 'tech')) return '🤖';
    if ($sid === '1004' || $sid === '104' || $sid === '10401' || str_contains($sid, 'aptitude') || str_contains($sid, 'quant')) return '📈';
    if ($sid === '1005' || $sid === '105' || $sid === '10501' || str_contains($sid, 'dsa') || str_contains($sid, 'algorithm')) return '⚡';
    if ($sid === '1006' || $sid === '106' || $sid === '10601' || str_contains($sid, 'java') || str_contains($sid, 'oops')) return '☕';
    if ($sid === '1007' || $sid === '107' || $sid === '10701' || str_contains($sid, 'os') || str_contains($sid, 'network')) return '💻';
    if ($sid === '1008' || $sid === '108' || $sid === '10801' || str_contains($sid, 'android')) return '📱';
    if ($sid === '1' || str_contains($sid, 'math')) return '📐';
    if ($sid === '2' || str_contains($sid, 'computer')) return '💻';
    if ($sid === '3' || str_contains($sid, 'data science')) return '📊';
    if ($sid === '4') return '🤖';
    if ($sid === '5' || str_contains($sid, 'physics')) return '⚛️';
    if ($sid === '6' || str_contains($sid, 'chemistry')) return '🧪';
    return '📝';
}

// 0. Deduplicate existing redundant notes by (user_id, chapter_title)
try {
    $pdo->exec("
        DELETE n1 FROM study_notes n1
        INNER JOIN study_notes n2 
        WHERE n1.id < n2.id 
          AND n1.user_id = n2.user_id 
          AND LOWER(TRIM(n1.chapter_title)) = LOWER(TRIM(n2.chapter_title))
    ");
} catch (Exception $e) {}

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

    // 1. SAVE / CREATE NOTE (UPSERT by user_id and chapter_title)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'save' || $action === 'create' || !empty($input['note_content']) || !empty($input['content']) || !empty($_POST['note_content']) || !empty($_POST['content']))) {
        $subjectId = trim($input['subject_id'] ?? $_POST['subject_id'] ?? 'general');
        $chapterId = (int)($input['chapter_id'] ?? $_GET['chapter_id'] ?? $_POST['chapter_id'] ?? 0);
        $title = trim($input['chapter_title'] ?? $input['title'] ?? $_POST['chapter_title'] ?? $_POST['title'] ?? 'Study Note');
        $content = trim($input['note_content'] ?? $input['content'] ?? $_POST['note_content'] ?? $_POST['content'] ?? '');

        if (empty($content)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Note content cannot be empty.']);
            exit();
        }

        // Check if note with same (user_id, title) already exists to prevent duplicates
        $chk = $pdo->prepare("SELECT id FROM study_notes WHERE user_id = :uid AND LOWER(TRIM(chapter_title)) = LOWER(TRIM(:title)) LIMIT 1");
        $chk->execute(['uid' => $uid, 'title' => $title]);
        $existingId = $chk->fetchColumn();

        if ($existingId) {
            $upd = $pdo->prepare("
                UPDATE study_notes 
                SET note_content = :content, subject_id = :sid, chapter_id = :cid, created_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $upd->execute([
                'content' => $content,
                'sid' => $subjectId,
                'cid' => $chapterId > 0 ? $chapterId : null,
                'id' => $existingId
            ]);
            $noteId = (int)$existingId;
        } else {
            $ins = $pdo->prepare("
                INSERT INTO study_notes (user_id, subject_id, chapter_id, chapter_title, note_content, created_at) 
                VALUES (:uid, :sid, :cid, :title, :content, CURRENT_TIMESTAMP)
            ");
            $ins->execute([
                'uid' => $uid,
                'sid' => $subjectId,
                'cid' => $chapterId > 0 ? $chapterId : null,
                'title' => $title,
                'content' => $content
            ]);
            $noteId = (int)$pdo->lastInsertId();
        }

        $dispName = getSubjectDisplayName($subjectId);
        $dispIcon = getSubjectIcon($subjectId);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'Note saved successfully!',
            'note' => [
                'id' => $noteId,
                'user_id' => $uid,
                'subject_id' => $subjectId,
                'subject_name' => $dispName,
                'subject_icon' => $dispIcon,
                'chapter_id' => $chapterId,
                'chapter_title' => $title,
                'title' => $title,
                'note_content' => $content,
                'content' => $content,
                'category' => 'Subject Notes',
                'is_saved' => true,
                'is_downloaded' => true,
                'created_at' => date('Y-m-d H:i:s')
            ],
            'data' => [
                'id' => $noteId,
                'user_id' => $uid,
                'subject_id' => $subjectId,
                'subject_name' => $dispName,
                'subject_icon' => $dispIcon,
                'chapter_id' => $chapterId,
                'title' => $title,
                'content' => $content,
                'category' => 'Subject Notes',
                'is_saved' => true,
                'is_downloaded' => true
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
        $dispName = getSubjectDisplayName($n['subject_id']);
        $dispIcon = getSubjectIcon($n['subject_id']);
        return [
            'id' => (int)$n['id'],
            'user_id' => (int)$n['user_id'],
            'subject_id' => $n['subject_id'],
            'subject_name' => $dispName,
            'subject_icon' => $dispIcon,
            'chapter_id' => !empty($n['chapter_id']) ? (int)$n['chapter_id'] : 1,
            'title' => $n['chapter_title'],
            'chapter_title' => $n['chapter_title'],
            'content' => $n['note_content'],
            'note_content' => $n['note_content'],
            'category' => 'Subject Notes',
            'is_saved' => true,
            'is_downloaded' => true,
            'created_at' => $n['created_at']
        ];
    }, $notes);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'count' => count($formattedNotes),
        'notes' => $formattedNotes,
        'data' => [
            'type' => 'all',
            'total' => count($formattedNotes),
            'notes' => $formattedNotes
        ],
        'summary' => [
            'total_notes' => count($formattedNotes),
            'saved_notes' => count($formattedNotes),
            'downloaded_notes' => count($formattedNotes),
            'subject_notes_count' => count($formattedNotes),
            'common_notes_count' => 0
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Notes operation failed: ' . $e->getMessage()]);
}
