<?php
// ==============================================================================
// Progress & Analytics API Endpoint - Synapse AI Backend
// Route: GET/POST /auth/analytics.php or /analytics.php
// Returns real-time user study analytics, track breakdowns, and streak calendar.
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

// Auto-create study_sessions table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `study_sessions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `track_name` VARCHAR(100) NOT NULL,
          `category` VARCHAR(50) NOT NULL,
          `duration_minutes` INT NOT NULL DEFAULT 10,
          `study_date` DATE NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_sessions_user_date` (`user_id`, `study_date`),
          CONSTRAINT `fk_study_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

// Helpers
function formatDuration($minutes) {
    if ($minutes <= 0) return "0m";
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    if ($h > 0 && $m > 0) return "{$h}h {$m}m";
    if ($h > 0) return "{$h}h";
    return "{$m}m";
}

function normalizeTrackName($raw) {
    $r = trim(strtolower($raw));
    if (strpos($r, 'python') !== false || strpos($r, 'coding') !== false) return 'Python & Coding Logic';
    if (strpos($r, 'sql') !== false || strpos($r, 'database') !== false || strpos($r, 'dbms') !== false) return 'SQL & Database Management';
    if (strpos($r, 'ai') !== false || strpos($r, 'tech') !== false || strpos($r, 'intelligence') !== false) return 'AI & Modern Tech Concepts';
    if (strpos($r, 'aptitude') !== false || strpos($r, 'interview') !== false || strpos($r, 'math') !== false) return 'Aptitude & Interview Prep';
    return 'Python & Coding Logic';
}

function getTrackSlug($trackName) {
    switch ($trackName) {
        case 'Python & Coding Logic': return 'PYTHON';
        case 'SQL & Database Management': return 'SQL';
        case 'AI & Modern Tech Concepts': return 'AI_TECH';
        case 'Aptitude & Interview Prep': return 'APTITUDE';
        default: return 'PYTHON';
    }
}

function getTrackIcon($trackName) {
    switch ($trackName) {
        case 'Python & Coding Logic': return '🐍';
        case 'SQL & Database Management': return '🗄️';
        case 'AI & Modern Tech Concepts': return '⚡';
        case 'Aptitude & Interview Prep': return '🎯';
        default: return '📚';
    }
}

function getTrackColor($trackName) {
    switch ($trackName) {
        case 'Python & Coding Logic': return '#FF6B00'; // Sunset Orange
        case 'SQL & Database Management': return '#FFAA00'; // Amber Gold
        case 'AI & Modern Tech Concepts': return '#8B5CF6'; // Purple / Blue
        case 'Aptitude & Interview Prep': return '#10B981'; // Emerald Green
        default: return '#FF6B00';
    }
}

// 1. Identify User
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

$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'get_analytics';

