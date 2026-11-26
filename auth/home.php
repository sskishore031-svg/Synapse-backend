<?php
// ==============================================================================
// Home Dashboard API Endpoint - Synapse AI Backend (5 Core Tables)
// Route: GET /home.php or GET /auth/home.php
// Returns complete, real-time student learning dashboard data from 5 core tables.
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

// Helper to determine protocol & photo URL
function buildHomePhotoUrl($photoPath) {
    if (empty($photoPath)) return null;
    if (str_starts_with($photoPath, 'http')) return $photoPath;
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host . "/Synapse_backend/" . ltrim($photoPath, '/');
}

function formatHomeTimeAgo($timestamp) {
    if (empty($timestamp)) return "Recently";
    $time = strtotime($timestamp);
    $diff = time() - $time;
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . "m ago";
    if ($diff < 86400) return floor($diff / 3600) . "h ago";
    if ($diff < 172800) return "Yesterday";
    if ($diff < 604800) return floor($diff / 86400) . " days ago";
    return date("M j", $time);
}

// 1. Authenticate Student Identity
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
    $user = null;
    $userFields = "id, name, email, created_at";
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
        if ($checkCol) {
            $userFields = "id, name, email, phone, created_at";
        }
    } catch (Exception $e) {}
    try {
        $checkImg = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'")->fetch();
        if ($checkImg) {
            $userFields .= ", profile_image";
        }
    } catch (Exception $e) {}

    if (!empty($userId)) {
        $stmt = $pdo->prepare("SELECT $userFields FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare("SELECT $userFields FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
    } else {
        $stmt = $pdo->query("SELECT $userFields FROM users ORDER BY id DESC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found. Please log in or register.'
        ]);
        exit();
    }

    $uid = (int)$user['id'];

    // 2. Fetch User Profile (Core Table 2: user_profiles)
    $profileStmt = $pdo->prepare("
        SELECT selected_interests, avatar_url, updated_at
        FROM user_profiles
        WHERE user_id = :uid
        LIMIT 1
    ");
    $profileStmt->execute(['uid' => $uid]);
    $profileData = $profileStmt->fetch();

    $interests = ['Python & Coding Logic', 'SQL & Database Management', 'AI & Modern Tech Concepts', 'Aptitude & Interview Prep'];
    if (!empty($profileData['selected_interests'])) {
        $decoded = json_decode($profileData['selected_interests'], true);
        $interests = is_array($decoded) ? $decoded : array_map('trim', explode(',', $profileData['selected_interests']));
    }

    $rawAvatar = !empty($user['profile_image']) ? $user['profile_image'] : (!empty($profileData['avatar_url']) ? $profileData['avatar_url'] : null);
    $avatarUrl = buildHomePhotoUrl($rawAvatar);

    $userPhone = !empty($user['phone']) ? $user['phone'] : '';

    $student = [
        'id' => $uid,
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $userPhone,
        'course' => !empty($profileData['course']) ? $profileData['course'] : 'Placement & Micro-Learning Prep',
        'preferred_language' => 'English',
        'interests' => $interests,
        'selected_interests' => $interests,
        'study_goal' => !empty($profileData['study_goal']) ? $profileData['study_goal'] : '15 min / day',
        'profile_photo' => $rawAvatar ?: $avatarUrl,
        'profile_image' => $rawAvatar ?: $avatarUrl,
        'photo_url' => $avatarUrl,
        'avatar_url' => $avatarUrl,
        'user_streak' => '0 Day Streak 🔥'
    ];

    // 3. Quiz & Performance Statistics (Core Table 5: quiz_records)
    $quizStmt = $pdo->prepare("
        SELECT 
            COUNT(id) AS total_quizzes,
            IFNULL(ROUND(AVG(CASE WHEN total_questions > 0 THEN (score * 100.0 / total_questions) ELSE score END)), 0) AS avg_score
        FROM quiz_records
        WHERE user_id = :uid
    ");
    $quizStmt->execute(['uid' => $uid]);
    $quizStats = $quizStmt->fetch();
    $totalQuizzesAttempted = (int)($quizStats['total_quizzes'] ?? 0);
    $averageQuizScore = (int)($quizStats['avg_score'] ?? 0);

    $latestQuizStmt = $pdo->prepare("
        SELECT subject_id, score, total_questions, completed_at
        FROM quiz_records
        WHERE user_id = :uid
        ORDER BY id DESC LIMIT 1
    ");
    $latestQuizStmt->execute(['uid' => $uid]);
    $latestQuiz = $latestQuizStmt->fetch();
    $latestQuizScore = $latestQuiz 
        ? (int)round(($latestQuiz['score'] * 100.0) / max(1, $latestQuiz['total_questions'])) 
        : 0;

    // 4. Study Notes count (Core Table 4: study_notes)
    $notesStmt = $pdo->prepare("
        SELECT COUNT(id) AS notes_count, MAX(created_at) AS last_note_at
        FROM study_notes
        WHERE user_id = :uid
    ");
    $notesStmt->execute(['uid' => $uid]);
    $notesStats = $notesStmt->fetch();
    $notesCount = (int)($notesStats['notes_count'] ?? 0);

    // 5. Recent Sessions (from study_notes and quiz_records)
    $recentSessions = [];

    $recentNotesStmt = $pdo->prepare("
        SELECT id, subject_id, chapter_title, created_at
        FROM study_notes
        WHERE user_id = :uid
        ORDER BY id DESC LIMIT 3
    ");
    $recentNotesStmt->execute(['uid' => $uid]);
    while ($row = $recentNotesStmt->fetch()) {
        $recentSessions[] = [
            'id' => (int)$row['id'],
            'chapterId' => (int)$row['id'],
            'title' => $row['chapter_title'],
            'category' => ucfirst($row['subject_id']),
            'subjectSlug' => strtolower($row['subject_id']),
            'timeAgo' => formatHomeTimeAgo($row['created_at']),
            'progress' => 1.0
        ];
    }

    // 6. Dynamic 4 Placement & Tech Tracks aligned with Curriculum
    $curriculumCatalog = [
        ['id' => 101, 'name' => 'Python & Coding Logic', 'slug' => 'python-coding-logic', 'icon' => '🐍', 'color' => '#38BDF8', 'total' => 5],
        ['id' => 102, 'name' => 'SQL & Database Management', 'slug' => 'sql-database-management', 'icon' => '🗄️', 'color' => '#818CF8', 'total' => 5],
        ['id' => 103, 'name' => 'AI & Modern Tech Concepts', 'slug' => 'ai-modern-tech-concepts', 'icon' => '⚡', 'color' => '#A855F7', 'total' => 5],
        ['id' => 104, 'name' => 'Aptitude & Interview Prep', 'slug' => 'aptitude-interview-prep', 'icon' => '🎯', 'color' => '#F59E0B', 'total' => 5]
    ];

    // Query actual completed chapters per subject
    $compMap = [];
    try {
        $cStmt = $pdo->prepare("SELECT subject_id, COUNT(DISTINCT chapter_id) as cnt FROM user_chapter_completions WHERE user_id = :uid GROUP BY subject_id");
        $cStmt->execute(['uid' => $uid]);
        while ($r = $cStmt->fetch()) {
            $compMap[(string)$r['subject_id']] = (int)$r['cnt'];
        }
    } catch (Exception $e) {}

    $subjectsOutput = [];
    $totalCompletedChapters = 0;
    foreach ($curriculumCatalog as $c) {
        $sid = (string)$c['id'];
        $sslug = $c['slug'];
        $completed = $compMap[$sid] ?? $compMap[$sslug] ?? 0;
        $totalCompletedChapters += $completed;
        $pct = (int)round(($completed / $c['total']) * 100);

        $subjectsOutput[] = [
            'id' => $c['id'],
            'name' => $c['name'],
            'slug' => $c['slug'],
            'iconSymbol' => $c['icon'],
            'colorHex' => $c['color'],
            'totalChapters' => $c['total'],
            'completedChapters' => $completed,
            'progressPercent' => min(100, $pct),
            'isInterested' => true
        ];
    }

    $brainMapNodes = [];
    foreach ($curriculumCatalog as $c) {
        $sid = (string)$c['id'];
        $sslug = $c['slug'];
        $completed = $compMap[$sid] ?? $compMap[$sslug] ?? 0;
        $pct = (int)round(($completed / $c['total']) * 100);
        $brainMapNodes[] = [
            'id' => (string)$c['slug'],
            'title' => $c['name'],
            'percentage' => $pct,
            'colorHex' => $c['color'],
            'iconSymbol' => $c['icon'],
            'chaptersCompleted' => $completed,
            'totalChapters' => $c['total']
        ];
    }

    $calculatedTopicsDone = $totalCompletedChapters + $totalQuizzesAttempted;
    $overallProgress = (int)round((min(20, $calculatedTopicsDone) / 20.0) * 100);

    // Compute real continuous study streak from study_sessions
    $studyStreakDays = 0;
    try {
        $stkStmt = $pdo->prepare("SELECT DISTINCT study_date FROM study_sessions WHERE user_id = :uid ORDER BY study_date DESC");
        $stkStmt->execute(['uid' => $uid]);
        $activeDates = $stkStmt->fetchAll(PDO::FETCH_COLUMN);

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $cursor = null;
        if (in_array($today, $activeDates)) {
            $cursor = $today;
        } elseif (in_array($yesterday, $activeDates)) {
            $cursor = $yesterday;
        }

        if ($cursor !== null) {
            $tempDate = $cursor;
            while (in_array($tempDate, $activeDates)) {
                $studyStreakDays++;
                $tempDate = date('Y-m-d', strtotime($tempDate . ' -1 day'));
            }
        }
    } catch (Exception $e) {}

    $student['user_streak'] = $studyStreakDays . ' Day Streak 🔥';

    $brainMap = [
        'overallKnowledgePercentage' => $overallProgress,
        'strongestSubject' => 'Python & Coding Logic',
        'strongestScore' => 0,
        'weakestSubject' => 'Aptitude & Interview Prep',
        'weakestScore' => 0,
        'recommendationTitle' => 'Micro-Learning Placement Path',
        'recommendationSubtitle' => 'Start your 2-minute daily concept lessons',
        'nodes' => $brainMapNodes
    ];

    $continueLearning = null;
    if ($totalCompletedChapters > 0) {
        $continueLearning = [
            'chapter_id' => 10101,
            'chapterId' => 10101,
            'chapter_title' => 'Variables, Data Types & Dynamic Typing',
            'chapterTitle' => 'Variables, Data Types & Dynamic Typing',
            'subject_slug' => 'python-coding-logic',
            'subjectSlug' => 'python-coding-logic'
        ];
    }

    $dashboardData = [
        'student' => $student,
        'greeting' => 'Welcome Back,',
        'mindPower' => $overallProgress,
        'overallProgress' => $overallProgress,
        'progressMessage' => 'Active Learning Mode',
        'topicsDone' => $calculatedTopicsDone,
        'totalTopics' => 20,
        'quizScore' => $averageQuizScore,
        'quizzesAttempted' => $totalQuizzesAttempted,
        'latestQuizScore' => $latestQuizScore,
        'studyStreakDays' => $studyStreakDays,
        'continueLearning' => $continueLearning,
        'recentSessions' => $recentSessions,
        'subjects' => $subjectsOutput,
        'brainMap' => $brainMap
    ];

    http_response_code(200);
    echo json_encode(array_merge([
        'status' => 'success',
        'success' => true,
        'data' => $dashboardData
    ], $dashboardData));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to load home dashboard: ' . $e->getMessage()
    ]);
}
