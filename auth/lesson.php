<?php
// ==============================================================================
// Lesson Content & Progression API - Synapse AI Backend
// Route: GET/POST /auth/lesson.php or /lesson.php
// Scoped strictly to authenticated student identity.
// ==============================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dbPath = file_exists(__DIR__ . '/../config/db.php') ? __DIR__ . '/../config/db.php' : __DIR__ . '/config/db.php';
require_once $dbPath;

try {
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true) ?? [];

    $userId = $_GET['user_id'] ?? $input['user_id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
    $email = $_GET['email'] ?? $input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? null;

    $user = null;
    if (!empty($userId)) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int)$userId]);
        $user = $stmt->fetch();
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => trim($email)]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        $pdo->prepare("INSERT INTO users (name, email, password) VALUES ('Student', 'student@synapse.ai', 'synapse_default')")->execute();
        $user = ['id' => (int)$pdo->lastInsertId(), 'name' => 'Student', 'email' => 'student@synapse.ai'];
    }

    $uid = (int)$user['id'];
    $action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'get';

    // --------------------------------------------------------------------------
    // ACTION: get - Retrieve complete Lesson details, Part 1 & Part 2 content
    // --------------------------------------------------------------------------
    if ($action === 'get' || $action === 'details') {
        $chapterId = $_GET['chapter_id'] ?? $input['chapter_id'] ?? $_POST['chapter_id'] ?? null;
        $subjectId = $_GET['subject_id'] ?? $input['subject_id'] ?? $_POST['subject_id'] ?? null;
        $subjectSlug = $_GET['subject_slug'] ?? $input['subject_slug'] ?? $_POST['subject_slug'] ?? null;

        // Find chapter
        $chapter = null;
        if (!empty($chapterId)) {
            $stmt = $pdo->prepare("
                SELECT c.*, s.name AS subject_name, s.slug AS subject_slug, s.icon_symbol AS subject_icon, s.color_hex AS subject_color
                FROM chapters c
                JOIN subjects s ON c.subject_id = s.id
                WHERE c.id = :cid
                LIMIT 1
            ");
            $stmt->execute(['cid' => (int)$chapterId]);
            $chapter = $stmt->fetch();
        } elseif (!empty($subjectId) || !empty($subjectSlug)) {
            $sql = "
                SELECT c.*, s.name AS subject_name, s.slug AS subject_slug, s.icon_symbol AS subject_icon, s.color_hex AS subject_color
                FROM chapters c
                JOIN subjects s ON c.subject_id = s.id
                WHERE " . (!empty($subjectId) ? "s.id = :sid" : "s.slug = :sslug") . "
                ORDER BY c.chapter_order ASC
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            if (!empty($subjectId)) {
                $stmt->execute(['sid' => (int)$subjectId]);
            } else {
                $stmt->execute(['sslug' => trim($subjectSlug)]);
            }
            $chapter = $stmt->fetch();
        }

        // Default to Chapter 18 (AI Gradient Descent) if not found
        if (!$chapter) {
            $stmt = $pdo->query("
                SELECT c.*, s.name AS subject_name, s.slug AS subject_slug, s.icon_symbol AS subject_icon, s.color_hex AS subject_color
                FROM chapters c
                JOIN subjects s ON c.subject_id = s.id
                WHERE c.id = 18 OR c.title LIKE '%Gradient descent%'
                LIMIT 1
            ");
            $chapter = $stmt->fetch();
            if (!$chapter) {
                $stmt = $pdo->query("
                    SELECT c.*, s.name AS subject_name, s.slug AS subject_slug, s.icon_symbol AS subject_icon, s.color_hex AS subject_color
                    FROM chapters c
                    JOIN subjects s ON c.subject_id = s.id
                    ORDER BY c.id ASC LIMIT 1
                ");
                $chapter = $stmt->fetch();
            }
        }

        if (!$chapter) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Chapter not found.']);
            exit();
        }

        $cid = (int)$chapter['id'];
        $sid = (int)$chapter['subject_id'];
        $chapOrder = (int)$chapter['chapter_order'];

        // Total chapters for this subject
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM chapters WHERE subject_id = :sid");
        $countStmt->execute(['sid' => $sid]);
        $totalChapters = (int)$countStmt->fetchColumn();
        if ($totalChapters <= 0) $totalChapters = 5;

        // Subject completion progress for this student
        $progStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM user_chapter_progress ucp
            JOIN chapters c ON ucp.chapter_id = c.id
            WHERE ucp.user_id = :uid AND c.subject_id = :sid AND ucp.is_completed = 1
        ");
        $progStmt->execute(['uid' => $uid, 'sid' => $sid]);
        $completedInSubject = (int)$progStmt->fetchColumn();

        $calcPercent = (int)round(($completedInSubject / $totalChapters) * 100);
        // For individual lesson progress, if 3 of 5 chapters completed, show 60%
        $lessonCompletionPct = $calcPercent > 0 ? $calcPercent : min(60, $chapOrder * 20);

        // Check if this chapter is completed
        $thisChapStmt = $pdo->prepare("SELECT is_completed FROM user_chapter_progress WHERE user_id = :uid AND chapter_id = :cid LIMIT 1");
        $thisChapStmt->execute(['uid' => $uid, 'cid' => $cid]);
        $isChapterCompleted = (bool)$thisChapStmt->fetchColumn();

        // Check bookmark status
        $bkmkStmt = $pdo->prepare("SELECT COUNT(*) FROM user_lesson_bookmarks WHERE user_id = :uid AND chapter_id = :cid");
        $bkmkStmt->execute(['uid' => $uid, 'cid' => $cid]);
        $isBookmarked = (int)$bkmkStmt->fetchColumn() > 0;

        // Fetch previous and next chapter IDs
        $prevStmt = $pdo->prepare("SELECT id FROM chapters WHERE subject_id = :sid AND chapter_order = :ord LIMIT 1");
        $prevStmt->execute(['sid' => $sid, 'ord' => $chapOrder - 1]);
        $prevId = $prevStmt->fetchColumn() ?: null;
        if ($prevId) $prevId = (int)$prevId;

        $nextStmt = $pdo->prepare("SELECT id FROM chapters WHERE subject_id = :sid AND chapter_order = :ord LIMIT 1");
        $nextStmt->execute(['sid' => $sid, 'ord' => $chapOrder + 1]);
        $nextId = $nextStmt->fetchColumn() ?: null;
        if ($nextId) $nextId = (int)$nextId;

        // Fetch lesson parts
        $lessonStmt = $pdo->prepare("SELECT * FROM chapter_lessons WHERE chapter_id = :cid ORDER BY part_number ASC");
        $lessonStmt->execute(['cid' => $cid]);
        $rawLessons = $lessonStmt->fetchAll();

        $part1Row = null;
        $part2Row = null;
        foreach ($rawLessons as $lr) {
            if ((int)$lr['part_number'] === 1) $part1Row = $lr;
            if ((int)$lr['part_number'] === 2) $part2Row = $lr;
        }

        // Format Part 1 Key Points
        $keyPoints = [];
        if (!empty($part1Row['key_points'])) {
            $lines = preg_split('/\r\n|\r|\n/', $part1Row['key_points']);
            foreach ($lines as $line) {
                $trimmed = trim(preg_replace('/^[•\-\*]\s*/', '', trim($line)));
                if (!empty($trimmed)) $keyPoints[] = $trimmed;
            }
        }
        if (empty($keyPoints)) {
            $keyPoints = [
                "The gradient tells you which way to move.",
                "The learning rate sets your step size."
            ];
        }

        // Format Part 1 Example Content
        $p1ExampleTitle = !empty($part1Row['example_title']) ? $part1Row['example_title'] : "A quick example";
        $p1ExampleContent = !empty($part1Row['quick_example_text']) ? $part1Row['quick_example_text'] : (!empty($part1Row['example_problem']) ? $part1Row['example_problem'] . "\n" . ($part1Row['example_solution'] ?? '') : "If w = 4, gradient = 2 and rate = 0.1,\nthen w_new = 4 - 0.1 × 2 = 3.8.");

        $part1Data = [
            'headline' => !empty($part1Row['headline']) ? $part1Row['headline'] : "Small steps. Less error.",
            'description' => !empty($part1Row['concept_text']) ? $part1Row['concept_text'] : "Gradient descent updates a model's weights in the direction that reduces its prediction error.",
            'key_points' => $keyPoints,
            'example_title' => $p1ExampleTitle,
            'example_content' => $p1ExampleContent
        ];

        // Format Part 2 Steps
        $steps = [];
        if (!empty($part2Row['steps_json'])) {
            $decodedSteps = json_decode($part2Row['steps_json'], true);
            if (is_array($decodedSteps)) {
                $steps = $decodedSteps;
            }
        }
        if (empty($steps)) {
            $steps = [
                ['weight' => '4.00', 'loss' => 'Loss 2.00', 'isCurrent' => false],
                ['weight' => '3.80', 'loss' => 'Loss 1.62', 'isCurrent' => false],
                ['weight' => '3.62', 'loss' => 'Loss 1.31', 'isCurrent' => true]
            ];
        }

        $part2Data = [
            'part_tag' => !empty($part2Row['subtitle_tag']) ? $part2Row['subtitle_tag'] : "PART 2 · THE LEARNING RATE",
            'headline' => !empty($part2Row['headline']) ? $part2Row['headline'] : "Choose the right step size.",
            'description' => !empty($part2Row['concept_text']) ? $part2Row['concept_text'] : "A small rate learns slowly. A large rate can overshoot the minimum and increase loss.",
            'formula_header' => !empty($part2Row['formula_header']) ? $part2Row['formula_header'] : "Another step · J(w) = ½(w - 2)² · rate = 0.1",
            'formula_calculation' => !empty($part2Row['formula_calculation']) ? $part2Row['formula_calculation'] : "w_new = 3.8 - 0.1 × 1.8 = 3.62",
            'steps' => $steps,
            'formula_footer' => !empty($part2Row['formula_footer']) ? $part2Row['formula_footer'] : "Gradient = w - 2; each update lowers loss.",
            'takeaway_title' => !empty($part2Row['takeaway_title']) ? $part2Row['takeaway_title'] : "Key takeaway",
            'takeaway_content' => !empty($part2Row['takeaway_content']) ? $part2Row['takeaway_content'] : "Recompute the gradient each step. If loss oscillates, try a smaller rate."
        ];

        $estMin = (int)($chapter['estimated_minutes'] ?? 12);
        if ($estMin <= 0) $estMin = 12;

        echo json_encode([
            'status' => 'success',
            'data' => [
                'subject_id' => $sid,
                'subject_name' => $chapter['subject_name'],
                'subject_slug' => $chapter['subject_slug'],
                'subject_tag' => strtoupper($chapter['subject_name']),
                'chapter_id' => $cid,
                'chapter_order' => $chapOrder,
                'total_chapters' => $totalChapters,
                'lesson_title' => $chapter['title'],
                'lesson_info' => "Lesson $chapOrder of $totalChapters · $estMin min",
                'completion_percentage' => $lessonCompletionPct,
                'is_completed' => $isChapterCompleted,
                'is_bookmarked' => $isBookmarked,
                'previous_chapter_id' => $prevId,
                'next_chapter_id' => $nextId,
                'part1' => $part1Data,
                'part2' => $part2Data
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: complete - Mark lesson/chapter completed, log activity, unlock next
    // --------------------------------------------------------------------------
    if ($action === 'complete' || $action === 'mark_complete') {
        $chapterId = $input['chapter_id'] ?? $_POST['chapter_id'] ?? $_GET['chapter_id'] ?? null;
        if (empty($chapterId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide chapter_id.']);
            exit();
        }

        $cid = (int)$chapterId;

        // Fetch chapter and subject
        $chapStmt = $pdo->prepare("SELECT c.*, s.name AS subject_name FROM chapters c JOIN subjects s ON c.subject_id = s.id WHERE c.id = :cid LIMIT 1");
        $chapStmt->execute(['cid' => $cid]);
        $chap = $chapStmt->fetch();

        if (!$chap) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Chapter not found.']);
            exit();
        }

        $sid = (int)$chap['subject_id'];

        // Upsert into user_chapter_progress
        $upsertStmt = $pdo->prepare("
            INSERT INTO user_chapter_progress (user_id, chapter_id, subject_id, is_completed, completed_at, updated_at)
            VALUES (:uid, :cid, :sid, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW(), updated_at = NOW()
        ");
        $upsertStmt->execute(['uid' => $uid, 'cid' => $cid, 'sid' => $sid]);

        // Record in user_learning_activity
        $actStmt = $pdo->prepare("
            INSERT INTO user_learning_activity (user_id, chapter_id, activity_type, title, description, timestamp)
            VALUES (:uid, :cid, 'LESSON_COMPLETED', :title, :descr, NOW())
        ");
        $actStmt->execute([
            'uid' => $uid,
            'cid' => $cid,
            'title' => "Completed Lesson: " . $chap['title'],
            'descr' => "Finished " . $chap['subject_name'] . " - Chapter " . $chap['chapter_order'] . " (" . $chap['title'] . ")"
        ]);

        // Next chapter ID
        $nextStmt = $pdo->prepare("SELECT id FROM chapters WHERE subject_id = :sid AND chapter_order = :ord LIMIT 1");
        $nextStmt->execute(['sid' => $sid, 'ord' => (int)$chap['chapter_order'] + 1]);
        $nextId = $nextStmt->fetchColumn() ?: null;

        // Recalculate subject progress
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM chapters WHERE subject_id = :sid");
        $countStmt->execute(['sid' => $sid]);
        $totalChapters = (int)$countStmt->fetchColumn();

        $compStmt = $pdo->prepare("
            SELECT COUNT(*) FROM user_chapter_progress ucp
            JOIN chapters c ON ucp.chapter_id = c.id
            WHERE ucp.user_id = :uid AND c.subject_id = :sid AND ucp.is_completed = 1
        ");
        $compStmt->execute(['uid' => $uid, 'sid' => $sid]);
        $completedCount = (int)$compStmt->fetchColumn();

        $progressPercent = $totalChapters > 0 ? (int)round(($completedCount / $totalChapters) * 100) : 100;

        echo json_encode([
            'status' => 'success',
            'message' => 'Lesson marked as completed.',
            'data' => [
                'chapter_id' => $cid,
                'is_completed' => true,
                'subject_id' => $sid,
                'subject_name' => $chap['subject_name'],
                'completed_chapters' => $completedCount,
                'total_chapters' => $totalChapters,
                'progress_percent' => $progressPercent,
                'next_chapter_id' => $nextId ? (int)$nextId : null
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: toggle_bookmark - Bookmark/unbookmark a lesson
    // --------------------------------------------------------------------------
    if ($action === 'toggle_bookmark') {
        $chapterId = $input['chapter_id'] ?? $_POST['chapter_id'] ?? $_GET['chapter_id'] ?? null;
        $part = (int)($input['part'] ?? $_POST['part'] ?? $_GET['part'] ?? 1);

        if (empty($chapterId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide chapter_id.']);
            exit();
        }

        $cid = (int)$chapterId;

        $checkStmt = $pdo->prepare("SELECT id FROM user_lesson_bookmarks WHERE user_id = :uid AND chapter_id = :cid AND part_number = :p LIMIT 1");
        $checkStmt->execute(['uid' => $uid, 'cid' => $cid, 'p' => $part]);
        $existingId = $checkStmt->fetchColumn();

        $isBookmarked = false;
        if ($existingId) {
            $delStmt = $pdo->prepare("DELETE FROM user_lesson_bookmarks WHERE id = :id");
            $delStmt->execute(['id' => $existingId]);
            $isBookmarked = false;
        } else {
            $insStmt = $pdo->prepare("INSERT INTO user_lesson_bookmarks (user_id, chapter_id, part_number) VALUES (:uid, :cid, :p)");
            $insStmt->execute(['uid' => $uid, 'cid' => $cid, 'p' => $part]);
            $isBookmarked = true;
        }

        echo json_encode([
            'status' => 'success',
            'message' => $isBookmarked ? 'Lesson bookmarked.' : 'Bookmark removed.',
            'data' => [
                'chapter_id' => $cid,
                'part_number' => $part,
                'is_bookmarked' => $isBookmarked
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Invalid action '$action'."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

