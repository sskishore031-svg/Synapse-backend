<?php
// ==============================================================================
// BrainMap Analytics API Endpoint - Synapse AI Backend
// Route: GET /brainmap.php or GET /auth/brainmap.php
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

// Extract user identity from query parameter, JSON/POST body, or custom headers
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
    // Authenticate and locate student in users table
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
        // Fallback to active logged in session or first user in local dev
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

    // 1. Fetch all subjects ordered by display order
    $subjectsStmt = $pdo->query("SELECT id, name, slug, icon_symbol, color_hex FROM subjects ORDER BY display_order ASC, id ASC");
    $subjects = $subjectsStmt->fetchAll();

    // 2. Fetch total chapters available overall
    $totalChaptersQuery = $pdo->query("SELECT COUNT(*) FROM chapters");
    $totalChaptersAvailable = (int)$totalChaptersQuery->fetchColumn();

    // 3. Fetch chapter completions for this user grouped by subject
    $completedChaptersStmt = $pdo->prepare("
        SELECT subject_id, COUNT(DISTINCT chapter_id) AS completed_count
        FROM user_chapter_progress
        WHERE user_id = :uid AND is_completed = 1
        GROUP BY subject_id
    ");
    $completedChaptersStmt->execute(['uid' => $uid]);
    $subjectCompletedMap = [];
    $totalChaptersCompleted = 0;
    while ($row = $completedChaptersStmt->fetch()) {
        $sid = (int)$row['subject_id'];
        $cnt = (int)$row['completed_count'];
        $subjectCompletedMap[$sid] = $cnt;
        $totalChaptersCompleted += $cnt;
    }

    // 4. Fetch total chapters per subject
    $subjectTotalChaptersStmt = $pdo->query("
        SELECT subject_id, COUNT(id) AS total_count
        FROM chapters
        GROUP BY subject_id
    ");
    $subjectTotalChaptersMap = [];
    while ($row = $subjectTotalChaptersStmt->fetch()) {
        $subjectTotalChaptersMap[(int)$row['subject_id']] = (int)$row['total_count'];
    }

    // 5. Fetch quiz statistics for this user grouped by subject
    $quizSubjectStmt = $pdo->prepare("
        SELECT 
            subject_id, 
            COUNT(id) AS attempts_count, 
            ROUND(AVG(score_percentage)) AS avg_score,
            MAX(score_percentage) AS max_score
        FROM user_quiz_attempts
        WHERE user_id = :uid
        GROUP BY subject_id
    ");
    $quizSubjectStmt->execute(['uid' => $uid]);
    $subjectQuizMap = [];
    while ($row = $quizSubjectStmt->fetch()) {
        $subjectQuizMap[(int)$row['subject_id']] = [
            'attempts' => (int)$row['attempts_count'],
            'avg_score' => (int)$row['avg_score'],
            'max_score' => (int)$row['max_score']
        ];
    }

    // 6. Overall Quiz Statistics for this user
    $overallQuizStmt = $pdo->prepare("
        SELECT 
            COUNT(id) AS total_attempts,
            IFNULL(ROUND(AVG(score_percentage)), 0) AS avg_score
        FROM user_quiz_attempts
        WHERE user_id = :uid
    ");
    $overallQuizStmt->execute(['uid' => $uid]);
    $overallQuizData = $overallQuizStmt->fetch();
    $totalQuizzesAttempted = (int)($overallQuizData['total_attempts'] ?? 0);
    $averageQuizScore = (int)($overallQuizData['avg_score'] ?? 0);

    $latestQuizStmt = $pdo->prepare("
        SELECT score_percentage 
        FROM user_quiz_attempts 
        WHERE user_id = :uid 
        ORDER BY id DESC LIMIT 1
    ");
    $latestQuizStmt->execute(['uid' => $uid]);
    $latestScoreVal = $latestQuizStmt->fetchColumn();
    $latestQuizScore = $latestScoreVal !== false && $latestScoreVal !== null ? (int)$latestScoreVal : 0;

    // 7. Build Subject Nodes
    $nodes = [];
    $subjectsWithActivity = 0;
    $percentageSum = 0;
    $subjectScores = [];

    foreach ($subjects as $subject) {
        $sid = (int)$subject['id'];
        $subjectName = $subject['name'];
        $completed = $subjectCompletedMap[$sid] ?? 0;
        $totalInSubject = $subjectTotalChaptersMap[$sid] ?? 5;
        $quizData = $subjectQuizMap[$sid] ?? ['attempts' => 0, 'avg_score' => 0, 'max_score' => 0];

        $chapterPct = $totalInSubject > 0 ? (int)round(($completed / $totalInSubject) * 100) : 0;
        $hasQuizzes = $quizData['attempts'] > 0;
        $hasChapters = $completed > 0;

        if ($hasQuizzes && $hasChapters) {
            $compositePercentage = (int)round(($chapterPct * 0.5) + ($quizData['avg_score'] * 0.5));
        } elseif ($hasQuizzes) {
            $compositePercentage = $quizData['avg_score'];
        } elseif ($hasChapters) {
            $compositePercentage = $chapterPct;
        } else {
            $compositePercentage = 0;
        }

        $compositePercentage = min(100, max(0, $compositePercentage));

        if ($hasChapters || $hasQuizzes) {
            $subjectsWithActivity++;
            $subjectScores[$subjectName] = $compositePercentage;
        }

        $percentageSum += $compositePercentage;

        // Determine Status Text and Theme Colors
        if ($compositePercentage >= 70) {
            $statusText = "Strong · {$compositePercentage}%";
            $colorHex = !empty($subject['color_hex']) ? $subject['color_hex'] : "#3B82F6";
        } elseif ($compositePercentage >= 50) {
            $statusText = "Learning · {$compositePercentage}%";
            $colorHex = "#8B5CF6";
        } elseif ($compositePercentage >= 40) {
            $statusText = "Need Revision · {$compositePercentage}%";
            $colorHex = "#D97706";
        } elseif ($compositePercentage > 0) {
            $statusText = "Weak · {$compositePercentage}%";
            $colorHex = "#EF4444";
        } else {
            $statusText = "Not Started · 0%";
            $colorHex = "#64748B";
        }

        $nodes[] = [
            'id' => (string)$sid,
            'title' => $subjectName,
            'statusText' => $statusText,
            'percentage' => $compositePercentage,
            'colorHex' => $colorHex,
            'iconSymbol' => $subject['icon_symbol'] ?? '📚',
            'chaptersCompleted' => $completed,
            'totalChapters' => $totalInSubject,
            'quizzesAttempted' => $quizData['attempts'],
            'averageQuizScore' => $quizData['avg_score']
        ];
    }

    // 8. Overall Knowledge Percentage
    $totalSubjectsCount = count($subjects);
    if ($totalSubjectsCount > 0 && $subjectsWithActivity > 0) {
        $overallKnowledgePercentage = (int)round($percentageSum / $totalSubjectsCount);
    } else {
        $overallKnowledgePercentage = 0;
    }

    // 9. Identify Strongest and Weakest Subjects
    $strongestSubject = null;
    $strongestScore = -1;
    $weakestSubject = null;
    $weakestScore = 101;

    if (!empty($subjectScores)) {
        foreach ($subjectScores as $sName => $sScore) {
            if ($sScore > $strongestScore) {
                $strongestScore = $sScore;
                $strongestSubject = $sName;
            }
            if ($sScore < $weakestScore) {
                $weakestScore = $sScore;
                $weakestSubject = $sName;
            }
        }
    }

    // 10. Dynamic Personalized Recommendation
    if ($subjectsWithActivity == 0) {
        $recommendationTitle = "Begin your learning journey";
        $recommendationText = "Select any subject to complete your first lesson. Each completed chapter and quiz will light up your personalized brain map.";
    } elseif ($weakestSubject !== null && $weakestScore < 50) {
        $recommendationTitle = "Strengthen your next connection";
        $recommendationText = "Revisit {$weakestSubject} this week. A short revision and quiz will keep your foundations strong.";
    } elseif ($overallKnowledgePercentage >= 80) {
        $recommendationTitle = "Outstanding Mastery!";
        $recommendationText = "You have demonstrated strong performance across topics. Take a challenge quiz to maintain your edge.";
    } else {
        $targetSubject = $weakestSubject ?? ($subjects[0]['name'] ?? 'Mathematics');
        $recommendationTitle = "Build your knowledge network";
        $recommendationText = "Continue with {$targetSubject} milestones to deepen your understanding and unlock new badges.";
    }

    // 11. Fetch Recent Learning Activities
    $activitiesStmt = $pdo->prepare("
        SELECT id, activity_type, title, subtitle, subject_name, created_at
        FROM user_learning_activity
        WHERE user_id = :uid
        ORDER BY id DESC
        LIMIT 5
    ");
    $activitiesStmt->execute(['uid' => $uid]);
    $recentActivities = $activitiesStmt->fetchAll();

    // 12. Return JSON response
    echo json_encode([
        'status' => 'success',
        'message' => 'BrainMap analytics retrieved successfully.',
        'data' => [
            'student' => [
                'id' => $uid,
                'name' => $user['name'],
                'email' => $user['email']
            ],
            'summary' => [
                'totalSubjectsStudied' => $subjectsWithActivity,
                'totalSubjectsAvailable' => $totalSubjectsCount,
                'totalChaptersCompleted' => $totalChaptersCompleted,
                'totalChaptersAvailable' => $totalChaptersAvailable,
                'totalQuizzesAttempted' => $totalQuizzesAttempted,
                'averageQuizScore' => $averageQuizScore,
                'latestQuizScore' => $latestQuizScore,
                'overallKnowledgePercentage' => $overallKnowledgePercentage,
                'overallKnowledgeText' => $overallKnowledgePercentage > 0 
                    ? "Every lesson makes a new connection."
                    : "Start your first chapter to connect your mind.",
                'strongestSubject' => $strongestSubject,
                'strongestScore' => $strongestScore >= 0 ? $strongestScore : null,
                'weakestSubject' => $weakestSubject,
                'weakestScore' => $weakestScore <= 100 ? $weakestScore : null,
                'recommendationTitle' => $recommendationTitle,
                'recommendationText' => $recommendationText
            ],
            'nodes' => $nodes,
            'recentActivities' => $recentActivities
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to retrieve BrainMap analytics: ' . $e->getMessage()
    ]);
}
