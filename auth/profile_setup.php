<?php
// Profile Setup / Onboarding Endpoint - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');
$name = trim($input['name'] ?? $_POST['name'] ?? '');
$interests = $input['selected_interests'] ?? $input['interests'] ?? $_POST['selected_interests'] ?? $_POST['interests'] ?? [];

try {
    $sql = "
        SELECT u.id, u.name, u.email, u.created_at, p.selected_interests, p.avatar_url
        FROM users u
        LEFT JOIN user_profiles p ON u.id = p.user_id
    ";

    $user = null;
    if (!empty($userId)) {
        $stmt = $pdo->prepare($sql . " WHERE u.id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare($sql . " WHERE u.email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
    } else {
        $stmt = $pdo->query($sql . " ORDER BY u.id DESC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit();
    }

    $uid = (int)$user['id'];
    $updateName = !empty($name) ? $name : $user['name'];

    $interestsArr = is_array($interests) ? $interests : (json_decode($interests, true) ?: array_map('trim', explode(',', $interests)));
    $interestsJson = json_encode(array_values(array_filter($interestsArr)));

    // Update users table (Core Table 1)
    if (!empty($name)) {
        $updUser = $pdo->prepare("UPDATE users SET name = :name WHERE id = :id");
        $updUser->execute(['name' => $updateName, 'id' => $uid]);
    }

    // Upsert user_profiles (Core Table 2)
    $updProfile = $pdo->prepare("
        INSERT INTO user_profiles (user_id, selected_interests)
        VALUES (:uid, :interests)
        ON DUPLICATE KEY UPDATE selected_interests = :interests_update
    ");
    $updProfile->execute([
        'uid' => $uid,
        'interests' => $interestsJson,
        'interests_update' => $interestsJson
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Profile setup completed successfully!',
        'user' => [
            'id' => $uid,
            'name' => $updateName,
            'email' => $user['email'],
            'interests' => $interestsArr,
            'selected_interests' => $interestsArr,
            'course' => 'B.Tech Computer Science · Year 3',
            'preferred_language' => 'English',
            'study_goal' => '25 min / day'
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Profile setup failed: ' . $e->getMessage()]);
}
