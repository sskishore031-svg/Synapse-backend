<?php
// ==============================================================================
// Chapters & Learning Flow API - Synapse AI Backend
// Route: GET/POST /auth/chapters.php or /chapters.php
// Manages subject chapter list, unlock status, lesson content, & completion.
// ==============================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id");

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
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found. Please provide valid authenticated student credentials.'
        ]);
        exit();
    }

    $uid = (int)$user['id'];

    // --------------------------------------------------------------------------
    // ACTION: list - Get chapters for a subject with unlock and completion status
    // --------------------------------------------------------------------------
    if ($action === 'list') {
        $subjectId = $_GET['subject_id'] ?? $input['subject_id'] ?? null;
        $subjectSlug = trim($_GET['subject_slug'] ?? $input['subject_slug'] ?? '');

        $subject = null;
        if (!empty($subjectId)) {
            $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = :sid LIMIT 1");
            $stmt->execute(['sid' => $subjectId]);
            $subject = $stmt->fetch();
        } elseif (!empty($subjectSlug)) {
            $stmt = $pdo->prepare("SELECT * FROM subjects WHERE slug = :slug OR LOWER(name) = :slugName LIMIT 1");
            $stmt->execute(['slug' => $subjectSlug, 'slugName' => strtolower($subjectSlug)]);
            $subject = $stmt->fetch();
        }

        if (!$subject) {
            // Default to Mathematics (subject 1)
            $stmt = $pdo->query("SELECT * FROM subjects ORDER BY display_order ASC LIMIT 1");
            $subject = $stmt->fetch();
        }

        $sid = (int)$subject['id'];

        // Fetch all chapters for this subject ordered by chapter_order
        $chapStmt = $pdo->prepare("
            SELECT id, title, chapter_order, estimated_minutes 
            FROM chapters 
            WHERE subject_id = :sid 
            ORDER BY chapter_order ASC
        ");
        $chapStmt->execute(['sid' => $sid]);
        $rawChapters = $chapStmt->fetchAll();

        // Fetch completed chapters for this user
        $progStmt = $pdo->prepare("
            SELECT chapter_id, is_completed 
            FROM user_chapter_progress 
            WHERE user_id = :uid AND subject_id = :sid
        ");
        $progStmt->execute(['uid' => $uid, 'sid' => $sid]);
        $completionMap = [];
        while ($p = $progStmt->fetch()) {
            if ($p['is_completed']) {
                $completionMap[(int)$p['chapter_id']] = true;
            }
        }

        $chapterList = [];
        $previousCompleted = true; // Chapter 1 is always unlocked by default
        $completedCount = 0;

        foreach ($rawChapters as $idx => $rc) {
            $cid = (int)$rc['id'];
            $isCompleted = isset($completionMap[$cid]);
            if ($isCompleted) $completedCount++;

            // Chapter is unlocked if it is chapter 1, or previous chapter was completed, or it is already completed
            $isUnlocked = ($rc['chapter_order'] == 1) || $previousCompleted || $isCompleted;

            $status = 'LOCKED';
            if ($isCompleted) {
                $status = 'COMPLETED';
            } elseif ($isUnlocked) {
                $status = 'ACTIVE';
            }

            $subtext = $status === 'COMPLETED' ? "Completed · " . $rc['estimated_minutes'] . " min"
                : ($status === 'ACTIVE' ? "Ready to learn · " . $rc['estimated_minutes'] . " min"
                : "Unlock after Chapter " . ($rc['chapter_order'] - 1));

            $chapterList[] = [
                'id' => (string)$cid,
                'chapter_id' => $cid,
                'title' => $rc['title'],
                'order' => (int)$rc['chapter_order'],
                'chapter_order' => (int)$rc['chapter_order'],
                'estimatedMinutes' => (int)$rc['estimated_minutes'],
                'isCompleted' => $isCompleted,
                'isUnlocked' => $isUnlocked,
                'status' => $status,
                'subtext' => $subtext
            ];

            // Next chapter is unlocked only if this chapter is completed
            $previousCompleted = $isCompleted;
        }

        $totalChapters = count($rawChapters);
        $progressPercent = $totalChapters > 0 ? (int)round(($completedCount / $totalChapters) * 100) : 0;

        echo json_encode([
            'status' => 'success',
            'data' => [
                'subject' => [
                    'id' => $sid,
                    'name' => $subject['name'],
                    'slug' => $subject['slug'],
                    'icon_symbol' => $subject['icon_symbol'] ?? '📚',
                    'color_hex' => $subject['color_hex'] ?? '#3B82F6',
                    'total_chapters' => $totalChapters,
                    'completed_chapters' => $completedCount,
                    'progress_percent' => $progressPercent,
                    'progress_subtext' => "$completedCount of $totalChapters lessons completed"
                ],
                'chapters' => $chapterList
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: lesson - Get chapter content (Part 1 and Part 2) & previous/next
    // --------------------------------------------------------------------------
    if ($action === 'lesson') {
        $chapterId = $_GET['chapter_id'] ?? $input['chapter_id'] ?? null;
        if (empty($chapterId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide chapter_id.']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT c.*, s.name AS subject_name, s.slug AS subject_slug, s.icon_symbol, s.color_hex 
            FROM chapters c 
            JOIN subjects s ON c.subject_id = s.id 
            WHERE c.id = :cid 
            LIMIT 1
        ");
        $stmt->execute(['cid' => $chapterId]);
        $chapter = $stmt->fetch();

        if (!$chapter) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Chapter not found.']);
            exit();
        }

        $sid = (int)$chapter['subject_id'];
        $order = (int)$chapter['chapter_order'];

        // Fetch previous chapter in this subject if exists
        $prevStmt = $pdo->prepare("SELECT id, title FROM chapters WHERE subject_id = :sid AND chapter_order < :ord ORDER BY chapter_order DESC LIMIT 1");
        $prevStmt->execute(['sid' => $sid, 'ord' => $order]);
        $prevChapter = $prevStmt->fetch();

        // Fetch next chapter in this subject if exists
        $nextStmt = $pdo->prepare("SELECT id, title FROM chapters WHERE subject_id = :sid AND chapter_order > :ord ORDER BY chapter_order ASC LIMIT 1");
        $nextStmt->execute(['sid' => $sid, 'ord' => $order]);
        $nextChapter = $nextStmt->fetch();

        // Fetch lesson parts
        $lessonStmt = $pdo->prepare("
            SELECT part_number, subtitle_tag, headline, concept_text, key_points, example_title, example_problem, example_solution, quick_example_text, ai_recommendation
            FROM chapter_lessons
            WHERE chapter_id = :cid
            ORDER BY part_number ASC
        ");
        $lessonStmt->execute(['cid' => $chapterId]);
        $lessonRows = $lessonStmt->fetchAll();

        $part1 = null;
        $part2 = null;

        foreach ($lessonRows as $lr) {
            $keyPointsArr = array_values(array_filter(array_map('trim', explode("\n", $lr['key_points'] ?? ''))));
            $partData = [
                'partNumber' => (int)$lr['part_number'],
                'subtitleTag' => $lr['subtitle_tag'] ?? "Part " . $lr['part_number'],
                'headline' => $lr['headline'],
                'conceptText' => $lr['concept_text'],
                'keyPoints' => $keyPointsArr,
                'exampleTitle' => $lr['example_title'] ?? "Quick Example",
                'exampleProblem' => $lr['example_problem'],
                'exampleSolution' => $lr['example_solution'],
                'quickExampleText' => $lr['quick_example_text'],
                'aiRecommendation' => $lr['ai_recommendation']
            ];

            if ($lr['part_number'] == 1) {
                $part1 = $partData;
            } elseif ($lr['part_number'] == 2) {
                $part2 = $partData;
            }
        }

        // Check if student completed this chapter
        $checkStmt = $pdo->prepare("SELECT is_completed FROM user_chapter_progress WHERE user_id = :uid AND chapter_id = :cid LIMIT 1");
        $checkStmt->execute(['uid' => $uid, 'cid' => $chapterId]);
        $isCompleted = (bool)($checkStmt->fetchColumn() ?? 0);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'subject' => [
                    'id' => $sid,
                    'name' => $chapter['subject_name'],
                    'slug' => $chapter['subject_slug'],
                    'icon_symbol' => $chapter['icon_symbol'] ?? '📚',
                    'color_hex' => $chapter['color_hex'] ?? '#3B82F6'
                ],
                'chapter' => [
                    'id' => (int)$chapter['id'],
                    'title' => $chapter['title'],
                    'chapter_order' => $order,
                    'estimated_minutes' => (int)($chapter['estimated_minutes'] ?? 15),
                    'is_completed' => $isCompleted,
                    'previous_chapter_id' => $prevChapter ? (int)$prevChapter['id'] : null,
                    'previous_chapter_title' => $prevChapter ? $prevChapter['title'] : null,
                    'next_chapter_id' => $nextChapter ? (int)$nextChapter['id'] : null,
                    'next_chapter_title' => $nextChapter ? $nextChapter['title'] : null,
                    'part1' => $part1,
                    'part2' => $part2
                ]
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: complete - Mark chapter as completed, unlock next, log activity
    // --------------------------------------------------------------------------
    if ($action === 'complete') {
        $chapterId = $_GET['chapter_id'] ?? $input['chapter_id'] ?? $_POST['chapter_id'] ?? null;
        if (empty($chapterId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide chapter_id.']);
            exit();
        }

        // Fetch chapter and subject info
        $chapStmt = $pdo->prepare("SELECT c.*, s.name AS subject_name FROM chapters c JOIN subjects s ON c.subject_id = s.id WHERE c.id = :cid LIMIT 1");
        $chapStmt->execute(['cid' => $chapterId]);
        $chapter = $chapStmt->fetch();

        if (!$chapter) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Chapter not found.']);
            exit();
        }

        $sid = (int)$chapter['subject_id'];
        $chapterTitle = $chapter['title'];
        $subjectName = $chapter['subject_name'];

        // Upsert into user_chapter_progress
        $upsertStmt = $pdo->prepare("
            INSERT INTO user_chapter_progress (user_id, chapter_id, subject_id, is_completed, completed_at)
            VALUES (:uid, :cid, :sid, 1, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = CURRENT_TIMESTAMP
        ");
        $upsertStmt->execute(['uid' => $uid, 'cid' => $chapterId, 'sid' => $sid]);

        // Insert into user_learning_activity
        $actStmt = $pdo->prepare("
            INSERT INTO user_learning_activity (user_id, activity_type, title, subtitle, subject_name)
            VALUES (:uid, 'CHAPTER_COMPLETED', :title, :subtitle, :sname)
        ");
        $actStmt->execute([
            'uid' => $uid,
            'title' => "Finished Lesson: $chapterTitle",
            'subtitle' => "Completed full chapter and mastery lessons",
            'sname' => $subjectName
        ]);

        // Calculate new subject progress
        $totStmt = $pdo->prepare("SELECT COUNT(*) FROM chapters WHERE subject_id = :sid");
        $totStmt->execute(['sid' => $sid]);
        $tot = (int)$totStmt->fetchColumn();

        $compStmt = $pdo->prepare("SELECT COUNT(DISTINCT chapter_id) FROM user_chapter_progress WHERE user_id = :uid AND subject_id = :sid AND is_completed = 1");
        $compStmt->execute(['uid' => $uid, 'sid' => $sid]);
        $comp = (int)$compStmt->fetchColumn();

        $newPercent = $tot > 0 ? (int)round(($comp / $tot) * 100) : 0;

        echo json_encode([
            'status' => 'success',
            'message' => "Chapter '$chapterTitle' marked as completed! Next chapter unlocked.",
            'data' => [
                'chapter_id' => (int)$chapterId,
                'is_completed' => true,
                'subject_id' => $sid,
                'subject_name' => $subjectName,
                'completed_chapters' => $comp,
                'total_chapters' => $tot,
                'progress_percent' => $newPercent
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Invalid action '$action'."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

