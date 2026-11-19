<?php
// Profile Endpoint - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

function buildPhotoUrl($photoPath) {
    if (empty($photoPath)) return null;
    if (str_starts_with($photoPath, 'http')) return $photoPath;
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host . "/Synapse_backend/" . ltrim($photoPath, '/');
}

function formatUserResponse($user, $pdo) {
    $interests = ['Computer Science', 'Artificial Intelligence', 'Mathematics'];
    if (!empty($user['selected_interests'])) {
        $decoded = json_decode($user['selected_interests'], true);
        $interests = is_array($decoded) ? $decoded : array_map('trim', explode(',', $user['selected_interests']));
    }

    $avatarUrl = buildPhotoUrl($user['avatar_url'] ?? null);
    $rawPath = $user['avatar_url'] ?? null;

    // Count quizzes and notes completed
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

    return [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => !empty($user['phone']) ? $user['phone'] : (!empty($user['phone_number']) ? $user['phone_number'] : ''),
        'phone_number' => !empty($user['phone_number']) ? $user['phone_number'] : (!empty($user['phone']) ? $user['phone'] : ''),
        'bio' => !empty($user['bio']) ? $user['bio'] : 'Passionate student exploring AI, Computer Science & Mathematics.',
        'course' => !empty($user['course']) ? $user['course'] : 'B.Tech Computer Science · Year 3',
        'year' => !empty($user['course']) ? $user['course'] : 'Year 3',
        'preferred_language' => 'English',
        'interests' => $interests,
        'selected_interests' => $interests,
        'study_goal' => !empty($user['study_goal']) ? $user['study_goal'] : '25 min / day',
        'notifications_enabled' => true,
        'avatar_url' => $avatarUrl,
        'profile_photo' => $rawPath ?: $avatarUrl,
        'photo_url' => $avatarUrl,
        'achievements_count' => ($quizCount + $notesCount) . " milestones",
        'user_streak' => '1 Day Streak 🔥',
        'quiz_count' => $quizCount,
        'notes_count' => $notesCount,
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
        $phoneCol = "";
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
            if ($checkCol) { $phoneCol = ", u.phone"; }
        } catch (Exception $e) {}

        $sql = "
            SELECT u.id, u.name, u.email $phoneCol, u.created_at, 
                   p.selected_interests, p.avatar_url, p.course, p.study_goal, p.bio
            FROM users u
            LEFT JOIN user_profiles p ON u.id = p.user_id
        ";

        if (!empty($userId)) {
            $stmt = $pdo->prepare($sql . " WHERE u.id = :uid LIMIT 1");
            $stmt->execute(['uid' => $userId]);
        } elseif (!empty($email)) {
            $stmt = $pdo->prepare($sql . " WHERE u.email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'User ID or Email is required.']);
            exit();
        }

        $user = $stmt->fetch();
        if (!$user) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'User profile not found.']);
            exit();
        }

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'user' => formatUserResponse($user, $pdo)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error fetching profile: ' . $e->getMessage()]);
    }
    exit();
}

