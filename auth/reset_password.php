<?php
// Reset Password Endpoint - Synapse AI Backend
// Changes password directly when a registered Gmail address is provided.
// No tokens, No OTP, No email links. Purely Gmail / User ID based.
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Accept JSON payload or POST form-data
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$email = trim($input['email'] ?? $_POST['email'] ?? '');
$userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? null;
$newPassword = $input['new_password'] ?? $_POST['new_password'] ?? $input['password'] ?? $_POST['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? $_POST['confirm_password'] ?? $newPassword;

// 1. Validate Email or User ID input
if (empty($email) && empty($userId)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Please provide a valid registered Gmail/email address or user_id.'
    ]);
    exit();
}

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Please provide a valid email format.'
    ]);
    exit();
}

try {
    // 2. Check if the Gmail/User exists in the database
    if (!empty($email)) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
    }
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'No account found with this Gmail/User ID. Please check your email or Sign Up.'
        ]);
        exit();
    }

    $targetEmail = $user['email'];

    // 3. If password is not provided yet, confirm Gmail is found
    if (empty($newPassword)) {
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Gmail address found! Enter new password to change.',
            'user_id' => (int)$user['id'],
            'email' => $targetEmail,
            'name' => $user['name']
        ]);
        exit();
    }

    // 4. Validate password length and match
    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'New password must be at least 6 characters long.'
        ]);
        exit();
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'New Password and Confirm Password do not match.'
        ]);
        exit();
    }

    // 5. Hash new password securely with Bcrypt and update database directly (NO TOKENS)
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

    $updateStmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
    $updateStmt->execute([
        'password' => $hashedPassword,
        'id' => $user['id']
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Password changed successfully for this Gmail account. Please log in with your new password.',
        'user_id' => (int)$user['id'],
        'email' => $targetEmail
    ]);
    exit();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred while changing the password: ' . $e->getMessage()
    ]);
    exit();
}
