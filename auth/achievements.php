<?php
// Achievements Endpoint - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

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

    // Ensure user_achievements table exists
    try {
        $pdo->query("CREATE TABLE IF NOT EXISTS user_achievements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            badge_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_badge (user_id, badge_id)
        )");
    } catch (Exception $e) {}

    // POST: Unlock / Record Achievement Milestone
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $badgeId = (int)($input['badge_id'] ?? $input['id'] ?? $_POST['badge_id'] ?? 1);
        $title = trim($input['title'] ?? $_POST['title'] ?? "Milestone $badgeId");

        try {
            $ins = $pdo->prepare("
                INSERT INTO user_achievements (user_id, badge_id, title, unlocked_at)
                VALUES (:uid, :bid, :title, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE unlocked_at = CURRENT_TIMESTAMP
            ");
            $ins->execute([
                'uid' => $uid,
                'bid' => $badgeId,
                'title' => $title
            ]);
        } catch (Exception $e) {}

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => "Badge '$title' unlocked successfully!",
            'badge_id' => $badgeId,
            'is_unlocked' => true
        ]);
        exit();
    }

    // Compute achievements from Core Tables (quiz_records, study_notes, completions, sessions)
    $quizStmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_records WHERE user_id = :uid");
    $quizStmt->execute(['uid' => $uid]);
    $quizCount = (int)$quizStmt->fetchColumn();

    $notesStmt = $pdo->prepare("SELECT COUNT(*) FROM study_notes WHERE user_id = :uid");
    $notesStmt->execute(['uid' => $uid]);
    $notesCount = (int)$notesStmt->fetchColumn();

    $unlockedMap = [];
    try {
        $uStmt = $pdo->prepare("SELECT badge_id, unlocked_at FROM user_achievements WHERE user_id = :uid");
        $uStmt->execute(['uid' => $uid]);
        while ($r = $uStmt->fetch()) {
            $unlockedMap[(int)$r['badge_id']] = $r['unlocked_at'];
        }
    } catch (Exception $e) {}

    $compCount = 0;
    try {
        $cStmt = $pdo->prepare("SELECT COUNT(DISTINCT chapter_id) FROM user_chapter_completions WHERE user_id = :uid");
        $cStmt->execute(['uid' => $uid]);
        $compCount = (int)$cStmt->fetchColumn();
    } catch (Exception $e) {}

    $streakDays = 0;
    try {
        $sStmt = $pdo->prepare("SELECT COUNT(DISTINCT study_date) FROM study_sessions WHERE user_id = :uid");
        $sStmt->execute(['uid' => $uid]);
        $streakDays = (int)$sStmt->fetchColumn();
    } catch (Exception $e) {}

    $badges = [
        ['id' => 1, 'title' => 'Quick Learner', 'desc' => 'Completed your first AI lessons', 'icon' => '⚡', 'unlocked' => ($compCount > 0 || isset($unlockedMap[1]))],
        ['id' => 2, 'title' => 'Streak Master', 'desc' => 'Studied consecutively for 3 days', 'icon' => '🔥', 'unlocked' => ($streakDays >= 3 || isset($unlockedMap[2]))],
        ['id' => 3, 'title' => 'Quiz Master', 'desc' => 'Completed quiz assessments', 'icon' => '🎯', 'unlocked' => ($quizCount > 0 || isset($unlockedMap[3]))],
        ['id' => 4, 'title' => 'Note Taker', 'desc' => 'Saved comprehensive study notes', 'icon' => '📝', 'unlocked' => ($notesCount > 0 || isset($unlockedMap[4]))],
        ['id' => 5, 'title' => 'Problem Solver', 'desc' => 'Solved academic challenges', 'icon' => '🧩', 'unlocked' => (($compCount >= 3 && $quizCount >= 1) || isset($unlockedMap[5]))],
        ['id' => 6, 'title' => 'AI Explorer', 'desc' => 'Explored on-device neural tutor', 'icon' => '🤖', 'unlocked' => ($streakDays > 0 || $compCount > 0 || isset($unlockedMap[6]))],
        ['id' => 7, 'title' => 'Consistent Scholar', 'desc' => 'Maintained active daily study across 5+ lessons', 'icon' => '📚', 'unlocked' => ($compCount >= 5 || isset($unlockedMap[7]))],
        ['id' => 8, 'title' => 'Early Bird', 'desc' => 'Completed morning study sessions', 'icon' => '🌅', 'unlocked' => isset($unlockedMap[8])]
    ];

    $unlockedCount = count(array_filter($badges, fn($b) => $b['unlocked']));

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'user_id' => $uid,
        'email' => $user['email'],
        'name' => $user['name'],
        'total_badges' => count($badges),
        'unlocked_count' => $unlockedCount,
        'badges_count_text' => "$unlockedCount badges",
        'achievements' => array_map(function($a) {
            return [
                'id' => $a['id'],
                'title' => $a['title'],
                'description' => $a['desc'],
                'icon' => $a['icon'],
                'is_unlocked' => $a['unlocked'],
                'unlocked_at' => date('Y-m-d')
            ];
        }, $badges)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error fetching achievements: ' . $e->getMessage()]);
}
