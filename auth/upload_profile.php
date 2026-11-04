<?php
// Profile & Photo Upload Endpoint - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

$dbPath = file_exists(__DIR__ . '/config/db.php') ? __DIR__ . '/config/db.php' : __DIR__ . '/../config/db.php';
require_once $dbPath;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "status" => "error",
        "message" => "POST method required"
    ]);
    exit;
}

// Helper to determine protocol & photo URL
function buildPhotoUrl($photoPath) {
    if (empty($photoPath)) return null;
    if (preg_match('/^https?:\/\//i', $photoPath)) return $photoPath;
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

    return [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => '+1 202-555-0147',
        'bio' => 'Passionate student exploring AI, Computer Science & Mathematics.',
        'course' => 'B.Tech Computer Science · Year 3',
        'preferred_language' => 'English',
        'interests' => $interests,
        'selected_interests' => $interests,
        'study_goal' => '25 min / day',
        'notifications_enabled' => true,
        'avatar_url' => $avatarUrl,
        'profile_photo' => $rawPath ?: $avatarUrl,
        'photo_url' => $avatarUrl,
        'achievements_count' => "5 milestones",
        'user_streak' => '3 Day Streak 🔥',
        'created_at' => $user['created_at'] ?? null
    ];
}

try {
    // 1. Identify User
    $userId = $_POST['user_id'] ?? $_POST['id'] ?? null;
    $email = trim($_POST['email'] ?? '');

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

    $sql = "
        SELECT u.id, u.name, u.email, u.created_at, p.selected_interests, p.avatar_url
        FROM users u
        LEFT JOIN user_profiles p ON u.id = p.user_id
    ";

    $user = null;
    if (!empty($userId)) {
        $stmt = $pdo->prepare($sql . " WHERE u.id = :id LIMIT 1");
        $stmt->execute(["id" => $userId]);
        $user = $stmt->fetch();
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare($sql . " WHERE u.email = :email LIMIT 1");
        $stmt->execute(["email" => $email]);
        $user = $stmt->fetch();
    } else {
        $stmt = $pdo->query($sql . " ORDER BY u.id DESC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found."]);
        exit;
    }

    $uid = (int)$user["id"];

    // 2. Extract input fields
    $name = trim($_POST["name"] ?? "");
    $interestsInput = $_POST["selected_interests"] ?? $_POST["interests"] ?? null;

    // 3. Handle File Upload if provided
    $avatarUrl = $user["avatar_url"];
    if (isset($_FILES["profile_photo"]) && $_FILES["profile_photo"]["error"] === UPLOAD_ERR_OK) {
        $file = $_FILES["profile_photo"];
        $uploadDir = __DIR__ . "/../upload/profile/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
        if (in_array($ext, ["jpg", "jpeg", "png", "webp"])) {
            $filename = md5(uniqid($uid, true)) . "." . $ext;
            $destination = $uploadDir . $filename;
            if (move_uploaded_file($file["tmp_name"], $destination)) {
                $avatarUrl = "upload/profile/" . $filename;
            }
        }
    }

    // 4. Update users table (Core Table 1)
    if (!empty($name)) {
        $updUser = $pdo->prepare("UPDATE users SET name = :name WHERE id = :id");
        $updUser->execute(["name" => $name, "id" => $uid]);
    }

    // 5. Update user_profiles table (Core Table 2)
    $interestsJson = $user['selected_interests'];
    if ($interestsInput !== null) {
        $interestsArr = is_array($interestsInput) ? $interestsInput : (json_decode($interestsInput, true) ?: array_map('trim', explode(',', $interestsInput)));
        $interestsJson = json_encode(array_values(array_filter($interestsArr)));
    }

    $updProfile = $pdo->prepare("
        INSERT INTO user_profiles (user_id, selected_interests, avatar_url)
        VALUES (:uid, :interests, :avatar)
        ON DUPLICATE KEY UPDATE 
            selected_interests = COALESCE(:interests_update, selected_interests),
            avatar_url = COALESCE(:avatar_update, avatar_url)
    ");
    $updProfile->execute([
        "uid" => $uid,
        "interests" => $interestsJson,
        "avatar" => $avatarUrl,
        "interests_update" => $interestsJson,
        "avatar_update" => $avatarUrl
    ]);

    // 6. Fetch updated record
    $fetch = $pdo->prepare($sql . " WHERE u.id = :id");
    $fetch->execute(["id" => $uid]);
    $savedUser = $fetch->fetch();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Profile updated successfully.",
        "user" => formatUserResponse($savedUser, $pdo)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Upload failed: " . $e->getMessage()]);
}
