<?php
// ==============================================================================
// Notes Management API - Synapse AI Backend
// Route: GET/POST /auth/notes.php or /notes.php
// Manages Subject Notes, Common Notes, Saved Notes, & Downloaded Notes per student.
// ==============================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dbPath = file_exists(__DIR__ . '/config/db.php') ? __DIR__ . '/config/db.php' : __DIR__ . '/../config/db.php';
require_once $dbPath;

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'home_summary';
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_GET['email'] ?? $input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

try {
    // Authenticate student
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
        $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit();
    }

    $uid = (int)$user['id'];

    // --------------------------------------------------------------------------
    // ACTION: home_summary - Overview of Subject, Common, Saved, & Downloaded notes
    // --------------------------------------------------------------------------
    if ($action === 'home_summary') {
        // Count common notes
        $commonStmt = $pdo->prepare("SELECT COUNT(*) FROM user_notes WHERE user_id = :uid AND (subject_id IS NULL OR category = 'COMMON')");
        $commonStmt->execute(['uid' => $uid]);
        $commonCount = (int)$commonStmt->fetchColumn();

        // Count saved notes
        $savedStmt = $pdo->prepare("SELECT COUNT(*) FROM user_notes WHERE user_id = :uid AND is_saved = 1");
        $savedStmt->execute(['uid' => $uid]);
        $savedCount = (int)$savedStmt->fetchColumn();

        // Count downloaded notes
        $dlStmt = $pdo->prepare("SELECT COUNT(*) FROM user_notes WHERE user_id = :uid AND is_downloaded = 1");
        $dlStmt->execute(['uid' => $uid]);
        $dlCount = (int)$dlStmt->fetchColumn();

        // Count subject notes per subject
        $subjNotesStmt = $pdo->prepare("
            SELECT subject_id, COUNT(id) AS cnt 
            FROM user_notes 
            WHERE user_id = :uid AND subject_id IS NOT NULL 
            GROUP BY subject_id
        ");
        $subjNotesStmt->execute(['uid' => $uid]);
        $subjNotesMap = [];
        $totalSubjectNotes = 0;
        while ($r = $subjNotesStmt->fetch()) {
            $c = (int)$r['cnt'];
            $subjNotesMap[(int)$r['subject_id']] = $c;
            $totalSubjectNotes += $c;
        }

        // Fetch subjects
        $subjectsStmt = $pdo->query("SELECT id, name, slug, icon_symbol, color_hex FROM subjects ORDER BY display_order ASC, id ASC");
        $subjects = $subjectsStmt->fetchAll();

        $subjectList = [];
        foreach ($subjects as $sb) {
            $sid = (int)$sb['id'];
            $subjectList[] = [
                'id' => $sid,
                'name' => $sb['name'],
                'slug' => $sb['slug'],
                'icon_symbol' => $sb['icon_symbol'] ?? '📚',
                'color_hex' => $sb['color_hex'] ?? '#3B82F6',
                'notes_count' => $subjNotesMap[$sid] ?? 0
            ];
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'subject_notes_count' => $totalSubjectNotes,
                    'common_notes_count' => $commonCount,
                    'saved_notes_count' => $savedCount,
                    'downloaded_notes_count' => $dlCount,
                ],
                'subjects' => $subjectList
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: list - List notes filtered by type ('subject', 'common', 'saved', 'downloaded')
    // --------------------------------------------------------------------------
    if ($action === 'list') {
        $type = strtolower($_GET['type'] ?? $input['type'] ?? 'all');
        $subjectId = $_GET['subject_id'] ?? $input['subject_id'] ?? null;
        $search = trim($_GET['search'] ?? $input['search'] ?? '');

        $sql = "
            SELECT 
                n.id, n.user_id, n.subject_id, n.chapter_id, n.title, n.content, n.category, n.is_saved, n.is_downloaded, n.created_at, n.updated_at,
                s.name AS subject_name, s.slug AS subject_slug, s.icon_symbol AS subject_icon, s.color_hex AS subject_color,
                c.title AS chapter_title
            FROM user_notes n
            LEFT JOIN subjects s ON n.subject_id = s.id
            LEFT JOIN chapters c ON n.chapter_id = c.id
            WHERE n.user_id = :uid
        ";
        $params = ['uid' => $uid];

        if ($type === 'subject') {
            if (!empty($subjectId)) {
                $sql .= " AND n.subject_id = :sid";
                $params['sid'] = $subjectId;
            } else {
                $sql .= " AND n.subject_id IS NOT NULL";
            }
        } elseif ($type === 'common') {
            $sql .= " AND (n.subject_id IS NULL OR n.category = 'COMMON')";
        } elseif ($type === 'saved') {
            $sql .= " AND n.is_saved = 1";
        } elseif ($type === 'downloaded') {
            $sql .= " AND n.is_downloaded = 1";
        }

        if (!empty($search)) {
            $sql .= " AND (n.title LIKE :search OR n.content LIKE :search)";
            $params['search'] = "%$search%";
        }

        $sql .= " ORDER BY n.updated_at DESC, n.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $notes = [];
        foreach ($rows as $r) {
            $notes[] = [
                'id' => (int)$r['id'],
                'user_id' => (int)$r['user_id'],
                'subject_id' => $r['subject_id'] ? (int)$r['subject_id'] : null,
                'chapter_id' => $r['chapter_id'] ? (int)$r['chapter_id'] : null,
                'title' => $r['title'],
                'content' => $r['content'],
                'category' => $r['category'],
                'is_saved' => (bool)$r['is_saved'],
                'is_downloaded' => (bool)$r['is_downloaded'],
                'subject_name' => $r['subject_name'] ?? 'General / Common',
                'subject_slug' => $r['subject_slug'],
                'subject_icon' => $r['subject_icon'] ?? '📝',
                'subject_color' => $r['subject_color'] ?? '#8B5CF6',
                'chapter_title' => $r['chapter_title'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at']
            ];
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'type' => $type,
                'total' => count($notes),
                'notes' => $notes
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: create - Create a new note
    // --------------------------------------------------------------------------
    if ($action === 'create') {
        $title = trim($input['title'] ?? $_POST['title'] ?? '');
        $content = trim($input['content'] ?? $_POST['content'] ?? '');
        $subjectId = $input['subject_id'] ?? $_POST['subject_id'] ?? null;
        $chapterId = $input['chapter_id'] ?? $_POST['chapter_id'] ?? null;
        $category = !empty($subjectId) ? 'SUBJECT' : 'COMMON';

        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide a note title.']);
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO user_notes (user_id, subject_id, chapter_id, title, content, category, is_saved, is_downloaded)
            VALUES (:uid, :sid, :cid, :title, :content, :cat, 1, 0)
        ");
        $stmt->execute([
            'uid' => $uid,
            'sid' => !empty($subjectId) ? $subjectId : null,
            'cid' => !empty($chapterId) ? $chapterId : null,
            'title' => $title,
            'content' => $content,
            'cat' => $category
        ]);

        $newId = (int)$pdo->lastInsertId();

        echo json_encode([
            'status' => 'success',
            'message' => 'Note created successfully.',
            'data' => [
                'id' => $newId,
                'title' => $title,
                'category' => $category
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: update - Update note title and content
    // --------------------------------------------------------------------------
    if ($action === 'update') {
        $noteId = $input['id'] ?? $input['note_id'] ?? $_POST['id'] ?? null;
        $title = trim($input['title'] ?? $_POST['title'] ?? '');
        $content = trim($input['content'] ?? $_POST['content'] ?? '');

        if (empty($noteId) || empty($title)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide note id and title.']);
            exit();
        }

        $stmt = $pdo->prepare("
            UPDATE user_notes 
            SET title = :title, content = :content 
            WHERE id = :nid AND user_id = :uid
        ");
        $stmt->execute([
            'title' => $title,
            'content' => $content,
            'nid' => $noteId,
            'uid' => $uid
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Note updated successfully.'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: delete - Delete a note belonging to this student
    // --------------------------------------------------------------------------
    if ($action === 'delete') {
        $noteId = $_GET['id'] ?? $_GET['note_id'] ?? $input['id'] ?? $input['note_id'] ?? $_POST['id'] ?? null;
        if (empty($noteId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide note id.']);
            exit();
        }

        $stmt = $pdo->prepare("DELETE FROM user_notes WHERE id = :nid AND user_id = :uid");
        $stmt->execute(['nid' => $noteId, 'uid' => $uid]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Note deleted successfully.'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: toggle_save / toggle_download
    // --------------------------------------------------------------------------
    if ($action === 'toggle_save') {
        $noteId = $input['id'] ?? $input['note_id'] ?? $_POST['id'] ?? null;
        $stmt = $pdo->prepare("UPDATE user_notes SET is_saved = IF(is_saved = 1, 0, 1) WHERE id = :nid AND user_id = :uid");
        $stmt->execute(['nid' => $noteId, 'uid' => $uid]);
        echo json_encode(['status' => 'success', 'message' => 'Saved status toggled.']);
        exit();
    }

    if ($action === 'toggle_download') {
        $noteId = $input['id'] ?? $input['note_id'] ?? $_POST['id'] ?? null;
        $stmt = $pdo->prepare("UPDATE user_notes SET is_downloaded = IF(is_downloaded = 1, 0, 1) WHERE id = :nid AND user_id = :uid");
        $stmt->execute(['nid' => $noteId, 'uid' => $uid]);
        echo json_encode(['status' => 'success', 'message' => 'Download status toggled.']);
        exit();
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Invalid action '$action'."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

