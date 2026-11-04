<?php
// User Registration / Sign Up Endpoint - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Parse input (supports JSON payload and POST form data)
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$name = trim($input['name'] ?? $_POST['name'] ?? '');
$email = strtolower(trim($input['email'] ?? $_POST['email'] ?? ''));
$password = $input['password'] ?? $_POST['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? $_POST['confirm_password'] ?? $password;
$userInterests = $input['selected_interests'] ?? $input['interests'] ?? $_POST['selected_interests'] ?? $_POST['interests'] ?? null;

if (empty($name)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Please enter your full name.'
    ]);
    exit();
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ]);
    exit();
}

if (empty($password) || strlen($password) < 6) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Password must be at least 6 characters long.'
    ]);
    exit();
}

if ($password !== $confirmPassword) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Password and Confirm Password do not match.'
    ]);
    exit();
}

try {
    // 1. Check if email is already registered in users table
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $checkStmt->execute(['email' => $email]);

    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'An account with this email address already exists. Please Sign In.'
        ]);
        exit();
    }

    // 2. Hash password securely
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    // 3. Insert new user into users table (Core Table 1)
    $insertStmt = $pdo->prepare("
        INSERT INTO users (name, email, password_hash) 
        VALUES (:name, :email, :password_hash)
    ");
    $insertStmt->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => $hashedPassword
    ]);

    $userId = (int)$pdo->lastInsertId();

    // 4. Format and store user interests in user_profiles (Core Table 2)
    $interestsArray = ['Computer Science', 'Artificial Intelligence', 'Mathematics'];
    if (!empty($userInterests)) {
        if (is_array($userInterests)) {
            $interestsArray = $userInterests;
        } elseif (is_string($userInterests)) {
            $decoded = json_decode($userInterests, true);
            $interestsArray = is_array($decoded) ? $decoded : array_map('trim', explode(',', $userInterests));
        }
    }
    $interestsJson = json_encode(array_values(array_filter($interestsArray)));

    $profileStmt = $pdo->prepare("
        INSERT INTO user_profiles (user_id, selected_interests, avatar_url) 
        VALUES (:uid, :interests, NULL)
    ");
    $profileStmt->execute([
        'uid' => $userId,
        'interests' => $interestsJson
    ]);

    // 5. Generate session token and record in user_sessions (Core Table 3)
    $authToken = bin2hex(random_bytes(32));
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $sessionStmt = $pdo->prepare("
        INSERT INTO user_sessions (user_id, auth_token, ip_address, last_login_at) 
        VALUES (:uid, :token, :ip, CURRENT_TIMESTAMP)
    ");
    $sessionStmt->execute([
        'uid' => $userId,
        'token' => $authToken,
        'ip' => $ipAddress
    ]);

    // 6. Return success response with token & user profile
    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Account created successfully!',
        'token' => $authToken,
        'user' => [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'interests' => $interestsArray,
            'course' => 'B.Tech Computer Science · Year 3',
            'preferred_language' => 'English',
            'study_goal' => '25 min / day',
            'profile_photo' => null,
            'photo_url' => null,
            'avatar_url' => null
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Server error creating account: ' . $e->getMessage()
    ]);
}
