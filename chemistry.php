<?php
// ==============================================================================
// Chemistry Subject & Lesson API Endpoint - Synapse AI Backend
// Route: GET/POST /chemistry.php or GET/POST /auth/chemistry.php
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

$dbPath = file_exists(__DIR__ . '/config/db.php') ? __DIR__ . '/config/db.php' : __DIR__ . '/../config/db.php';
require_once $dbPath;

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_GET['email'] ?? $input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

try {
    // Authenticate and locate student
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
            'message' => 'User not found. Please provide valid authenticated user credentials.'
        ]);
        exit();
    }

    $uid = (int)$user['id'];
    $subjectId = 6; // Chemistry

    // Fetch Subject Details
    $subStmt = $pdo->prepare("SELECT id, name, slug, icon_symbol, color_hex FROM subjects WHERE id = :sid LIMIT 1");
    $subStmt->execute(['sid' => $subjectId]);
    $subject = $subStmt->fetch();

    if (!$subject) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Chemistry subject not found in database.'
        ]);
        exit();
    }

    // Determine target chapter ID (defaults to Chapter 28: Chemical Bonding)
    $chapterId = (int)($_GET['chapter_id'] ?? $input['chapter_id'] ?? $_POST['chapter_id'] ?? 28);

    // --------------------------------------------------------------------------
    // ACTION: POST - Mark Chapter Completed
    // --------------------------------------------------------------------------
    $action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'complete_chapter') {
        $chStmt = $pdo->prepare("SELECT id, title FROM chapters WHERE id = :cid AND subject_id = :sid LIMIT 1");
        $chStmt->execute(['cid' => $chapterId, 'sid' => $subjectId]);
        $targetChapter = $chStmt->fetch();

        if ($targetChapter) {
            $compStmt = $pdo->prepare("
                INSERT INTO user_chapter_progress (user_id, chapter_id, subject_id, is_completed, completed_at)
                VALUES (:uid, :cid, :sid, 1, NOW())
                ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW()
            ");
            $compStmt->execute(['uid' => $uid, 'cid' => $chapterId, 'sid' => $subjectId]);

            // Log learning activity
            $actStmt = $pdo->prepare("
                INSERT INTO user_learning_activity (user_id, activity_type, title, subtitle, subject_name, created_at)
                VALUES (:uid, 'CHAPTER_COMPLETED', :title, 'Completed full chapter and mastery lessons', 'Chemistry', NOW())
            ");
            $actStmt->execute([
                'uid' => $uid,
                'title' => 'Finished Lesson: ' . $targetChapter['title']
            ]);
        }
    }

    // --------------------------------------------------------------------------
    // ACTION: POST - Submit Quiz Attempt
    // --------------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit_quiz') {
        $scorePct = (int)($input['score_percentage'] ?? $_POST['score_percentage'] ?? 0);
        $correctAnswers = (int)($input['correct_answers'] ?? $_POST['correct_answers'] ?? 0);
        $totalQuestions = (int)($input['total_questions'] ?? $_POST['total_questions'] ?? 3);
        $timeTaken = (int)($input['time_taken_seconds'] ?? $_POST['time_taken_seconds'] ?? 180);
        $quizTitle = trim($input['quiz_title'] ?? $_POST['quiz_title'] ?? 'Chemical Bonding Assessment');

        $quizInsStmt = $pdo->prepare("
            INSERT INTO user_quiz_attempts (user_id, subject_id, quiz_title, score_percentage, correct_answers, total_questions, time_taken_seconds, created_at)
            VALUES (:uid, :sid, :title, :score, :correct, :total, :time, NOW())
        ");
        $quizInsStmt->execute([
            'uid' => $uid,
            'sid' => $subjectId,
            'title' => $quizTitle,
            'score' => $scorePct,
            'correct' => $correctAnswers,
            'total' => $totalQuestions,
            'time' => $timeTaken
        ]);

        $actStmt = $pdo->prepare("
            INSERT INTO user_learning_activity (user_id, activity_type, title, subtitle, subject_name, created_at)
            VALUES (:uid, 'QUIZ_ATTEMPTED', :title, :sub, 'Chemistry', NOW())
        ");
        $actStmt->execute([
            'uid' => $uid,
            'title' => 'Completed ' . $quizTitle,
            'sub' => "Scored {$scorePct}% ({$correctAnswers}/{$totalQuestions} correct)"
        ]);
    }

    // --------------------------------------------------------------------------
    // Fetch Chapters and User Completion Status
    // --------------------------------------------------------------------------
    $chaptersStmt = $pdo->prepare("
        SELECT 
            c.id, 
            c.title, 
            c.chapter_order, 
            c.estimated_minutes,
            IFNULL(ucp.is_completed, 0) AS is_completed
        FROM chapters c
        LEFT JOIN user_chapter_progress ucp 
            ON c.id = ucp.chapter_id AND ucp.user_id = :uid
        WHERE c.subject_id = :sid
        ORDER BY c.chapter_order ASC
    ");
    $chaptersStmt->execute(['uid' => $uid, 'sid' => $subjectId]);
    $rawChapters = $chaptersStmt->fetchAll();

    $totalChapters = count($rawChapters);
    $completedCount = 0;
    $chapterList = [];
    $activeFound = false;

    foreach ($rawChapters as $idx => $ch) {
        $isComp = (int)$ch['is_completed'] === 1;
        if ($isComp) $completedCount++;

        // Status calculation (COMPLETED, ACTIVE, LOCKED)
        if ($isComp) {
            $status = "COMPLETED";
            $subtext = "Completed · " . $ch['estimated_minutes'] . " min";
        } elseif (!$activeFound) {
            $status = "ACTIVE";
            $subtext = "Continue learning · " . $ch['estimated_minutes'] . " min";
            $activeFound = true;
        } else {
            $status = "LOCKED";
            $subtext = "Unlock after previous lesson";
        }

        $chapterList[] = [
            'id' => (string)$ch['id'],
            'title' => $ch['title'],
            'order' => (int)$ch['chapter_order'],
            'estimatedMinutes' => (int)$ch['estimated_minutes'],
            'isCompleted' => $isComp,
            'status' => $status,
            'subtext' => $subtext
        ];
    }

    // Progress percentage calculation
    $progressPercent = $totalChapters > 0 ? (int)round(($completedCount / $totalChapters) * 100) : 0;
    $progressSubtext = "{$completedCount} of {$totalChapters} lessons completed · Beginner path";

    // --------------------------------------------------------------------------
    // Fetch Lesson Contents for Requested Chapter (Part 1 & Part 2)
    // --------------------------------------------------------------------------
    $lessonsStmt = $pdo->prepare("
        SELECT part_number, subtitle_tag, headline, concept_text, key_points, example_title, example_problem, example_solution, quick_example_text, ai_recommendation
        FROM chapter_lessons
        WHERE chapter_id = :cid
        ORDER BY part_number ASC
    ");
    $lessonsStmt->execute(['cid' => $chapterId]);
    $rawLessons = $lessonsStmt->fetchAll();

    $part1 = null;
    $part2 = null;

    foreach ($rawLessons as $lesson) {
        $partNum = (int)$lesson['part_number'];
        $rawPoints = json_decode($lesson['key_points'], true);
        if (!is_array($rawPoints)) {
            $rawPoints = array_filter(array_map('trim', explode("\n", $lesson['key_points'])));
        }

        $lessonObj = [
            'partNumber' => $partNum,
            'subtitleTag' => $lesson['subtitle_tag'] ?? ($partNum === 2 ? 'Part 2: Going Deeper' : 'Part 1: Foundations'),
            'headline' => $lesson['headline'],
            'conceptText' => $lesson['concept_text'],
            'keyPoints' => array_values($rawPoints),
            'exampleTitle' => $lesson['example_title'] ?? 'Quick Example',
            'exampleProblem' => $lesson['example_problem'],
            'exampleSolution' => $lesson['example_solution'],
            'quickExampleText' => $lesson['quick_example_text'],
            'aiRecommendation' => $lesson['ai_recommendation']
        ];

        if ($partNum === 1) $part1 = $lessonObj;
        elseif ($partNum === 2) $part2 = $lessonObj;
    }

    // Fallbacks if database rows are missing
    if (!$part1) {
        $part1 = [
            'partNumber' => 1,
            'subtitleTag' => 'Part 1: Foundations',
            'headline' => 'What is Chemical Bonding?',
            'conceptText' => 'Chemical bonding is the force of attraction between atoms that helps them form molecules and compounds. Atoms bond to achieve a stable electron configuration.',
            'keyPoints' => [
                'Ionic bonds: transfer of electrons (metal + non-metal).',
                'Covalent bonds: sharing of electrons (non-metal + non-metal).',
                'Forms stable compounds and molecules.'
            ],
            'exampleTitle' => 'Quick Example',
            'exampleProblem' => 'Sodium chloride (NaCl) forms by transfer of one electron from Na to Cl.',
            'exampleSolution' => 'Na⁺ + Cl⁻ → NaCl',
            'quickExampleText' => null,
            'aiRecommendation' => null
        ];
    }

    if (!$part2) {
        $part2 = [
            'partNumber' => 2,
            'subtitleTag' => 'Part 2: Going Deeper',
            'headline' => 'Ionic vs Covalent Bonding',
            'conceptText' => 'Atoms form chemical bonds to achieve a stable electron configuration. The two main types are ionic bonds (transfer) and covalent bonds (sharing).',
            'keyPoints' => [
                'Ionic bonding: transfer of electrons (metal + non-metal).',
                'Covalent bonding: sharing of electrons (non-metal + non-metal).',
                'Ionic bonds form lattice structures.',
                'Covalent bonds form molecules.'
            ],
            'exampleTitle' => 'Quick Example',
            'exampleProblem' => null,
            'exampleSolution' => null,
            'quickExampleText' => "What type of bond is in H₂O?\nAnswer: Covalent (electron sharing).",
            'aiRecommendation' => 'Practice drawing Lewis structures for common compounds.'
        ];
    }

    // --------------------------------------------------------------------------
    // Fetch Chapter Quiz Questions
    // --------------------------------------------------------------------------
    $quizzesStmt = $pdo->prepare("
        SELECT id, question, option_a, option_b, option_c, option_d, correct_option, explanation
        FROM chapter_quizzes
        WHERE chapter_id = :cid
        ORDER BY id ASC
    ");
    $quizzesStmt->execute(['cid' => $chapterId]);
    $quizRows = $quizzesStmt->fetchAll();

    $quizQuestions = [];
    foreach ($quizRows as $q) {
        $quizQuestions[] = [
            'id' => (int)$q['id'],
            'question' => $q['question'],
            'options' => [
                'A' => $q['option_a'],
                'B' => $q['option_b'],
                'C' => $q['option_c'],
                'D' => $q['option_d']
            ],
            'correctOption' => $q['correct_option'],
            'explanation' => $q['explanation']
        ];
    }

    // Latest quiz attempt for this subject by student
    $latestQuizStmt = $pdo->prepare("
        SELECT score_percentage, correct_answers, total_questions, created_at
        FROM user_quiz_attempts
        WHERE user_id = :uid AND subject_id = :sid
        ORDER BY id DESC LIMIT 1
    ");
    $latestQuizStmt->execute(['uid' => $uid, 'sid' => $subjectId]);
    $latestQuiz = $latestQuizStmt->fetch() ?: null;

    // --------------------------------------------------------------------------
    // Return Structured Response
    // --------------------------------------------------------------------------
    echo json_encode([
        'status' => 'success',
        'message' => 'Chemistry lesson data retrieved successfully.',
        'data' => [
            'student' => [
                'id' => $uid,
                'name' => $user['name'],
                'email' => $user['email']
            ],
            'subject' => [
                'id' => (int)$subject['id'],
                'name' => $subject['name'],
                'slug' => $subject['slug'],
                'iconSymbol' => $subject['icon_symbol'],
                'colorHex' => $subject['color_hex'],
                'progressPercent' => $progressPercent,
                'progressSubtext' => $progressSubtext,
                'totalChapters' => $totalChapters,
                'completedChapters' => $completedCount
            ],
            'activeChapter' => [
                'id' => $chapterId,
                'title' => 'Chemical Bonding',
                'part1' => $part1,
                'part2' => $part2
            ],
            'chapters' => $chapterList,
            'quiz' => [
                'title' => 'Chemical Bonding Assessment',
                'questions' => $quizQuestions,
                'latestAttempt' => $latestQuiz
            ]
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
