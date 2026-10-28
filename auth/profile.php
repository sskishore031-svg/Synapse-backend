<?php
// Profile Endpoint - Synapse AI Backend (Gmail / User ID Based, Modular Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// Helper to determine protocol & photo URL
function buildPhotoUrl($photoPath) {
    if (empty($photoPath)) return null;
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host . "/Synapse_backend/" . ltrim($photoPath, '/');
}

// Helper to format user response object
function formatUserResponse($user, $pdo) {
    $interests = [];
    if (!empty($user['interests'])) {
        $decoded = json_decode($user['interests'], true);
        $interests = is_array($decoded) ? $decoded : array_map('trim', explode(',', $user['interests']));
    }

    $achievementsCount = "8 badges";
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_achievements WHERE user_id = :uid AND is_unlocked = 1");
        $stmt->execute(['uid' => $user['id']]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) {
            $achievementsCount = "$count " . ($count === 1 ? "badge" : "badges");
        }
    } catch (Exception $e) {}

    return [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone'] ?? '+1 202-555-0147',
        'bio' => $user['bio'] ?? '',
        'course' => $user['course'] ?? 'B.Tech Computer Science · Year 3',
        'preferred_language' => $user['preferred_language'] ?? 'English',
        'interests' => $interests,
        'study_goal' => $user['study_goal'] ?? '25 min / day',
        'notifications_enabled' => (bool)($user['notifications_enabled'] ?? 1),
        'profile_photo' => $user['profile_photo'] ?? null,
        'photo_url' => buildPhotoUrl($user['profile_photo'] ?? null),
        'achievements_count' => $achievementsCount,
        'user_streak' => 'Year 3 · Learning every day',
        'created_at' => $user['created_at'] ?? null
    ];
}

// -------------------------------------------------------------
// 1. GET Request: Fetch Profile by Gmail or user_id
// -------------------------------------------------------------
if ($method === 'GET') {
    $userId = $_GET['user_id'] ?? $_GET['id'] ?? null;
    $email = trim($_GET['email'] ?? '');

    if (empty($email) && !empty($_SERVER['HTTP_X_USER_EMAIL'])) {
        $email = trim($_SERVER['HTTP_X_USER_EMAIL']);
    }
    if (empty($userId) && !empty($_SERVER['HTTP_X_USER_ID'])) {
        $userId = trim($_SERVER['HTTP_X_USER_ID']);
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (empty($email) && !empty($authHeader)) {
        if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
            $email = $matches[1];
        }
    }

    try {
        $sql = "
            SELECT 
                u.id, COALESCE(p.name, u.name) AS name, u.email, u.phone, u.created_at,
                p.bio, p.course, p.interests, p.profile_photo,
                s.preferred_language, s.study_goal, s.notifications_enabled
            FROM users u
            LEFT JOIN user_profiles p ON u.id = p.user_id
            LEFT JOIN user_settings s ON u.id = s.user_id
        ";

        if (!empty($userId)) {
            $stmt = $pdo->prepare($sql . " WHERE u.id = :id LIMIT 1");
            $stmt->execute(['id' => $userId]);
        } elseif (!empty($email)) {
            $stmt = $pdo->prepare($sql . " WHERE u.email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
        } else {
            // Local fallback to default first user
            $stmt = $pdo->query($sql . " ORDER BY u.id ASC LIMIT 1");
        }
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'User profile not found.'
            ]);
            exit();
        }

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'user' => formatUserResponse($user, $pdo)
        ]);
        exit();

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to retrieve profile: ' . $e->getMessage()
        ]);
        exit();
    }
}

