<?php
// Profile Setup / Onboarding Endpoint - Synapse AI Backend (Gmail / User ID Based, Modular Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');
$name = trim($input['name'] ?? $_POST['name'] ?? '');
$course = trim($input['course'] ?? $_POST['course'] ?? '');
$language = trim($input['preferred_language'] ?? $_POST['preferred_language'] ?? 'English');
$interests = $input['interests'] ?? $_POST['interests'] ?? [];

try {
    // 1. Locate user
    $sql = "
        SELECT 
            u.id, COALESCE(p.name, u.name) AS name, u.email,
            p.course, p.interests, p.profile_photo,
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
            'message' => 'User not found. Please provide registered email or user_id.'
        ]);
        exit();
    }

    $uid = (int)$user['id'];
    $updateName = !empty($name) ? $name : $user['name'];
    $updateCourse = !empty($course) ? $course : ($user['course'] ?? 'B.Tech Computer Science · Year 3');
    $updateLanguage = !empty($language) ? $language : ($user['preferred_language'] ?? 'English');

    $interestsJson = is_array($interests) 
        ? json_encode(array_values($interests)) 
        : (is_string($interests) ? $interests : $user['interests']);

    // 2. Update users table (name)
    $updUser = $pdo->prepare("UPDATE users SET name = :name WHERE id = :id");
    $updUser->execute(['name' => $updateName, 'id' => $uid]);

    // 3. Upsert user_profiles (name, course, interests)
    $updProfile = $pdo->prepare("
        INSERT INTO user_profiles (user_id, name, course, interests)
        VALUES (:uid, :name, :course, :interests)
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            course = VALUES(course), 
            interests = VALUES(interests)
    ");
    $updProfile->execute([
        'uid' => $uid,
        'name' => $updateName,
        'course' => $updateCourse,
        'interests' => $interestsJson
    ]);

    // 4. Upsert user_settings (preferred_language)
    $updSettings = $pdo->prepare("
        INSERT INTO user_settings (user_id, preferred_language)
        VALUES (:uid, :lang)
        ON DUPLICATE KEY UPDATE preferred_language = VALUES(preferred_language)
    ");
    $updSettings->execute(['uid' => $uid, 'lang' => $updateLanguage]);

    // 5. Fetch updated profile
    $fetchStmt = $pdo->prepare($sql . " WHERE u.id = :id");
    $fetchStmt->execute(['id' => $uid]);
    $savedUser = $fetchStmt->fetch();

    $parsedInterests = [];
    if (!empty($savedUser['interests'])) {
        $decoded = json_decode($savedUser['interests'], true);
        $parsedInterests = is_array($decoded) ? $decoded : array_map('trim', explode(',', $savedUser['interests']));
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $photoUrl = !empty($savedUser['profile_photo']) 
        ? $protocol . $host . "/Synapse_backend/" . ltrim($savedUser['profile_photo'], '/') 
        : null;

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Profile setup completed successfully.',
        'user' => [
            'id' => (int)$savedUser['id'],
            'name' => $savedUser['name'],
            'email' => $savedUser['email'],
            'course' => $savedUser['course'],
            'preferred_language' => $savedUser['preferred_language'] ?? 'English',
            'interests' => $parsedInterests,
            'study_goal' => $savedUser['study_goal'] ?? '25 min / day',
            'notifications_enabled' => (bool)($savedUser['notifications_enabled'] ?? 1),
            'profile_photo' => $savedUser['profile_photo'],
            'photo_url' => $photoUrl
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error saving profile setup: ' . $e->getMessage()
    ]);
}
