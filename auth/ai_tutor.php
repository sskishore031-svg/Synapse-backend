<?php
// AI Tutor API Endpoint - Synapse AI Backend with RAG Weighted Scoring & Stemming
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$rawInput = file_get_contents("php://input");
if (empty($rawInput) && php_sapi_name() === 'cli') {
    $rawInput = file_get_contents("php://stdin");
}
$input = json_decode($rawInput, true) ?? [];
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $input['user_id'] ?? $input['id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
$email = trim($_GET['email'] ?? $input['email'] ?? $_POST['email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '');
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? '';
$question = trim($input['question'] ?? $input['message'] ?? $_POST['question'] ?? $_GET['question'] ?? '');

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

    if ($action === 'history' && empty($question)) {
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'total_messages' => 0,
                'messages' => []
            ]
        ]);
        exit();
    }

    if (empty($question)) {
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'SYNAPSE Hybrid SLM + Semantic RAG Engine Active.',
            'data' => null
        ]);
        exit();
    }

    // Dynamic preferences and user name resolution
    // Dynamic preferences and user name resolution
    // CRITICAL: NEVER auto-use account username from MySQL or Session.
    // Strictly opt-in: only use if explicitly passed by client via chatPreferredName.
    $preferredName = trim($input['chat_preferred_name'] ?? $input['preferred_name'] ?? $_GET['chat_preferred_name'] ?? $_GET['preferred_name'] ?? '');

    $includeFormulas = isset($input['include_formulas']) ? filter_var($input['include_formulas'], FILTER_VALIDATE_BOOLEAN) : (isset($_GET['include_formulas']) ? filter_var($_GET['include_formulas'], FILTER_VALIDATE_BOOLEAN) : true);
    $includeExamples = isset($input['include_examples']) ? filter_var($input['include_examples'], FILTER_VALIDATE_BOOLEAN) : (isset($_GET['include_examples']) ? filter_var($_GET['include_examples'], FILTER_VALIDATE_BOOLEAN) : true);
    $useEmojis = isset($input['use_emojis']) ? filter_var($input['use_emojis'], FILTER_VALIDATE_BOOLEAN) : (isset($_GET['use_emojis']) ? filter_var($_GET['use_emojis'], FILTER_VALIDATE_BOOLEAN) : false);
    $consecutiveUnclear = intval($input['consecutive_unclear'] ?? $_GET['consecutive_unclear'] ?? 0);

    $normQuery = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $question)));
    $cleanWords = array_values(array_filter(preg_split('/\s+/', $normQuery)));

    // =========================================================================
    // STAGE 1: Dynamic Custom User Name / Nickname Memory
    // =========================================================================
    // If waiting for name from previous "call me by my name" prompt
    if (!empty($input['is_waiting_for_name']) || !empty($_GET['is_waiting_for_name'])) {
        $candidate = trim(preg_replace('/[^a-zA-Z]/', '', $question));
        if (strlen($candidate) >= 2) {
            $preferredName = ucfirst(strtolower($candidate));
            $reply = "Got it, $preferredName! From now on, I will call you $preferredName.";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'is_name_capture' => true,
                    'captured_name' => $preferredName,
                    'preferred_name' => $preferredName,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    if (preg_match('/^(?:from\s+now\s+on\s+)?(?:call\s+me\s+(?:by\s+my\s+name\s+|by\s+name\s+|with\s+my\s+name\s+)?|my\s+name\s+is\s+|name\s+is\s+|i\s+am\s+|i[\'’]m\s+)([a-zA-Z]+)/i', $question, $m)) {
        $candidate = ucfirst(strtolower($m[1]));
        if (!in_array(strtolower($candidate), ['by', 'my', 'name', 'a', 'an', 'the'], true) && strlen($candidate) >= 2) {
            $preferredName = $candidate;
            $reply = "Got it, $preferredName! From now on, I will call you $preferredName.";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'is_name_capture' => true,
                    'captured_name' => $preferredName,
                    'preferred_name' => $preferredName,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    $askNameRequests = [
        'call me by my name', 'call me by name', 'call me with my name', 'call me my name', 'call me',
        'enoda name solu kupdu', 'enoda name solli kupdu', 'ennoda name solu kupdu', 'ennoda name solli kupdu'
    ];
    if (in_array($normQuery, $askNameRequests, true) || $normQuery === 'call me') {
        $reply = "Sure! What name would you like me to call you?";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'awaiting_preferred_name' => true,
                'is_waiting_for_name' => true,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    if (preg_match('/^(?:enoda|ennoda|en)\s+(?:name|per|peyar)(?:\s+is)?\s+([a-zA-Z]+)/i', $question, $m)) {
        $preferredName = ucfirst(strtolower($m[1]));
        $reply = "Got it, $preferredName! From now on, I will call you $preferredName.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'is_name_capture' => true,
                'captured_name' => $preferredName,
                'preferred_name' => $preferredName,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    if (preg_match('/(?:enakku|enna|inimel\s+enna|inimel)?\s*([a-zA-Z]+)\s*(?:nu|endru)\s*(?:kupdu|koopdu|kollu)/i', $question, $m)) {
        $candidate = ucfirst(strtolower($m[1]));
        if (!in_array(strtolower($candidate), ['enna', 'enakku', 'inimel', 'name'], true)) {
            $preferredName = $candidate;
            $reply = "Got it, $preferredName! From now on, I will call you $preferredName.";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'is_name_capture' => true,
                    'captured_name' => $preferredName,
                    'preferred_name' => $preferredName,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    // =========================================================================
    // STAGE 2: Formatting & Style Preferences (no formula, no example, no emoji)
    // =========================================================================
    $gotIt = ($preferredName !== '') ? "Got it, $preferredName!" : "Got it!";

    $disableEmojiPhrases = ['dont use emoji', 'dont use emojis', 'do not use emoji', 'do not use emojis', 'no emoji', 'no emojis', 'stop emoji', 'without emoji', 'without emojis'];
    foreach ($disableEmojiPhrases as $dep) {
        if (strpos($normQuery, $dep) !== false) {
            $useEmojis = false;
            $reply = "$gotIt I'll keep all responses clean with * bullet points and no emojis.";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'use_emojis' => false,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    $suppressBothPhrases = [
        'no need of formula and example', 'no formula and example', 'without formula and example',
        'no formula no example', 'no formulas and examples', 'only explain', 'just explain', 'only concept', 'only definition'
    ];
    foreach ($suppressBothPhrases as $sbp) {
        if (strpos($normQuery, $sbp) !== false || (strpos($normQuery, 'no formula') !== false && strpos($normQuery, 'no example') !== false)) {
            $includeFormulas = false;
            $includeExamples = false;
            $reply = "$gotIt I'll provide only pure concepts and definitions without formulas or examples for upcoming topics. What shall we learn?";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'include_formulas' => false,
                    'include_examples' => false,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    $suppressFormulaPhrases = ['no formula', 'no formulas', 'without formula', 'without formulas', 'no need of formula', 'dont show formula'];
    foreach ($suppressFormulaPhrases as $sfp) {
        if (strpos($normQuery, $sfp) !== false) {
            $includeFormulas = false;
            $reply = "$gotIt I'll omit formulas and focus on concepts and practical examples.";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'include_formulas' => false,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    $suppressExamplePhrases = ['no example', 'no examples', 'without example', 'without examples', 'no need of example', 'dont show example'];
    foreach ($suppressExamplePhrases as $sep) {
        if (strpos($normQuery, $sep) !== false) {
            $includeExamples = false;
            $reply = "$gotIt I'll omit examples and focus on core definitions and formulas.";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'include_examples' => false,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    // =========================================================================
    // STAGE 3: Farewells, Greetings & Small Talk
    // =========================================================================
    $farewells = ['bye', 'byee', 'byeee', 'goodbye', 'good bye', 'see you', 'see ya', 'tata', 'take care', 'kelamburen', 'poyitu varen'];
    $isFarewell = in_array($normQuery, $farewells, true) || (count($cleanWords) <= 2 && in_array($normQuery, ['bye', 'goodbye', 'tata']));
    foreach ($farewells as $fw) {
        if ($normQuery === $fw || (count($cleanWords) <= 3 && strpos($normQuery, $fw) !== false)) {
            $isFarewell = true;
            break;
        }
    }
    if ($isFarewell) {
        $reply = ($preferredName !== '')
            ? "Okay bye, $preferredName! We'll continue tomorrow."
            : "Okay bye! We'll continue tomorrow.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // Identity & Persona
    $identityPhrases = [
        'who are you', 'what is your name', 'whats your name', 'tell me your name',
        'nee yaru', 'neenga yaru', 'un peru enna', 'unga peru enna', 'un per enna', 'unga per enna',
        'what can you do', 'how does this app work', 'how many subjects', 'how to use this app',
        'how can you help', 'how can you help me', 'what are your features', 'what do you do'
    ];
    foreach ($identityPhrases as $ip) {
        if ($normQuery === $ip || strpos($normQuery, $ip) !== false) {
            $idText = "I'm your study friend here to make learning easy! I can give you clear study notes, test you with interactive quizzes, explain any subject concept step-by-step, and clear your exam doubts whenever you need.";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $idText,
                    'paragraph2' => '',
                    'answer' => $idText,
                    'matched_subject' => 'SYNAPSE AI',
                    'matched_chapter' => 'Identity & Persona',
                    'matched_topic' => 'Study Companion',
                    'confidence_score' => 100.0,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    // Small Talk Handlers
    $greetingReply = ($preferredName !== '')
        ? "Hello, $preferredName! I'm doing great and ready to help you study. What topic shall we explore today?"
        : "Hello! I'm doing great and ready to help you study. What topic shall we explore today?";
    $gmReply = ($preferredName !== '')
        ? "Good morning, $preferredName! Ready to learn something exciting today? What subject are we tackling?"
        : "Good morning! Ready to learn something exciting today? What subject are we tackling?";
    $gaReply = ($preferredName !== '')
        ? "Good afternoon, $preferredName! Hope you're having a productive day. What subject shall we explore?"
        : "Good afternoon! Hope you're having a productive day. What subject shall we explore?";
    $geReply = ($preferredName !== '')
        ? "Good evening, $preferredName! Let's do some focused study tonight. What can I help you understand?"
        : "Good evening! Let's do some focused study tonight. What can I help you understand?";
    $gnReply = ($preferredName !== '')
        ? "Good night, $preferredName! Rest well and keep learning tomorrow."
        : "Good night! Rest well and keep learning tomorrow.";

    $smallTalk = [
        'how are you' => $greetingReply,
        'hows it going' => $greetingReply,
        'good morning' => $gmReply,
        'good afternoon' => $gaReply,
        'good evening' => $geReply,
        'good night' => $gnReply,
        'gn' => $gnReply,
        'thank you' => ($preferredName !== '') ? "You're very welcome, $preferredName! Always happy to help. Let me know if you have any other doubts!" : "You're very welcome! Always happy to help. Let me know if you have any other doubts!",
        'thanks' => ($preferredName !== '') ? "You're very welcome, $preferredName! Keep up the great effort!" : "You're very welcome! Keep up the great effort!",
        'you are awesome' => ($preferredName !== '') ? "Thank you so much, $preferredName! You're awesome for putting in the effort to study. Let's keep it going!" : "Thank you so much! You're awesome for putting in the effort to study. Let's keep it going!",
        'you are great' => ($preferredName !== '') ? "Thank you, $preferredName! I'm here anytime you need a study boost." : "Thank you! I'm here anytime you need a study boost.",
        'i am bored' => "How about a quick 3-minute quiz or learning a fascinating fact in science or tech? Pick a subject and let's make it fun!",
        'motivate me' => "Every expert was once a beginner who refused to quit. Focus on mastering just one concept right now—progress adds up fast!",
        'who made you' => "I was built by the SYNAPSE AI team to be your personal university study companion and learning buddy!",
        'are you smart' => "I know Python, SQL, Modern AI, and Aptitude inside and out, and my goal is helping you excel in technical placement exams and interviews!",
        'hello friend' => ($preferredName !== '') ? "Hey, $preferredName! Ready to help you study. What topic shall we explore today?" : "Hey! Ready to help you study. What topic shall we explore today?",
        'hi friend' => ($preferredName !== '') ? "Hey, $preferredName! Ready to help you study. What topic shall we explore today?" : "Hey! Ready to help you study. What topic shall we explore today?",
        'hi' => $greetingReply,
        'hello' => $greetingReply,
        'hey' => $greetingReply,
        'vanakkam' => $greetingReply,
        'namaste' => $greetingReply
    ];

    foreach ($smallTalk as $key => $reply) {
        if ($normQuery === $key || (count($cleanWords) <= 3 && strpos($normQuery, $key) !== false)) {
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'matched_subject' => 'SYNAPSE AI',
                    'matched_chapter' => 'Conversational',
                    'matched_topic' => 'Small Talk',
                    'confidence_score' => 100.0,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    // =========================================================================
    // STAGE 4: Explicit Out-of-Scope & Casual Slang Guardrails
    // =========================================================================
    $outOfScopePhrases = [
        'can you eat', 'do you eat', 'what do you eat', 'who is rajini', 'who is rajinikanth',
        'rajini', 'rajinikanth', 'kamal', 'vijay', 'thalapathy', 'ajith', 'thala', 'suriya',
        'dhanush', 'simbu', 'sivakarthikeyan', 'actor', 'actress', 'movie', 'movies', 'cinema',
        'box office', 'song', 'songs', 'cricket', 'recipe', 'food', 'cook', 'cooking',
        'girlfriend', 'boyfriend', 'marry me', 'do you love me', 'joke', 'jokes', 'politics'
    ];
    $isDirectOutOfScope = false;
    foreach ($outOfScopePhrases as $op) {
        if ($normQuery === $op || strpos($normQuery, $op) !== false) {
            if ($op === 'food' && (strpos($normQuery, 'food chain') !== false || strpos($normQuery, 'nutrition') !== false)) {
                continue;
            }
            $isDirectOutOfScope = true;
            break;
        }
    }
    if ($isDirectOutOfScope) {
        $boundary = "Sorry, that's not relatable to our 10th, 11th & 12th studies! Ask me any subject concept, quiz, or notes.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $boundary,
                'paragraph2' => '',
                'answer' => $boundary,
                'matched_subject' => null,
                'matched_chapter' => null,
                'matched_topic' => null,
                'confidence_score' => 100.0,
                'is_out_of_scope' => true,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // Casual Slang Guardrail ("bhai", "bro", "machan", "hm")
    $casualSlang = ['bhai', 'bro', 'brother', 'machan', 'machi', 'thala', 'mapla', 'nanba', 'dost', 'hm', 'hmm', 'hmmm', 'da', 'di', 'pa', 'ah', 'k', 'kk', 'lol', 'wat', 'huh', 'y', 'enna'];
    $isSlang = false;
    if (count($cleanWords) <= 2) {
        foreach ($cleanWords as $cw) {
            if (in_array($cw, $casualSlang, true)) {
                $isSlang = true;
                break;
            }
        }
    }
    if ($isSlang) {
        if ($consecutiveUnclear >= 1) {
            $unclearReply = ($preferredName !== '')
                ? "I don't know about that, $preferredName. Please ask something related to your subjects, study notes, or quizzes."
                : "I don't know about that. Please ask something related to your subjects, study notes, or quizzes.";
        } else {
            $unclearReply = ($preferredName !== '')
                ? "I didn't understand that, $preferredName. Could you please explain clearly which subject or topic you need help with?"
                : "I didn't understand that. Could you please explain clearly which subject or topic you need help with?";
        }
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $unclearReply,
                'paragraph2' => '',
                'answer' => $unclearReply,
                'confidence_score' => 100.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // =========================================================================
    // STAGE 4B: Subject Overview Intent ("what about biology", "uses of math", etc.)
    // =========================================================================
    $overviewRegex = '/^(?:what\s+about|what\s+abou|about|tell\s+me\s+about|uses\s+of|use\s+of|advantages\s+of|advantage\s+of|benefits\s+of|overview\s+of|explain\s+about|scope\s+of|details\s+of)\s+([a-zA-Z\s]+)$/i';
    $candidateSub = null;
    if (preg_match($overviewRegex, trim($question), $ovMatch)) {
        $candidateSub = strtolower(trim($ovMatch[1]));
    } else {
        $standaloneSubjects = [
            'python', 'coding', 'programming', 'python & coding logic', 'python-coding-logic',
            'sql', 'database', 'dbms', 'rdbms', 'sql & database management', 'sql-database-management',
            'ai', 'artificial intelligence', 'modern tech', 'tech', 'llm', 'rag', 'cloud', 'cybersecurity', 'ai & modern tech concepts', 'ai-modern-tech-concepts',
            'aptitude', 'interview', 'interview prep', 'quant', 'puzzles', 'aptitude & interview prep', 'aptitude-interview-prep'
        ];
        if (in_array($normQuery, $standaloneSubjects, true)) {
            $candidateSub = $normQuery;
        }
    }

    if (!empty($candidateSub)) {
        $candWords = preg_split('/\s+/', $candidateSub);
        if (array_intersect($candWords, ['it', 'its', 'this', 'that', 'these', 'those', 'roots', 'solve', 'formula'])) {
            $candidateSub = null;
        }
    }

    if (!empty($candidateSub)) {
        $subOverviews = [
            'python & coding logic' => [
                'name' => 'Python & Coding Logic',
                'what' => 'Python & Coding Logic is the foundational software engineering discipline covering memory architecture, core OOP principles, functional paradigms, and algorithmic problem-solving techniques.',
                'uses' => 'Used in backend web development (Django/FastAPI), machine learning model building, data pipeline automation, enterprise scripting, and technical coding rounds for top tech companies.',
                'adv' => 'High developer velocity, readable syntax, massive standard and third-party library ecosystem, versatile applicability from microservices to AI, and high recruitment demand.',
                'chal' => 'Dynamic typing runtime overhead, GIL (Global Interpreter Lock) concurrency limitations, and memory consumption compared to compiled languages like C++ or Rust.'
            ],
            'sql & database management' => [
                'name' => 'SQL & Database Management',
                'what' => 'SQL & Database Management focuses on relational database modeling, relational algebra, ACID transaction safety, B-Tree index optimization, and querying complex structured datasets.',
                'uses' => 'Powering core enterprise data storage, financial transactions, real-time analytics, user authentication backends, and data warehousing for high-concurrency systems.',
                'adv' => 'Strict data integrity and consistency through ACID compliance, standardized declarative query language across all relational engines (PostgreSQL, MySQL), and predictable query execution plans.',
                'chal' => 'Horizontal scaling complexity (sharding/replication lag) compared to NoSQL, rigid schema migrations on multi-terabyte tables, and database deadlocks under extreme contention.'
            ],
            'ai & modern tech concepts' => [
                'name' => 'AI & Modern Tech Concepts',
                'what' => 'AI & Modern Tech Concepts encompasses the architecture and deployment of machine learning models, neural networks, Large Language Models (LLMs), Retrieval-Augmented Generation (RAG), cloud architectures, and cybersecurity.',
                'uses' => 'Generative AI assistants, autonomous robotics, semantic search engines, enterprise cloud infrastructure, threat intelligence, and predictive decision-support systems.',
                'adv' => 'Automates complex human-cognitive tasks, processes unstructured multimodal data at scale, and unlocks unprecedented software capabilities.',
                'chal' => 'High GPU compute and inference latency costs, model hallucination and alignment risks, data privacy regulations, and complex MLOps deployment pipelines.'
            ],
            'aptitude & interview prep' => [
                'name' => 'Aptitude & Interview Prep',
                'what' => 'Aptitude & Interview Prep trains quantitative problem-solving speed, logical reasoning, data interpretation, pattern recognition, and professional behavioral interview communication.',
                'uses' => 'Clearing first-round campus placement screening tests, competitive tech assessments, managerial case studies, and engineering HR/technical interviews.',
                'adv' => 'Drastically sharpens mental math speed, structured deduction under strict time limits, cognitive clarity, and articulation during high-pressure placement drives.',
                'chal' => 'Strict time constraints per problem (under 60 seconds), trick questions requiring pattern shortcuts, and fatigue during multi-hour recruitment examinations.'
            ]
        ];

        $subAliases = [
            'python' => 'python & coding logic', 'python3' => 'python & coding logic', 'py' => 'python & coding logic',
            'coding' => 'python & coding logic', 'programming' => 'python & coding logic', 'logic' => 'python & coding logic',
            'sql' => 'sql & database management', 'database' => 'sql & database management', 'databases' => 'sql & database management',
            'dbms' => 'sql & database management', 'rdbms' => 'sql & database management',
            'ai' => 'ai & modern tech concepts', 'artificial intelligence' => 'ai & modern tech concepts',
            'machine learning' => 'ai & modern tech concepts', 'ml' => 'ai & modern tech concepts',
            'deep learning' => 'ai & modern tech concepts', 'modern tech' => 'ai & modern tech concepts',
            'tech' => 'ai & modern tech concepts', 'llm' => 'ai & modern tech concepts', 'rag' => 'ai & modern tech concepts',
            'aptitude' => 'aptitude & interview prep', 'quant' => 'aptitude & interview prep',
            'quantitative aptitude' => 'aptitude & interview prep', 'logical reasoning' => 'aptitude & interview prep',
            'reasoning' => 'aptitude & interview prep', 'interview' => 'aptitude & interview prep',
            'interview prep' => 'aptitude & interview prep', 'placement' => 'aptitude & interview prep',
            'placement prep' => 'aptitude & interview prep', 'puzzles' => 'aptitude & interview prep'
        ];

        $resolvedSubKey = $subAliases[$candidateSub] ?? $candidateSub;

        $matchedInfo = $subOverviews[$resolvedSubKey] ?? null;
        if (!$matchedInfo && count(explode(' ', trim($candidateSub))) === 1) {
            foreach ($subOverviews as $k => $v) {
                if ($candidateSub === $k || strpos($k, $candidateSub) !== false) {
                    $matchedInfo = $v;
                    break;
                }
            }
        }

        if (!$matchedInfo) {
            $formattedName = ucwords($candidateSub);
            $matchedInfo = [
                'name' => $formattedName,
                'what' => "$formattedName is an academic discipline focused on exploring the foundational principles, analytical methodologies, and structured theories that govern its field of study.",
                'uses' => "Practitioners and scholars apply $formattedName across modern research, industrial problem-solving, high-level analysis, technology integration, and empirical investigation.",
                'adv' => "Develops rigorous critical reasoning, analytical problem-solving skills, and deep subject-matter expertise with diverse professional applications.",
                'chal' => "Requires substantial dedication, mastery of prerequisite theory, and sustained problem-solving practice to navigate complex abstract concepts."
            ];
        }

        $reply = "* **What it is:**\n" . $matchedInfo['what'] . "\n\n" .
                 "* **Key Uses & Real-World Work:**\n" . $matchedInfo['uses'] . "\n\n" .
                 "* **Advantages:**\n" . $matchedInfo['adv'] . "\n\n" .
                 "* **Disadvantages / Challenges:**\n" . $matchedInfo['chal'];

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'matched_subject' => $matchedInfo['name'],
                'matched_chapter' => 'Overview',
                'matched_topic' => $matchedInfo['name'] . ' Overview',
                'confidence_score' => 98.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // =========================================================================
    // STAGE 5: Weighted Academic RAG Search
    // =========================================================================
    $stopWords = [
        'what', 'is', 'are', 'the', 'in', 'of', 'explain', 'define', 'tell', 'me', 'about',
        'how', 'does', 'do', 'did', 'work', 'a', 'an', 'and', 'or', 'to', 'for', 'with',
        'at', 'by', 'from', 'on', 'it', 'this', 'that', 'can', 'you', 'please', 'give', 'show',
        'my', 'your', 'we', 'i'
    ];

    $genericSubjectWords = [
        'physics', 'math', 'maths', 'mathematics', 'chemistry', 'computer', 'computers',
        'science', 'sciences', 'biology', 'history', 'economics', 'engineering', 'ai', 'data'
    ];

    function synapse_stem($word) {
        $w = strtolower(trim($word));
        if (strlen($w) <= 2) return $w;
        if ($w === 'matrices') return 'matrix';
        if ($w === 'indices') return 'index';
        if ($w === 'vertices') return 'vertex';
        if (substr($w, -3) === 'ics') return $w;
        if (substr($w, -3) === 'ies' && strlen($w) > 4) return substr($w, 0, -3) . 'y';
        if (substr($w, -3) === 'ves' && strlen($w) > 4) return substr($w, 0, -1);
        if (preg_match('/(shes|ches|sses|xes|zes)$/', $w)) return substr($w, 0, -2);
        if (substr($w, -1) === 's' && substr($w, -2) !== 'ss' && substr($w, -2) !== 'us' && substr($w, -2) !== 'is' && strlen($w) > 3) {
            return substr($w, 0, -1);
        }
        return $w;
    }

    function synapse_tokenize($text, $stopWords) {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', strtolower($text));
        $words = preg_split('/\s+/', trim($clean));
        $res = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (strlen($w) > 1 && !in_array($w, $stopWords, true)) {
                $res[] = $w;
            }
        }
        return $res;
    }

    // Load Knowledge Base Chunks
    $chunks = [];
    $assetPath = file_exists(__DIR__ . '/educational_knowledge_base.json')
        ? __DIR__ . '/educational_knowledge_base.json'
        : 'C:/Users/SCAD LAB/AndroidStudioProjects/SYNAPSE/app/src/main/assets/educational_knowledge_base.json';
    if (file_exists($assetPath)) {
        $kbJson = file_get_contents($assetPath);
        $kbData = json_decode($kbJson, true);
        if (!empty($kbData['subjects']) && is_array($kbData['subjects'])) {
            foreach ($kbData['subjects'] as $sub) {
                $subName = $sub['subject'] ?? '';
                if (!empty($sub['rag_chunks'])) {
                    foreach ($sub['rag_chunks'] as $rc) {
                        if (empty($rc['subject'])) $rc['subject'] = $subName;
                        $chunks[] = $rc;
                    }
                }
            }
        }
    }

    // Dynamic length control: Shorten queries ("give me short", "short ha sollu", "chinadha thaa")
    $shortPhrases = [
        'give me short', 'short ha sollu', 'short-aa sollu', 'chinadha thaa', 'make it short',
        'in short', 'short summary', 'short note', 'short please', 'give short', 'solu short', 'short'
    ];
    $isStandaloneShort = in_array($normQuery, $shortPhrases, true);
    if ($isStandaloneShort) {
        $p1 = "* **Quick Revision Summary:**\n* **Core Definition:** Review governing definitions and fundamental laws from your chapter.";
        $p2 = "* **Key Formula / Principle:** Focus on board exam standard units, formulas, and diagrams.\n* **Exam Tip:** Prepare 2-mark definitions and 5-mark derivations systematically for your Tamil Nadu State Board exams.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $p1 . "\n" . $p2,
                'matched_subject' => 'Tamil Nadu State Board',
                'matched_chapter' => 'Quick Revision',
                'matched_topic' => 'Summary',
                'confidence_score' => 100.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    $tokens = synapse_tokenize($normQuery, $stopWords);
    $conceptTokens = [];
    $subjectTokens = [];
    foreach ($tokens as $t) {
        if (in_array($t, $genericSubjectWords, true)) {
            $subjectTokens[] = $t;
        } else {
            $conceptTokens[] = $t;
        }
    }

    $bestChunk = null;
    $bestScore = 0.0;

    foreach ($chunks as $chunk) {
        $score = 0.0;
        $matchedConceptTokens = 0;

        $normTopic = strtolower($chunk['topic'] ?? '');
        $normChapter = strtolower($chunk['chapter'] ?? '');
        $normSubject = strtolower($chunk['subject'] ?? '');
        $normDef = strtolower($chunk['definition'] ?? '');
        $normContent = strtolower($chunk['content'] ?? '');
        $normFormula = strtolower($chunk['formula'] ?? $chunk['formulaOrCoreRule'] ?? '');

        $titleTokens = array_merge(synapse_tokenize($normChapter, $stopWords), synapse_tokenize($normTopic, $stopWords));
        $titleStems = array_map('synapse_stem', $titleTokens);

        $kwList = $chunk['keywords'] ?? [];
        $kwPhrases = array_map('strtolower', $kwList);
        $kwStems = [];
        foreach ($kwPhrases as $kw) {
            foreach (synapse_tokenize($kw, $stopWords) as $kt) {
                $kwStems[] = synapse_stem($kt);
            }
        }

        $bodyTokens = array_merge(
            synapse_tokenize($normDef, $stopWords),
            synapse_tokenize($normContent, $stopWords),
            synapse_tokenize($normFormula, $stopWords)
        );
        $bodyStems = array_map('synapse_stem', $bodyTokens);

        foreach ($conceptTokens as $ct) {
            $cStem = synapse_stem($ct);
            $tokenMatched = false;

            if (in_array($ct, $titleTokens, true) || in_array($cStem, $titleStems, true) || strpos($normTopic, $ct) !== false || strpos($normChapter, $ct) !== false || strpos($normTopic, $cStem) !== false) {
                $score += 15.0;
                $tokenMatched = true;
            }

            if (in_array($ct, $kwPhrases, true) || in_array($cStem, $kwPhrases, true) || in_array($cStem, $kwStems, true)) {
                $score += 10.0;
                $tokenMatched = true;
            } else {
                foreach ($kwPhrases as $kwp) {
                    if (strpos($kwp, $ct) !== false || strpos($kwp, $cStem) !== false) {
                        $score += 10.0;
                        $tokenMatched = true;
                        break;
                    }
                }
            }

            if (in_array($cStem, $bodyStems, true) || strpos($normDef, $ct) !== false || strpos($normContent, $ct) !== false || strpos($normDef, $cStem) !== false) {
                $score += 3.0;
                $tokenMatched = true;
            }

            if ($tokenMatched) {
                $matchedConceptTokens++;
            }
        }

        foreach ($kwPhrases as $kw) {
            if (strlen($kw) >= 4 && strpos($normQuery, $kw) !== false && !in_array($kw, $genericSubjectWords, true)) {
                $score += 15.0;
                $matchedConceptTokens++;
            }
        }

        if ($matchedConceptTokens > 0) {
            $chunkSubjectStem = synapse_stem($normSubject);
            foreach ($subjectTokens as $st) {
                $stStem = synapse_stem($st);
                if ($normSubject === $st || $chunkSubjectStem === $stStem || strpos($normSubject, $st) !== false) {
                    $score += 1.0;
                    break;
                }
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestChunk = $chunk;
        }
    }

    $tokenCount = max(count($conceptTokens), 1);
    $normalizedScore = min(1.0, $bestScore / ($tokenCount * 25.0));
    $isElaborated = (strpos($normQuery, 'elaborate') !== false || strpos($normQuery, 'in detail') !== false || strpos($normQuery, 'deep dive') !== false || strpos($normQuery, 'detailed') !== false);
    $isShort = (
        strpos($normQuery, 'short') !== false ||
        strpos($normQuery, 'chinadha') !== false ||
        strpos($normQuery, 'brief') !== false ||
        strpos($normQuery, 'quick') !== false ||
        strpos($normQuery, 'concise') !== false ||
        strpos($normQuery, 'summarize') !== false
    );

    if ($bestChunk !== null && $bestScore >= 12.0 && $normalizedScore >= 0.25) {
        $subject = $bestChunk['subject'] ?? 'Tamil Nadu State Board';
        $chapter = $bestChunk['chapter'] ?? $bestChunk['topic'] ?? '';
        $topic = $bestChunk['topic'] ?? $chapter;
        $def = trim($bestChunk['definition'] ?? '');
        $formula = trim($bestChunk['formula'] ?? $bestChunk['formulaOrCoreRule'] ?? '');
        $example = trim($bestChunk['worked_example'] ?? $bestChunk['workedExample'] ?? '');
        $content = trim($bestChunk['content'] ?? '');

        $bPrefix = $useEmojis ? "📘 " : "* ";
        $fPrefix = $useEmojis ? "⚡ " : "* ";
        $ePrefix = $useEmojis ? "💡 " : "* ";

        if ($isShort) {
            $p1 = "* **Quick Summary: $topic** ($subject · $chapter)\n* **Concept:** $def";
            $p2Parts = [];
            if ($includeFormulas && !empty($formula)) {
                $p2Parts[] = "* **Formula / Core Principle:** $formula";
            }
            if ($includeExamples && !empty($example)) {
                $p2Parts[] = "* **Key Example / Application:** $example";
            }
            $p2Parts[] = "* **Exam Takeaway:** High-yield board exam topic—review key steps and definitions.";
            $p2 = implode("\n", $p2Parts);
            $fullAnswer = trim($p1 . "\n" . $p2);
        } elseif ($isElaborated) {
            $p1 = "{$bPrefix}**In-Depth Breakdown: $topic** ($subject · $chapter)\n\n**1. Theoretical Foundations & Mechanism:**\n$content" .
                  ($includeFormulas && !empty($formula) ? "\n\n**2. Mathematical Formulations & Derivations:**\n$formula" : "");
            $p2Parts = [];
            if ($includeExamples && !empty($example)) {
                $p2Parts[] = "**3. Comprehensive Practical Application:**\n$example";
            }
            $p2Parts[] = "**4. High-Yield Exam Takeaway:**\nMastering $topic requires remembering key governing assumptions, units, and boundary conditions.";
            $p2 = implode("\n\n", $p2Parts);
            $fullAnswer = trim($p1 . "\n\n" . $p2);
        } else {
            // Clean * bullet points, zero emojis by default
            $p1 = "{$bPrefix}**Concept & Definition** ($subject · $topic)\n$def";
            $p2Parts = [];
            if ($includeFormulas && !empty($formula)) {
                $p2Parts[] = "{$fPrefix}**Formula / Core Principle:** $formula";
            }
            if ($includeExamples && !empty($example)) {
                $p2Parts[] = "{$ePrefix}**Key Example & Practical Takeaway:** $example";
            }
            $p2 = implode("\n", $p2Parts);
            $fullAnswer = trim($p1 . (empty($p2) ? "" : "\n" . $p2));
        }

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $fullAnswer,
                'matched_subject' => $subject,
                'matched_chapter' => $chapter,
                'matched_topic' => $topic,
                'confidence_score' => $bestScore,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // Check if legitimate academic guidance question
    $academicWords = ['python', 'coding', 'sql', 'database', 'rdbms', 'ai', 'tech', 'llm', 'rag', 'aptitude', 'placement', 'interview', 'study', 'quiz', 'notes'];
    $isAcademic = false;
    foreach ($academicWords as $aw) {
        if (strpos($normQuery, $aw) !== false) {
            $isAcademic = true;
            break;
        }
    }

    if ($isAcademic) {
        $cleanTopic = ucwords(preg_replace('/(?i)^(what is|explain|tell me about|how does|what are|define)\s+/', '', $question));
        $p1 = "* **SYNAPSE AI Placement & Micro-Learning Companion**\n\n$cleanTopic is an essential technical concept for placements and coding rounds. Mastering this topic equips you with practical problem-solving logic and interview-ready competence.";
        $p2 = "* **Key Principles & Placement Preparation:**\n\n• **Core Rules & Syntax:** Understand foundational mechanics, algorithmic time complexity, and data structures for $cleanTopic.\n• **Interview Focus:** Practice high-yield MCQs and behavioral/technical interview questions.\n• **Practical Application:** Review code examples and real-world system designs.\n\nWhich track or concept would you like to explore further? Let me know!";

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $p1 . "\n\n" . $p2,
                'matched_subject' => 'Placement Prep',
                'matched_chapter' => $cleanTopic,
                'matched_topic' => $cleanTopic,
                'confidence_score' => 70.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // Unclear query or out-of-scope fallback
    if ($consecutiveUnclear >= 1 || $normalizedScore < 0.25) {
        $unclearReply = "That falls outside my placement and technical scope. I'm SYNAPSE AI—built specifically to help you master Python, SQL, Modern AI concepts, and Placement Aptitude!";
    } else {
        $unclearReply = ($preferredName !== '')
            ? "I didn't understand that, $preferredName. Could you please explain clearly which subject or topic you need help with?"
            : "I didn't understand that. Could you please explain clearly which subject or topic you need help with?";
    }

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'data' => [
            'question' => $question,
            'paragraph1' => $unclearReply,
            'paragraph2' => '',
            'answer' => $unclearReply,
            'matched_subject' => null,
            'matched_chapter' => null,
            'matched_topic' => null,
            'confidence_score' => 100.0,
            'tutor_name' => 'SYNAPSE AI',
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'AI Tutor error: ' . $e->getMessage()
    ]);
}
