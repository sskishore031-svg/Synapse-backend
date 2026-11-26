<?php
// Dedicated Avatar Upload Endpoint - Synapse AI Backend
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// 1. Ensure profile_image column exists on users table
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL");
} catch (Exception $e) {}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "POST method required."]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $_POST['user_id'] ?? $_POST['id'] ?? $input['user_id'] ?? $input['id'] ?? ($_SERVER['HTTP_X_USER_ID'] ?? null);
$email = trim($_POST['email'] ?? $input['email'] ?? ($_SERVER['HTTP_X_USER_EMAIL'] ?? ''));

if (empty($userId) && empty($email)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "User ID or Email is required."]);
    exit();
}

try {
    // 2. Fetch user
    if (!empty($userId)) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(["id" => $userId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(["email" => $email]);
    }
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found."]);
        exit();
    }
    $uid = (int)$user['id'];

    // 3. Ensure target directory exists: C:\xampp\htdocs\Synapse_backend\uploads\avatars\
    $uploadDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $relativeImagePath = null;

    // Check multipart file upload
    $fileObj = $_FILES['avatar'] ?? $_FILES['profile_photo'] ?? $_FILES['photo'] ?? $_FILES['file'] ?? $_FILES['image'] ?? null;
    if ($fileObj && isset($fileObj['error']) && $fileObj['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($fileObj['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $ext = 'jpg';
        }
        $filename = "user_" . $uid . "_" . time() . "." . $ext;
        $destPath = $uploadDir . $filename;
        if (move_uploaded_file($fileObj['tmp_name'], $destPath)) {
            $relativeImagePath = "uploads/avatars/" . $filename;
        }
    }

    // Check base64 upload
    if (empty($relativeImagePath)) {
        $base64Data = $_POST['avatar_base64'] ?? $_POST['profile_image_base64'] ?? $_POST['avatar'] ?? $_POST['profile_photo'] ?? ($input['avatar_base64'] ?? $input['profile_image_base64'] ?? ($input['avatar'] ?? ($input['profile_photo'] ?? null)));
        if (!empty($base64Data) && is_string($base64Data)) {
            if (str_contains($base64Data, 'base64,')) {
                $base64Data = explode('base64,', $base64Data)[1];
            }
            $decoded = base64_decode($base64Data);
            if ($decoded !== false && strlen($decoded) > 0) {
                $filename = "user_" . $uid . "_" . time() . ".jpg";
                $destPath = $uploadDir . $filename;
                if (file_put_contents($destPath, $decoded) !== false) {
                    $relativeImagePath = "uploads/avatars/" . $filename;
                }
            }
        }
    }

    if (empty($relativeImagePath)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "No valid avatar image received."]);
        exit();
    }

    // 4. Update MySQL users.profile_image with ONLY relative path
    $updUser = $pdo->prepare("UPDATE users SET profile_image = :img WHERE id = :id");
    $updUser->execute(["img" => $relativeImagePath, "id" => $uid]);

    // Also update user_profiles.avatar_url
    try {
        $updProfile = $pdo->prepare("
            INSERT INTO user_profiles (user_id, avatar_url)
            VALUES (:uid, :avatar)
            ON DUPLICATE KEY UPDATE avatar_url = :avatar_upd
        ");
        $updProfile->execute(["uid" => $uid, "avatar" => $relativeImagePath, "avatar_upd" => $relativeImagePath]);
    } catch (Exception $e) {}

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fullAvatarUrl = $protocol . $host . "/Synapse_backend/" . ltrim($relativeImagePath, '/');

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "success" => true,
        "message" => "Avatar updated successfully.",
        "profile_image" => $relativeImagePath,
        "avatar_url" => $relativeImagePath,
        "full_url" => $fullAvatarUrl,
        "user" => [
            "id" => $uid,
            "name" => $user["name"],
            "email" => $user["email"],
            "profile_image" => $relativeImagePath,
            "avatar_url" => $relativeImagePath,
            "photo_url" => $fullAvatarUrl
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Avatar upload failed: " . $e->getMessage()]);
}
