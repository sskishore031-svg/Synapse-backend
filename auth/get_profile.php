<?php
// Dedicated Get Profile Endpoint - Synapse AI Backend
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Support both GET and POST requests, JSON payload or form data
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $_POST['user_id'] ?? $_POST['id'] ?? $input['user_id'] ?? $input['id'] ?? ($_SERVER['HTTP_X_USER_ID'] ?? null);
$email = trim($_GET['email'] ?? $_POST['email'] ?? $input['email'] ?? ($_SERVER['HTTP_X_USER_EMAIL'] ?? ''));

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

function buildPhotoUrl($photoPath) {
    if (empty($photoPath)) return null;
    if (str_starts_with($photoPath, 'http://') || str_starts_with($photoPath, 'https://')) return $photoPath;
    if (str_starts_with($photoPath, 'data:image')) return $photoPath;
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host . "/Synapse_backend/" . ltrim($photoPath, '/');
}

try {
    $user = null;
    if (!empty($userId)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Fallback: load first active user so screen never hard-fails
        $stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'User not found.'
        ]);
        exit();
    }

    $rawPath = !empty($user['profile_image']) ? $user['profile_image'] : ($user['avatar_url'] ?? null);
    $avatarUrl = buildPhotoUrl($rawPath);

    // Count quizzes and notes
    $quizCount = 0;
    try {
        $qStmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_records WHERE user_id = :uid");
        $qStmt->execute(['uid' => $user['id']]);
        $quizCount = (int)$qStmt->fetchColumn();
    } catch (Exception $e) {}

    $notesCount = 0;
    try {
        $nStmt = $pdo->prepare("SELECT COUNT(*) FROM study_notes WHERE user_id = :uid");
        $nStmt->execute(['uid' => $user['id']]);
        $notesCount = (int)$nStmt->fetchColumn();
    } catch (Exception $e) {}

    $userPayload = [
        'id' => (int)$user['id'],
        'name' => $user['name'] ?? 'Student',
        'email' => $user['email'] ?? '',
        'phone' => !empty($user['phone']) ? $user['phone'] : (!empty($user['phone_number']) ? $user['phone_number'] : ''),
        'phone_number' => !empty($user['phone_number']) ? $user['phone_number'] : (!empty($user['phone']) ? $user['phone'] : ''),
        'bio' => !empty($user['bio']) ? $user['bio'] : 'Learning, exploring and building a better version of myself.',
        'course' => !empty($user['course']) ? $user['course'] : 'B.Tech Computer Science · Year 3',
        'year' => !empty($user['course']) ? $user['course'] : 'Year 3',
        'preferred_language' => 'English',
        'study_goal' => !empty($user['study_goal']) ? $user['study_goal'] : '25 min / day',
        'streak' => '1 Day Streak 🔥',
        'user_streak' => '1 Day Streak 🔥',
        'notifications_enabled' => true,
        'profile_image' => $rawPath ?: $avatarUrl,
        'avatar_url' => $avatarUrl,
        'photo_url' => $avatarUrl,
        'profile_photo' => $rawPath ?: $avatarUrl,
        'quiz_count' => $quizCount,
        'notes_count' => $notesCount,
        'achievements_count' => ($quizCount + $notesCount) . " milestones",
        'created_at' => $user['created_at'] ?? null
    ];

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'user' => $userPayload
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Error retrieving profile: ' . $e->getMessage()
    ]);
}
