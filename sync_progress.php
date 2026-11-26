<?php
// ==============================================================================
// Sync Progress API Endpoint - Synapse AI Backend
// Route: GET/POST /auth/sync_progress.php or /sync_progress.php
// Persists and retrieves chapter & track completion progress across user devices.
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

// Auto-create user_lesson_progress and user_chapter_completions tables if not exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_lesson_progress` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `track_id` VARCHAR(50) NOT NULL,
            `lesson_id` VARCHAR(50) NOT NULL,
            `lesson_completed` TINYINT(1) NOT NULL DEFAULT 0,
            `quiz_completed` TINYINT(1) NOT NULL DEFAULT 0,
            `concept_done` TINYINT(1) NOT NULL DEFAULT 0,
            `quiz_done` TINYINT(1) NOT NULL DEFAULT 0,
            `is_completed` TINYINT(1) NOT NULL DEFAULT 1,
            `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_track_lesson` (`user_id`, `track_id`, `lesson_id`),
            INDEX `idx_user_track` (`user_id`, `track_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

try { $pdo->exec("ALTER TABLE user_lesson_progress ADD COLUMN concept_done TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE user_lesson_progress ADD COLUMN quiz_done TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_chapter_completions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `subject_id` VARCHAR(50) NOT NULL,
            `chapter_id` INT NOT NULL,
            `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_sub_chap` (`user_id`, `subject_id`, `chapter_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

// Identify User
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_GET['email'] ?? $input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

$user = null;
try {
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
} catch (Exception $e) {}

$uid = $user ? (int)$user['id'] : 1;
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'sync_progress' : 'get_progress');

