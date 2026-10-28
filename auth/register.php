<?php
// User Registration / Sign Up Endpoint - Synapse AI Backend (Gmail based, Modular Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Parse input (supports JSON payload and POST form data)
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$name = trim($input['name'] ?? $_POST['name'] ?? '');
$email = trim($input['email'] ?? $_POST['email'] ?? '');
$phone = trim($input['phone'] ?? $_POST['phone'] ?? '');
$password = $input['password'] ?? $_POST['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? $_POST['confirm_password'] ?? $password;

if (empty($name)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Please enter your full name.'
    ]);
    exit();
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Please enter a valid email address.'
    ]);
    exit();
}

if (empty($password) || strlen($password) < 6) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Password must be at least 6 characters long.'
    ]);
    exit();
}

if ($password !== $confirmPassword) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Password and Confirm Password do not match.'
    ]);
    exit();
}

try {
    // 1. Check if email is already registered
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $checkStmt->execute(['email' => $email]);

    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'An account with this email address already exists. Please Sign In.'
        ]);
        exit();
    }

    // 2. Hash password securely using password_hash()
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    // 3. Insert new user into users table
    $insertStmt = $pdo->prepare("
        INSERT INTO users (name, email, phone, password) 
        VALUES (:name, :email, :phone, :password)
    ");
    $insertStmt->execute([
        'name' => $name,
        'email' => $email,
        'phone' => !empty($phone) ? $phone : null,
        'password' => $hashedPassword
    ]);

    $userId = (int)$pdo->lastInsertId();

    // 4. Create separate user_profiles record with name
    $profileStmt = $pdo->prepare("
        INSERT INTO user_profiles (user_id, name, course) 
        VALUES (:uid, :name, :course)
    ");
    $profileStmt->execute([
        'uid' => $userId,
        'name' => $name,
        'course' => 'B.Tech Computer Science · Year 3'
    ]);

    // 5. Create separate user_settings record
    $settingsStmt = $pdo->prepare("
        INSERT INTO user_settings (user_id, preferred_language, study_goal, notifications_enabled) 
        VALUES (:uid, :lang, :goal, 1)
    ");
    $settingsStmt->execute([
        'uid' => $userId,
        'lang' => 'English',
        'goal' => '25 min / day'
    ]);

    // 6. Seed default achievements & notifications for new user
    $defaultBadges = [
        ['title' => 'Quick Learner', 'desc' => 'Completed your first 5 AI lessons', 'icon' => '⚡'],
        ['title' => '7-Day Streak', 'desc' => 'Studied consecutively for 7 days', 'icon' => '🔥'],
        ['title' => 'Quiz Master', 'desc' => 'Scored 100% on 3 assessments', 'icon' => '🎯'],
        ['title' => 'Night Owl', 'desc' => 'Completed study sessions past 10 PM', 'icon' => '🦉'],
        ['title' => 'Problem Solver', 'desc' => 'Solved 20 coding challenges', 'icon' => '🧩'],
        ['title' => 'AI Explorer', 'desc' => 'Generated 50 AI summaries and notes', 'icon' => '🤖'],
        ['title' => 'Consistent Scholar', 'desc' => 'Reached 30 hours of total study time', 'icon' => '📚'],
        ['title' => 'Early Bird', 'desc' => 'Completed morning study sessions', 'icon' => '🌅']
    ];
    $insBadge = $pdo->prepare("INSERT INTO user_achievements (user_id, title, description, badge_icon, is_unlocked) VALUES (:uid, :t, :d, :icon, 1)");
    foreach ($defaultBadges as $b) {
        $insBadge->execute(['uid' => $userId, 't' => $b['title'], 'd' => $b['desc'], 'icon' => $b['icon']]);
    }

    $insNotif = $pdo->prepare("INSERT INTO user_notifications (user_id, title, message, type) VALUES (:uid, :t, :m, :type)");
    $insNotif->execute(['uid' => $userId, 't' => 'Welcome to Synapse AI! 🚀', 'm' => 'Your personalized AI study companion is ready.', 'type' => 'info']);

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Account created successfully!',
        'user' => [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'phone' => !empty($phone) ? $phone : null,
            'course' => 'B.Tech Computer Science · Year 3',
            'preferred_language' => 'English',
            'study_goal' => '25 min / day',
            'notifications_enabled' => true
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
