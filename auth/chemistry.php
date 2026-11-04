<?php
// Chemistry Endpoint - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'lesson';
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_GET['email'] ?? $input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
if (empty($email) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer\s+)?(\S+@\S+)/i', $authHeader, $matches)) {
        $email = $matches[1];
    }
}

try {
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
        $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id DESC LIMIT 1");
        $user = $stmt->fetch();
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit();
    }

    $uid = (int)$user['id'];

    if ($action === 'complete') {
        $chapterId = (int)($_GET['chapter_id'] ?? $input['chapter_id'] ?? 28);
        $ins = $pdo->prepare("
            INSERT INTO study_notes (user_id, subject_id, chapter_title, note_content, created_at)
            VALUES (:uid, 'chemistry', 'Chemical Bonding', 'Chemical bonding lesson completed successfully.', CURRENT_TIMESTAMP)
        ");
        $ins->execute(['uid' => $uid]);
    }

    if ($action === 'submit_quiz') {
        $score = (int)($input['score'] ?? $input['correct_answers'] ?? 8);
        $total = max(1, (int)($input['total_questions'] ?? 10));

        $stmt = $pdo->prepare("
            INSERT INTO quiz_records (user_id, subject_id, score, total_questions, completed_at)
            VALUES (:uid, 'chemistry', :score, :total, CURRENT_TIMESTAMP)
        ");
        $stmt->execute(['uid' => $uid, 'score' => $score, 'total' => $total]);
    }

    // 5 Chapters
    $chapters = [
        ['id' => '28', 'title' => 'Chemical Bonding', 'order' => 1, 'estimatedMinutes' => 15, 'isCompleted' => true, 'status' => 'ACTIVE', 'subtext' => 'Ionic, Covalent & Metallic Bonds'],
        ['id' => '24', 'title' => 'Acids and Bases', 'order' => 2, 'estimatedMinutes' => 18, 'isCompleted' => false, 'status' => 'AVAILABLE', 'subtext' => 'pH Scale & Titrations'],
        ['id' => '25', 'title' => 'Periodic Table Trends', 'order' => 3, 'estimatedMinutes' => 20, 'isCompleted' => false, 'status' => 'LOCKED', 'subtext' => 'Electronegativity & Ionization'],
        ['id' => '26', 'title' => 'Stoichiometry & Reactions', 'order' => 4, 'estimatedMinutes' => 22, 'isCompleted' => false, 'status' => 'LOCKED', 'subtext' => 'Moles & Limiting Reactants'],
        ['id' => '27', 'title' => 'Thermodynamics & Kinetics', 'order' => 5, 'estimatedMinutes' => 25, 'isCompleted' => false, 'status' => 'LOCKED', 'subtext' => 'Enthalpy & Reaction Rates']
    ];

    $questions = [
        [
            'id' => 1,
            'questionText' => 'Which type of bond involves sharing electron pairs between atoms?',
            'optionA' => 'Ionic Bond',
            'optionB' => 'Covalent Bond',
            'optionC' => 'Metallic Bond',
            'optionD' => 'Hydrogen Bond',
            'correctOption' => 'B',
            'explanation' => 'Covalent bonds form when nonmetal atoms share pairs of valence electrons.'
        ],
        [
            'id' => 2,
            'questionText' => 'What happens to electrons in an ionic bond?',
            'optionA' => 'They are shared equally',
            'optionB' => 'They are transferred from metal to nonmetal',
            'optionC' => 'They form a delocalized sea',
            'optionD' => 'They are annihilated',
            'correctOption' => 'B',
            'explanation' => 'Ionic bonding involves the complete transfer of valence electrons from one atom to another.'
        ]
    ];

    $data = [
        'student' => [
            'id' => $uid,
            'name' => $user['name'],
            'email' => $user['email']
        ],
        'subject' => [
            'id' => 6,
            'name' => 'Chemistry',
            'slug' => 'chemistry',
            'iconSymbol' => '🧪',
            'colorHex' => '#EF4444',
            'progressPercent' => 20,
            'progressSubtext' => '1 of 5 lessons completed',
            'totalChapters' => 5,
            'completedChapters' => 1
        ],
        'activeChapter' => [
            'id' => 28,
            'title' => 'Chemical Bonding',
            'part1' => [
                'partNumber' => 1,
                'subtitleTag' => 'Part 1: Foundations',
                'headline' => 'What is Chemical Bonding?',
                'conceptText' => 'Chemical bonding is the attractive force that holds atoms or ions together to create molecules.',
                'keyPoints' => [
                    'Bonds form to achieve lowest potential energy states.',
                    'Octet rule drives outer electron shell stability.'
                ],
                'exampleTitle' => 'Octet Rule Example',
                'exampleProblem' => 'Why does Na react violently with Cl?',
                'exampleSolution' => 'Na transfers 1 electron to Cl, completing both outer valence shells.'
            ],
            'part2' => [
                'partNumber' => 2,
                'subtitleTag' => 'Part 2: Comparison',
                'headline' => 'Ionic vs Covalent Bonding',
                'conceptText' => 'Ionic bonding involves electrostatic attraction between oppositely charged ions, whereas covalent bonding involves sharing electron pairs.',
                'keyPoints' => [
                    'Ionic bonds have high melting points and conduct electricity in solution.',
                    'Covalent bonds have lower melting points and poor electrical conductivity.'
                ],
                'exampleTitle' => 'Comparison Example',
                'exampleProblem' => 'Is H2O ionic or covalent?',
                'exampleSolution' => 'H2O shares electron pairs between hydrogen and oxygen, making it polar covalent.'
            ]
        ],
        'chapters' => $chapters,
        'quiz' => [
            'title' => 'Chemical Bonding Assessment',
            'questions' => $questions,
            'latestAttempt' => [
                'scorePercentage' => 85,
                'correctAnswers' => 2,
                'totalQuestions' => 2,
                'completedAt' => date('Y-m-d H:i:s')
            ]
        ]
    ];

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Chemistry operation successful.',
        'data' => $data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Chemistry error: ' . $e->getMessage()]);
}
