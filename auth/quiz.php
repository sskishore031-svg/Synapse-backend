<?php
// ==============================================================================
// Subject-Wise Quiz Management API - Synapse AI Backend
// Route: GET/POST /auth/quiz.php or /quiz.php
// Strictly isolates quizzes by subject, serves questions, & records student attempts.
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
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'subjects';
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
    // ACTION: subjects - List all subjects with quiz statistics
    // --------------------------------------------------------------------------
    if ($action === 'subjects') {
        $subjectsStmt = $pdo->query("SELECT id, name, slug, icon_symbol, color_hex FROM subjects ORDER BY display_order ASC, id ASC");
        $subjects = $subjectsStmt->fetchAll();

        // Count available quizzes (chapters that have quizzes) per subject
        $quizCountStmt = $pdo->query("
            SELECT subject_id, COUNT(DISTINCT chapter_id) AS total_quizzes 
            FROM chapter_quizzes 
            GROUP BY subject_id
        ");
        $availableMap = [];
        while ($r = $quizCountStmt->fetch()) {
            $availableMap[(int)$r['subject_id']] = (int)$r['total_quizzes'];
        }

        // Count student attempts and average score per subject
        $attStmt = $pdo->prepare("
            SELECT subject_id, COUNT(id) AS attempted_count, IFNULL(ROUND(AVG(score_percentage)), 0) AS avg_score
            FROM user_quiz_attempts
            WHERE user_id = :uid
            GROUP BY subject_id
        ");
        $attStmt->execute(['uid' => $uid]);
        $attemptMap = [];
        while ($r = $attStmt->fetch()) {
            $attemptMap[(int)$r['subject_id']] = [
                'attempted' => (int)$r['attempted_count'],
                'avg_score' => (int)$r['avg_score']
            ];
        }

        $list = [];
        foreach ($subjects as $sb) {
            $sid = (int)$sb['id'];
            $totalQ = $availableMap[$sid] ?? 5;
            $attData = $attemptMap[$sid] ?? ['attempted' => 0, 'avg_score' => 0];

            $list[] = [
                'id' => $sid,
                'name' => $sb['name'],
                'slug' => $sb['slug'],
                'icon_symbol' => $sb['icon_symbol'] ?? '📚',
                'color_hex' => $sb['color_hex'] ?? '#3B82F6',
                'available_quizzes' => $totalQ,
                'attempted_quizzes' => $attData['attempted'],
                'average_score' => $attData['avg_score']
            ];
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'student' => [
                    'id' => $uid,
                    'name' => $user['name']
                ],
                'subjects' => $list
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: subject_quizzes - List quizzes strictly belonging to the selected subject
    // --------------------------------------------------------------------------
    if ($action === 'subject_quizzes') {
        $subjectId = $_GET['subject_id'] ?? $input['subject_id'] ?? null;
        $subjectSlug = trim($_GET['subject_slug'] ?? $input['subject_slug'] ?? '');

        $subject = null;
        if (!empty($subjectId)) {
            $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = :sid LIMIT 1");
            $stmt->execute(['sid' => $subjectId]);
            $subject = $stmt->fetch();
        } elseif (!empty($subjectSlug)) {
            $stmt = $pdo->prepare("SELECT * FROM subjects WHERE slug = :slug OR LOWER(name) = :sname LIMIT 1");
            $stmt->execute(['slug' => $subjectSlug, 'sname' => strtolower($subjectSlug)]);
            $subject = $stmt->fetch();
        }

        if (!$subject) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Subject not found.']);
            exit();
        }

        $sid = (int)$subject['id'];

        // Fetch chapters and question counts for this subject
        $chapStmt = $pdo->prepare("
            SELECT 
                c.id AS chapter_id,
                c.title AS chapter_title,
                c.chapter_order,
                COUNT(cq.id) AS question_count
            FROM chapters c
            LEFT JOIN chapter_quizzes cq ON c.id = cq.chapter_id
            WHERE c.subject_id = :sid
            GROUP BY c.id
            ORDER BY c.chapter_order ASC
        ");
        $chapStmt->execute(['sid' => $sid]);
        $chapters = $chapStmt->fetchAll();

        // Fetch student's latest quiz score per chapter quiz title
        $scoreStmt = $pdo->prepare("
            SELECT quiz_title, MAX(score_percentage) AS max_score, COUNT(id) AS attempts
            FROM user_quiz_attempts
            WHERE user_id = :uid AND subject_id = :sid
            GROUP BY quiz_title
        ");
        $scoreStmt->execute(['uid' => $uid, 'sid' => $sid]);
        $scoreMap = [];
        while ($r = $scoreStmt->fetch()) {
            $scoreMap[$r['quiz_title']] = [
                'score' => (int)$r['max_score'],
                'attempts' => (int)$r['attempts']
            ];
        }

        $quizzes = [];
        foreach ($chapters as $ch) {
            $quizTitle = $ch['chapter_title'] . " Assessment";
            $history = $scoreMap[$quizTitle] ?? null;

            $quizzes[] = [
                'chapter_id' => (int)$ch['chapter_id'],
                'chapter_order' => (int)$ch['chapter_order'],
                'chapter_title' => $ch['chapter_title'],
                'quiz_title' => $quizTitle,
                'subject_id' => $sid,
                'subject_name' => $subject['name'],
                'subject_slug' => $subject['slug'],
                'question_count' => (int)($ch['question_count'] > 0 ? $ch['question_count'] : 3),
                'duration_minutes' => 10,
                'total_marks' => 100,
                'is_attempted' => $history !== null,
                'best_score' => $history ? $history['score'] : null,
                'attempts_count' => $history ? $history['attempts'] : 0
            ];
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'subject' => [
                    'id' => $sid,
                    'name' => $subject['name'],
                    'slug' => $subject['slug'],
                    'icon_symbol' => $subject['icon_symbol'] ?? '📚',
                    'color_hex' => $subject['color_hex'] ?? '#3B82F6'
                ],
                'quizzes' => $quizzes
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: questions - Get questions for a specific chapter quiz
    // --------------------------------------------------------------------------
    if ($action === 'questions' || $action === 'quiz_details') {
        $chapterId = $_GET['chapter_id'] ?? $input['chapter_id'] ?? null;
        if (empty($chapterId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide chapter_id.']);
            exit();
        }

        // Get chapter and subject
        $chapStmt = $pdo->prepare("
            SELECT c.*, s.name AS subject_name, s.slug AS subject_slug, s.icon_symbol, s.color_hex
            FROM chapters c
            JOIN subjects s ON c.subject_id = s.id
            WHERE c.id = :cid
            LIMIT 1
        ");
        $chapStmt->execute(['cid' => $chapterId]);
        $chapter = $chapStmt->fetch();

        if (!$chapter) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Chapter not found.']);
            exit();
        }

        $sid = (int)$chapter['subject_id'];
        $quizTitle = $chapter['title'] . " Assessment";

        // Fetch questions strictly for this chapter
        $qStmt = $pdo->prepare("
            SELECT id, question, option_a, option_b, option_c, option_d, correct_option, explanation
            FROM chapter_quizzes
            WHERE chapter_id = :cid
            ORDER BY id ASC
        ");
        $qStmt->execute(['cid' => $chapterId]);
        $questionsRaw = $qStmt->fetchAll();

        $questionList = [];
        $qIndex = 1;
        foreach ($questionsRaw as $qr) {
            $questionList[] = [
                'id' => (int)$qr['id'],
                'question_number' => $qIndex++,
                'question' => $qr['question'],
                'options' => [
                    'A' => $qr['option_a'],
                    'B' => $qr['option_b'],
                    'C' => $qr['option_c'],
                    'D' => $qr['option_d']
                ],
                // For client verification/result presentation
                'correct_option' => $qr['correct_option'],
                'explanation' => $qr['explanation']
            ];
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'quiz' => [
                    'title' => $quizTitle,
                    'subject_id' => $sid,
                    'subject_name' => $chapter['subject_name'],
                    'subject_slug' => $chapter['subject_slug'],
                    'chapter_id' => (int)$chapter['id'],
                    'chapter_title' => $chapter['title'],
                    'chapter_order' => (int)$chapter['chapter_order'],
                    'total_questions' => count($questionList),
                    'duration_minutes' => 10,
                    'total_marks' => 100
                ],
                'questions' => $questionList
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: submit - Submit quiz answers, calculate score, record attempt
    // --------------------------------------------------------------------------
    if ($action === 'submit') {
        $chapterId = $_GET['chapter_id'] ?? $input['chapter_id'] ?? $_POST['chapter_id'] ?? null;
        $submittedAnswers = $input['answers'] ?? []; // Map or list of {question_id: selected_option}

        if (empty($chapterId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide chapter_id.']);
            exit();
        }

        // Fetch questions and correct options from database
        $chapStmt = $pdo->prepare("
            SELECT c.*, s.name AS subject_name 
            FROM chapters c 
            JOIN subjects s ON c.subject_id = s.id 
            WHERE c.id = :cid 
            LIMIT 1
        ");
        $chapStmt->execute(['cid' => $chapterId]);
        $chapter = $chapStmt->fetch();

        if (!$chapter) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Chapter not found.']);
            exit();
        }

        $sid = (int)$chapter['subject_id'];
        $quizTitle = $chapter['title'] . " Assessment";

        $qStmt = $pdo->prepare("SELECT id, correct_option FROM chapter_quizzes WHERE chapter_id = :cid");
        $qStmt->execute(['cid' => $chapterId]);
        $dbQuestions = $qStmt->fetchAll();

        $totalQuestions = count($dbQuestions);
        $correctCount = 0;

        // Build lookup map for submitted answers
        $userAnswerMap = [];
        if (is_array($submittedAnswers)) {
            foreach ($submittedAnswers as $key => $val) {
                if (is_array($val) && isset($val['question_id'])) {
                    $userAnswerMap[(int)$val['question_id']] = strtoupper(trim($val['selected_option'] ?? ''));
                } else {
                    $userAnswerMap[(int)$key] = strtoupper(trim($val));
                }
            }
        }

        foreach ($dbQuestions as $dbQ) {
            $qid = (int)$dbQ['id'];
            $selected = $userAnswerMap[$qid] ?? '';
            if ($selected === strtoupper(trim($dbQ['correct_option']))) {
                $correctCount++;
            }
        }

        $scorePercent = $totalQuestions > 0 ? (int)round(($correctCount / $totalQuestions) * 100) : 0;
        $incorrectCount = $totalQuestions - $correctCount;

        // Record attempt in user_quiz_attempts
        $insStmt = $pdo->prepare("
            INSERT INTO user_quiz_attempts (user_id, subject_id, quiz_title, score_percentage, correct_answers, total_questions)
            VALUES (:uid, :sid, :title, :score, :correct, :total)
        ");
        $insStmt->execute([
            'uid' => $uid,
            'sid' => $sid,
            'title' => $quizTitle,
            'score' => $scorePercent,
            'correct' => $correctCount,
            'total' => $totalQuestions
        ]);

        // Insert into user_learning_activity
        $actStmt = $pdo->prepare("
            INSERT INTO user_learning_activity (user_id, activity_type, title, subtitle, subject_name)
            VALUES (:uid, 'QUIZ_ATTEMPTED', :title, :subtitle, :sname)
        ");
        $actStmt->execute([
            'uid' => $uid,
            'title' => "Completed $quizTitle",
            'subtitle' => "Scored $scorePercent% ($correctCount/$totalQuestions correct)",
            'sname' => $chapter['subject_name']
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Quiz submitted successfully.',
            'data' => [
                'student_name' => $user['name'],
                'subject_name' => $chapter['subject_name'],
                'quiz_title' => $quizTitle,
                'chapter_id' => (int)$chapterId,
                'score' => $correctCount * 10,
                'total_marks' => $totalQuestions * 10,
                'percentage' => $scorePercent,
                'correct_answers' => $correctCount,
                'incorrect_answers' => $incorrectCount,
                'total_questions' => $totalQuestions,
                'attempt_date' => date('Y-m-d H:i:s'),
                'passed' => $scorePercent >= 60
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

