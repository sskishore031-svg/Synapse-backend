<?php
// Update Profile Endpoint - Synapse AI Backend
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $_POST['user_id'] ?? $_POST['id'] ?? $input['user_id'] ?? $input['id'] ?? ($_GET['user_id'] ?? ($_GET['id'] ?? null));
$email = trim($_POST['email'] ?? $input['email'] ?? ($_GET['email'] ?? ($_SERVER['HTTP_X_USER_EMAIL'] ?? '')));
$name = trim($_POST['name'] ?? $input['name'] ?? '');
$phone = trim($_POST['phone'] ?? $input['phone'] ?? ($_POST['phone_number'] ?? ($input['phone_number'] ?? '')));
$bio = trim($_POST['bio'] ?? $input['bio'] ?? '');
$course = trim($_POST['course'] ?? $input['course'] ?? '');
$studyGoal = trim($_POST['study_goal'] ?? $input['study_goal'] ?? '');

try {
    // 1. Identify target user
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

    $targetId = (int)$user['id'];

    // 2. Perform updates
    $updates = [];
    $params = ['id' => $targetId];

    if (!empty($name)) {
        $updates[] = "name = :name";
        $params['name'] = $name;
    }

    // Ensure phone/bio/course/study_goal columns exist
    if (!empty($phone)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL");
        } catch (Exception $e) {}
        $updates[] = "phone = :phone";
        $params['phone'] = $phone;
    }

    if (!empty($bio)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN bio TEXT NULL");
        } catch (Exception $e) {}
        $updates[] = "bio = :bio";
        $params['bio'] = $bio;
    }

    if (!empty($course)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN course VARCHAR(100) NULL");
        } catch (Exception $e) {}
        $updates[] = "course = :course";
        $params['course'] = $course;
    }

    if (!empty($studyGoal)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN study_goal VARCHAR(100) NULL");
        } catch (Exception $e) {}
        $updates[] = "study_goal = :study_goal";
        $params['study_goal'] = $studyGoal;
    }

    if (!empty($updates)) {
        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = :id";
        $updStmt = $pdo->prepare($sql);
        $updStmt->execute($params);
    }

    // Re-fetch updated user
    $refetch = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $refetch->execute(['id' => $targetId]);
    $updatedUser = $refetch->fetch(PDO::FETCH_ASSOC);

    $rawPath = !empty($updatedUser['profile_image']) ? $updatedUser['profile_image'] : ($updatedUser['avatar_url'] ?? null);
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fullAvatarUrl = !empty($rawPath) ? ($protocol . $host . "/Synapse_backend/" . ltrim($rawPath, '/')) : null;

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Profile updated successfully.',
        'user' => [
            'id' => $targetId,
            'name' => $updatedUser['name'] ?? 'Student',
            'email' => $updatedUser['email'] ?? '',
            'phone' => $updatedUser['phone'] ?? '',
            'bio' => $updatedUser['bio'] ?? '',
            'course' => $updatedUser['course'] ?? '',
            'study_goal' => $updatedUser['study_goal'] ?? '25 min / day',
            'profile_image' => $rawPath ?: $fullAvatarUrl,
            'avatar_url' => $fullAvatarUrl,
            'photo_url' => $fullAvatarUrl
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Error updating profile: ' . $e->getMessage()
    ]);
}