// -------------------------------------------------------------
// 2. POST / PUT Request: Update Profile & Interests
// -------------------------------------------------------------
if ($method === 'POST' || $method === 'PUT') {
    $input = json_decode(file_get_contents("php://input"), true) ?? [];
    $userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? null;
    $email = trim($input['email'] ?? $_POST['email'] ?? $_GET['email'] ?? '');
    $name = trim($input['name'] ?? $_POST['name'] ?? '');
    $selectedInterests = $input['selected_interests'] ?? $input['interests'] ?? $_POST['selected_interests'] ?? $_POST['interests'] ?? null;
    $avatarUrl = $input['avatar_url'] ?? $input['photo_url'] ?? $_POST['avatar_url'] ?? null;

    try {
        // Resolve user
        $user = null;
        if (!empty($userId)) {
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :uid LIMIT 1");
            $stmt->execute(['uid' => $userId]);
            $user = $stmt->fetch();
        } elseif (!empty($email)) {
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'User ID or Email is required.']);
            exit();
        }

        if (!$user) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'User not found.']);
            exit();
        }

        $uid = (int)$user['id'];

        // Update name in users table if provided
        if (!empty($name)) {
            $uStmt = $pdo->prepare("UPDATE users SET name = :name WHERE id = :uid");
            $uStmt->execute(['name' => $name, 'uid' => $uid]);
        }

        $phone = trim($input['phone'] ?? $input['phone_number'] ?? $_POST['phone'] ?? $_POST['phone_number'] ?? '');
        if (!empty($phone)) {
            try {
                $pStmt = $pdo->prepare("UPDATE users SET phone = :phone, phone_number = :phone_num WHERE id = :uid");
                $pStmt->execute(['phone' => $phone, 'phone_num' => $phone, 'uid' => $uid]);
            } catch (Exception $e) {
                try {
                    $pStmt = $pdo->prepare("UPDATE users SET phone = :phone WHERE id = :uid");
                    $pStmt->execute(['phone' => $phone, 'uid' => $uid]);
                } catch (Exception $e2) {}
            }
        }

        // Update profile course, study_goal, bio
        $course = trim($input['course'] ?? $input['year'] ?? $_POST['course'] ?? $_POST['year'] ?? '');
        $studyGoal = trim($input['study_goal'] ?? $_POST['study_goal'] ?? '');
        $bio = trim($input['bio'] ?? $_POST['bio'] ?? '');

        if (!empty($course) || !empty($studyGoal) || !empty($bio)) {
            $pStmt = $pdo->prepare("
                INSERT INTO user_profiles (user_id, course, study_goal, bio)
                VALUES (:uid, :course, :goal, :bio)
                ON DUPLICATE KEY UPDATE 
                    course = IF(VALUES(course) != '', VALUES(course), course),
                    study_goal = IF(VALUES(study_goal) != '', VALUES(study_goal), study_goal),
                    bio = IF(VALUES(bio) != '', VALUES(bio), bio)
            ");
            $pStmt->execute([
                'uid' => $uid,
                'course' => !empty($course) ? $course : 'B.Tech Computer Science · Year 3',
                'goal' => !empty($studyGoal) ? $studyGoal : '25 min / day',
                'bio' => !empty($bio) ? $bio : 'Passionate student exploring AI, Computer Science & Mathematics.'
            ]);
        }

        // Format interests JSON
        if ($selectedInterests !== null) {
            $interestsArr = is_array($selectedInterests) 
                ? $selectedInterests 
                : (is_string($selectedInterests) ? (json_decode($selectedInterests, true) ?: array_map('trim', explode(',', $selectedInterests))) : []);
            $interestsJson = json_encode(array_values(array_filter($interestsArr)));

            $pStmt = $pdo->prepare("
                INSERT INTO user_profiles (user_id, selected_interests) 
                VALUES (:uid, :interests)
                ON DUPLICATE KEY UPDATE selected_interests = :interests_update
            ");
            $pStmt->execute([
                'uid' => $uid,
                'interests' => $interestsJson,
                'interests_update' => $interestsJson
            ]);
        }

        // Handle base64 avatar upload if provided
        $base64Image = $input['profile_image_base64'] ?? $input['avatar_base64'] ?? $_POST['profile_image_base64'] ?? null;
        if (!empty($base64Image)) {
            $uploadsDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }
            if (strpos($base64Image, 'base64,') !== false) {
                $base64Image = explode('base64,', $base64Image)[1];
            }
            $decodedData = base64_decode($base64Image);
            if ($decodedData !== false) {
                $avatarFile = "avatar_" . $uid . ".jpg";
                file_put_contents($uploadsDir . $avatarFile, $decodedData);
                $avatarUrl = "uploads/" . $avatarFile;
            }
        }

        // Update avatar if provided
        if ($avatarUrl !== null) {
            $aStmt = $pdo->prepare("
                INSERT INTO user_profiles (user_id, avatar_url) 
                VALUES (:uid, :avatar)
                ON DUPLICATE KEY UPDATE avatar_url = :avatar_update
            ");
            $aStmt->execute([
                'uid' => $uid,
                'avatar' => $avatarUrl,
                'avatar_update' => $avatarUrl
            ]);
        }

        // Fetch updated
        $phoneCol = "";
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
            if ($checkCol) { $phoneCol = ", u.phone"; }
        } catch (Exception $e) {}

        $stmt = $pdo->prepare("
            SELECT u.id, u.name, u.email $phoneCol, u.created_at, 
                   p.selected_interests, p.avatar_url, p.course, p.study_goal, p.bio
            FROM users u
            LEFT JOIN user_profiles p ON u.id = p.user_id
            WHERE u.id = :uid
        ");
        $stmt->execute(['uid' => $uid]);
        $updatedUser = $stmt->fetch();

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'Profile updated successfully!',
            'user' => formatUserResponse($updatedUser, $pdo)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error updating profile: ' . $e->getMessage()]);
    }
}
