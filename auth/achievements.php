<?php
// Achievements Endpoint - Synapse AI Backend (Gmail / User ID Based, No Tokens)
// Corresponds to AchievementsScreen and ProfileScreen
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Accept user_id or email from query parameters, POST body, JSON payload, or header
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
    // Locate user
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
        // Fallback to first user in database
        $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found. Please provide registered email or user_id.'
        ]);
        exit();
    }

    $uid = (int)$user['id'];

    // Check if achievements exist for this user, if not seed default badges
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM user_achievements WHERE user_id = :uid");
    $checkStmt->execute(['uid' => $uid]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count === 0) {
        $defaultBadges = [
            ['title' => 'Quick Learner', 'desc' => 'Completed your first 5 AI lessons', 'icon' => '⚡'],
            ['title' => '7-Day Streak', 'desc' => 'Studied consecutively for 7 days', 'icon' => '🔥'],
            ['title' => 'Quiz Master', 'desc' => 'Scored 100% on 3 assessments', 'icon' => '🎯'],
            ['title' => 'Night Owl', 'desc' => 'Completed study sessions past 10 PM', 'icon' => '🦉'],
            ['title' => 'Problem Solver', 'desc' => 'Solved 20 coding challenges', 'icon' => '🧩'],
            ['title' => 'AI Explorer', 'desc' => 'Generated 50 AI summaries and notes', 'icon' => '🤖'],
            ['title' => 'Consistent Scholar', 'desc' => 'Reached 30 hours of total study time', 'icon' => '📚'],
            ['title' => 'Early Bird', 'desc' => 'Completed morning study sessions', 'icon' => '🌅']
        ];

        $insertBadge = $pdo->prepare("
            INSERT INTO user_achievements (user_id, title, description, badge_icon, is_unlocked) 
            VALUES (:uid, :title, :desc, :icon, 1)
        ");
        foreach ($defaultBadges as $b) {
            $insertBadge->execute([
                'uid' => $uid,
                'title' => $b['title'],
                'desc' => $b['desc'],
                'icon' => $b['icon']
            ]);
        }
    }

    // Fetch achievements
    $fetchStmt = $pdo->prepare("
        SELECT id, title, description, badge_icon, is_unlocked, unlocked_at 
        FROM user_achievements 
        WHERE user_id = :uid 
        ORDER BY id ASC
    ");
    $fetchStmt->execute(['uid' => $uid]);
    $achievements = $fetchStmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'user_id' => $uid,
        'email' => $user['email'],
        'name' => $user['name'],
        'total_badges' => count($achievements),
        'badges_count_text' => count($achievements) . ' badges',
        'achievements' => array_map(function($a) {
            return [
                'id' => (int)$a['id'],
                'title' => $a['title'],
                'description' => $a['description'],
                'icon' => $a['badge_icon'],
                'is_unlocked' => (bool)$a['is_unlocked'],
                'unlocked_at' => $a['unlocked_at']
            ];
        }, $achievements)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error fetching achievements: ' . $e->getMessage()
    ]);
}