// -----------------------------------------------------------------------------
// Action: log_session
// -----------------------------------------------------------------------------
if ($action === 'log_session' || ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action']) && isset($input['track_name']))) {
    $trackRaw = $input['track_name'] ?? $_POST['track_name'] ?? 'Python & Coding Logic';
    $trackName = normalizeTrackName($trackRaw);
    $category = trim($input['category'] ?? $_POST['category'] ?? 'Focused Study');
    $duration = (int)($input['duration_minutes'] ?? $_POST['duration_minutes'] ?? 15);
    if ($duration <= 0) $duration = 10;
    $studyDate = trim($input['study_date'] ?? $_POST['study_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDate)) {
        $studyDate = date('Y-m-d');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO study_sessions (user_id, track_name, category, duration_minutes, study_date, created_at)
            VALUES (:uid, :track, :category, :duration, :sdate, NOW())
        ");
        $stmt->execute([
            'uid' => $uid,
            'track' => $trackName,
            'category' => $category,
            'duration' => $duration,
            'sdate' => $studyDate
        ]);
        $insertId = $pdo->lastInsertId();

        echo json_encode([
            'status' => 'success',
            'message' => 'Study session logged successfully',
            'data' => [
                'id' => (int)$insertId,
                'user_id' => $uid,
                'track_name' => $trackName,
                'category' => $category,
                'duration_minutes' => $duration,
                'study_date' => $studyDate
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to log study session: ' . $e->getMessage()]);
    }
    exit();
}

// -----------------------------------------------------------------------------
// Action: get_analytics
// -----------------------------------------------------------------------------
$timeFilter = strtolower(trim($_GET['time_filter'] ?? $_GET['range'] ?? $input['time_filter'] ?? $input['range'] ?? 'this_week'));
if ($timeFilter === 'this week') $timeFilter = 'this_week';
if ($timeFilter === 'last week') $timeFilter = 'last_week';
if ($timeFilter === 'this month') $timeFilter = 'this_month';
if (!in_array($timeFilter, ['this_week', 'last_week', 'this_month'])) {
    $timeFilter = 'this_week';
}

$reqMonth = trim($_GET['month'] ?? $input['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $reqMonth)) {
    $reqMonth = date('Y-m');
}

// Determine filter date boundaries
$today = date('Y-m-d');
$dayOfWeek = (int)date('N'); // 1 (Mon) - 7 (Sun)
$curMondayTime = strtotime('-' . ($dayOfWeek - 1) . ' days', strtotime($today));

if ($timeFilter === 'last_week') {
    $refMonday = strtotime('-7 days', $curMondayTime);
    $startDate = date('Y-m-d', $refMonday);
    $endDate = date('Y-m-d', strtotime('+6 days', $refMonday));
} elseif ($timeFilter === 'this_month') {
    $refMonday = $curMondayTime;
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-t');
} else {
    // this_week (Monday to Sunday)
    $refMonday = $curMondayTime;
    $startDate = date('Y-m-d', $curMondayTime);
    $endDate = date('Y-m-d', strtotime('+6 days', $curMondayTime));
}

// 1. Gather all active study dates across study_sessions, quiz_records, and study_notes
$allActiveDates = [];
try {
    // study_sessions
    $sStmt = $pdo->prepare("SELECT DISTINCT study_date FROM study_sessions WHERE user_id = :uid");
    $sStmt->execute(['uid' => $uid]);
    while ($r = $sStmt->fetch()) {
        $allActiveDates[] = $r['study_date'];
    }
    // quiz_records
    $qStmt = $pdo->prepare("SELECT DISTINCT DATE(completed_at) as qdate FROM quiz_records WHERE user_id = :uid");
    $qStmt->execute(['uid' => $uid]);
    while ($r = $qStmt->fetch()) {
        if (!empty($r['qdate'])) $allActiveDates[] = $r['qdate'];
    }
    // study_notes
    $nStmt = $pdo->prepare("SELECT DISTINCT DATE(created_at) as ndate FROM study_notes WHERE user_id = :uid");
    $nStmt->execute(['uid' => $uid]);
    while ($r = $nStmt->fetch()) {
        if (!empty($r['ndate'])) $allActiveDates[] = $r['ndate'];
    }
} catch (Exception $e) {}

$allActiveDates = array_values(array_unique(array_filter($allActiveDates)));
sort($allActiveDates);

// Calculate Current Streak and Best Streak
$currentStreak = 0;
$bestStreak = 0;
if (!empty($allActiveDates)) {
    // Reverse for current streak check
    $descDates = array_reverse($allActiveDates);
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $cursor = null;

    if (in_array($today, $descDates)) {
        $cursor = $today;
    } elseif (in_array($yesterday, $descDates)) {
        $cursor = $yesterday;
    }

    if ($cursor !== null) {
        $tempDate = $cursor;
        while (in_array($tempDate, $descDates)) {
            $currentStreak++;
            $tempDate = date('Y-m-d', strtotime($tempDate . ' -1 day'));
        }
    }

    // Best streak
    $streakCount = 0;
    $prevTime = null;
    foreach ($allActiveDates as $dStr) {
        $curTime = strtotime($dStr);
        if ($prevTime === null) {
            $streakCount = 1;
        } else {
            $diffDays = round(($curTime - $prevTime) / 86400);
            if ($diffDays == 1) {
                $streakCount++;
            } elseif ($diffDays > 1) {
                $streakCount = 1;
            }
        }
        $prevTime = $curTime;
        if ($streakCount > $bestStreak) {
            $bestStreak = $streakCount;
        }
    }
}

// 2. Query sessions within the selected time window
$sessionsInWindow = [];
try {
    $wStmt = $pdo->prepare("
        SELECT id, track_name, category, duration_minutes, study_date
        FROM study_sessions
        WHERE user_id = :uid AND study_date >= :sdate AND study_date <= :edate
        ORDER BY study_date ASC
    ");
    $wStmt->execute([
        'uid' => $uid,
        'sdate' => $startDate,
        'edate' => $endDate
    ]);
    $sessionsInWindow = $wStmt->fetchAll();
} catch (Exception $e) {}

// Calculate total study minutes in period
$totalMinutes = 0;
$trackMinutes = [
    'Python & Coding Logic' => 0,
    'SQL & Database Management' => 0,
    'AI & Modern Tech Concepts' => 0,
    'Aptitude & Interview Prep' => 0
];
$categoryMinutes = [
    'Focused Study' => 0,
    'Practice (Quiz)' => 0,
    'Note Making' => 0
];
$dailyMinutesMap = []; // YYYY-MM-DD => total minutes
$trackDailyMap = [ // track => [0..6 => minutes]
    'Python & Coding Logic' => [0, 0, 0, 0, 0, 0, 0],
    'SQL & Database Management' => [0, 0, 0, 0, 0, 0, 0],
    'AI & Modern Tech Concepts' => [0, 0, 0, 0, 0, 0, 0],
    'Aptitude & Interview Prep' => [0, 0, 0, 0, 0, 0, 0]
];

// Reference Monday for day index 0..6 (already computed accurately above)

foreach ($sessionsInWindow as $s) {
    $mins = (int)$s['duration_minutes'];
    $totalMinutes += $mins;
    $tName = normalizeTrackName($s['track_name']);
    $cat = $s['category'];
    $sDate = $s['study_date'];

    if (isset($trackMinutes[$tName])) {
        $trackMinutes[$tName] += $mins;
    } else {
        $trackMinutes['Python & Coding Logic'] += $mins;
    }

    if (isset($categoryMinutes[$cat])) {
        $categoryMinutes[$cat] += $mins;
    } else {
        $categoryMinutes['Focused Study'] += $mins;
    }

    if (!isset($dailyMinutesMap[$sDate])) {
        $dailyMinutesMap[$sDate] = 0;
    }
    $dailyMinutesMap[$sDate] += $mins;

    // Day of week index (0 = Monday, 6 = Sunday)
    $sTime = strtotime($sDate);
    $dayIndex = (int)date('N', $sTime) - 1; // 1 (Mon) - 7 (Sun) -> 0 - 6
    if ($dayIndex >= 0 && $dayIndex < 7 && isset($trackDailyMap[$tName])) {
        $trackDailyMap[$tName][$dayIndex] += $mins;
    }
}

// Compute Mon-Sun Daily Study Chart Heights
$dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$weeklyDayMinutes = [0, 0, 0, 0, 0, 0, 0];
for ($i = 0; $i < 7; $i++) {
    $dDate = date('Y-m-d', strtotime("+{$i} days", $refMonday));
    $weeklyDayMinutes[$i] = $dailyMinutesMap[$dDate] ?? 0;
}

$maxDayMins = max($weeklyDayMinutes);
$chartHeights = [];
for ($i = 0; $i < 7; $i++) {
    if ($maxDayMins <= 0 || $weeklyDayMinutes[$i] <= 0) {
        $chartHeights[] = 0.0;
    } else {
        $chartHeights[] = round(max(0.12, $weeklyDayMinutes[$i] / $maxDayMins), 2);
    }
}

// Most productive day
$maxDayIdx = array_search(max($weeklyDayMinutes), $weeklyDayMinutes);
$mostProductiveDayName = ($totalMinutes > 0) ? $dayNames[$maxDayIdx] : "None";
$mostProductiveDayText = ($totalMinutes > 0) ? formatDuration($weeklyDayMinutes[$maxDayIdx]) . " spent" : "0m spent";

// Average daily study time
$avgDailyMins = ($timeFilter === 'this_month') ? round($totalMinutes / max(1, (int)date('j'))) : round($totalMinutes / 7);
$avgDailyText = formatDuration($avgDailyMins);

// Time Distribution (Percentages)
$timeDistribution = [];
$catColors = [
    'Focused Study' => '#FF6B00',
    'Practice (Quiz)' => '#FFAA00',
    'Note Making' => '#8B5CF6'
];
foreach (['Focused Study', 'Practice (Quiz)', 'Note Making'] as $cat) {
    $cMins = $categoryMinutes[$cat];
    $cPct = ($totalMinutes > 0) ? round(($cMins / $totalMinutes) * 100) : 0;
    $timeDistribution[] = [
        'label' => $cat,
        'minutes' => $cMins,
        'formattedTime' => formatDuration($cMins),
        'percentage' => (int)$cPct,
        'fraction' => ($totalMinutes > 0) ? round($cMins / (float)$totalMinutes, 2) : 0.0,
        'colorHex' => $catColors[$cat]
    ];
}

// 4 Placement Tracks Breakdown
$subjectBreakdown = [];
$topTrackName = null;
$topTrackMinutes = -1;
foreach ($trackMinutes as $tName => $tMins) {
    $pct = ($totalMinutes > 0) ? round(($tMins / $totalMinutes) * 100) : 0;
    if ($tMins > $topTrackMinutes) {
        $topTrackMinutes = $tMins;
        $topTrackName = $tName;
    }
    $subjectBreakdown[] = [
        'name' => $tName,
        'slug' => getTrackSlug($tName),
        'icon' => getTrackIcon($tName),
        'colorHex' => getTrackColor($tName),
        'minutes' => $tMins,
        'formattedTime' => formatDuration($tMins),
        'percentage' => (int)$pct,
        'fraction' => ($totalMinutes > 0) ? round($tMins / (float)$totalMinutes, 2) : 0.0
    ];
}

// Weekly Subject Comparison Normalized Heights (0..6 days for each track)
$maxTrackDaily = 1;
foreach ($trackDailyMap as $tArr) {
    $m = max($tArr);
    if ($m > $maxTrackDaily) $maxTrackDaily = $m;
}
$weeklySubjectComparison = [];
foreach ($trackDailyMap as $tName => $tArr) {
    $hArr = [];
    foreach ($tArr as $tm) {
        $hArr[] = ($tm > 0) ? round(max(0.1, $tm / (float)$maxTrackDaily), 2) : 0.0;
    }
    $weeklySubjectComparison[] = [
        'name' => $tName,
        'slug' => getTrackSlug($tName),
        'colorHex' => getTrackColor($tName),
        'heights' => $hArr
    ];
}

// Consistency & Completed Goals in current week
$curMonday = strtotime('monday this week');
$activeDaysThisWeek = 0;
for ($i = 0; $i < 7; $i++) {
    $cd = date('Y-m-d', strtotime("+{$i} days", $curMonday));
    if (in_array($cd, $allActiveDates)) {
        $activeDaysThisWeek++;
    }
}
$weeklyConsistencyPct = round(($activeDaysThisWeek / 7.0) * 100);

// Calendar active dates for requested month
$monthActiveDates = [];
$monthActiveDayNumbers = [];
foreach ($allActiveDates as $dStr) {
    if (str_starts_with($dStr, $reqMonth)) {
        $monthActiveDates[] = $dStr;
        $monthActiveDayNumbers[] = (int)date('j', strtotime($dStr));
    }
}

// Dynamic Insights
$insights = "Start your first micro-lesson, quiz, or smart note to unlock personalized study insights!";
if ($totalMinutes > 0 && $topTrackName !== null) {
    $topPct = round(($topTrackMinutes / $totalMinutes) * 100);
    $insights = "You spent the most time on {$topTrackName} this period ({$topPct}% of your {$avgDailyText}/day study time). Keep up the great pace!";
}

$response = [
    'status' => 'success',
    'data' => [
        'userId' => $uid,
        'timeFilter' => $timeFilter,
        'selectedMonth' => $reqMonth,
        'totalMinutes' => $totalMinutes,
        'formattedStudyTime' => formatDuration($totalMinutes),
        'averageDailyTime' => $avgDailyText,
        'mostProductiveDay' => $mostProductiveDayName,
        'mostProductiveDayText' => $mostProductiveDayText,
        'currentStreak' => $currentStreak,
        'bestStreak' => $bestStreak,
        'weeklyConsistency' => "{$weeklyConsistencyPct}%",
        'weeklyConsistencyPercentage' => (int)$weeklyConsistencyPct,
        'completedDailyGoals' => "{$activeDaysThisWeek} / 7",
        'completedDailyGoalsCount' => $activeDaysThisWeek,
        'activeStudyDates' => $allActiveDates,
        'monthActiveDays' => $monthActiveDayNumbers,
        'chartDays' => $dayNames,
        'chartHeights' => $chartHeights,
        'chartMinutes' => $weeklyDayMinutes,
        'timeDistribution' => $timeDistribution,
        'subjectBreakdown' => $subjectBreakdown,
        'weeklySubjectComparison' => $weeklySubjectComparison,
        'insights' => $insights
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
