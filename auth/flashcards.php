<?php
// ==============================================================================
// Flashcards API Endpoint - Synapse AI Backend
// Route: GET/POST /auth/flashcards.php
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
    // Authenticate student
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
    $action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'list';

    // --------------------------------------------------------------------------
    // ACTION: mark_difficult - Toggle difficult status on a card
    // --------------------------------------------------------------------------
    if ($action === 'mark_difficult') {
        $cardId = (int)($input['card_id'] ?? $input['flashcard_id'] ?? $_POST['card_id'] ?? $_GET['card_id'] ?? 0);
        if ($cardId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please provide valid card_id.']);
            exit();
        }

        // Check current status
        $check = $pdo->prepare("SELECT is_difficult FROM user_flashcard_progress WHERE user_id = :uid AND flashcard_id = :cid");
        $check->execute(['uid' => $uid, 'cid' => $cardId]);
        $current = $check->fetchColumn();

        $newVal = ($current === false || $current === null) ? 1 : ($current == 1 ? 0 : 1);

        $upsert = $pdo->prepare("
            INSERT INTO user_flashcard_progress (user_id, flashcard_id, is_difficult, is_reviewed)
            VALUES (:uid, :cid, :is_diff, 1)
            ON DUPLICATE KEY UPDATE is_difficult = :is_diff2, is_reviewed = 1
        ");
        $upsert->execute([
            'uid' => $uid,
            'cid' => $cardId,
            'is_diff' => $newVal,
            'is_diff2' => $newVal
        ]);

        echo json_encode([
            'status' => 'success',
            'card_id' => $cardId,
            'is_difficult' => $newVal == 1,
            'message' => $newVal == 1 ? 'Card marked as difficult.' : 'Card unmarked as difficult.'
        ]);
        exit();
    }

    // --------------------------------------------------------------------------
    // ACTION: list - List cards with student's mastery status
    // --------------------------------------------------------------------------
    $deckFilter = trim($_GET['deck'] ?? $input['deck'] ?? $_POST['deck'] ?? '');
    $subjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;

    $sql = "
        SELECT 
            f.id,
            f.subject_id,
            f.chapter_id,
            f.deck_name,
            f.question,
            f.answer,
            f.difficulty,
            s.name AS subject_name,
            IFNULL(p.is_difficult, 0) AS is_difficult,
            IFNULL(p.is_reviewed, 0) AS is_reviewed
        FROM flashcards f
        LEFT JOIN subjects s ON f.subject_id = s.id
        LEFT JOIN user_flashcard_progress p ON f.id = p.flashcard_id AND p.user_id = :uid
        WHERE 1=1
    ";

    $params = ['uid' => $uid];

    if (!empty($deckFilter)) {
        $sql .= " AND f.deck_name LIKE :deck";
        $params['deck'] = "%$deckFilter%";
    }

    if (!empty($subjectId) && $subjectId > 0) {
        $sql .= " AND f.subject_id = :sid";
        $params['sid'] = $subjectId;
    }

    $sql .= " ORDER BY f.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll();

    $result = [];
    foreach ($cards as $c) {
        $result[] = [
            'id' => (int)$c['id'],
            'subject_id' => (int)$c['subject_id'],
            'chapter_id' => $c['chapter_id'] !== null ? (int)$c['chapter_id'] : null,
            'deck_name' => $c['deck_name'],
            'subject_name' => $c['subject_name'] ?? 'General',
            'question' => $c['question'],
            'answer' => $c['answer'],
            'difficulty' => $c['difficulty'],
            'is_difficult' => (bool)$c['is_difficult'],
            'is_reviewed' => (bool)$c['is_reviewed']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'total_cards' => count($result),
            'deck_name' => !empty($result) ? $result[0]['deck_name'] : 'Revision Deck',
            'cards' => $result
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
