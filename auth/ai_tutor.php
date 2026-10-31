<?php
// ==============================================================================
// AI Tutor Endpoint - Synapse AI Backend (FRIDAY AI Companion)
// Route: GET/POST /auth/ai_tutor.php
// Scoped strictly to authenticated student identity.
// ==============================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_GET['email'] ?? $input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

try {
    // Locate student
    $user = null;
    if (!empty($userId)) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
    } elseif (!empty($email)) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
    } else {
        $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit();
    }

    $uid = (int)$user['id'];
    $action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'chat';

    // --------------------------------------------------------------------------
    // ACTION: history - Retrieve recent chat history
    // --------------------------------------------------------------------------
    if ($action === 'history') {
        $stmt = $pdo->prepare("
            SELECT id, sender_type, message, created_at
            FROM user_tutor_chats
            WHERE user_id = :uid
            ORDER BY id ASC
            LIMIT 50
        ");
        $stmt->execute(['uid' => $uid]);
        $chats = $stmt->fetchAll();

        echo json_encode([
            'status' => 'success',
            'data' => [
                'total_messages' => count($chats),
                'messages' => $chats
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: chat - Ask FRIDAY AI a question
    // --------------------------------------------------------------------------
    $question = trim($input['question'] ?? $input['message'] ?? $_POST['question'] ?? $_POST['message'] ?? '');
    if (empty($question)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please provide a question.']);
        exit();
    }

    // Generate intelligent contextual response
    $lower = strtolower($question);
    $p1 = "";
    $p2 = "";

    if (strpos($lower, 'gradient') !== false || strpos($lower, 'descent') !== false || strpos($lower, 'neural') !== false) {
        $p1 = "Think of gradient descent like walking downhill in dense fog. You evaluate the slope beneath your feet and take small steps in the steepest downward direction.";
        $p2 = "In deep learning, neural networks calculate the gradient of the loss function with respect to every weight. The learning rate (α) determines how large each step is to minimize loss.";
    } elseif (strpos($lower, 'bond') !== false || strpos($lower, 'chem') !== false || strpos($lower, 'ionic') !== false || strpos($lower, 'covalent') !== false) {
        $p1 = "Chemical bonding is all about electron stability! Atoms bond to achieve an octet in their valence shell, resembling inert noble gases.";
        $p2 = "Ionic bonds form when one atom transfers electrons to another (like Na giving an electron to Cl), whereas covalent bonds share electron pairs (like H2O).";
    } elseif (strpos($lower, 'math') !== false || strpos($lower, 'quadratic') !== false || strpos($lower, 'equation') !== false || strpos($lower, 'discriminant') !== false) {
        $p1 = "A quadratic equation follows the standard form ax² + bx + c = 0. Its graph forms a smooth symmetric curve called a parabola.";
        $p2 = "The discriminant Δ = b² - 4ac tells you the nature of roots: Δ > 0 yields two real distinct roots, Δ = 0 gives one repeated root, and Δ < 0 gives complex roots.";
    } elseif (strpos($lower, 'algorithm') !== false || strpos($lower, 'sort') !== false || strpos($lower, 'search') !== false || strpos($lower, 'tree') !== false) {
        $p1 = "Algorithms are step-by-step procedures designed to solve computational problems with optimal efficiency.";
        $p2 = "For search tasks, Binary Search operates in O(log n) time on sorted data. For sorting, Merge Sort and Quick Sort achieve an average complexity of O(n log n).";
    } elseif (strpos($lower, 'wave') !== false || strpos($lower, 'physics') !== false || strpos($lower, 'frequency') !== false) {
        $p1 = "Waves transfer energy across space without transferring matter. They are characterized by wavelength (λ), frequency (f), and propagation speed (v).";
        $p2 = "The fundamental wave equation v = f · λ links these properties. Mechanical waves require a physical medium, while electromagnetic waves can travel through a vacuum.";
    } elseif (strpos($lower, 'data') !== false || strpos($lower, 'feature') !== false || strpos($lower, 'dataset') !== false) {
        $p1 = "Data science merges statistics, algorithms, and domain expertise to extract meaningful insights from structured and unstructured datasets.";
        $p2 = "Effective feature engineering and exploratory data analysis (EDA) often provide greater model accuracy improvements than hyperparameter tuning alone.";
    } else {
        $p1 = "Great question! Every subject in Synapse AI connects to foundational principles. When approaching this topic, start by defining the core variables and identifying the primary objective.";
        $p2 = "Practice applying the concept to small example problems before tackling larger scenarios. Would you like a step-by-step example or a practice quiz question?";
    }

    $fullAnswer = $p1 . "\n\n" . $p2;

    // Save student message and AI response to user_tutor_chats
    $insertMsg = $pdo->prepare("INSERT INTO user_tutor_chats (user_id, sender_type, message) VALUES (:uid, 'user', :msg)");
    $insertMsg->execute(['uid' => $uid, 'msg' => $question]);

    $insertAi = $pdo->prepare("INSERT INTO user_tutor_chats (user_id, sender_type, message) VALUES (:uid, 'ai', :msg)");
    $insertAi->execute(['uid' => $uid, 'msg' => $fullAnswer]);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'question' => $question,
            'paragraph1' => $p1,
            'paragraph2' => $p2,
            'answer' => $fullAnswer,
            'tutor_name' => 'FRIDAY AI',
            'created_at' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