// Handle Reset Action
if ($action === 'reset' || !empty($input['reset']) || !empty($_POST['reset'])) {
    $lessonId = trim($input['lesson_id'] ?? $input['chapter_id'] ?? $_POST['lesson_id'] ?? $_POST['chapter_id'] ?? $_GET['lesson_id'] ?? '');
    $trackId = strtolower(trim($input['track_id'] ?? $input['subject_id'] ?? $_POST['track_id'] ?? $_POST['subject_id'] ?? $_GET['track_id'] ?? 'python'));
    
    // Normalize trackId across all 8 tracks
    if (strpos($trackId, 'python') !== false || $trackId === '101') $trackId = 'python';
    elseif (strpos($trackId, 'sql') !== false || $trackId === '102') $trackId = 'sql';
    elseif (strpos($trackId, 'ai') !== false || $trackId === '103') $trackId = 'ai';
    elseif (strpos($trackId, 'aptitude') !== false || $trackId === '104') $trackId = 'aptitude';
    elseif (strpos($trackId, 'dsa') !== false || $trackId === '105') $trackId = 'dsa';
    elseif (strpos($trackId, 'java') !== false || strpos($trackId, 'oops') !== false || $trackId === '106') $trackId = 'java_oops';
    elseif (strpos($trackId, 'os') !== false || strpos($trackId, 'cn') !== false || strpos($trackId, 'network') !== false || $trackId === '107') $trackId = 'os_cn';
    elseif (strpos($trackId, 'android') !== false || $trackId === '108') $trackId = 'android_dev';
    else $trackId = 'python';

    try {
        if (!empty($lessonId)) {
            $delStmt = $pdo->prepare("DELETE FROM user_lesson_progress WHERE user_id = :uid AND track_id = :track AND lesson_id = :lesson");
            $delStmt->execute(['uid' => $uid, 'track' => $trackId, 'lesson' => $lessonId]);

            $numCid = (int)$lessonId;
            $compDel = $pdo->prepare("DELETE FROM user_chapter_completions WHERE user_id = :uid AND subject_id = :track AND chapter_id = :cid");
            $compDel->execute(['uid' => $uid, 'track' => $trackId, 'cid' => $numCid]);
        } else {
            // Reset entire track
            $delStmt = $pdo->prepare("DELETE FROM user_lesson_progress WHERE user_id = :uid AND track_id = :track");
            $delStmt->execute(['uid' => $uid, 'track' => $trackId]);
        }

        echo json_encode([
            'status' => 'success',
            'message' => "Progress reset successfully for track $trackId" . (!empty($lessonId) ? ", lesson $lessonId" : ""),
            'data' => [
                'userId' => (string)$uid,
                'trackId' => $trackId,
                'lessonId' => $lessonId,
                'isCompleted' => false
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to reset progress: ' . $e->getMessage()]);
    }
    exit();
}

if ($action === 'sync_progress' || $action === 'complete' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $lessonId = trim($input['lesson_id'] ?? $input['chapter_id'] ?? $_POST['lesson_id'] ?? $_POST['chapter_id'] ?? $_GET['lesson_id'] ?? '10101');
    $trackId = strtolower(trim($input['track_id'] ?? $input['subject_id'] ?? $_POST['track_id'] ?? $_POST['subject_id'] ?? $_GET['track_id'] ?? 'python'));
    
    // Normalize trackId across all 8 tracks
    if (strpos($trackId, 'python') !== false || $trackId === '101') $trackId = 'python';
    elseif (strpos($trackId, 'sql') !== false || $trackId === '102') $trackId = 'sql';
    elseif (strpos($trackId, 'ai') !== false || $trackId === '103') $trackId = 'ai';
    elseif (strpos($trackId, 'aptitude') !== false || $trackId === '104') $trackId = 'aptitude';
    elseif (strpos($trackId, 'dsa') !== false || $trackId === '105') $trackId = 'dsa';
    elseif (strpos($trackId, 'java') !== false || strpos($trackId, 'oops') !== false || $trackId === '106') $trackId = 'java_oops';
    elseif (strpos($trackId, 'os') !== false || strpos($trackId, 'cn') !== false || strpos($trackId, 'network') !== false || $trackId === '107') $trackId = 'os_cn';
    elseif (strpos($trackId, 'android') !== false || $trackId === '108') $trackId = 'android_dev';
    else $trackId = 'python';

    $conceptDone = !empty($input['concept_done'] ?? $input['conceptDone'] ?? $input['lesson_completed'] ?? $_POST['concept_done'] ?? $_POST['conceptDone'] ?? $_POST['lesson_completed'] ?? false) ? 1 : 0;
    $quizDone = !empty($input['quiz_done'] ?? $input['quizDone'] ?? $input['quiz_completed'] ?? $_POST['quiz_done'] ?? $_POST['quizDone'] ?? $_POST['quiz_completed'] ?? false) ? 1 : 0;
    $lessonCompleted = $conceptDone;
    $quizCompleted = $quizDone;
    $isCompleted = ($conceptDone || $quizDone || !empty($input['is_completed'] ?? $_POST['is_completed'])) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_lesson_progress (user_id, track_id, lesson_id, lesson_completed, quiz_completed, concept_done, quiz_done, is_completed, completed_at)
            VALUES (:uid, :track, :lesson, :lcomp, :qcomp, :cdone, :qdone, :iscomp, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE 
                lesson_completed = GREATEST(lesson_completed, VALUES(lesson_completed)),
                quiz_completed = GREATEST(quiz_completed, VALUES(quiz_completed)),
                concept_done = GREATEST(concept_done, VALUES(concept_done)),
                quiz_done = GREATEST(quiz_done, VALUES(quiz_done)),
                is_completed = 1,
                completed_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'uid' => $uid,
            'track' => $trackId,
            'lesson' => $lessonId,
            'lcomp' => $lessonCompleted,
            'qcomp' => $quizCompleted,
            'cdone' => $conceptDone,
            'qdone' => $quizDone,
            'iscomp' => $isCompleted
        ]);

        // Keep user_chapter_completions in sync
        $numCid = (int)$lessonId;
        $compStmt = $pdo->prepare("
            INSERT INTO user_chapter_completions (user_id, subject_id, chapter_id, completed_at)
            VALUES (:uid, :track, :cid, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP
        ");
        $compStmt->execute([
            'uid' => $uid,
            'track' => $trackId,
            'cid' => $numCid
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => "Progress saved successfully for track $trackId, lesson $lessonId",
            'data' => [
                'userId' => (string)$uid,
                'trackId' => $trackId,
                'lessonId' => $lessonId,
                'conceptDone' => (bool)$conceptDone,
                'quizDone' => (bool)$quizDone,
                'lessonCompleted' => (bool)$lessonCompleted,
                'quizCompleted' => (bool)$quizCompleted,
                'isCompleted' => true
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save progress: ' . $e->getMessage()]);
    }
    exit();
}

// Action: get_progress
try {
    $stmt = $pdo->prepare("
        SELECT track_id as trackId, lesson_id as lessonId, lesson_completed as lessonCompleted,
               COALESCE(concept_done, lesson_completed) as conceptDone,
               quiz_completed as quizCompleted, COALESCE(quiz_done, quiz_completed) as quizDone,
               is_completed as isCompleted, UNIX_TIMESTAMP(completed_at) * 1000 as completedAt
        FROM user_lesson_progress
        WHERE user_id = :uid AND is_completed = 1
        ORDER BY completed_at ASC
    ");
    $stmt->execute(['uid' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $progressList = array_map(function($r) use ($uid) {
        return [
            'lessonId' => (string)$r['lessonId'],
            'trackId' => (string)$r['trackId'],
            'userId' => (string)$uid,
            'conceptDone' => (bool)$r['conceptDone'],
            'quizDone' => (bool)$r['quizDone'],
            'lessonCompleted' => (bool)$r['lessonCompleted'],
            'quizCompleted' => (bool)$r['quizCompleted'],
            'isCompleted' => (bool)$r['isCompleted'],
            'completedAt' => (int)$r['completedAt']
        ];
    }, $rows);

    echo json_encode([
        'status' => 'success',
        'data' => $progressList
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch progress: ' . $e->getMessage()]);
}
