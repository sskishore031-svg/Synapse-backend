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

require_once __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "status" => "error",
        "message" => "POST method required"
    ]);
    exit;
}

// Accepts Form-Data fields
$userId = $_POST["user_id"] ?? $_POST["id"] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_POST["email"] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? "");
$name = trim($_POST["name"] ?? "");
$phone = trim($_POST["phone"] ?? "");
$bio = trim($_POST["bio"] ?? "");
$course = trim($_POST["course"] ?? "");
$language = trim($_POST["preferred_language"] ?? "");
$removePhoto = isset($_POST["remove_photo"]) && ($_POST["remove_photo"] === "1" || $_POST["remove_photo"] === "true");

try {
    // 1. Locate user
    $sql = "
        SELECT 
            u.id, COALESCE(p.name, u.name) AS name, u.email, u.phone,
            p.bio, p.course, p.profile_photo,
            s.preferred_language
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
            "message" => "User not found for this Gmail/ID."
        ]);
        exit;
    }

    $uid = (int)$user["id"];
    $photoPath = $user["profile_photo"];

    // 2. Handle Remove Photo flag
    if ($removePhoto) {
        if (!empty($photoPath)) {
            $existingFile = dirname(__DIR__) . "/" . ltrim($photoPath, "/");
            if (file_exists($existingFile) && is_file($existingFile)) {
                @unlink($existingFile);
            }
        }
        $photoPath = null;
    }

    // 3. Handle Photo Upload
    $photoFile = $_FILES["photo"] ?? $_FILES["profile_photo"] ?? null;

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
            "image/png"  => "png",
            "image/webp" => "webp"
        ];

        if (!isset($allowed[$mime])) {
            throw new InvalidArgumentException(
                "Only JPG, PNG and WEBP photos are allowed"
            );
        }

        $uploadDir = dirname(__DIR__) . "/upload/profile/";

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException("Could not create upload/profile folder");
        }

        $filename = bin2hex(random_bytes(16)) . "." . $allowed[$mime];
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($photoFile["tmp_name"], $destination)) {
            throw new RuntimeException("Could not save the uploaded photo");
        }

        if (!empty($user["profile_photo"]) && $user["profile_photo"] !== ("upload/profile/" . $filename)) {
            $oldFile = dirname(__DIR__) . "/" . ltrim($user["profile_photo"], "/");
            if (file_exists($oldFile) && is_file($oldFile)) {
                @unlink($oldFile);
            }
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

    // 6. Update user_settings table if language provided
    if (!empty($language)) {
        $updSettings = $pdo->prepare("
            INSERT INTO user_settings (user_id, preferred_language)
            VALUES (:uid, :lang)
            ON DUPLICATE KEY UPDATE preferred_language = VALUES(preferred_language)
        ");
        $updSettings->execute(["uid" => $uid, "lang" => $language]);
    }

    // 7. Fetch updated profile
    $fetch = $pdo->prepare($sql . " WHERE u.id = :id");
    $fetch->execute(["id" => $uid]);
    $savedUser = $fetch->fetch(PDO::FETCH_ASSOC);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $photoUrl = !empty($savedUser["profile_photo"])
        ? $protocol . $host . "/Synapse_backend/" . ltrim($savedUser["profile_photo"], "/")
        : null;

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Profile updated successfully.",
        "user" => [
            "id" => (int)$savedUser["id"],
            "name" => $savedUser["name"],
            "email" => $savedUser["email"],
            "phone" => $savedUser["phone"],
            "bio" => $savedUser["bio"],
            "course" => $savedUser["course"],
            "preferred_language" => $savedUser["preferred_language"] ?? "English",
            "profile_photo" => $savedUser["profile_photo"],
            "photo_url" => $photoUrl
        ]
    ]);
    exit;

} catch (Throwable $e) {
    error_log("Profile Update Error: " . $e->getMessage());

    $isInvalidInput = $e instanceof InvalidArgumentException;
    http_response_code($isInvalidInput ? 400 : 500);
    echo json_encode([
        "status" => "error",
        "message" => $isInvalidInput ? $e->getMessage() : ("Unable to update profile: " . $e->getMessage())
    ]);
    exit;
}
