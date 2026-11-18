<?php
// Chapters & Learning Flow API - Synapse AI Backend (5 Core Tables)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dbPath = file_exists(__DIR__ . '/config/db.php') ? __DIR__ . '/config/db.php' : __DIR__ . '/../config/db.php';
require_once $dbPath;

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? 'list';
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

    if ($action === 'complete' || $action === 'mark_completed' || ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($input['chapter_id']) || isset($_POST['chapter_id'])))) {
        $chapterId = (int)($_GET['chapter_id'] ?? $input['chapter_id'] ?? $_POST['chapter_id'] ?? 1);
        $chapterTitle = trim($input['chapter_title'] ?? $_POST['chapter_title'] ?? "Chapter $chapterId");
        $subjectId = trim($input['subject_id'] ?? $_POST['subject_id'] ?? "general");

        // 1. Record completion in user_chapter_completions table
        try {
            $compStmt = $pdo->prepare("
                INSERT INTO user_chapter_completions (user_id, subject_id, chapter_id, completed_at)
                VALUES (:uid, :sid, :cid, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP
            ");
            $compStmt->execute([
                'uid' => $uid,
                'sid' => $subjectId,
                'cid' => $chapterId
            ]);
        } catch (Exception $e) {}

        // 2. Also record in study_notes for backward compatibility
        try {
            $ins = $pdo->prepare("
                INSERT INTO study_notes (user_id, subject_id, chapter_id, chapter_title, note_content, created_at)
                VALUES (:uid, :sid, :cid, :title, :content, CURRENT_TIMESTAMP)
            ");
            $ins->execute([
                'uid' => $uid,
                'sid' => $subjectId,
                'cid' => $chapterId,
                'title' => $chapterTitle,
                'content' => "Completed chapter: $chapterTitle."
            ]);
        } catch (Exception $e) {}

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => "Chapter '$chapterTitle' marked as completed!",
            'chapter_id' => $chapterId,
            'subject_id' => $subjectId,
            'is_completed' => true
        ]);
        exit();
    }

    // Dynamic chapters catalog
    $rawSubject = trim($_GET['subject_id'] ?? $_GET['subject_slug'] ?? $input['subject_id'] ?? $input['subject_slug'] ?? 'mathematics');
    $subjectSlug = strtolower($rawSubject);

    $subjectMap = [
        '1' => ['id' => 1, 'name' => 'Mathematics', 'slug' => 'mathematics', 'chapters' => [
            ['id' => 1, 'title' => 'Quadratic Equations & Roots', 'chapter_order' => 1, 'estimated_minutes' => 15],
            ['id' => 2, 'title' => 'Calculus & Derivatives', 'chapter_order' => 2, 'estimated_minutes' => 20],
            ['id' => 3, 'title' => 'Linear Algebra & Matrices', 'chapter_order' => 3, 'estimated_minutes' => 18],
            ['id' => 4, 'title' => 'Probability & Statistics', 'chapter_order' => 4, 'estimated_minutes' => 15]
        ]],
        '2' => ['id' => 2, 'name' => 'Computer Science', 'slug' => 'computer-science', 'chapters' => [
            ['id' => 201, 'title' => 'Data Structures & Algorithms', 'chapter_order' => 1, 'estimated_minutes' => 20],
            ['id' => 202, 'title' => 'Object-Oriented Programming', 'chapter_order' => 2, 'estimated_minutes' => 18],
            ['id' => 203, 'title' => 'Database Management Systems', 'chapter_order' => 3, 'estimated_minutes' => 22],
            ['id' => 204, 'title' => 'Operating Systems & Concurrency', 'chapter_order' => 4, 'estimated_minutes' => 25]
        ]],
        '3' => ['id' => 3, 'name' => 'Data Science', 'slug' => 'data-science', 'chapters' => [
            ['id' => 301, 'title' => 'Exploratory Data Analysis', 'chapter_order' => 1, 'estimated_minutes' => 18],
            ['id' => 302, 'title' => 'Data Visualization & Wrangling', 'chapter_order' => 2, 'estimated_minutes' => 15],
            ['id' => 303, 'title' => 'Statistical Inference & Modeling', 'chapter_order' => 3, 'estimated_minutes' => 20],
            ['id' => 304, 'title' => 'Feature Engineering & Pipelines', 'chapter_order' => 4, 'estimated_minutes' => 22]
        ]],
        '4' => ['id' => 4, 'name' => 'Artificial Intelligence', 'slug' => 'artificial-intelligence', 'chapters' => [
            ['id' => 401, 'title' => 'Machine Learning Foundations', 'chapter_order' => 1, 'estimated_minutes' => 20],
            ['id' => 402, 'title' => 'Neural Networks & Deep Learning', 'chapter_order' => 2, 'estimated_minutes' => 25],
            ['id' => 403, 'title' => 'Natural Language Processing', 'chapter_order' => 3, 'estimated_minutes' => 22],
            ['id' => 404, 'title' => 'Computer Vision & CNNs', 'chapter_order' => 4, 'estimated_minutes' => 24]
        ]],
        '5' => ['id' => 5, 'name' => 'Physics', 'slug' => 'physics', 'chapters' => [
            ['id' => 501, 'title' => "Newton's Laws of Motion", 'chapter_order' => 1, 'estimated_minutes' => 18],
            ['id' => 502, 'title' => 'Waves & Oscillations', 'chapter_order' => 2, 'estimated_minutes' => 20],
            ['id' => 503, 'title' => 'Electromagnetism & Circuits', 'chapter_order' => 3, 'estimated_minutes' => 22],
            ['id' => 504, 'title' => 'Thermodynamics & Kinetic Theory', 'chapter_order' => 4, 'estimated_minutes' => 20]
        ]],
        '6' => ['id' => 6, 'name' => 'Chemistry', 'slug' => 'chemistry', 'chapters' => [
            ['id' => 601, 'title' => 'Chemical Bonding & Molecular Structure', 'chapter_order' => 1, 'estimated_minutes' => 18],
            ['id' => 602, 'title' => 'Organic Reaction Mechanisms', 'chapter_order' => 2, 'estimated_minutes' => 22],
            ['id' => 603, 'title' => 'Thermodynamics & Equilibrium', 'chapter_order' => 3, 'estimated_minutes' => 20],
            ['id' => 604, 'title' => 'Electrochemistry & Kinetics', 'chapter_order' => 4, 'estimated_minutes' => 20]
        ]],
        '101' => ['id' => 101, 'name' => 'Biology', 'slug' => 'biology', 'chapters' => [
            ['id' => 10101, 'title' => 'Cell Structure & Organelles', 'chapter_order' => 1, 'estimated_minutes' => 18],
            ['id' => 10102, 'title' => 'Cell Division & Mitosis', 'chapter_order' => 2, 'estimated_minutes' => 16],
            ['id' => 10103, 'title' => 'DNA Replication & Genetics', 'chapter_order' => 3, 'estimated_minutes' => 22],
            ['id' => 10104, 'title' => 'Photosynthesis & Energy', 'chapter_order' => 4, 'estimated_minutes' => 20]
        ]],
        '102' => ['id' => 102, 'name' => 'World History', 'slug' => 'history', 'chapters' => [
            ['id' => 10201, 'title' => 'Ancient Civilizations & River Valleys', 'chapter_order' => 1, 'estimated_minutes' => 15],
            ['id' => 10202, 'title' => 'The Classical Era & Empires', 'chapter_order' => 2, 'estimated_minutes' => 18],
            ['id' => 10203, 'title' => 'The Industrial Revolution', 'chapter_order' => 3, 'estimated_minutes' => 20],
            ['id' => 10204, 'title' => 'Modern Global Conflicts & Treaties', 'chapter_order' => 4, 'estimated_minutes' => 22]
        ]],
        '103' => ['id' => 103, 'name' => 'Economics & Finance', 'slug' => 'economics', 'chapters' => [
            ['id' => 10301, 'title' => 'Supply, Demand & Market Equilibrium', 'chapter_order' => 1, 'estimated_minutes' => 18],
            ['id' => 10302, 'title' => 'Elasticity & Consumer Choice', 'chapter_order' => 2, 'estimated_minutes' => 16],
            ['id' => 10303, 'title' => 'Macroeconomics & GDP', 'chapter_order' => 3, 'estimated_minutes' => 20],
            ['id' => 10304, 'title' => 'Monetary Policy & Inflation', 'chapter_order' => 4, 'estimated_minutes' => 22]
        ]],
        '104' => ['id' => 104, 'name' => 'Environmental Science', 'slug' => 'environmental-science', 'chapters' => [
            ['id' => 10401, 'title' => 'Ecosystem Dynamics & Energy Flow', 'chapter_order' => 1, 'estimated_minutes' => 16],
            ['id' => 10402, 'title' => 'Biodiversity & Conservation', 'chapter_order' => 2, 'estimated_minutes' => 18],
            ['id' => 10403, 'title' => 'Climate Systems & Greenhouse Effect', 'chapter_order' => 3, 'estimated_minutes' => 20],
            ['id' => 10404, 'title' => 'Renewable Energy & Sustainability', 'chapter_order' => 4, 'estimated_minutes' => 20]
        ]],
        '105' => ['id' => 105, 'name' => 'Computer Networks', 'slug' => 'computer-networks', 'chapters' => [
            ['id' => 10501, 'title' => 'OSI & TCP/IP Model Architectures', 'chapter_order' => 1, 'estimated_minutes' => 20],
            ['id' => 10502, 'title' => 'IP Addressing & Subnetting', 'chapter_order' => 2, 'estimated_minutes' => 22],
            ['id' => 10503, 'title' => 'Routing Protocols & Algorithms', 'chapter_order' => 3, 'estimated_minutes' => 24],
            ['id' => 10504, 'title' => 'Application Protocols (HTTP, DNS)', 'chapter_order' => 4, 'estimated_minutes' => 18]
        ]],
        '106' => ['id' => 106, 'name' => 'Cyber Security', 'slug' => 'cyber-security', 'chapters' => [
            ['id' => 10601, 'title' => 'Cryptography & Encryption Fundamentals', 'chapter_order' => 1, 'estimated_minutes' => 20],
            ['id' => 10602, 'title' => 'Network Vulnerabilities & Firewalls', 'chapter_order' => 2, 'estimated_minutes' => 22],
            ['id' => 10603, 'title' => 'Authentication & Access Control', 'chapter_order' => 3, 'estimated_minutes' => 18],
            ['id' => 10604, 'title' => 'Threat Modeling & Incident Response', 'chapter_order' => 4, 'estimated_minutes' => 24]
        ]],
        '107' => ['id' => 107, 'name' => 'Psychology', 'slug' => 'psychology', 'chapters' => [
            ['id' => 10701, 'title' => 'Foundations of Psychological Science', 'chapter_order' => 1, 'estimated_minutes' => 16],
            ['id' => 10702, 'title' => 'Cognitive Processes & Memory', 'chapter_order' => 2, 'estimated_minutes' => 20],
            ['id' => 10703, 'title' => 'Developmental Stages & Learning', 'chapter_order' => 3, 'estimated_minutes' => 18],
            ['id' => 10704, 'title' => 'Behavioral Neuroscience & Emotions', 'chapter_order' => 4, 'estimated_minutes' => 22]
        ]],
        '108' => ['id' => 108, 'name' => 'Business Management', 'slug' => 'business', 'chapters' => [
            ['id' => 10801, 'title' => 'Principles of Management & Leadership', 'chapter_order' => 1, 'estimated_minutes' => 18],
            ['id' => 10802, 'title' => 'Strategic Planning & SWOT Analysis', 'chapter_order' => 2, 'estimated_minutes' => 20],
            ['id' => 10803, 'title' => 'Operations & Supply Chain Basics', 'chapter_order' => 3, 'estimated_minutes' => 22],
            ['id' => 10804, 'title' => 'Organizational Behavior & Culture', 'chapter_order' => 4, 'estimated_minutes' => 18]
        ]],
        '109' => ['id' => 109, 'name' => 'Electrical Engineering', 'slug' => 'electrical-engineering', 'chapters' => [
            ['id' => 10901, 'title' => 'Circuit Analysis & Ohm/Kirchhoff Laws', 'chapter_order' => 1, 'estimated_minutes' => 22],
            ['id' => 10902, 'title' => 'AC Circuits, Impedance & Phasors', 'chapter_order' => 2, 'estimated_minutes' => 24],
            ['id' => 10903, 'title' => 'Semiconductor Diodes & Transistors', 'chapter_order' => 3, 'estimated_minutes' => 22],
            ['id' => 10904, 'title' => 'Digital Logic & Microcontrollers', 'chapter_order' => 4, 'estimated_minutes' => 25]
        ]],
        '110' => ['id' => 110, 'name' => 'Astronomy & Astrophysics', 'slug' => 'astronomy', 'chapters' => [
            ['id' => 11001, 'title' => 'Solar System & Planetary Dynamics', 'chapter_order' => 1, 'estimated_minutes' => 18],
            ['id' => 11002, 'title' => 'Stellar Evolution & Nucleosynthesis', 'chapter_order' => 2, 'estimated_minutes' => 22],
            ['id' => 11003, 'title' => 'Galaxies, Dark Matter & Cosmology', 'chapter_order' => 3, 'estimated_minutes' => 25],
            ['id' => 11004, 'title' => 'Observational Techniques & Telescopes', 'chapter_order' => 4, 'estimated_minutes' => 20]
        ]]
    ];

    // Slug aliases
    $slugAliases = [
        'mathematics' => '1', 'math' => '1', 'maths' => '1',
        'computer-science' => '2', 'cs' => '2', 'algorithms' => '2',
        'data-science' => '3', 'ds' => '3',
        'artificial-intelligence' => '4', 'ai' => '4', 'ml' => '4',
        'physics' => '5', 'waves' => '5',
        'chemistry' => '6', 'chemical-bonding' => '6',
        'biology' => '101', 'bio' => '101',
        'history' => '102', 'world-history' => '102',
        'economics' => '103', 'econ' => '103', 'finance' => '103',
        'environmental-science' => '104', 'env' => '104', 'ecology' => '104',
        'computer-networks' => '105', 'net' => '105', 'networks' => '105',
        'cyber-security' => '106', 'security' => '106', 'sec' => '106',
        'psychology' => '107', 'psych' => '107',
        'business' => '108', 'management' => '108', 'biz' => '108',
        'electrical-engineering' => '109', 'ee' => '109', 'circuits' => '109',
        'astronomy' => '110', 'astrophysics' => '110', 'astro' => '110'
    ];

    $matchedKey = $subjectMap[$rawSubject] ?? null;
    if (!$matchedKey && isset($slugAliases[$subjectSlug])) {
        $matchedKey = $subjectMap[$slugAliases[$subjectSlug]] ?? null;
    }
    if (!$matchedKey) {
        foreach ($subjectMap as $k => $subj) {
            if ($subj['slug'] === $subjectSlug || strtolower($subj['name']) === $subjectSlug) {
                $matchedKey = $subj;
                break;
            }
        }
    }
    if (!$matchedKey) {
        $matchedKey = $subjectMap['1']; // fallback
    }

    // Get user completions from DB
    $completedSet = [];
    try {
        $cStmt = $pdo->prepare("SELECT chapter_id FROM user_chapter_completions WHERE user_id = :uid");
        $cStmt->execute(['uid' => $uid]);
        $rows = $cStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $cid) {
            $completedSet[(int)$cid] = true;
        }
    } catch (Exception $e) {}

    $chaptersWithStatus = array_map(function($ch, $idx) use ($completedSet) {
        $isComp = isset($completedSet[(int)$ch['id']]);
        return [
            'id' => $ch['id'],
            'chapter_id' => $ch['id'],
            'title' => $ch['title'],
            'chapter_order' => $ch['chapter_order'],
            'estimated_minutes' => $ch['estimated_minutes'],
            'is_completed' => $isComp,
            'is_unlocked' => true,
            'status' => $isComp ? 'COMPLETED' : ($idx === 0 ? 'ACTIVE' : 'UNLOCKED')
        ];
    }, $matchedKey['chapters'], array_keys($matchedKey['chapters']));

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'data' => [
            'subject' => [
                'id' => $matchedKey['id'],
                'name' => $matchedKey['name'],
                'slug' => $matchedKey['slug']
            ],
            'chapters' => $chaptersWithStatus
        ],
        'subject' => [
            'id' => $matchedKey['id'],
            'name' => $matchedKey['name'],
            'slug' => $matchedKey['slug']
        ],
        'chapters' => $chaptersWithStatus
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Chapters operation failed: ' . $e->getMessage()]);
}