// -------------------------------------------------------------
// 2. POST / PUT Request: Update Profile by Gmail or user_id
// -------------------------------------------------------------
if ($method === 'POST' || $method === 'PUT') {
    $input = json_decode(file_get_contents("php://input"), true) ?? [];
    $userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? null;
    $email = trim($input['email'] ?? $_POST['email'] ?? $_GET['email'] ?? '');

    if (empty($email) && !empty($_SERVER['HTTP_X_USER_EMAIL'])) {
        $email = trim($_SERVER['HTTP_X_USER_EMAIL']);
    }
    if (empty($userId) && !empty($_SERVER['HTTP_X_USER_ID'])) {
        $userId = trim($_SERVER['HTTP_X_USER_ID']);
    }

    try {
        $sql = "
            SELECT 
                u.id, COALESCE(p.name, u.name) AS name, u.email, u.phone, u.created_at,
                p.bio, p.course, p.interests, p.profile_photo,
                s.preferred_language, s.study_goal, s.notifications_enabled
            FROM users u
            LEFT JOIN user_profiles p ON u.id = p.user_id
            LEFT JOIN user_settings s ON u.id = s.user_id
        ";

        if (!empty($userId)) {
            $stmt = $pdo->prepare($sql . " WHERE u.id = :id LIMIT 1");
            $stmt->execute(['id' => $userId]);
        } elseif (!empty($email)) {
            $stmt = $pdo->prepare($sql . " WHERE u.email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
        } else {
            $stmt = $pdo->query($sql . " ORDER BY u.id ASC LIMIT 1");
        }
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'User profile not found. Please provide registered email or user_id.'
            ]);
            exit();
        }

        $uid = (int)$user['id'];
        $name = isset($input['name']) ? trim($input['name']) : (isset($_POST['name']) ? trim($_POST['name']) : $user['name']);
        $phone = isset($input['phone']) ? trim($input['phone']) : (isset($_POST['phone']) ? trim($_POST['phone']) : $user['phone']);
        $bio = isset($input['bio']) ? trim($input['bio']) : (isset($_POST['bio']) ? trim($_POST['bio']) : $user['bio']);
        $course = isset($input['course']) ? trim($input['course']) : (isset($_POST['course']) ? trim($_POST['course']) : $user['course']);
        $language = isset($input['preferred_language']) ? trim($input['preferred_language']) : (isset($_POST['preferred_language']) ? trim($_POST['preferred_language']) : $user['preferred_language']);
        $studyGoal = isset($input['study_goal']) ? trim($input['study_goal']) : (isset($_POST['study_goal']) ? trim($_POST['study_goal']) : $user['study_goal']);
        
        $notifications = isset($input['notifications_enabled']) 
            ? ($input['notifications_enabled'] ? 1 : 0) 
            : (isset($_POST['notifications_enabled']) ? ($_POST['notifications_enabled'] ? 1 : 0) : $user['notifications_enabled']);

        $interests = $user['interests'];
        if (isset($input['interests'])) {
            $interests = is_array($input['interests']) ? json_encode(array_values($input['interests'])) : $input['interests'];
        } elseif (isset($_POST['interests'])) {
            $interests = is_array($_POST['interests']) ? json_encode(array_values($_POST['interests'])) : $_POST['interests'];
        }

        // 1. Update users table (name, phone)
        $updUser = $pdo->prepare("UPDATE users SET name = :name, phone = :phone WHERE id = :id");
        $updUser->execute(['name' => $name, 'phone' => $phone, 'id' => $uid]);

        // 2. Upsert user_profiles table (name, bio, course, interests)
        $updProfile = $pdo->prepare("
            INSERT INTO user_profiles (user_id, name, bio, course, interests) 
            VALUES (:uid, :name, :bio, :course, :interests)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                bio = VALUES(bio), 
                course = VALUES(course), 
                interests = VALUES(interests)
        ");
        $updProfile->execute([
            'uid' => $uid,
            'name' => $name,
            'bio' => $bio,
            'course' => $course,
            'interests' => $interests
        ]);

        // 3. Upsert user_settings table (study_goal, preferred_language, notifications_enabled)
        $updSettings = $pdo->prepare("
            INSERT INTO user_settings (user_id, study_goal, preferred_language, notifications_enabled) 
            VALUES (:uid, :goal, :lang, :notif)
            ON DUPLICATE KEY UPDATE 
                study_goal = VALUES(study_goal), 
                preferred_language = VALUES(preferred_language), 
                notifications_enabled = VALUES(notifications_enabled)
        ");
        $updSettings->execute([
            'uid' => $uid,
            'goal' => $studyGoal,
            'lang' => $language,
            'notif' => $notifications
        ]);

        // 4. Fetch updated combined user profile
        $fetchStmt = $pdo->prepare($sql . " WHERE u.id = :id LIMIT 1");
        $fetchStmt->execute(['id' => $uid]);
        $updatedUser = $fetchStmt->fetch();

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Profile updated successfully.',
            'user' => formatUserResponse($updatedUser, $pdo)
        ]);
        exit();

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Error updating profile: ' . $e->getMessage()
        ]);
        exit();
    }
}
