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
$userId = $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? null;
$email = trim($input['email'] ?? $_POST['email'] ?? $_GET['email'] ?? '');
$name = trim($input['name'] ?? $_POST['name'] ?? '');

if (empty($name)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Profile name cannot be empty.'
    ]);
    exit();
}

try {
    $updated = false;
    if (!empty($userId)) {
        $stmt = $pdo->prepare("UPDATE users SET name = :name WHERE id = :uid");
        $stmt->execute(['name' => $name, 'uid' => $userId]);
        $updated = true;
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare("UPDATE users SET name = :name WHERE email = :email");
        $stmt->execute(['name' => $name, 'email' => $email]);
        $updated = true;
    } else {
        // Fallback to primary active user if no specific ID passed
        $stmt = $pdo->prepare("UPDATE users SET name = :name ORDER BY id ASC LIMIT 1");
        $stmt->execute(['name' => $name]);
        $updated = true;
    }

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => "Profile name has been updated to $name",
        'name' => $name,
        'user' => [
            'id' => $userId ? (int)$userId : 1,
            'name' => $name,
            'email' => $email
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
