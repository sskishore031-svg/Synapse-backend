<?php
// Profile & Photo Upload Endpoint - Synapse AI Backend (Gmail / User ID Based, Modular Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id");

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
        'phone' => $user['phone'] ?? '+91 98765-43210',
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

// Accepts Form-Data fields
$userId = $_POST["user_id"] ?? $_POST["id"] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_POST["email"] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? "");
$name = trim($_POST["name"] ?? "");
$phone = trim($_POST["phone"] ?? "");
$bio = trim($_POST["bio"] ?? "");
$course = trim($_POST["course"] ?? "");
$language = trim($_POST["preferred_language"] ?? "");
$studyGoal = trim($_POST["study_goal"] ?? "");
$removePhoto = isset($_POST["remove_photo"]) && ($_POST["remove_photo"] === "1" || $_POST["remove_photo"] === "true");

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

$uploadedFilePath = null;
$oldPhotoFileToDelete = null;

try {
    // 1. Locate student
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
        $check = $pdo->prepare($sql . " WHERE u.id = :id LIMIT 1");
        $check->execute(["id" => $userId]);
    } elseif (!empty($email)) {
        $check = $pdo->prepare($sql . " WHERE u.email = :email LIMIT 1");
        $check->execute(["email" => $email]);
    } else {
        $check = $pdo->query($sql . " ORDER BY u.id ASC LIMIT 1");
    }
    $user = $check->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "User not found for this Gmail or ID."
        ]);
        exit;
    }

    $uid = (int)$user["id"];
    $photoPath = $user["profile_photo"];

    $baseDir = is_dir(__DIR__ . '/upload') ? __DIR__ : dirname(__DIR__);

    // 2. Handle Remove Photo flag
    if ($removePhoto) {
        if (!empty($photoPath)) {
            $existingFile = $baseDir . "/" . ltrim($photoPath, "/");
            if (file_exists($existingFile) && is_file($existingFile)) {
                @unlink($existingFile);
            }
        }
        $photoPath = null;
    }

    // 3. Handle Photo Upload
    $photoFile = $_FILES["photo"] ?? $_FILES["profile_photo"] ?? $_FILES["image"] ?? null;

    if ($photoFile !== null && $photoFile["error"] !== UPLOAD_ERR_NO_FILE) {
        if ($photoFile["error"] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(
                "Photo upload failed. PHP upload error code: " . $photoFile["error"]
            );
        }

        if ($photoFile["size"] > 5 * 1024 * 1024) {
            throw new InvalidArgumentException("Photo must be smaller than 5 MB");
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($photoFile["tmp_name"]);

        $allowed = [
            "image/jpeg" => "jpg",
            "image/jpg"  => "jpg",
            "image/png"  => "png",
            "image/webp" => "webp"
        ];

        if (!isset($allowed[$mime])) {
            throw new InvalidArgumentException(
                "Only JPG, PNG and WEBP photos are allowed. Detected: " . $mime
            );
        }

        $uploadDir = $baseDir . "/upload/profile/";

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException("Could not create upload/profile folder");
        }

        $filename = bin2hex(random_bytes(16)) . "." . $allowed[$mime];
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($photoFile["tmp_name"], $destination)) {
            throw new RuntimeException("Could not save the uploaded photo to server directory");
        }

        $uploadedFilePath = $destination;

        // Remember old file to delete AFTER successful database update
        if (!empty($user["profile_photo"]) && $user["profile_photo"] !== ("upload/profile/" . $filename)) {
            $oldPhotoFileToDelete = $baseDir . "/" . ltrim($user["profile_photo"], "/");
        }

        $photoPath = "upload/profile/" . $filename;
    }

    // 4. Update users table (name, phone)
    $updateName = !empty($name) ? $name : $user["name"];
    $updatePhone = ($phone !== "") ? $phone : $user["phone"];
    $updUser = $pdo->prepare("UPDATE users SET name = :name, phone = :phone WHERE id = :id");
    $updUser->execute(["name" => $updateName, "phone" => $updatePhone, "id" => $uid]);

    // 5. Upsert user_profiles table (name, bio, course, profile_photo)
    $updateBio = ($bio !== "") ? $bio : $user["bio"];
    $updateCourse = ($course !== "") ? $course : ($user["course"] ?? 'B.Tech Computer Science · Year 3');
    $updProfile = $pdo->prepare("
        INSERT INTO user_profiles (user_id, name, bio, course, profile_photo)
        VALUES (:uid, :name, :bio, :course, :photo)
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            bio = VALUES(bio), 
            course = VALUES(course), 
            profile_photo = VALUES(profile_photo)
    ");
    $updProfile->execute([
        "uid" => $uid,
        "name" => $updateName,
        "bio" => $updateBio,
        "course" => $updateCourse,
        "photo" => $photoPath
    ]);

    // 6. Update user_settings table if preferences provided
    if (!empty($language) || !empty($studyGoal)) {
        $updSettings = $pdo->prepare("
            INSERT INTO user_settings (user_id, preferred_language, study_goal)
            VALUES (:uid, :lang, :goal)
            ON DUPLICATE KEY UPDATE 
                preferred_language = IF(VALUES(preferred_language) != '', VALUES(preferred_language), preferred_language),
                study_goal = IF(VALUES(study_goal) != '', VALUES(study_goal), study_goal)
        ");
        $updSettings->execute([
            "uid" => $uid,
            "lang" => !empty($language) ? $language : ($user["preferred_language"] ?? 'English'),
            "goal" => !empty($studyGoal) ? $studyGoal : ($user["study_goal"] ?? '25 min / day')
        ]);
    }

    // Database update succeeded: now clean up old photo file safely
    if ($oldPhotoFileToDelete && file_exists($oldPhotoFileToDelete) && is_file($oldPhotoFileToDelete)) {
        @unlink($oldPhotoFileToDelete);
    }

    // 7. Fetch updated complete profile
    $fetch = $pdo->prepare($sql . " WHERE u.id = :id");
    $fetch->execute(["id" => $uid]);
    $savedUser = $fetch->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Profile photo and details updated successfully.",
        "user" => formatUserResponse($savedUser, $pdo)
    ]);
    exit;

} catch (Throwable $e) {
    // If DB update failed after moving file, clean up newly uploaded file
    if ($uploadedFilePath && file_exists($uploadedFilePath)) {
        @unlink($uploadedFilePath);
    }

    error_log("Profile Photo Upload Error: " . $e->getMessage());

    $isInvalidInput = $e instanceof InvalidArgumentException;
    http_response_code($isInvalidInput ? 400 : 500);
    echo json_encode([
        "status" => "error",
        "message" => $isInvalidInput ? $e->getMessage() : ("Unable to update profile: " . $e->getMessage())
    ]);
    exit;
}
