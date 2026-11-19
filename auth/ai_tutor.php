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
        'are you smart' => "I know 50+ college subjects inside and out, but my favorite thing is helping you become the smartest person in the room!",
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
            'math', 'maths', 'mathematics', 'computer science', 'cs', 'data science', 'ds',
            'artificial intelligence', 'ai', 'machine learning', 'ml', 'deep learning',
            'physics', 'chemistry', 'biology', 'bio', 'history', 'world history',
            'economics', 'finance', 'environmental science', 'ecology', 'computer networks',
            'networking', 'cyber security', 'cybersecurity', 'security', 'psychology',
            'business', 'business management', 'electrical engineering', 'astronomy',
            'astrophysics', 'statistics', 'software engineering', 'cloud computing',
            'operating systems', 'dbms', 'database', 'web development', 'mobile development',
            'blockchain', 'robotics', 'mechanical engineering', 'civil engineering',
            'biotechnology', 'genetics', 'neuroscience', 'organic chemistry', 'quantum physics',
            'calculus', 'linear algebra', 'discrete mathematics', 'philosophy', 'sociology',
            'political science', 'literature', 'accounting', 'marketing', 'cryptography',
            'information technology', 'architecture', 'aerospace engineering',
            'chemical engineering', 'biomedical engineering', 'nanotechnology',
            'supply chain', 'supply chain management', 'law'
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
            'mathematics' => [
                'name' => 'Mathematics',
                'what' => 'Mathematics is the foundational science of structure, quantity, space, and change, studying patterns through rigorous logical deduction and formal proofs.',
                'uses' => 'Used across machine learning algorithms, quantitative finance, structural and aerospace engineering, cryptography, data science, and physics simulations.',
                'adv' => 'Builds unparalleled analytical rigor, sharp abstract problem-solving, and universally transferable reasoning applied across all STEM fields.',
                'chal' => 'High abstraction levels, complex prerequisite chains, and unforgiving precision requirements where minor computational errors alter entire proofs.'
            ],
            'computer science' => [
                'name' => 'Computer Science',
                'what' => 'Computer Science is the systematic study of computation, algorithmic problem-solving, data structures, software architecture, and the interaction between hardware and software.',
                'uses' => 'Powering modern software engineering, operating systems, cloud infrastructures, distributed web systems, cybersecurity, and artificial intelligence platforms.',
                'adv' => 'Massive industry demand, high scalability of digital solutions, rapid feedback cycles, and endless creative engineering possibilities.',
                'chal' => 'Fast-evolving technological landscape requiring constant continuous learning, intricate software debugging, and complex system concurrency issues.'
            ],
            'data science' => [
                'name' => 'Data Science',
                'what' => 'Data Science is an interdisciplinary field combining statistics, machine learning, and domain knowledge to extract actionable insights and predictive intelligence from structured and unstructured data.',
                'uses' => 'Business intelligence, algorithmic trading, customer behavioral modeling, recommendation engines (Netflix/Spotify), healthcare diagnostics, and supply chain forecasting.',
                'adv' => 'Data-driven decision making, high industry valuation, and powerful synergy across business strategy and machine intelligence.',
                'chal' => 'Noisy and messy real-world data, risks of statistical bias and over-fitting, and complex data pipeline maintenance.'
            ],
            'artificial intelligence' => [
                'name' => 'Artificial Intelligence',
                'what' => 'Artificial Intelligence is the branch of computer science dedicated to engineering intelligent agents that simulate cognitive capabilities like learning, pattern recognition, decision-making, and natural language understanding.',
                'uses' => 'Large language models, autonomous self-driving vehicles, medical imaging diagnosis, automated robotics, real-time speech translation, and intelligent game theory.',
                'adv' => 'Automates complex cognitive workflows, enables superhuman processing of massive data, and drives groundbreaking innovations across every scientific domain.',
                'chal' => 'High computational and GPU power costs, "black-box" explainability challenges, hallucination risks, and complex ethical and alignment considerations.'
            ],
            'physics' => [
                'name' => 'Physics',
                'what' => 'Physics is the fundamental natural science that investigates matter, energy, space, time, and the core physical laws that govern the universe from subatomic particles to cosmic scales.',
                'uses' => 'Semiconductor hardware design, aerospace and satellite navigation, quantum computing, renewable energy systems, laser optics, and medical radiation technology.',
                'adv' => 'Provides the deepest mechanistic understanding of physical reality, first-principles thinking, and universal mathematical modeling skills.',
                'chal' => 'Demanding mathematical machinery (multivariable calculus, tensor algebra, differential equations) and unintuitive counter-intuitive quantum concepts.'
            ],
            'chemistry' => [
                'name' => 'Chemistry',
                'what' => 'Chemistry is the central science studying the composition, molecular structure, properties, and reactive transformations of matter at the atomic scale.',
                'uses' => 'Pharmaceutical drug discovery, battery technology and energy storage, materials engineering, polymers, petrochemicals, and agricultural fertilizers.',
                'adv' => 'Direct tangible impact on medicine and green energy, bridging physics and biology, and high industrial manufacturing relevance.',
                'chal' => 'Extensive memorization of reaction pathways, complex spatial 3D stereochemistry, and hazardous laboratory handling protocols.'
            ],
            'biology' => [
                'name' => 'Biology',
                'what' => 'Biology is the natural science dedicated to investigating living organisms, covering cellular processes, physiological systems, genetic inheritance, evolutionary history, and ecological interactions.',
                'uses' => 'Biomedical research, genomic medicine and CRISPR gene editing, vaccine engineering, ecological conservation, agricultural crop improvement, and forensic pathology.',
                'adv' => 'Directly advances human health and disease prevention, explains the living natural world, and drives multi-billion dollar biotechnology innovations.',
                'chal' => 'High conceptual complexity with endless biological exceptions, massive terminology memorization, and long experimental wet-lab incubation periods.'
            ],
            'history' => [
                'name' => 'World History',
                'what' => 'History is the academic study of past human societies, civilizations, cultural revolutions, governance systems, and geopolitical transformations across time.',
                'uses' => 'Foreign policy and diplomatic strategy, legal jurisprudence, historical document archiving, geopolitical risk assessment, journalism, and public governance.',
                'adv' => 'Contextualizes modern global politics, refines critical source evaluation, and teaches pattern recognition across human socio-economic cycles.',
                'chal' => 'Subjective source interpretations, historiographical biases, and reliance on qualitative archival evidence rather than repeatable experiments.'
            ],
            'economics' => [
                'name' => 'Economics & Finance',
                'what' => 'Economics and Finance analyze the production, distribution, and consumption of scarce resources, examining individual decision-making, market mechanisms, and monetary policy.',
                'uses' => 'Central banking, corporate financial strategy, investment portfolio management, public policy design, market pricing models, and international trade treaties.',
                'adv' => 'High-earning career paths in investment banking and consulting, quantitative financial literacy, and deep understanding of global market dynamics.',
                'chal' => 'Economic models depend heavily on simplifying behavioral assumptions, market volatility, and unpredictable geopolitical shocks.'
            ],
            'environmental science' => [
                'name' => 'Environmental Science',
                'what' => 'Environmental Science is an integrative discipline examining ecological systems, climate dynamics, human environmental impact, and sustainable natural resource management.',
                'uses' => 'Carbon emissions auditing, renewable energy transition, wildlife conservation planning, environmental impact assessments (EIA), and climate policy advocacy.',
                'adv' => 'Addresses the world\'s most critical sustainability challenges, clean energy innovations, and growing environmental regulatory compliance demand.',
                'chal' => 'Complex multi-variable climate feedback loops, political polarization surrounding climate policy, and high friction in global treaty enforcement.'
            ],
            'computer networks' => [
                'name' => 'Computer Networks',
                'what' => 'Computer Networks explores the physical and logical communication architectures that interconnect computing devices, transmitting data packets through structured protocol layers.',
                'uses' => 'Internet routing, cloud data center architectures, 5G wireless telecommunications, Content Delivery Networks (CDNs), and enterprise VPNs.',
                'adv' => 'Essential foundation for all connected devices and web systems, stable enterprise infrastructure roles, and vital knowledge for cloud scale.',
                'chal' => 'Complex packet loss and latency troubleshooting, distributed failure modes, and constant threat of network-level security attacks.'
            ],
            'cyber security' => [
                'name' => 'Cyber Security',
                'what' => 'Cyber Security is the practice of safeguarding digital architectures, servers, endpoints, networks, and confidential data from malicious intrusions, ransomware, and vulnerabilities.',
                'uses' => 'Penetration testing and ethical hacking, security operations centers (SOC), secure cryptographic protocols, vulnerability patching, and compliance auditing.',
                'adv' => 'Massive global talent shortage, high compensation, mission-critical organizational impact, and engaging adversarial problem solving.',
                'chal' => 'Asymmetric battlefield (defenders must block all attacks, attackers need one gap), high burnout stress during active breaches, and relentless evolving threats.'
            ],
            'psychology' => [
                'name' => 'Psychology',
                'what' => 'Psychology is the scientific study of the mind and human behavior, examining cognitive perception, emotional regulation, neurobiology, and interpersonal social dynamics.',
                'uses' => 'Clinical psychotherapy and mental health, User Experience (UX) product design, behavioral economics, organizational leadership, and marketing psychology.',
                'adv' => 'Improves human understanding and emotional intelligence, enhances user-centered software design, and vital for mental health therapy.',
                'chal' => 'High individual behavioral variance, replication crises in experimental psychological studies, and emotional fatigue in clinical therapy roles.'
            ],
            'business' => [
                'name' => 'Business Management',
                'what' => 'Business Management focuses on coordinating organizational resources, strategic planning, human capital, and operational execution to achieve sustainable commercial success.',
                'uses' => 'Corporate strategy, startup scaling and venture capital, operational supply chains, product management, human resources, and marketing execution.',
                'adv' => 'Broad executive career mobility, foundational leadership skills, and universal applicability across private enterprises and non-profits.',
                'chal' => 'High dependency on market competition, interpersonal corporate friction, and high organizational accountability during revenue downturns.'
            ],
            'electrical engineering' => [
                'name' => 'Electrical Engineering',
                'what' => 'Electrical Engineering investigates the physics and practical engineering of electricity, electromagnetism, analog/digital electronics, power systems, and signal processing.',
                'uses' => 'Microchip (VLSI) design, electric vehicle powertrains, power grid transmission, wireless telecommunications, and IoT embedded microcontrollers.',
                'adv' => 'Powers the hardware backbone of modern civilization, strong cross-discipline hardware-software synergy, and protected engineering licensing.',
                'chal' => 'Steep physical mathematics and electromagnetic field equations, expensive hardware prototyping cycles, and physical electrical hazards.'
            ],
            'astronomy' => [
                'name' => 'Astronomy & Astrophysics',
                'what' => 'Astronomy and Astrophysics explore celestial bodies, stellar life cycles, black holes, galactic structures, dark energy, and the cosmological origins of the universe.',
                'uses' => 'Deep-space satellite exploration, cosmic radiation monitoring, orbital trajectory planning for space agencies, and advanced spectroscopy imaging.',
                'adv' => 'Expands human knowledge of cosmic origins, pushes the limits of optical and radio sensors, and inspires visionary scientific exploration.',
                'chal' => 'Inability to conduct hands-on laboratory experiments on celestial targets, scarce academic research positions, and reliance on massive space telescopes.'
            ]
        ];

        $subAliases = [
            'math' => 'mathematics', 'maths' => 'mathematics', 'algebra' => 'mathematics', 'calculus' => 'mathematics',
            'cs' => 'computer science', 'programming' => 'computer science', 'coding' => 'computer science',
            'ds' => 'data science', 'data analytics' => 'data science',
            'ai' => 'artificial intelligence', 'ml' => 'artificial intelligence', 'machine learning' => 'artificial intelligence', 'deep learning' => 'artificial intelligence',
            'phy' => 'physics', 'waves' => 'physics',
            'chem' => 'chemistry', 'bonding' => 'chemistry', 'chemical bonding' => 'chemistry',
            'bio' => 'biology', 'life science' => 'biology', 'biological sciences' => 'biology',
            'world history' => 'history', 'ancient history' => 'history',
            'finance' => 'economics', 'econ' => 'economics', 'macroeconomics' => 'economics', 'microeconomics' => 'economics',
            'ecology' => 'environmental science', 'environment' => 'environmental science', 'environmental' => 'environmental science',
            'networks' => 'computer networks', 'networking' => 'computer networks', 'net' => 'computer networks',
            'security' => 'cyber security', 'cybersecurity' => 'cyber security', 'infosec' => 'cyber security', 'sec' => 'cyber security',
            'psych' => 'psychology',
            'management' => 'business', 'business management' => 'business', 'mba' => 'business', 'biz' => 'business',
            'electronics' => 'electrical engineering', 'circuits' => 'electrical engineering', 'ee' => 'electrical engineering',
            'astrophysics' => 'astronomy', 'astro' => 'astronomy'
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
    $academicWords = ['syllabus', 'curriculum', 'study', 'exam', 'board', '10th', '11th', '12th', 'samacheer', 'standard', 'subject', 'notes', 'quiz'];
    $isAcademic = false;
    foreach ($academicWords as $aw) {
        if (strpos($normQuery, $aw) !== false) {
            $isAcademic = true;
            break;
        }
    }

    if ($isAcademic) {
        $cleanTopic = ucwords(preg_replace('/(?i)^(what is|explain|tell me about|how does|what are|define)\s+/', '', $question));
        $p1 = "* **SYNAPSE AI Tamil Nadu State Board (Samacheer Kalvi) Study Companion**\n\n$cleanTopic is an important academic topic in 10th, 11th & 12th Standard core subjects. Mastering this concept equips students with rigorous conceptual grounding and problem-solving methodologies for board exams.";
        $p2 = "* **Core Study Framework & Key Principles:**\n\n• **Theoretical Foundations:** Review primary definitions, governing laws, and underlying assumptions for $cleanTopic.\n• **Analytical Derivations:** Break down complex multi-step problems and verify boundary conditions.\n• **Board Exam Application:** Focus on 2-mark definitions, 5-mark derivations, and 1-mark objective questions.\n\nWhich specific subject or chapter are you studying? Let me know your question and we'll break it down together!";

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $p1 . "\n\n" . $p2,
                'matched_subject' => 'Tamil Nadu State Board',
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
        $unclearReply = "Sorry, that's not relatable to our 10th, 11th & 12th studies! Ask me any subject concept, quiz, or notes.";
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
