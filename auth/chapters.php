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

    // Dynamic chapters catalog
    $rawSubject = trim($_GET['subject_id'] ?? $_GET['subject_slug'] ?? $input['subject_id'] ?? $input['subject_slug'] ?? 'mathematics');
    $subjectSlug = strtolower($rawSubject);

    // Dynamic Tamil Nadu State Board (Samacheer Kalvi) chapters catalog
    $rawSubject = trim($_GET['subject_id'] ?? $_GET['subject_slug'] ?? $input['subject_id'] ?? $input['subject_slug'] ?? '1001');
    $subjectSlug = strtolower($rawSubject);

    $subjectMap = [];
    $catalogFile = file_exists(__DIR__ . '/extended_curriculum_catalog.json')
        ? __DIR__ . '/extended_curriculum_catalog.json'
        : 'C:/Users/SCAD LAB/AndroidStudioProjects/SYNAPSE/app/src/main/assets/extended_curriculum_catalog.json';

    if (file_exists($catalogFile)) {
        $catalogData = json_decode(file_get_contents($catalogFile), true);
        if (!empty($catalogData['subjects'])) {
            foreach ($catalogData['subjects'] as $subj) {
                $sid = (string)($subj['subject_id'] ?? $subj['id']);
                $sChapters = [];
                if (!empty($subj['chapters'])) {
                    foreach ($subj['chapters'] as $ch) {
                        $sChapters[] = [
                            'id' => (int)($ch['chapter_id'] ?? $ch['id']),
                            'title' => $ch['title'],
                            'chapter_order' => (int)($ch['chapter_order'] ?? 1),
                            'estimated_minutes' => (int)($ch['estimated_minutes'] ?? 20)
                        ];
                    }
                }
                $subjectMap[$sid] = [
                    'id' => (int)$sid,
                    'name' => $subj['name'],
                    'slug' => $subj['slug'] ?? strtolower(str_replace(' ', '-', $subj['name'])),
                    'chapters' => $sChapters
                ];
            }
        }
    }

    // Comprehensive slug aliases for 4 Placement & Micro-Learning Tracks
    $slugAliases = [
        '101' => '101', 'python' => '101', 'py' => '101', 'coding' => '101', 'python-coding-logic' => '101', 'sub_python' => '101',
        '102' => '102', 'sql' => '102', 'db' => '102', 'database' => '102', 'sql-database-management' => '102', 'rdbms' => '102', 'sub_sql' => '102',
        '103' => '103', 'ai' => '103', 'tech' => '103', 'ai_tech' => '103', 'ai-modern-tech-concepts' => '103', 'genai' => '103', 'llm' => '103', 'sub_ai' => '103',
        '104' => '104', 'aptitude' => '104', 'prep' => '104', 'interview' => '104', 'aptitude-interview-prep' => '104', 'quant' => '104', 'sub_aptitude' => '104'
    ];

    $matchedKey = $subjectMap[$rawSubject] ?? null;
    if (!$matchedKey && isset($slugAliases[$subjectSlug])) {
        $matchedKey = $subjectMap[$slugAliases[$subjectSlug]] ?? null;
    }
    if (!$matchedKey) {
        foreach ($subjectMap as $k => $subj) {
            if ($subj['slug'] === $subjectSlug || strtolower($subj['name']) === $subjectSlug) {
                $matchedKey = $subj;
                break;
            }
        }
    }
    if (!$matchedKey && !empty($subjectMap)) {
        $matchedKey = reset($subjectMap); // default first subject (10th Mathematics)
    }

    // Get user completions from DB
    $completedSet = [];
    try {
        $cStmt = $pdo->prepare("SELECT chapter_id FROM user_chapter_completions WHERE user_id = :uid");
        $cStmt->execute(['uid' => $uid]);
        $rows = $cStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $cid) {
            $completedSet[(int)$cid] = true;
        }
    } catch (Exception $e) {}

    $chaptersWithStatus = array_map(function($ch, $idx) use ($completedSet) {
        $isComp = isset($completedSet[(int)$ch['id']]);
        return [
            'id' => $ch['id'],
            'chapter_id' => $ch['id'],
            'title' => $ch['title'],
            'chapter_order' => $ch['chapter_order'],
            'estimated_minutes' => $ch['estimated_minutes'],
            'is_completed' => $isComp,
            'is_unlocked' => true,
            'status' => $isComp ? 'COMPLETED' : ($idx === 0 ? 'ACTIVE' : 'UNLOCKED')
        ];
    }, $matchedKey['chapters'], array_keys($matchedKey['chapters']));

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'data' => [
            'subject' => [
                'id' => $matchedKey['id'],
                'name' => $matchedKey['name'],
                'slug' => $matchedKey['slug']
            ],
            'chapters' => $chaptersWithStatus
        ],
        'subject' => [
            'id' => $matchedKey['id'],
            'name' => $matchedKey['name'],
            'slug' => $matchedKey['slug']
        ],
        'chapters' => $chaptersWithStatus
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Chapters operation failed: ' . $e->getMessage()]);
}
