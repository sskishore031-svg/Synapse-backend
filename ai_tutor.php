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
if (empty($rawInput) && php_sapi_name() === 'cli' && empty($_POST) && empty($_GET)) {
    $read = [STDIN];
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 0, 50000) > 0) {
        $rawInput = stream_get_contents(STDIN);
    }
}
$input = (!empty($rawInput)) ? (json_decode($rawInput, true) ?? []) : [];
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

    $typoMap = [
        'pyhon' => 'python', 'pyhton' => 'python', 'pythn' => 'python', 'phyton' => 'python', 'pthon' => 'python',
        'codding' => 'coding', 'cooding' => 'coding', 'progamming' => 'programming', 'programing' => 'programming',
        'algoritm' => 'algorithm', 'algorthm' => 'algorithm', 'recusion' => 'recursion', 'recurssion' => 'recursion',
        'funtion' => 'function', 'funciton' => 'function', 'stucture' => 'structure', 'dictonary' => 'dictionary',
        'lopp' => 'loop', 'looop' => 'loop', 'interv' => 'interview', 'placment' => 'placement'
    ];
    $rawNorm = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $question)));
    $rawWords = array_values(array_filter(preg_split('/\s+/', $rawNorm)));
    $cleanWords = array_map(function($w) use ($typoMap) { return $typoMap[$w] ?? $w; }, $rawWords);
    $normQuery = implode(' ', $cleanWords);

    // =========================================================================
    // STAGE 1: User Asking for Their Own Name ("say my name", "what is my name", "who am i")
    // =========================================================================
    $effectiveName = !empty($preferredName) ? $preferredName : (!empty($user['name']) ? trim($user['name']) : 'Kishore');

    $hasYourRef = in_array('your', $cleanWords, true) || in_array('ur', $cleanWords, true) || in_array('synapse', $cleanWords, true);

    $exactAskPhrases = [
        'say my name', 'what is my name', 'whats my name', 'what s my name',
        'tell my name', 'tell me my name', 'speak my name', 'call my name',
        'who am i', 'who i am', 'do you know my name', 'do u know my name',
        'can you say my name', 'can you tell my name', 'my name', 'my name please',
        'what is my name please', 'say my name please', 'tell my name please',
        'enoda name', 'ennoda name', 'en peru', 'en peyar'
    ];

    $askOwnNamePhrases = [
        'say my name', 'what is my name', 'whats my name', 'what s my name',
        'tell my name', 'tell me my name', 'speak my name', 'call my name',
        'who am i', 'who i am', 'do you know my name', 'do u know my name',
        'can you say my name', 'can you tell my name', 'my name please',
        'what is my name please', 'say my name please', 'tell my name please',
        'enoda name solu', 'enoda name sollu', 'ennoda name solu', 'ennoda name sollu',
        'enoda name enna', 'ennoda name enna', 'en per enna', 'en peru enna', 'en peyar enna',
        'en per solu', 'en peru solu', 'en peyar solu', 'enoda name theriyuma', 'my name enna',
        'naan yaaru', 'naan yaar', 'nan yaru'
    ];

    $isAskingOwnName = false;
    if (!$hasYourRef) {
        $isAskingOwnName = in_array($normQuery, $exactAskPhrases, true);
        if (!$isAskingOwnName) {
            foreach ($askOwnNamePhrases as $aop) {
                if (strpos($normQuery, $aop) !== false) {
                    $isAskingOwnName = true;
                    break;
                }
            }
        }

        if (!$isAskingOwnName && in_array('name', $cleanWords, true) && (in_array('my', $cleanWords, true) || (in_array('me', $cleanWords, true) && !in_array('call', $cleanWords, true)))) {
            if (in_array('say', $cleanWords, true) || in_array('tell', $cleanWords, true) || in_array('what', $cleanWords, true) || in_array('whats', $cleanWords, true) || in_array('know', $cleanWords, true) || in_array('speak', $cleanWords, true) || in_array('repeat', $cleanWords, true)) {
                $isAskingOwnName = true;
            }
        }
    }

    if ($isAskingOwnName) {
        if (!empty($effectiveName)) {
            $reply = "Your name is **$effectiveName**! 😊 How can I help you with your placement preparation today, $effectiveName?";
        } else {
            $reply = "I don't know your name yet! What should I call you? You can tell me *'My name is Kishore'* or *'Call me Kishore'*.";
        }
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'preferred_name' => $effectiveName,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // =========================================================================
    // STAGE 1.5: Dynamic Custom User Name / Nickname Memory & Name Setting
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

    // 1. Direct profile name edit commands: "edit the profile name sri sai to kishore", "change profile name to kishore", etc.
    if (preg_match('/(?:edit|change|set|update|rename)\s+(?:the\s+|my\s+)?(?:profile\s+name|profile|user\s+name|name)(?:\s+(?:from\s+)?[a-zA-Z\s]+)?\s+(?:to|as)\s+([a-zA-Z]+(?:\s+[a-zA-Z]+)?)/i', $question, $m)) {
        $candidate = ucwords(strtolower(trim($m[1])));
        $stopWords = ['me', 'my', 'name', 'profile', 'to', 'as', 'a', 'the', 'something', 'anything'];
        if (!in_array(strtolower($candidate), $stopWords, true) && strlen($candidate) >= 2) {
            $preferredName = $candidate;
            // Update in MySQL if userId or email present
            try {
                if (!empty($userId)) {
                    $uStmt = $pdo->prepare("UPDATE users SET name = :name WHERE id = :uid");
                    $uStmt->execute(['name' => $preferredName, 'uid' => $userId]);
                } elseif (!empty($email)) {
                    $uStmt = $pdo->prepare("UPDATE users SET name = :name WHERE email = :email");
                    $uStmt->execute(['name' => $preferredName, 'email' => $email]);
                }
            } catch (Exception $e) {}

            $reply = "✅ Done! Your profile name has been updated to $preferredName.";
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

    if (preg_match('/^(?:(?:hello|hi|hey)\s+)?(?:from\s+now\s+on\s+)?(?:call\s+me\s+(?:by\s+my\s+name\s+|by\s+name\s+|with\s+my\s+name\s+)?|my\s+name\s+(?:is\s+)?|name\s+is\s+|i\s+am\s+|i[\'’]m\s+|myself\s+|this\s+is\s+)([a-zA-Z]+(?:\s+[a-zA-Z]+)?)/i', $question, $m)) {
        $candidate = ucwords(strtolower(trim($m[1])));
        if (!in_array(strtolower($candidate), ['by', 'my', 'name', 'a', 'an', 'the', 'what', 'who', 'say', 'me'], true) && strlen($candidate) >= 2) {
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

    $tanglishStopWords = ['solu', 'sollu', 'solunga', 'enna', 'theriyuma', 'kupdu', 'koopdu', 'nu', 'endru'];
    if (preg_match('/^(?:enoda|ennoda|en)\s+(?:name|per|peyar)(?:\s+is)?\s+([a-zA-Z]+)/i', $question, $m)) {
        $candidate = ucfirst(strtolower($m[1]));
        if (!in_array(strtolower($candidate), $tanglishStopWords, true)) {
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

    if (preg_match('/(?:enakku|enna|inimel\s+enna|inimel)?\s*([a-zA-Z]+)\s*(?:nu|endru)\s*(?:kupdu|koopdu|kollu)/i', $question, $m)) {
        $candidate = ucfirst(strtolower($m[1]));
        if (!in_array(strtolower($candidate), ['enna', 'enakku', 'inimel', 'name', 'solu', 'sollu'], true)) {
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
        'no formula no example', 'no formulas and examples', 'explain without formula and example'
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

    // Wellbeing & Greeting Responses
    $wellBeingReply = "I'm doing great, thanks for asking! 😊 Ready to help you practice coding, SQL, AI, or aptitude. What would you like to learn today?";
    $greetingReply = "Hello! 👋 How can I help you with your placement prep today?";
    $gmReply = "Good morning! Ready to learn something exciting today? What subject are we tackling?";
    $gaReply = "Good afternoon! Hope you're having a productive day. What subject shall we explore?";
    $geReply = "Good evening! Let's do some focused study tonight. What can I help you understand?";
    $gnReply = "Good night! Rest well and keep learning tomorrow.";

    $smallTalk = [
        'how are you' => $wellBeingReply,
        'how are u' => $wellBeingReply,
        'how r you' => $wellBeingReply,
        'how r u' => $wellBeingReply,
        'hru' => $wellBeingReply,
        'hows it going' => $wellBeingReply,
        'epdi iruka' => $wellBeingReply,
        'epadi iruka' => $wellBeingReply,
        'epdi irukinga' => $wellBeingReply,
        'epadi irukinga' => $wellBeingReply,
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
        'who made you' => "I was built by the SYNAPSE AI team to be your personal tech career & placement study companion!",
        'are you smart' => "I know Python, SQL, Modern AI, and Aptitude inside and out, and my goal is helping you excel in technical placement exams and interviews!",
        'hello friend' => $greetingReply,
        'hi friend' => $greetingReply,
        'hi' => $greetingReply,
        'hello' => $greetingReply,
        'hey' => $greetingReply,
        'vanakkam' => $greetingReply,
        'namaste' => "Namaste! 🙏 How can I help you with your placement prep today?"
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
    // STAGE 3A: Direct Full-Form & Abbreviation Solver
    // =========================================================================
    $isAskingFullForm = strpos($normQuery, 'full form') !== false || strpos($normQuery, 'fullform') !== false ||
        strpos($normQuery, 'stands for') !== false || strpos($normQuery, 'stand for') !== false ||
        (strpos($normQuery, 'what is ') === 0 && (substr($normQuery, -10) === ' stand for' || substr($normQuery, -13) === ' abbreviation'));

    if ($isAskingFullForm) {
        $ffMap = [
            'ai' => ['acronym' => 'AI', 'full' => 'Artificial Intelligence', 'desc' => 'Artificial Intelligence is the simulation of human intelligence by computer systems, enabling machines to learn from experience, reason, and solve complex problems autonomously.', 'sub' => 'AI & Modern Tech Concepts'],
            'sql' => ['acronym' => 'SQL', 'full' => 'Structured Query Language', 'desc' => 'Structured Query Language is the standard domain-specific language used for storing, manipulating, querying, and managing relational databases.', 'sub' => 'SQL & Database Management'],
            'dbms' => ['acronym' => 'DBMS', 'full' => 'Database Management System', 'desc' => 'A Database Management System is system software for creating, storing, managing, and retrieving structured data efficiently from databases.', 'sub' => 'SQL & Database Management'],
            'rdbms' => ['acronym' => 'RDBMS', 'full' => 'Relational Database Management System', 'desc' => 'A Relational Database Management System is a database management system based on the relational model, storing data in tables of rows and columns with foreign key relations.', 'sub' => 'SQL & Database Management'],
            'oop' => ['acronym' => 'OOP', 'full' => 'Object-Oriented Programming', 'desc' => 'Object-Oriented Programming is a programming paradigm based on the concept of "objects" containing data fields and code methods, built on Encapsulation, Abstraction, Inheritance, and Polymorphism.', 'sub' => 'Python & Coding Logic'],
            'oops' => ['acronym' => 'OOPS', 'full' => 'Object-Oriented Programming System', 'desc' => 'Object-Oriented Programming System is a programming paradigm structured around reusable objects and classes, providing modularity and real-world domain modeling.', 'sub' => 'Python & Coding Logic'],
            'api' => ['acronym' => 'API', 'full' => 'Application Programming Interface', 'desc' => 'An Application Programming Interface is a set of defined rules and protocols that allow different software applications to communicate and exchange data with each other.', 'sub' => 'AI & Modern Tech Concepts'],
            'ram' => ['acronym' => 'RAM', 'full' => 'Random Access Memory', 'desc' => 'Random Access Memory is volatile system memory that provides fast read and write access for programs actively being executed by the CPU.', 'sub' => 'Aptitude & Interview Prep'],
            'rom' => ['acronym' => 'ROM', 'full' => 'Read-Only Memory', 'desc' => 'Read-Only Memory is non-volatile memory that permanently stores firmware and boot instructions required to start up computing hardware.', 'sub' => 'Aptitude & Interview Prep'],
            'http' => ['acronym' => 'HTTP', 'full' => 'Hypertext Transfer Protocol', 'desc' => 'Hypertext Transfer Protocol is the foundational application-layer protocol used for transmitting hypermedia documents, such as HTML web pages, across the World Wide Web.', 'sub' => 'AI & Modern Tech Concepts'],
            'https' => ['acronym' => 'HTTPS', 'full' => 'Hypertext Transfer Protocol Secure', 'desc' => 'Hypertext Transfer Protocol Secure is an extension of HTTP encrypted using Transport Layer Security (TLS/SSL) for secure bidirectional communication over networks.', 'sub' => 'AI & Modern Tech Concepts'],
            'rag' => ['acronym' => 'RAG', 'full' => 'Retrieval-Augmented Generation', 'desc' => 'Retrieval-Augmented Generation is an AI architecture that enhances Large Language Models by dynamically retrieving relevant facts from an external knowledge base before generating responses.', 'sub' => 'AI & Modern Tech Concepts'],
            'llm' => ['acronym' => 'LLM', 'full' => 'Large Language Model', 'desc' => 'A Large Language Model is an advanced deep learning transformer neural network trained on massive datasets to understand, summarize, and generate human-like text and code.', 'sub' => 'AI & Modern Tech Concepts'],
            'llms' => ['acronym' => 'LLMs', 'full' => 'Large Language Models', 'desc' => 'Large Language Models are deep learning transformer models trained on billions of parameters to comprehend, reason, and generate human-like language across tasks.', 'sub' => 'AI & Modern Tech Concepts'],
            'cpu' => ['acronym' => 'CPU', 'full' => 'Central Processing Unit', 'desc' => 'The Central Processing Unit is the primary hardware component that interprets, processes, and executes arithmetic, logical, and control instructions within a computer.', 'sub' => 'Aptitude & Interview Prep'],
            'gpu' => ['acronym' => 'GPU', 'full' => 'Graphics Processing Unit', 'desc' => 'A Graphics Processing Unit is a specialized parallel processor designed to rapidly manipulate memory and accelerate image rendering, 3D graphics, and deep neural network matrix computations.', 'sub' => 'AI & Modern Tech Concepts'],
            'acid' => ['acronym' => 'ACID', 'full' => 'Atomicity, Consistency, Isolation, Durability', 'desc' => 'ACID is a set of four critical properties that guarantee database transactions are processed reliably, maintaining data integrity even during system failures.', 'sub' => 'SQL & Database Management'],
            'ddl' => ['acronym' => 'DDL', 'full' => 'Data Definition Language', 'desc' => 'Data Definition Language comprises SQL commands (such as CREATE, ALTER, DROP, and TRUNCATE) that define and modify the schema structure of database objects.', 'sub' => 'SQL & Database Management'],
            'dml' => ['acronym' => 'DML', 'full' => 'Data Manipulation Language', 'desc' => 'Data Manipulation Language consists of SQL commands (such as INSERT, UPDATE, and DELETE) used for managing and modifying the data stored within database tables.', 'sub' => 'SQL & Database Management'],
            'dql' => ['acronym' => 'DQL', 'full' => 'Data Query Language', 'desc' => 'Data Query Language consists of SQL commands (primarily SELECT) used to query, retrieve, and filter data from database tables without modifying them.', 'sub' => 'SQL & Database Management'],
            'tcl' => ['acronym' => 'TCL', 'full' => 'Transaction Control Language', 'desc' => 'Transaction Control Language consists of SQL commands (such as COMMIT, ROLLBACK, and SAVEPOINT) used to manage transactions and maintain database consistency.', 'sub' => 'SQL & Database Management'],
            'dcl' => ['acronym' => 'DCL', 'full' => 'Data Control Language', 'desc' => 'Data Control Language comprises SQL commands (such as GRANT and REVOKE) that control access privileges and security permissions in a database system.', 'sub' => 'SQL & Database Management'],
            'dsa' => ['acronym' => 'DSA', 'full' => 'Data Structures and Algorithms', 'desc' => 'Data Structures and Algorithms is the fundamental computer science discipline focusing on organizing data efficiently and designing step-by-step computational procedures to solve problems.', 'sub' => 'Python & Coding Logic'],
            'rest' => ['acronym' => 'REST', 'full' => 'Representational State Transfer', 'desc' => 'Representational State Transfer is an architectural style for networked systems using stateless HTTP operations (GET, POST, PUT, DELETE) to manage resources represented in formats like JSON.', 'sub' => 'AI & Modern Tech Concepts'],
            'json' => ['acronym' => 'JSON', 'full' => 'JavaScript Object Notation', 'desc' => 'JavaScript Object Notation is a lightweight, human-readable, language-independent data-interchange format structured in key-value pairs and arrays.', 'sub' => 'Python & Coding Logic'],
            'jvm' => ['acronym' => 'JVM', 'full' => 'Java Virtual Machine', 'desc' => 'The Java Virtual Machine is an abstract computing machine that enables a computer to execute compiled Java bytecode, providing platform independence across operating systems.', 'sub' => 'Aptitude & Interview Prep'],
            'jdk' => ['acronym' => 'JDK', 'full' => 'Java Development Kit', 'desc' => 'The Java Development Kit is a comprehensive software development environment that includes the compiler (javac), tools, and runtime environment needed to develop Java applications.', 'sub' => 'Aptitude & Interview Prep'],
            'jre' => ['acronym' => 'JRE', 'full' => 'Java Runtime Environment', 'desc' => 'The Java Runtime Environment is the software package containing the JVM, core class libraries, and supporting files required to run Java applications.', 'sub' => 'Aptitude & Interview Prep'],
            'os' => ['acronym' => 'OS', 'full' => 'Operating System', 'desc' => 'An Operating System is system software that manages computer hardware, memory, process execution, and system resources while providing common services for application programs.', 'sub' => 'Aptitude & Interview Prep'],
            'lan' => ['acronym' => 'LAN', 'full' => 'Local Area Network', 'desc' => 'A Local Area Network is a computer network that interconnects computers within a limited geographical area, such as a school, laboratory, office building, or home.', 'sub' => 'AI & Modern Tech Concepts'],
            'wan' => ['acronym' => 'WAN', 'full' => 'Wide Area Network', 'desc' => 'A Wide Area Network is a telecommunications network that extends over a large geographic distance, often connecting multiple smaller networks like LANs across cities or countries.', 'sub' => 'AI & Modern Tech Concepts'],
            'ip' => ['acronym' => 'IP', 'full' => 'Internet Protocol', 'desc' => 'Internet Protocol is the principal network-layer communications protocol in the Internet protocol suite for relaying datagrams across network boundaries using IP addresses.', 'sub' => 'AI & Modern Tech Concepts'],
            'tcp' => ['acronym' => 'TCP', 'full' => 'Transmission Control Protocol', 'desc' => 'Transmission Control Protocol is a reliable, connection-oriented transport-layer protocol that ensures complete, sequenced, and error-checked delivery of byte streams between hosts.', 'sub' => 'AI & Modern Tech Concepts'],
            'udp' => ['acronym' => 'UDP', 'full' => 'User Datagram Protocol', 'desc' => 'User Datagram Protocol is a lightweight, connectionless transport-layer protocol prioritizing low-latency transmission over delivery guarantees, commonly used in streaming and gaming.', 'sub' => 'AI & Modern Tech Concepts'],
            'dns' => ['acronym' => 'DNS', 'full' => 'Domain Name System', 'desc' => 'The Domain Name System is the hierarchical naming system that translates human-readable domain names into numerical IP addresses needed to locate network services.', 'sub' => 'AI & Modern Tech Concepts'],
            'ml' => ['acronym' => 'ML', 'full' => 'Machine Learning', 'desc' => 'Machine Learning is a subset of artificial intelligence where computer algorithms learn patterns directly from data to make predictions or decisions without explicit programming.', 'sub' => 'AI & Modern Tech Concepts'],
            'dl' => ['acronym' => 'DL', 'full' => 'Deep Learning', 'desc' => 'Deep Learning is a specialized branch of machine learning based on multi-layered artificial neural networks capable of learning hierarchical feature representations from large-scale data.', 'sub' => 'AI & Modern Tech Concepts'],
            'nlp' => ['acronym' => 'NLP', 'full' => 'Natural Language Processing', 'desc' => 'Natural Language Processing is a field of artificial intelligence and computational linguistics focused on enabling computers to understand, interpret, and generate human language.', 'sub' => 'AI & Modern Tech Concepts']
        ];

        foreach ($cleanWords as $w) {
            if (isset($ffMap[$w])) {
                $item = $ffMap[$w];
                $reply = "**{$item['acronym']} stands for {$item['full']}.**\n\n{$item['desc']}";
                echo json_encode([
                    'status' => 'success',
                    'success' => true,
                    'data' => [
                        'question' => $question,
                        'paragraph1' => $reply,
                        'paragraph2' => '',
                        'answer' => $reply,
                        'matched_subject' => $item['sub'],
                        'matched_chapter' => 'Fundamentals',
                        'matched_topic' => "{$item['acronym']} Full Form",
                        'confidence_score' => 100.0,
                        'tutor_name' => 'SYNAPSE AI',
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                exit();
            }
        }
    }

    // =========================================================================
    // STAGE 3B: Dynamic SQL Query Generator
    // =========================================================================
    $cleanSql = preg_replace([
        '/\bcrete\b/', '/\bwnat\b/', '/\bdelet\b/', '/\btabl\b/', '/\bquerry\b/',
        '/\bquerey\b/', '/\binser\b/', '/\budpate\b/', '/\bslect\b/', '/\bselct\b/'
    ], [
        'create', 'want', 'delete', 'table', 'query',
        'query', 'insert', 'update', 'select', 'select'
    ], $normQuery);

    // Extract table name
    $tableName = 'students';
    if (preg_match('/(?:create|insert\s+into|update|delete\s+from|alter\s+table|drop\s+table|truncate\s+table|table)\s+(?:a\s+|new\s+|the\s+)?([a-zA-Z0-9_]+)\s+table/i', $cleanSql, $tm)) {
        $candidate = strtolower(trim($tm[1]));
        if (strlen($candidate) >= 3 && !in_array($candidate, ['new', 'table', 'database', 'query', 'create', 'insert', 'update', 'delete'], true)) {
            $tableName = $candidate;
        }
    } elseif (preg_match('/(?:into|from)\s+([a-zA-Z0-9_]+)/i', $cleanSql, $tm)) {
        $candidate = strtolower(trim($tm[1]));
        if (strlen($candidate) >= 3 && !in_array($candidate, ['table', 'database', 'query'], true)) {
            $tableName = $candidate;
        }
    }

    // 1. "what is query"
    if ($cleanSql === 'what is query' || $cleanSql === 'what is a query' || $cleanSql === 'define query' || $cleanSql === 'what query' || $cleanSql === 'query definition') {
        $reply = "An **SQL Query** is a formal declarative command or instruction sent to a Relational Database Management System (RDBMS) to perform operations on structured data, such as retrieving, inserting, updating, or deleting records.\n\n" .
            "* **Core SQL Query Categories:**\n" .
            "• **DQL (Data Query Language):** `SELECT` statements to query, filter, aggregate, and project table data.\n" .
            "• **DML (Data Manipulation Language):** `INSERT`, `UPDATE`, `DELETE` to modify row-level records.\n" .
            "• **DDL (Data Definition Language):** `CREATE`, `ALTER`, `DROP`, `TRUNCATE` to define and modify table schema objects.\n" .
            "• **TCL & DCL:** `COMMIT`, `ROLLBACK`, `GRANT`, `REVOKE` for transaction durability and access security.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'matched_subject' => 'SQL & Database Management',
                'matched_chapter' => 'SQL Fundamentals',
                'matched_topic' => 'SQL Query Fundamentals',
                'confidence_score' => 100.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // 2. 2nd highest salary
    if (strpos($cleanSql, 'second highest salary') !== false || strpos($cleanSql, '2nd highest salary') !== false || strpos($cleanSql, 'nth highest salary') !== false || strpos($cleanSql, '2nd highest') !== false) {
        $reply = "Here are the top 2 SQL queries to find the **Second Highest Salary** (Most Frequent Placement Interview Question):\n\n" .
            "```sql\n" .
            "-- Method 1: Subquery (ANSI Standard SQL - Supported by all RDBMS)\n" .
            "SELECT MAX(salary) AS second_highest_salary\n" .
            "FROM employees\n" .
            "WHERE salary < (SELECT MAX(salary) FROM employees);\n\n" .
            "-- Method 2: LIMIT & OFFSET (MySQL, PostgreSQL, SQLite)\n" .
            "SELECT DISTINCT salary\n" .
            "FROM employees\n" .
            "ORDER BY salary DESC\n" .
            "LIMIT 1 OFFSET 1;\n" .
            "```\n\n" .
            "* **Interview Key Points:**\n" .
            "• Method 1 filters out the global maximum salary, then picks the highest remaining value.\n" .
            "• Method 2 sorts unique salaries in descending order, skips the first highest (`OFFSET 1`), and takes the next (`LIMIT 1`). Use `DISTINCT` to handle duplicate salaries correctly!";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'matched_subject' => 'SQL & Database Management',
                'matched_chapter' => 'Advanced Queries',
                'matched_topic' => 'Second Highest Salary Query',
                'confidence_score' => 100.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // 3. CREATE TABLE
    $isCreateTable = (strpos($cleanSql, 'create') !== false && strpos($cleanSql, 'table') !== false) ||
        strpos($cleanSql, 'new table') !== false || strpos($cleanSql, 'create student table') !== false ||
        (strpos($cleanSql, 'how to create') !== false && (strpos($cleanSql, 'table') !== false || strpos($cleanSql, 'database') !== false)) ||
        strpos($cleanSql, 'query for create') !== false || strpos($cleanSql, 'query to create') !== false;
    if ($isCreateTable && strpos($cleanSql, 'alter') === false && strpos($cleanSql, 'drop') === false) {
        $reply = "Here is the standard SQL query to create the **`$tableName`** table:\n\n" .
            "```sql\n" .
            "CREATE TABLE $tableName (\n" .
            "    id INT PRIMARY KEY AUTO_INCREMENT,\n" .
            "    name VARCHAR(100) NOT NULL,\n" .
            "    email VARCHAR(100) UNIQUE,\n" .
            "    marks INT,\n" .
            "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n" .
            ");\n" .
            "```\n\n" .
            "* **Query Breakdown:**\n" .
            "• **`id INT PRIMARY KEY AUTO_INCREMENT`**: Unique identifier for each record that auto-increments by 1.\n" .
            "• **`name VARCHAR(100) NOT NULL`**: Mandatory name attribute supporting up to 100 characters; cannot be NULL.\n" .
            "• **`email VARCHAR(100) UNIQUE`**: Guarantees no two records can store duplicate email addresses.\n" .
            "• **`marks INT`**: Integer column to store scores or grades.\n" .
            "• **`created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`**: Automatically timestamps record creation.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'matched_subject' => 'SQL & Database Management',
                'matched_chapter' => 'DDL Commands',
                'matched_topic' => 'CREATE TABLE Syntax',
                'confidence_score' => 100.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // 4. DELETE
    if (strpos($cleanSql, 'delete query') !== false || strpos($cleanSql, 'how to delete') !== false || (strpos($cleanSql, 'delete') !== false && strpos($cleanSql, 'table') !== false && strpos($cleanSql, 'truncate') === false)) {
        $reply = "Here is the SQL query to delete a record from **`$tableName`**:\n\n" .
            "```sql\n" .
            "DELETE FROM $tableName\n" .
            "WHERE id = 1;\n" .
            "```\n\n" .
            "* **DELETE vs TRUNCATE vs DROP (Important Placement Concept):**\n" .
            "• **`DELETE`**: DML command. Deletes specific rows identified by `WHERE`. Can be rolled back inside transactions. Slower on large tables.\n" .
            "• **`TRUNCATE`**: DDL command. Empties all rows instantly and resets the `AUTO_INCREMENT` counter to 1. Cannot be filtered by `WHERE` and cannot be rolled back.\n" .
            "• **`DROP`**: DDL command. Destroys the table schema, indexes, constraints, and all data permanently from the database.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'matched_subject' => 'SQL & Database Management',
                'matched_chapter' => 'DML Commands',
                'matched_topic' => 'DELETE Statement',
                'confidence_score' => 100.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // =========================================================================
    // STAGE 3B: Smart Code & Query Helper
    // =========================================================================
    $isQuestion = preg_match('/^(?:what is|what are|what|explain|tell me about|define|how does|why is|why|difference between|give me|show me|how to|how do|i want|can you|provide)/i', $question);
    if (!$isQuestion) {
        // Detect SQL Query (must look like actual SQL code, not natural language question)
        if (preg_match('/(?i)\b(SELECT\s+[\s\S]*?\s+FROM|INSERT\s+INTO\s+[a-zA-Z0-9_]+|UPDATE\s+[a-zA-Z0-9_]+\s+SET|DELETE\s+FROM\s+[a-zA-Z0-9_]+|CREATE\s+TABLE\s+[a-zA-Z0-9_]+)\b/', $question)) {
            $hasJoin = stripos($question, 'join') !== false;
            $hasGroupBy = stripos($question, 'group by') !== false;
            $hasHaving = stripos($question, 'having') !== false;
            $hasOrderBy = stripos($question, 'order by') !== false;
            $hasWhere = stripos($question, 'where') !== false;

            $bd = ["1. **Operation & Target Table:** Executes structured relational operation against database tables."];
            if ($hasJoin) $bd[] = "2. **Join Strategy:** Combines records across multiple tables using foreign key/relational predicates.";
            if ($hasWhere) $bd[] = "3. **Row Filtering (WHERE):** Filters rows before aggregation or projection.";
            if ($hasGroupBy) $bd[] = "4. **Aggregation (GROUP BY):** Groups matched rows into summary buckets applying aggregate metrics.";
            if ($hasHaving) $bd[] = "5. **Post-Aggregation Filter (HAVING):** Prunes aggregated groups based on summary thresholds.";
            if ($hasOrderBy) $bd[] = "6. **Projection & Sorting (ORDER BY):** Emits final selected attributes ordered by designated columns.";

            $timeComp = ($hasJoin && !$hasWhere)
                ? "O(M * N) worst-case without indexes (Hash/Merge join); O(M + N) with indexed join keys."
                : (($hasOrderBy && $hasGroupBy) ? "O(N log N) due to filesort / temporary table sorting." : ($hasWhere ? "O(log N) for B-Tree index range scans; O(N) for full table scans." : "O(N) sequential table scan."));
            $spaceComp = ($hasGroupBy || $hasOrderBy || $hasJoin) ? "O(K) auxiliary buffer memory for hash aggregation tables and sorting buffers." : "O(1) streaming cursor memory.";
            $edgeCases = "• **NULL Semantics:** In SQL, NULL = NULL evaluates to UNKNOWN, causing unexpected row omission in INNER JOINs.\n• **Cartesian Explosion:** Missing or non-unique JOIN conditions will trigger an accidental CROSS JOIN producing excessive rows.\n• **WHERE vs ON in OUTER JOINs:** Placing filters in the WHERE clause instead of ON turns a LEFT JOIN into an INNER JOIN.\n• **Aggregation on Empty Sets:** COUNT(*) returns 0, whereas SUM() and AVG() return NULL when 0 rows match.";

            $p1 = "* **SQL Query Logic Breakdown:**\n" . implode("\n", $bd);
            $p2 = "* **Time & Space Complexity:**\n• Time Complexity: $timeComp\n• Space Complexity: $spaceComp\n\n* **Placement Interview Edge Cases:**\n$edgeCases";

            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $p1,
                    'paragraph2' => $p2,
                    'answer' => $p1 . "\n\n" . $p2,
                    'matched_subject' => 'SQL & Database Management',
                    'matched_chapter' => 'Code & Query Helper',
                    'matched_topic' => 'SQL Query Analysis',
                    'confidence_score' => 99.0,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }

        // Detect Python Code
        $pythonMarkers = ['def ', 'class ', 'while ', 'try:', 'except ', 'lambda ', 'with open', 'import ', 'return ', 'yield '];
        $hasPython = false;
        foreach ($pythonMarkers as $pm) {
            if (stripos($question, $pm) !== false) {
                $hasPython = true;
                break;
            }
        }
        if (!$hasPython && preg_match('/\bfor\s+[a-zA-Z_]\w*\s+in\b/i', $question)) {
            $hasPython = true;
        }
        if (!$hasPython && (strpos($question, ' = [') !== false || strpos($question, ' = {') !== false || (substr_count($question, "\n") >= 1 && strpos($question, ':') !== false))) {
            $hasPython = true;
        }

        if ($hasPython && stripos($question, 'SELECT') === false) {
            $hasNested = preg_match('/for\s+.*?:[\s\S]*?for\s+.*?:/i', $question) || preg_match('/while\s+.*?:[\s\S]*?while\s+.*?:/i', $question);
            $hasSingle = stripos($question, 'for ') !== false || stripos($question, 'while ') !== false;
            $hasSorting = stripos($question, '.sort(') !== false || stripos($question, 'sorted(') !== false;
            $hasRecursion = preg_match('/def\s+(\w+)[\s\S]*?\1\s*\(/i', $question);
            $hasDict = stripos($question, 'dict(') !== false || stripos($question, 'set(') !== false || strpos($question, '{') !== false;

            $bd = ["1. **Declaration & Input Handling:** Prepares variables and validates function/block arguments."];
            if ($hasSingle || $hasNested) $bd[] = "2. **Iterative Traversal:** Loops through sequence elements maintaining state counters or pointers.";
            if ($hasDict) $bd[] = "3. **Hashing & Fast Lookup:** Utilizes hash table operations for O(1) average lookup/insert.";
            if ($hasRecursion) $bd[] = "4. **Recursive Call Stack:** Breaks problem down into smaller self-similar subproblems with base cases.";
            if (stripos($question, 'return ') !== false || stripos($question, 'yield ') !== false) $bd[] = "5. **Result Emission:** Returns calculated result or yields generator stream to caller.";

            $timeComp = $hasNested ? "O(N²) quadratic time due to nested loop iteration over data." : ($hasSorting ? "O(N log N) using Python's Timsort algorithm." : ($hasRecursion ? "O(2ⁿ) if un-memoized tree recursion; O(N) with dynamic programming / memoization." : ($hasSingle ? "O(N) linear time passing through the collection once." : "O(1) constant time execution.")));
            $spaceComp = $hasRecursion ? "O(D) call stack frame space, where D is maximum recursion depth." : ($hasDict ? "O(N) auxiliary space storing unique keys/elements." : "O(1) auxiliary space modifying or evaluating variables in-place.");
            $edgeCases = "• **Empty / None Inputs:** Check for `if not data: return ...` before accessing index 0 to avoid IndexError.\n• **Default Mutable Argument Anti-Pattern:** Avoid `def func(lst=[])`; default lists persist mutations across calls.\n• **Integer Division & Rounding:** Python 3 `/` returns float; use `//` for integer truncation in placement math.\n• **Off-by-One in Ranges:** Remember range(start, end) is exclusive of end.";

            $p1 = "* **Python Code Logic Breakdown:**\n" . implode("\n", $bd);
            $p2 = "* **Time & Space Complexity:**\n• Time Complexity: $timeComp\n• Space Complexity: $spaceComp\n\n* **Placement Interview Edge Cases:**\n$edgeCases";

            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $p1,
                    'paragraph2' => $p2,
                    'answer' => $p1 . "\n\n" . $p2,
                    'matched_subject' => 'Python & Coding Logic',
                    'matched_chapter' => 'Code & Query Helper',
                    'matched_topic' => 'Python Code Analysis',
                    'confidence_score' => 99.0,
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
        $boundary = "That falls outside my placement and technical scope. I'm SYNAPSE AI—built specifically to help you master Python, SQL, Modern AI concepts, and Placement Aptitude!";
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
    // STAGE 4A: Dedicated Coding & Python Logic Intent (ChatGPT / Gemini Grade)
    // =========================================================================
    $isGeneralCodingLogic = (strpos($normQuery, 'coding logic') !== false) ||
        (strpos($normQuery, 'python logic') !== false) ||
        (strpos($normQuery, 'programming logic') !== false) ||
        (strpos($normQuery, 'python') !== false && (strpos($normQuery, 'logic') !== false || strpos($normQuery, 'coding') !== false || strpos($normQuery, 'code') !== false || strpos($normQuery, 'basics') !== false)) ||
        in_array($normQuery, [
            'give me python and coding logic', 'python and coding logic', 'give me python logic',
            'give me coding logic', 'teach me python', 'python basics', 'python programming',
            'python coding', 'how to write logic', 'coding logic in python', 'python logic examples',
            'give me python', 'tell me python and coding logic', 'explain python and coding logic',
            'show me python and coding logic', 'give me pyhon and coding logic', 'pyhon and coding logic'
        ], true) ||
        (strpos($normQuery, 'logic') !== false && strpos($normQuery, 'code') !== false);

    if ($isGeneralCodingLogic) {
        $p1 = "### 🐍 Mastering Python & Coding Logic\n" .
            "*Python & Coding Logic · Foundational Problem Solving*\n\n" .
            "Coding logic is the thought process of breaking down a real-world objective into deterministic algorithmic steps that a computer can execute reliably and efficiently. In Python, constructing solid coding logic relies on **4 Core Structural Patterns**:\n\n" .
            "#### 1. Conditional & Decision-Making Logic (Control Flow)\n" .
            "Branching execution paths based on evaluated boolean expressions.\n\n" .
            "```python\n" .
            "def evaluate_candidate(tech_score: int, dsa_rounds: int) -> str:\n" .
            "    \"\"\"Evaluates placement interview outcome.\"\"\"\n" .
            "    if tech_score >= 85 and dsa_rounds >= 2:\n" .
            "        return \"Tier 1: SDE-1 Selected 🚀\"\n" .
            "    elif tech_score >= 70 or dsa_rounds >= 1:\n" .
            "        return \"Shortlisted: Second Technical Round\"\n" .
            "    else:\n" .
            "        return \"Needs Practice: Review Core Micro-Lessons\"\n\n" .
            "print(evaluate_candidate(88, 2))  # Tier 1: SDE-1 Selected 🚀\n" .
            "```\n\n" .
            "#### 2. Iteration & Data Transformation Logic (Loops & Accumulators)\n" .
            "Processing sequences cleanly with minimal time and memory overhead.\n\n" .
            "```python\n" .
            "# Filtering and transforming data in O(N) linear time\n" .
            "raw_salaries = [6, 12, 18, 8, 24, 15, 30]\n\n" .
            "# Idiomatic List Comprehension: O(N) Time\n" .
            "high_packages = [pkg * 1.15 for pkg in raw_salaries if pkg >= 12]\n" .
            "print(\"Adjusted High Packages:\", [round(p, 1) for p in high_packages])\n" .
            "# Output: [13.8, 20.7, 27.6, 17.2, 34.5]\n" .
            "```";

        $p2 = "#### 3. Algorithmic Hash-Map Logic (Frequency & Lookup Pattern)\n" .
            "Using dictionaries for amortized O(1) constant-time lookup and element tracking.\n\n" .
            "```python\n" .
            "def first_unique_char(s: str) -> str:\n" .
            "    \"\"\"Finds first non-repeating character in O(N) time.\"\"\"\n" .
            "    freq = {}\n" .
            "    for char in s:\n" .
            "        freq[char] = freq.get(char, 0) + 1\n" .
            "        \n" .
            "    for char in s:\n" .
            "        if freq[char] == 1:\n" .
            "            return char\n" .
            "    return \"None\"\n\n" .
            "print(\"First unique in 'swiss':\", first_unique_char(\"swiss\"))  # 'w'\n" .
            "```\n\n" .
            "#### 4. The 4 Golden Rules of Writing Coding Logic in Placement Interviews\n" .
            "1. **Understand Before Typing:** Clearly identify the input types, expected output, and edge cases (e.g., empty lists, negatives, single elements) before writing code.\n" .
            "2. **Decompose Problems:** Break complex tasks into smaller helper functions with a single responsibility.\n" .
            "3. **Trace with a Dry Run:** Manually trace your variables with a small sample input (e.g. `[1, 2, 3]`) across loop iterations to verify boundary conditions.\n" .
            "4. **Optimize Complexity:** Strive for O(N) or O(N log N) time complexity rather than nested brute-force O(N^2) loops.\n\n" .
            "*💡 What specific coding problem or logic would you like to build next? (e.g., Two Pointers, String Reversal, Binary Search, Palindrome, or Recursion?)*";

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $p1 . "\n\n" . $p2,
                'matched_subject' => 'Python & Coding Logic',
                'matched_chapter' => 'Algorithmic Problem Solving',
                'matched_topic' => 'Python & Coding Logic Principles',
                'confidence_score' => 99.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // =========================================================================
    // STAGE 4.5: Deep Technical Knowledge Base & Weighted Intent Scoring Engine
    // (150+ Deep Technical Topics Across All 8 Placement Subjects - 100% Self-Contained)
    // =========================================================================
    $cleanQuery = trim(preg_replace('/[^a-zA-Z0-9+\-=><\s]/', ' ', $normQuery));
    $cleanWords = array_values(array_filter(explode(' ', $cleanQuery)));

    $intentModifier = 'STANDARD';
    if (
        strpos($normQuery, 'definition only') !== false || strpos($normQuery, 'only definition') !== false ||
        strpos($normQuery, 'just definition') !== false || strpos($normQuery, '1 line') !== false ||
        strpos($normQuery, 'one line') !== false || strpos($normQuery, 'in short') !== false ||
        strpos($normQuery, 'shortly') !== false || strpos($normQuery, 'briefly') !== false ||
        strpos($normQuery, 'crisp definition') !== false ||
        (strpos($normQuery, 'i want') !== false && strpos($normQuery, 'definition') !== false && strpos($normQuery, 'example') === false) ||
        (strpos($normQuery, 'definition') !== false && strpos($normQuery, 'only') !== false)
    ) {
        $intentModifier = 'DEFINITION_ONLY';
    } elseif (
        strpos($normQuery, ' vs ') !== false || strpos($normQuery, 'versus') !== false ||
        strpos($normQuery, 'difference between') !== false || strpos($normQuery, 'differences between') !== false ||
        strpos($normQuery, 'compare ') !== false || strpos($normQuery, 'comparison between') !== false
    ) {
        $intentModifier = 'DIFFERENCE';
    } elseif (
        strpos($normQuery, 'query for') !== false || strpos($normQuery, 'give me query') !== false ||
        strpos($normQuery, 'syntax') !== false || strpos($normQuery, 'how to create') !== false ||
        strpos($normQuery, 'how to write') !== false || strpos($normQuery, 'how to make') !== false ||
        strpos($normQuery, 'code for') !== false || strpos($normQuery, 'code snippet') !== false ||
        strpos($normQuery, 'write query') !== false || strpos($normQuery, 'write a query') !== false ||
        strpos($normQuery, 'create query') !== false || strpos($normQuery, 'syntax for') !== false ||
        strpos($normQuery, 'query to') !== false ||
        (strpos($normQuery, 'how to') !== false && strpos($normQuery, 'work') === false) ||
        (strpos($normQuery, 'query') !== false && strpos($normQuery, 'what is query') === false && strpos($normQuery, 'define query') === false && strpos($normQuery, 'what query') === false)
    ) {
        $intentModifier = 'SYNTAX_OR_CODE';
    }

    $deepTopics = [
        [
            'id' => 'sql_database_dbms',
            'subject' => 'SQL & Database Management',
            'chapter' => 'Database Fundamentals',
            'topic' => 'Database & DBMS Architecture',
            'primaryKeywords' => ['database', 'databases', 'dbms', 'rdbms', 'what is database', 'database definition', 'define database', 'what is dbms', 'database definition only', 'i want database definition only'],
            'secondaryKeywords' => ['relational', 'storage', 'data storage', 'acid', 'mysql', 'postgresql'],
            'crispDefinition' => 'A **Database** is an organized, structured collection of electronically stored data designed for rapid search, retrieval, and updates. A **Database Management System (DBMS)** is system software (e.g. MySQL, PostgreSQL, Oracle) that serves as the interface between the database, users, and application programs, guaranteeing data security, integrity, and concurrent transaction control.',
            'keyPoints' => "• **Structured Storage:** Tables organize data into rows (records/tuples) and columns (attributes/fields).\n• **ACID Compliance:** Ensures reliable transaction processing even in the event of hardware failure.\n• **DBMS vs File System:** Prevents data redundancy, eliminates inconsistent states, and enforces multi-user access control.",
            'codeExample' => "```sql\n-- Create and use a new logical database container\nCREATE DATABASE placement_portal;\nUSE placement_portal;\n```",
            'comparison' => null
        ],
        [
            'id' => 'sql_query',
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL Fundamentals',
            'topic' => 'SQL Query Fundamentals',
            'primaryKeywords' => ['what is query', 'query', 'sql query', 'define query', 'what is a query', 'sql queries', 'database query'],
            'secondaryKeywords' => ['ddl', 'dml', 'dql', 'dcl', 'tcl', 'select', 'statement', 'command'],
            'crispDefinition' => 'A **SQL Query** is a declarative instruction executed against a relational database to retrieve, filter, manipulate, or structure stored data. Unlike procedural code, SQL describes *what* data is desired rather than specifying the low-level algorithmic search procedure.',
            'keyPoints' => "• **DQL (Data Query Language):** `SELECT` for querying and reading stored rows.\n• **DML (Data Manipulation Language):** `INSERT`, `UPDATE`, `DELETE` for modifying records.\n• **DDL (Data Definition Language):** `CREATE`, `ALTER`, `DROP`, `TRUNCATE` for modifying table schemas.\n• **TCL (Transaction Control Language):** `COMMIT`, `ROLLBACK`, `SAVEPOINT` for transaction safety.\n• **DCL (Data Control Language):** `GRANT`, `REVOKE` for permissions and security.",
            'codeExample' => "```sql\n-- Sample DQL Query filtering high-performing students\nSELECT student_id, full_name, gpa\nFROM students\nWHERE gpa >= 8.5\nORDER BY gpa DESC;\n```",
            'comparison' => null
        ],
        [
            'id' => 'sql_create_table',
            'subject' => 'SQL & Database Management',
            'chapter' => 'DDL Commands',
            'topic' => 'CREATE TABLE Syntax & Schema Definition',
            'primaryKeywords' => [
                'create table', 'new table', 'create a new table', 'create new table',
                'how to create a new table in database', 'give me query for create new table',
                'query to create table', 'syntax for create table', 'sql create table'
            ],
            'secondaryKeywords' => ['primary key', 'foreign key', 'varchar', 'int', 'not null', 'schema'],
            'crispDefinition' => 'The **CREATE TABLE** statement in SQL establishes a new table schema inside a database, specifying column names, appropriate data types (`INT`, `VARCHAR`, `DECIMAL`, `DATE`), and integrity constraints (`PRIMARY KEY`, `NOT NULL`, `UNIQUE`, `FOREIGN KEY`, `DEFAULT`).',
            'keyPoints' => "• **PRIMARY KEY:** Uniquely identifies each row; automatically enforces `UNIQUE` and `NOT NULL`.\n• **NOT NULL:** Prohibits missing or null values from being inserted into required columns.\n• **UNIQUE:** Guarantees no two records contain identical values in the column.\n• **FOREIGN KEY:** Enforces referential integrity by referencing the primary key of another table.\n• **DEFAULT:** Supplies a fallback value when no value is explicitly provided on insertion.",
            'codeExample' => "```sql\n-- SQL CREATE TABLE Syntax & Working Example\nCREATE TABLE employees (\n    emp_id INT AUTO_INCREMENT PRIMARY KEY,\n    first_name VARCHAR(50) NOT NULL,\n    email VARCHAR(100) UNIQUE NOT NULL,\n    salary DECIMAL(10, 2) NOT NULL DEFAULT 30000.00,\n    dept_id INT NOT NULL,\n    hire_date DATE NOT NULL,\n    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n    FOREIGN KEY (dept_id) REFERENCES departments(dept_id) ON DELETE CASCADE\n);\n```",
            'comparison' => null
        ],
        [
            'id' => 'sql_delete_truncate_drop',
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL Commands',
            'topic' => 'DELETE vs TRUNCATE vs DROP',
            'primaryKeywords' => ['delete vs truncate vs drop', 'difference between delete truncate and drop', 'truncate vs drop', 'delete vs truncate'],
            'secondaryKeywords' => ['ddl vs dml', 'rollback', 'table delete', 'where clause'],
            'crispDefinition' => '**DELETE** is a DML command that removes specific rows filtered by a `WHERE` clause and can be rolled back. **TRUNCATE** is a DDL command that deallocates all table pages to empty the table rapidly without logging row deletions. **DROP** is a DDL command that removes both the table data AND the entire table schema permanently from disk.',
            'keyPoints' => "• **Category:** `DELETE` = DML | `TRUNCATE` = DDL | `DROP` = DDL\n• **WHERE Clause:** Allowed only in `DELETE`; `TRUNCATE` and `DROP` affect the whole entity.\n• **Rollback:** `DELETE` can be rolled back in transactions; `TRUNCATE` and `DROP` auto-commit in most engines.\n• **Speed:** `TRUNCATE` is significantly faster than `DELETE` because it deallocates data pages instead of logging row-by-row deletions.",
            'codeExample' => "```sql\nDELETE FROM employees WHERE status = 'INACTIVE';\nTRUNCATE TABLE employees;\nDROP TABLE employees;\n```",
            'comparison' => "• **DELETE:** DML | Removes filtered rows | WHERE allowed | Rollbackable | Slower\n• **TRUNCATE:** DDL | Removes ALL rows | No WHERE | Cannot rollback | Resets identity | Very Fast\n• **DROP:** DDL | Erases schema + data | No WHERE | Table destroyed | Frees disk space"
        ],
        [
            'id' => 'sql_joins',
            'subject' => 'SQL & Database Management',
            'chapter' => 'Relational Operations',
            'topic' => 'SQL Joins (Inner, Left, Right, Full)',
            'primaryKeywords' => ['sql joins', 'joins in sql', 'inner join vs left join', 'left join', 'right join', 'full join', 'cross join'],
            'secondaryKeywords' => ['join', 'on clause', 'relational algebra', 'cartesian product'],
            'crispDefinition' => 'A **SQL JOIN** combines columns from two or more tables based on a related column between them (typically primary and foreign keys). Common types are **INNER JOIN** (only matching rows), **LEFT JOIN** (all left rows + matching right rows), **RIGHT JOIN** (all right rows + matching left rows), and **FULL OUTER JOIN** (all rows from both).',
            'keyPoints' => "• **INNER JOIN:** Returns records where the join predicate matches in both tables.\n• **LEFT (OUTER) JOIN:** Retains all rows from the left table; unmatched right columns return `NULL`.\n• **RIGHT (OUTER) JOIN:** Retains all rows from the right table; unmatched left columns return `NULL`.\n• **CROSS JOIN:** Produces the Cartesian product of all rows (\$M \\times \$N).",
            'codeExample' => "```sql\n-- INNER JOIN\nSELECT s.full_name, d.dept_name\nFROM students s\nINNER JOIN departments d ON s.dept_id = d.dept_id;\n```",
            'comparison' => null
        ],
        [
            'id' => 'sql_normalization',
            'subject' => 'SQL & Database Management',
            'chapter' => 'Database Design',
            'topic' => 'Database Normalization (1NF to BCNF)',
            'primaryKeywords' => ['normalization', '1nf', '2nf', '3nf', 'bcnf', 'normal forms', 'what is normalization'],
            'secondaryKeywords' => ['functional dependency', 'anomalies', 'transitive dependency', 'partial dependency'],
            'crispDefinition' => '**Normalization** is a systematic database design technique that decomposes large, redundant tables into smaller, well-structured relations to eliminate insertion, update, and deletion anomalies while maintaining data integrity.',
            'keyPoints' => "• **1NF:** Atomic (indivisible) values; no repeating groups.\n• **2NF:** In 1NF and no partial functional dependencies.\n• **3NF:** In 2NF and no transitive dependencies (\$A \\to \$B, \$B \\to \$C).\n• **BCNF:** For every functional dependency \$X \\to \$Y, \$X must be a super key.",
            'codeExample' => "```sql\nCREATE TABLE courses (course_id INT PRIMARY KEY, course_name VARCHAR(50), credits INT);\nCREATE TABLE student_enrollments (student_id INT, course_id INT, grade CHAR(2), PRIMARY KEY (student_id, course_id));\n```",
            'comparison' => null
        ],
        [
            'id' => 'sql_acid',
            'subject' => 'SQL & Database Management',
            'chapter' => 'Transactions',
            'topic' => 'ACID Properties in Transactions',
            'primaryKeywords' => ['acid', 'acid properties', 'atomicity', 'consistency', 'isolation', 'durability'],
            'secondaryKeywords' => ['transaction', 'commit', 'rollback', 'wal'],
            'crispDefinition' => '**ACID** represents the four fundamental guarantees of database transaction processing: **Atomicity** (all or nothing), **Consistency** (state transitions preserve constraints), **Isolation** (concurrent transactions do not interfere), and **Durability** (committed data survives crashes).',
            'keyPoints' => "• **Atomicity:** All statements succeed or entire transaction is rolled back.\n• **Consistency:** Database satisfies all schema constraints across states.\n• **Isolation:** Concurrency isolation levels prevent dirty/phantom reads.\n• **Durability:** Committed transactions survive system crashes.",
            'codeExample' => "```sql\nSTART TRANSACTION;\nUPDATE accounts SET balance = balance - 500 WHERE account_id = 101;\nUPDATE accounts SET balance = balance + 500 WHERE account_id = 102;\nCOMMIT;\n```",
            'comparison' => null
        ],
        [
            'id' => 'sql_second_highest_salary',
            'subject' => 'SQL & Database Management',
            'chapter' => 'Advanced Queries',
            'topic' => 'Second Highest Salary Query',
            'primaryKeywords' => ['second highest salary', 'nth highest salary', '2nd highest salary', 'find second highest salary'],
            'secondaryKeywords' => ['dense_rank', 'limit offset', 'subquery', 'max salary'],
            'crispDefinition' => 'Finding the **Second Highest Salary** is a classic technical placement question. It can be solved using either a subquery with `MAX()`, the `LIMIT ... OFFSET` clause, or the ANSI standard `DENSE_RANK()` window function.',
            'keyPoints' => "• **Method 1 (Subquery):** `WHERE salary < (SELECT MAX(salary) FROM employees)`\n• **Method 2 (LIMIT OFFSET):** `ORDER BY salary DESC LIMIT 1 OFFSET 1`\n• **Method 3 (DENSE_RANK):** Best for handling ties without skipping ranks.",
            'codeExample' => "```sql\n-- Universal Subquery:\nSELECT MAX(salary) AS SecondHighestSalary\nFROM employees\nWHERE salary < (SELECT MAX(salary) FROM employees);\n```",
            'comparison' => null
        ],
        [
            'id' => 'python_definition',
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Python Fundamentals',
            'topic' => 'Python Fundamentals',
            'primaryKeywords' => ['python definition', 'what is python', 'define python', 'python programming', 'python language', 'explain python', 'python overview'],
            'secondaryKeywords' => ['interpreted', 'dynamically typed', 'high level', 'guido van rossum'],
            'crispDefinition' => '**Python** is a high-level, interpreted, dynamically typed programming language created by Guido van Rossum in 1991. It emphasizes exceptional code readability with clean indentation, automatic memory management via reference counting, and support for multi-paradigm programming (OOP, procedural, functional).',
            'keyPoints' => "• **Memory Model & Mutability:** Variables are object references in heap memory; mutable vs immutable types.\n• **Global Interpreter Lock (GIL):** Mutex ensuring single-thread bytecode execution in CPython.\n• **Interpreted:** Compiles source to bytecode (.pyc) executed by virtual machine.",
            'codeExample' => "```python\ndef calculate_grade(score: float) -> str:\n    return 'A' if score >= 90 else ('B' if score >= 75 else 'C')\nprint(calculate_grade(88.5))\n```",
            'comparison' => null
        ],
        [
            'id' => 'python_mutability',
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Data Structures & Memory',
            'topic' => 'Python Mutability & Pass-by-Object-Reference',
            'primaryKeywords' => ['mutability', 'mutable vs immutable', 'mutable', 'immutable', 'python memory model'],
            'secondaryKeywords' => ['id()', 'pass by object reference', 'list vs tuple', 'hashable'],
            'crispDefinition' => 'In Python, **mutable objects** (e.g., `list`, `dict`, `set`) can have their internal state altered in-place without changing their memory address (`id()`). **Immutable objects** (e.g., `int`, `float`, `str`, `tuple`) cannot be changed once allocated; any modification creates a brand-new object in memory.',
            'keyPoints' => "• **Mutable Types:** `list`, `dict`, `set` (unhashable; cannot be dict keys).\n• **Immutable Types:** `int`, `str`, `tuple` (hashable; safe as dict keys).\n• **Pass-by-Object-Reference:** Modifying mutable argument in-place inside function affects caller.",
            'codeExample' => "```python\na = [1, 2]\nb = a\nb.append(3)\nprint(a)  # [1, 2, 3] -> Both variables reference same list!\n```",
            'comparison' => null
        ],
        [
            'id' => 'python_list_vs_tuple',
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Data Structures & Memory',
            'topic' => 'List vs Tuple',
            'primaryKeywords' => ['list vs tuple', 'difference between list and tuple', 'compare list and tuple', 'tuples vs lists'],
            'secondaryKeywords' => ['mutable', 'immutable', 'performance', 'memory'],
            'crispDefinition' => 'In Python, a **List** is a **mutable** sequence enclosed in brackets `[]` that allows in-place modifications, whereas a **Tuple** is an **immutable** sequence enclosed in parentheses `()` that cannot be changed once created.',
            'keyPoints' => "• **Mutability:** Lists are mutable (dynamic allocation, items can be added/removed); Tuples are immutable (read-only fixed memory).\n• **Performance:** Tuples allocate less memory and execute faster than lists.\n• **Hashability:** Tuples can be dictionary keys or set elements if all items are immutable; lists can never be dictionary keys.",
            'codeExample' => "```python\na_list = [1, 2, 3]    # Mutable: a_list.append(4) works in-place\na_tuple = (1, 2, 3)   # Immutable: fixed size, hashable\n```",
            'comparison' => "• **Mutability:** Lists = mutable (can modify in-place) vs Tuples = immutable (state locked)\n• **Syntax:** Lists = `[1, 2]` vs Tuples = `(1, 2)`\n• **Memory:** Tuples use less memory block allocation than dynamically over-allocated lists\n• **Dictionary Keys:** Lists = Unhashable (cannot be keys) vs Tuples = Hashable (can be keys)"
        ],
        [
            'id' => 'python_decorators',
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Functions & Metaprogramming',
            'topic' => 'Python Decorators',
            'primaryKeywords' => ['decorator', 'decorators', 'python decorator', 'what is decorator', 'how decorator works'],
            'secondaryKeywords' => ['higher order function', 'functools.wraps', '*args', '**kwargs', 'wrapper'],
            'crispDefinition' => 'A **Python Decorator** is a higher-order function that takes another function as an argument, extends or wraps its behavior without directly modifying its source code, and returns the modified wrapper function (syntactically applied with `@decorator_name`).',
            'keyPoints' => "• **First-Class Functions:** Functions can be passed as arguments, assigned, and returned.\n• **Closure:** Inner wrapper retains access to enclosing function variables.\n• **Use Cases:** Logging, timing, authentication, rate limiting.",
            'codeExample' => "```python\ndef timer(func):\n    def wrapper(*args, **kwargs):\n        print('Running...')\n        return func(*args, **kwargs)\n    return wrapper\n```",
            'comparison' => null
        ],
        [
            'id' => 'python_generators',
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Functions & Iterators',
            'topic' => 'Generators & Iterators (yield vs return)',
            'primaryKeywords' => ['generator', 'generators', 'yield', 'yield vs return', 'iterator', 'iterators'],
            'secondaryKeywords' => ['__iter__', '__next__', 'lazy evaluation', 'memory efficient'],
            'crispDefinition' => 'A **Generator** is a special Python function that produces an iterator sequence using the `yield` statement. Unlike `return`, which terminates execution and dumps all values into memory at once, `yield` pauses the function state, yielding values lazily on-demand with $O(1)$ memory consumption.',
            'keyPoints' => "• **Lazy Evaluation:** Computes next item only when requested.\n• **Memory Efficiency:** Streaming millions of items uses mere bytes of RAM.\n• **State Preservation:** Local variables frozen between yields.",
            'codeExample' => "```python\ndef count_up(n):\n    i = 1\n    while i <= n:\n        yield i\n        i += 1\n```",
            'comparison' => null
        ],
        [
            'id' => 'ai_definition',
            'subject' => 'AI & Modern Tech Concepts',
            'chapter' => 'AI Foundations',
            'topic' => 'Artificial Intelligence & Modern AI Taxonomy',
            'primaryKeywords' => ['artificial intelligence', 'what is ai', 'define ai', 'ai definition', 'ai taxonomy', 'machine learning definition'],
            'secondaryKeywords' => ['ml vs dl', 'deep learning', 'neural network', 'narrow ai', 'agi'],
            'crispDefinition' => '**Artificial Intelligence (AI)** is the broad engineering discipline of creating software systems capable of performing cognitive tasks that traditionally require human intelligence—such as pattern recognition, language understanding, reasoning, problem-solving, and continuous learning from empirical data.',
            'keyPoints' => "• **Taxonomy Hierarchy:** AI $\\supset$ **Machine Learning** $\\supset$ Deep Learning (neural networks).\n• **Narrow AI (ANI):** Specialized in single domain (ChatGPT, AlphaFold).\n• **General AI (AGI):** Autonomous human-equivalent cognitive intelligence across all domains.",
            'codeExample' => "```python\nfrom sklearn.linear_model import LogisticRegression\nmodel = LogisticRegression()\nmodel.fit(X_train, y_train)\n```",
            'comparison' => null
        ],
        [
            'id' => 'ai_rag',
            'subject' => 'AI & Modern Tech Concepts',
            'chapter' => 'Generative AI Systems',
            'topic' => 'RAG (Retrieval-Augmented Generation)',
            'primaryKeywords' => ['rag', 'retrieval augmented generation', 'what is rag', 'vector search', 'chunking'],
            'secondaryKeywords' => ['embeddings', 'vector db', 'chromadb', 'hallucination', 'llm context'],
            'crispDefinition' => '**Retrieval-Augmented Generation (RAG)** is an AI architectural framework that enriches LLM prompts with relevant, verified domain documents retrieved from an external vector database at inference time, dramatically eliminating hallucinations and keeping responses up-to-date without expensive model fine-tuning.',
            'keyPoints' => "• **Ingestion:** Documents chunked and converted to vector embeddings.\n• **Retrieval:** User prompt embedded; nearest chunks retrieved via Cosine Similarity.\n• **Generation:** LLM synthesizes answer grounded strictly on retrieved context.",
            'codeExample' => "```python\nprompt = f\"Context: {retrieved_docs}\\nQuestion: {query}\\nAnswer:\"\n```",
            'comparison' => null
        ],
        [
            'id' => 'ai_llm_transformers',
            'subject' => 'AI & Modern Tech Concepts',
            'chapter' => 'Transformers & Foundation Models',
            'topic' => 'Transformers & Large Language Models (LLMs)',
            'primaryKeywords' => ['llm', 'large language models', 'full form of llm', 'llm full form', 'what does llm stand for', 'transformer', 'transformers'],
            'secondaryKeywords' => ['self attention', 'bert', 'gpt', 'tokens', 'pre training', 'fine tuning', 'rag'],
            'crispDefinition' => '**Transformers** are deep neural network architectures introduced in "Attention Is All You Need" (2017) that process sequential data using parallel **Self-Attention mechanisms** instead of recurrence. **Large Language Models (LLMs)** are foundational models built on Transformers trained on hundreds of billions of tokens to predict subsequent tokens.',
            'keyPoints' => "• **Pre-training:** Self-supervised next-token prediction across trillions of tokens.\n• **RAG (Retrieval-Augmented Generation):** Augments LLM with external knowledge.\n• **Fine-Tuning:** Instruction tuning and RLHF align model to human intent.",
            'codeExample' => "```python\n# Attention(Q, K, V) = softmax((Q * K^T) / sqrt(d_k)) * V\n```",
            'comparison' => null
        ]
    ];

    $bestDeepEntry = null;
    $bestDeepScore = 0.0;
    $cleanWords = array_values(array_filter(explode(' ', $cleanQuery)));

    foreach ($deepTopics as $entry) {
        $score = 0.0;
        foreach ($entry['primaryKeywords'] as $kw) {
            $kwLower = strtolower(trim($kw));
            if ($kwLower === '') continue;
            if ($cleanQuery === $kwLower) {
                $score += 150.0;
            } elseif (strpos($cleanQuery, $kwLower) !== false) {
                $wordCount = count(explode(' ', $kwLower));
                $score += 60.0 + ($wordCount * 20.0);
            } else {
                $kwTokens = explode(' ', $kwLower);
                $matchedTokens = 0;
                foreach ($kwTokens as $t) {
                    if (in_array($t, $cleanWords, true)) $matchedTokens++;
                }
                if ($matchedTokens === count($kwTokens) && count($kwTokens) > 1) {
                    $score += 50.0 + (count($kwTokens) * 15.0);
                }
            }
        }

        foreach ($entry['secondaryKeywords'] as $skw) {
            $skwLower = strtolower(trim($skw));
            if ($skwLower === '') continue;
            if (strpos($cleanQuery, $skwLower) !== false) {
                $score += 15.0;
            } elseif (in_array($skwLower, $cleanWords, true)) {
                $score += 10.0;
            }
        }

        if ($intentModifier === 'DIFFERENCE' && !empty($entry['comparison'])) {
            $score += 25.0;
        }

        if ($score > $bestDeepScore) {
            $bestDeepScore = $score;
            $bestDeepEntry = $entry;
        }
    }

    if ($bestDeepEntry !== null && $bestDeepScore >= 30.0) {
        if ($intentModifier === 'DEFINITION_ONLY') {
            $p1 = "* **{$bestDeepEntry['topic']}** ({$bestDeepEntry['subject']})\n{$bestDeepEntry['crispDefinition']}";
            $p2 = "";
            $fullAnswer = $p1;
        } elseif ($intentModifier === 'SYNTAX_OR_CODE') {
            $p1 = "* **{$bestDeepEntry['topic']} — Code & Syntax:**\n{$bestDeepEntry['codeExample']}";
            $p2 = "* **Core Implementation Rules:**\n{$bestDeepEntry['keyPoints']}";
            $fullAnswer = $p1 . "\n\n" . $p2;
        } elseif ($intentModifier === 'DIFFERENCE') {
            $comp = !empty($bestDeepEntry['comparison']) ? $bestDeepEntry['comparison'] : $bestDeepEntry['keyPoints'];
            $p1 = "* **Comparison & Core Differences:** ({$bestDeepEntry['topic']})\n{$comp}";
            $p2 = "* **Practical Code / Query Comparison:**\n{$bestDeepEntry['codeExample']}";
            $fullAnswer = $p1 . "\n\n" . $p2;
        } else {
            $p1 = "* **Concept & Definition** ({$bestDeepEntry['subject']} · {$bestDeepEntry['topic']})\n{$bestDeepEntry['crispDefinition']}\n\n* **Real-Life Analogy & Principles:**\n{$bestDeepEntry['keyPoints']}";
            $p2 = "* **Core Principle & Formula:**\n{$bestDeepEntry['keyPoints']}\n\n* **Practical Example & Code Snippet:**\n{$bestDeepEntry['codeExample']}\n\n* **Placement Interview Tip & Takeaway:**\nHigh-yield placement interview topic: master syntax invariants, algorithmic time/space bounds, and real-world failure modes.";
            $fullAnswer = $p1 . "\n\n" . $p2;
        }

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $fullAnswer,
                'matched_subject' => $bestDeepEntry['subject'],
                'matched_chapter' => $bestDeepEntry['chapter'],
                'matched_topic' => $bestDeepEntry['topic'],
                'confidence_score' => 99.0,
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
        'science', 'sciences', 'biology', 'history', 'economics', 'engineering', 'ai', 'data',
        'python', 'sql', 'coding', 'programming', 'database', 'databases', 'dbms', 'rdbms', 'aptitude'
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

    // Dynamic length control: Shorten queries ("give me short", "short ha sollu", "chinadha thaa", "explain shortly")
    $shortPhrases = [
        'give me short', 'short ha sollu', 'short-aa sollu', 'chinadha thaa', 'make it short',
        'in short', 'short summary', 'short note', 'short please', 'give short', 'solu short', 'short',
        'explain shortly', 'shortly', 'give me a short summary', 'give a summary', 'summarize', 'briefly'
    ];

    $lastTopic = trim($input['last_topic'] ?? $_GET['last_topic'] ?? $input['lastTopic'] ?? $_GET['lastTopic'] ?? '');
    $lastTrack = trim($input['last_track'] ?? $_GET['last_track'] ?? $input['lastTrack'] ?? $_GET['lastTrack'] ?? '');

    $isExampleQuery = (strpos($normQuery, 'example') !== false && strpos($normQuery, 'no example') === false);
    $isShortQuery = in_array($normQuery, $shortPhrases, true) || strpos($normQuery, 'short') !== false;
    $isElaborateQuery = (strpos($normQuery, 'elaborate') !== false || strpos($normQuery, 'more details') !== false || strpos($normQuery, 'in detail') !== false);
    $isInterviewQuery = (strpos($normQuery, 'interview question') !== false || strpos($normQuery, 'interview questions') !== false || strpos($normQuery, 'interview') !== false);
    $isWhyQuery = ($normQuery === 'why' || strpos($normQuery, 'why ') === 0 || strpos($normQuery, 'why does') !== false || strpos($normQuery, 'reason') !== false);

    if (!empty($lastTopic) && ($isExampleQuery || $isShortQuery || $isElaborateQuery || $isInterviewQuery || $isWhyQuery)) {
        $trackName = !empty($lastTrack) ? $lastTrack : 'Placement Preparation';
        if ($isInterviewQuery) {
            $p1 = "* **Placement Interview Preparation ($trackName · $lastTopic)**\n\n• **Q1 (Core Mechanics):** How does $lastTopic operate under the hood in production environments?\n• **Q2 (Edge Cases & Failure Modes):** What are the most common concurrency bugs or performance traps with $lastTopic?\n• **Q3 (System Design & Tradeoffs):** When should you avoid using $lastTopic in favor of an alternative approach?";
            $p2 = "* **Interviewer Evaluation Tip & Takeaway:**\nFocus on explaining time/space complexity and real-world failure modes rather than just syntax.";
        } elseif ($isWhyQuery) {
            $p1 = "* **Engineering Rationale & Design Philosophy ($trackName · $lastTopic)**\n\n• **Core Problem:** $lastTopic was engineered to enforce predictable performance, safety, and deterministic resource bounds.\n• **Architectural Trade-offs:** Balances runtime velocity against strict structural invariants.\n• **Production Impact:** Eliminates costly memory leaks and race conditions at scale.";
            $p2 = "* **Core Principle & Takeaway:**\nUnderstanding the architectural reasoning behind $lastTopic differentiates junior coders from senior engineering talent.";
        } elseif ($isShortQuery) {
            $p1 = "* **Quick Summary: $lastTopic** ($trackName)\n* **Concept:** High-yield placement concept focusing on core mechanics and algorithmic efficiency.";
            $p2 = "* **Core Principle & Formula:** Focus on standard invariants, time complexity, and memory bounds.\n* **Placement Exam Focus & Takeaway:** Frequently tested in technical placement rounds and coding interviews.";
        } else {
            $p1 = "* **In-Depth Breakdown & Example: $lastTopic** ($trackName)\n\nExpanding further on $lastTopic with real-world implementation logic and system constraints.";
            $p2 = "* **Core Principle & Takeaway:** Mastering $lastTopic provides an immediate competitive advantage during technical coding interviews.";
        }

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $p1 . "\n\n" . $p2,
                'matched_subject' => $trackName,
                'matched_chapter' => 'Context Follow-up',
                'matched_topic' => $lastTopic,
                'confidence_score' => 95.0,
                'is_follow_up' => true,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    $isStandaloneShort = in_array($normQuery, $shortPhrases, true);
    if ($isStandaloneShort) {
        $p1 = "* **Quick Revision Summary:**\n* **Core Definition:** Review governing definitions, algorithmic principles, and system invariants.";
        $p2 = "* **Key Formula / Principle:** Focus on time/space complexity, data structures, and edge cases.\n* **Placement Interview Focus:** Prepare for coding rounds and technical interviews across Python, SQL, AI, and Aptitude.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $p1 . "\n" . $p2,
                'matched_subject' => 'Placement Prep',
                'matched_chapter' => 'Quick Revision',
                'matched_topic' => 'Summary',
                'confidence_score' => 100.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // Direct Question Intent & Clarifying Answers (Origin/Trivia, Comparisons, How-To/Code, Core Overviews)
    $cleanQuery = trim(preg_replace('/[^a-zA-Z0-9+\-=><\s]/', ' ', $normQuery));
    $directMatch = null;

    // 1. Python Snake / Origin
    if ((strpos($cleanQuery, 'python') !== false && (strpos($cleanQuery, 'snake') !== false || strpos($cleanQuery, 'reptile') !== false || strpos($cleanQuery, 'animal') !== false || strpos($cleanQuery, 'means snake') !== false)) ||
        preg_match('/(?:is\s+)?python\s+(?:means?|named\s+after|a|the)?\s*snake/', $cleanQuery) ||
        preg_match('/why\s+(?:is\s+it\s+called|named)\s+python/', $cleanQuery) ||
        $cleanQuery === 'python means snake' || $cleanQuery === 'why python' || strpos($cleanQuery, 'python name origin') !== false) {
        $directMatch = [
            'p1' => "* **No, Python is not named after the snake!**\n\nIn computer science, **Python** is a popular high-level programming language—not the reptile. Guido van Rossum created it in 1991 and named it after the famous BBC comedy series **'Monty Python\'s Flying Circus'**, because he wanted a short, unique, and slightly mysterious name.",
            'p2' => "* **Core Placement Concept:**\nPython is an interpreted, dynamically typed language emphasizing code readability and developer velocity.\n\n* **Key Placement Topics to Explore:**\n1. **Memory Model & Mutability** (`id()`, object references, mutable vs immutable)\n2. **List vs Tuple** (memory layout & performance)\n3. **Global Interpreter Lock (GIL)** (threading vs multiprocessing)\n4. **Generators & Iterators** (lazy evaluation with `yield`)",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Python Fundamentals',
            'topic' => 'Python Language Origin & Overview'
        ];
    }
    // 2. Creator of Python
    elseif ((strpos($cleanQuery, 'who') !== false && (strpos($cleanQuery, 'created') !== false || strpos($cleanQuery, 'invented') !== false || strpos($cleanQuery, 'made') !== false || strpos($cleanQuery, 'developed') !== false || strpos($cleanQuery, 'father') !== false) && strpos($cleanQuery, 'python') !== false) ||
        strpos($cleanQuery, 'father of python') !== false || $cleanQuery === 'who created python' || $cleanQuery === 'who invented python') {
        $directMatch = [
            'p1' => "* **Creator of Python:**\n**Guido van Rossum**, a Dutch computer programmer, created Python in 1989 and officially released it in **February 1991** at Centrum Wiskunde & Informatica (CWI) in the Netherlands. For decades he was recognized as Python's 'Benevolent Dictator For Life' (BDFL).",
            'p2' => "* **Design Philosophy:**\nGuido designed Python around \"The Zen of Python\" (PEP 20): *\"Simple is better than complex. Readability counts.\"* It has become the dominant language for Data Science, AI/ML, and rapid backend service development.",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Python Fundamentals',
            'topic' => 'Python History & Guido van Rossum'
        ];
    }
    // 3. Creator of SQL
    elseif ((strpos($cleanQuery, 'who') !== false && (strpos($cleanQuery, 'created') !== false || strpos($cleanQuery, 'invented') !== false || strpos($cleanQuery, 'made') !== false || strpos($cleanQuery, 'developed') !== false || strpos($cleanQuery, 'father') !== false) && strpos($cleanQuery, 'sql') !== false) ||
        strpos($cleanQuery, 'father of sql') !== false || $cleanQuery === 'who created sql' || $cleanQuery === 'who invented sql') {
        $directMatch = [
            'p1' => "* **Creators of SQL:**\n**Donald D. Chamberlin** and **Raymond F. Boyce** developed SQL at IBM's San Jose Research Laboratory in the **early 1970s**. It was originally named **SEQUEL** (*Structured English QUEry Language*) designed to manipulate and retrieve data stored in IBM's original System R relational database.",
            'p2' => "* **Placement Importance:**\nStandardized by ANSI in 1986 and ISO in 1987, SQL remains the universal standard declarative language across all major RDBMS engines (PostgreSQL, MySQL, Oracle, Microsoft SQL Server).",
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL Fundamentals',
            'topic' => 'SQL History & IBM Origins'
        ];
    }
    // 4. Full Form of SQL
    elseif ((strpos($cleanQuery, 'sql') !== false && (strpos($cleanQuery, 'full form') !== false || strpos($cleanQuery, 'stand for') !== false || strpos($cleanQuery, 'meaning of sql') !== false || strpos($cleanQuery, 'abbreviation') !== false)) ||
        $cleanQuery === 'sql full form' || $cleanQuery === 'full form of sql') {
        $directMatch = [
            'p1' => "* **Full Form of SQL:**\n**Structured Query Language** (pronounced either as individual letters *S-Q-L* or as *Sequel*).\n\nOriginally named **SEQUEL** (*Structured English QUEry Language*) at IBM, the name was shortened to **SQL** due to trademark issues.",
            'p2' => "* **Core Command Subsets:**\n• **DDL** (Data Definition): `CREATE`, `ALTER`, `DROP`, `TRUNCATE`\n• **DML** (Data Manipulation): `SELECT`, `INSERT`, `UPDATE`, `DELETE`\n• **DCL** (Data Control): `GRANT`, `REVOKE`\n• **TCL** (Transaction Control): `COMMIT`, `ROLLBACK`, `SAVEPOINT`",
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL Fundamentals',
            'topic' => 'SQL Full Form & Command Categories'
        ];
    }
    // 5. Full Form of LLM
    elseif ((strpos($cleanQuery, 'llm') !== false && (strpos($cleanQuery, 'full form') !== false || strpos($cleanQuery, 'stand for') !== false || strpos($cleanQuery, 'meaning of llm') !== false || strpos($cleanQuery, 'abbreviation') !== false)) ||
        $cleanQuery === 'llm full form' || $cleanQuery === 'full form of llm' || $cleanQuery === 'what does llm stand for') {
        $directMatch = [
            'p1' => "* **Full Form of LLM:**\n**Large Language Model**.\n\nLLMs are foundational deep learning systems trained on hundreds of billions of text and code tokens using Transformer neural networks (multi-head self-attention mechanisms) to understand, generate, and reason across natural language.",
            'p2' => "* **Core Production Workflow:**\n• **Pre-training:** Self-supervised next-token prediction across trillions of tokens.\n• **Fine-Tuning (SFT):** Instruction tuning on curated prompt-response datasets.\n• **Alignment (RLHF / DPO):** Optimizing model safety and adherence to human preferences.\n• **RAG (Retrieval-Augmented Generation):** Connecting LLMs to dynamic external vector databases.",
            'subject' => 'AI & Modern Tech Concepts',
            'chapter' => 'Generative AI & LLMs',
            'topic' => 'LLM (Large Language Model) Overview'
        ];
    }
    // 6. What is SQL
    elseif (in_array($normQuery, ['what is sql', 'what is sql language', 'what is sql database', 'explain sql', 'what sql', 'tell me about sql', 'sql overview'], true)) {
        $directMatch = [
            'p1' => "* **What is SQL?**\n**Structured Query Language (SQL)** is the standard declarative programming language used to manage, query, and manipulate data in Relational Database Management Systems (RDBMS).\n\nUnlike procedural languages, SQL is **declarative**: you specify *what* records you require (`SELECT ... FROM ... WHERE ...`), and the database query engine optimizes the underlying physical retrieval execution plan.",
            'p2' => "* **Key Placement Topics in SQL:**\n• **Joins:** `INNER JOIN`, `LEFT JOIN`, `FULL OUTER JOIN`, `CROSS JOIN`\n• **Aggregation & Filtering:** `GROUP BY`, `HAVING`, `WHERE` execution order\n• **Indexing & Performance:** B-Tree indexes, clustered vs non-clustered, query cost\n• **ACID Transactions:** Atomicity, Consistency, Isolation, Durability\n• **Normalization:** 1NF, 2NF, 3NF, BCNF",
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL Fundamentals',
            'topic' => 'SQL Architecture & Core Concepts'
        ];
    }
    // 7. What is Python
    elseif (in_array($normQuery, ['what is python', 'what is python language', 'what is python programming', 'explain python', 'what python', 'tell me about python', 'python overview'], true)) {
        $directMatch = [
            'p1' => "* **What is Python?**\n**Python** is a high-level, general-purpose, interpreted programming language designed with an emphasis on code readability and developer velocity.\n\nIt natively supports multiple programming paradigms including Object-Oriented, Functional, and Procedural styles. It powers modern industry workflows across Data Science, Machine Learning, Backend Web Services (FastAPI, Django), and Cloud Automation.",
            'p2' => "* **Key Placement Topics in Python:**\n• **Memory Model & Mutability:** Immutable types (`int`, `str`, `tuple`) vs mutable types (`list`, `dict`, `set`)\n• **Concurrency & GIL:** Global Interpreter Lock implications on multithreading vs multiprocessing\n• **Advanced Idioms:** List/Dict comprehensions, generators (`yield`), decorators (`@`)\n• **Data Structures & Complexity:** Hash map lookup $O(1)$, Timsort algorithm $O(N \log N)$",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Python Fundamentals',
            'topic' => 'Python Language Overview & Core Concepts'
        ];
    }
    // 8. What is AI
    elseif (in_array($normQuery, ['what is ai', 'what is artificial intelligence', 'explain ai', 'tell me about ai', 'define ai', 'what is ai in computer science'], true)) {
        $directMatch = [
            'p1' => "* **What is Artificial Intelligence (AI)?**\n**Artificial Intelligence** is the branch of computer science dedicated to building computational systems capable of performing tasks that historically required human intelligence—such as pattern recognition, visual perception, natural language understanding, and complex decision-making.",
            'p2' => "* **The AI Hierarchy (Placement Framework):**\n• **Artificial Intelligence (AI):** The overarching field of intelligent machines and algorithms.\n• **Machine Learning (ML):** A subset of AI where systems learn statistical patterns from data.\n• **Deep Learning (DL):** A subset of ML utilizing multi-layered artificial neural networks.\n• **Generative AI & LLMs:** Modern deep models synthesizing new text, code, or images.",
            'subject' => 'AI & Modern Tech Concepts',
            'chapter' => 'Artificial Intelligence Fundamentals',
            'topic' => 'AI Hierarchy & Overview'
        ];
    }
    // 9. What is LLM
    elseif (in_array($normQuery, ['what is llm', 'what is a large language model', 'what is large language model', 'explain llm', 'tell me about llm'], true)) {
        $directMatch = [
            'p1' => "* **What is a Large Language Model (LLM)?**\nA **Large Language Model** is an advanced artificial intelligence system built upon deep neural network architectures—specifically the **Transformer** self-attention mechanism—and trained on hundreds of billions of words.\n\nLLMs excel at natural language understanding, text synthesis, contextual reasoning, code generation, and conversational problem-solving.",
            'p2' => "* **Key LLM Placement Concepts:**\n• **Self-Attention Mechanism:** Computes contextual relevance between every pair of tokens in a sequence ($Q, K, V$).\n• **Context Window:** The token buffer length the model can process simultaneously (e.g. 8k to 1M tokens).\n• **Hallucination Mitigation:** Using **RAG** (Retrieval-Augmented Generation) to ground generation with verified database records.",
            'subject' => 'AI & Modern Tech Concepts',
            'chapter' => 'Generative AI & LLMs',
            'topic' => 'LLM (Large Language Model) Concepts'
        ];
    }
    // 10. What is Database / DBMS / RDBMS
    elseif (in_array($normQuery, ['what is database', 'what is dbms', 'what is rdbms', 'difference between dbms and rdbms', 'what is a database', 'what is a dbms', 'what is a rdbms'], true) ||
        $cleanQuery === 'what is database' || $cleanQuery === 'what is dbms' || $cleanQuery === 'what is rdbms') {
        $directMatch = [
            'p1' => "* **Database, DBMS, and RDBMS Definitions:**\n\n• **Database:** An organized, persistent electronic collection of structured information or data.\n• **DBMS (Database Management System):** Software that interacts with end users, applications, and the database itself to capture, query, and manage data (e.g., file systems, XML databases).\n• **RDBMS (Relational DBMS):** An advanced DBMS based on Edgar Codd's relational model where data is structured into tables (relations) with rows (tuples) and columns (attributes), enforcing integrity constraints and ACID transactions (e.g., PostgreSQL, MySQL, Oracle).",
            'p2' => "* **Core Placement Pillars of RDBMS:**\n• **Primary Key & Foreign Key:** Enforce entity and referential integrity across related tables.\n• **ACID Compliance:** Ensures enterprise transactions remain atomic, consistent, isolated, and durable.\n• **Indexing (B-Tree):** Reduces query search time from $O(N)$ full table scan to $O(\log N)$ index seek.",
            'subject' => 'SQL & Database Management',
            'chapter' => 'Database Concepts',
            'topic' => 'Database & RDBMS Fundamentals'
        ];
    }
    // 11. VS Comparisons
    // 11a. List vs Tuple
    elseif (strpos($cleanQuery, 'list') !== false && strpos($cleanQuery, 'tuple') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'versus') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false || strpos($cleanQuery, 'and') !== false)) {
        $directMatch = [
            'p1' => "* **List vs. Tuple Comparison (Python):**\n\n• **Mutability:** Lists are **mutable** (elements can be added, removed, or modified in place); Tuples are **immutable** (once instantiated, elements cannot be modified).\n• **Syntax:** Lists use square brackets `[1, 2, 3]`; Tuples use parentheses `(1, 2, 3)`.\n• **Memory & Speed:** Tuples allocate a single fixed memory block and are faster to construct and iterate over; Lists allocate extra capacity to support dynamic amortized $O(1)$ append operations.\n• **Dictionary Keys:** Tuples can serve as dictionary keys or set elements (if all elements are hashable); Lists can **never** be dictionary keys because mutable types are unhashable.",
            'p2' => "* **Placement Interview Tip:**\nUse a tuple for fixed records or heterogeneous data (e.g. `(latitude, longitude)` or database rows). Use a list for collections of homogeneous items that grow or shrink dynamically.",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Python Data Structures',
            'topic' => 'List vs Tuple'
        ];
    }
    // 11b. SQL vs NoSQL
    elseif (strpos($cleanQuery, 'sql') !== false && strpos($cleanQuery, 'nosql') !== false) {
        $directMatch = [
            'p1' => "* **SQL vs. NoSQL Comparison:**\n\n• **Data Model:** SQL databases are **relational** (predefined schemas, tables, rows, columns); NoSQL databases are **non-relational** (Document, Key-Value, Wide-Column, or Graph stores with dynamic JSON-like schemas).\n• **Transactions:** SQL guarantees strict **ACID** properties; NoSQL embraces **BASE** properties (Basically Available, Soft-state, Eventual consistency).\n• **Scaling:** SQL scales primarily **vertically** (increasing CPU, RAM, SSD on a server); NoSQL scales **horizontally** (sharding and distributing data partitions across commodity nodes).\n• **Examples:** SQL: PostgreSQL, MySQL, Oracle; NoSQL: MongoDB, Redis, Apache Cassandra, DynamoDB.",
            'p2' => "* **Placement System Design Tip:**\nChoose SQL for relational integrity, financial transactions, and complex analytical JOINs. Choose NoSQL for high-throughput write-heavy logs, caching, rapidly evolving schemas, or massive horizontal scale.",
            'subject' => 'SQL & Database Management',
            'chapter' => 'Database Architecture',
            'topic' => 'SQL vs NoSQL'
        ];
    }
    // 11c. DELETE vs TRUNCATE
    elseif (strpos($cleanQuery, 'delete') !== false && strpos($cleanQuery, 'truncate') !== false) {
        $directMatch = [
            'p1' => "* **DELETE vs. TRUNCATE Comparison (SQL):**\n\n• **Command Type:** `DELETE` is a **DML** (Data Manipulation Language) command; `TRUNCATE` is a **DDL** (Data Definition Language) command.\n• **WHERE Clause:** `DELETE` supports a `WHERE` clause to filter specific rows; `TRUNCATE` removes **all rows** unconditionally.\n• **Transaction Logging & Speed:** `DELETE` scans and logs each deleted row individually in the transaction log (slow for large tables); `TRUNCATE` deallocates data pages directly and logs only page deallocations (extremely fast).\n• **Rollback:** `DELETE` operations can be rolled back in an active transaction; `TRUNCATE` commits immediately in some engines (though PostgreSQL and SQL Server support rolling back TRUNCATE within an uncommitted transaction).\n• **Identity Counter:** `TRUNCATE` resets auto-increment / identity seed values back to initial state; `DELETE` retains the current counter.",
            'p2' => "* **Placement Interview Takeaway:**\nNever run `DELETE` without a `WHERE` clause in production. Use `TRUNCATE TABLE` when emptying staging or temporary tables.",
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL DDL & DML Commands',
            'topic' => 'DELETE vs TRUNCATE'
        ];
    }
    // 11d. Primary Key vs Foreign Key
    elseif (strpos($cleanQuery, 'primary key') !== false && strpos($cleanQuery, 'foreign key') !== false) {
        $directMatch = [
            'p1' => "* **Primary Key vs. Foreign Key Comparison:**\n\n• **Purpose:** A **Primary Key (PK)** uniquely identifies each row within its own table; a **Foreign Key (FK)** references a Primary Key or Unique Key in another table to establish a relationship.\n• **Uniqueness & Nullability:** Primary Keys must be strictly **UNIQUE and NOT NULL**; Foreign Keys can contain duplicate values and can be `NULL` (unless defined `NOT NULL`).\n• **Quantity:** A table can have only **one** Primary Key (which may be composite); a table can have **multiple** Foreign Keys pointing to different tables.\n• **Clustered Index:** Primary Keys automatically create a clustered index by default; Foreign Keys do not create indexes automatically (they should be indexed manually for JOIN performance).",
            'p2' => "* **Placement Interview Takeaway:**\nForeign Keys enforce **Referential Integrity**. Cascading rules like `ON DELETE CASCADE` or `ON DELETE SET NULL` prevent orphaned child rows when a parent record is deleted.",
            'subject' => 'SQL & Database Management',
            'chapter' => 'Database Constraints & Keys',
            'topic' => 'Primary Key vs Foreign Key'
        ];
    }
    // 11e. AI vs ML
    elseif ((strpos($cleanQuery, 'ai') !== false && strpos($cleanQuery, 'ml') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false)) ||
        (strpos($cleanQuery, 'artificial intelligence') !== false && strpos($cleanQuery, 'machine learning') !== false)) {
        $directMatch = [
            'p1' => "* **AI vs. Machine Learning (ML) Comparison:**\n\n• **Scope:** **Artificial Intelligence** is the overarching discipline of building intelligent systems capable of human-like decision making; **Machine Learning** is a specific branch/subset of AI.\n• **Methodology:** AI encompasses symbolic AI, expert rule systems, and search trees ($A^*$ algorithm); ML focuses on statistical algorithms that learn patterns directly from training data without being explicitly programmed.\n• **Goal:** AI seeks general intelligent behavior; ML seeks accurate predictive models on specific tasks through data optimization.",
            'p2' => "* **Placement Takeaway:**\nAll Machine Learning is Artificial Intelligence, but not all Artificial Intelligence is Machine Learning (e.g. minimax chess algorithms are AI without ML).",
            'subject' => 'AI & Modern Tech Concepts',
            'chapter' => 'Artificial Intelligence Fundamentals',
            'topic' => 'AI vs ML'
        ];
    }
    // 11f. Deep Learning vs Machine Learning
    elseif (strpos($cleanQuery, 'deep learning') !== false && strpos($cleanQuery, 'machine learning') !== false) {
        $directMatch = [
            'p1' => "* **Deep Learning (DL) vs. Traditional Machine Learning (ML):**\n\n• **Feature Engineering:** Traditional ML requires manual feature extraction (PCA, TF-IDF, domain feature engineering); DL automatically learns hierarchical representations directly from raw data (pixels, text tokens, audio signals).\n• **Architecture:** Traditional ML relies on models like Random Forest, SVM, and XGBoost; DL utilizes multi-layer artificial neural networks (CNNs, RNNs, Transformers).\n• **Hardware & Data Requirements:** Traditional ML works well on small-to-medium tabular datasets on standard CPUs; DL requires massive datasets and GPU/TPU matrix accelerators.",
            'p2' => "* **Placement Takeaway:**\nFor structured enterprise tabular data, Gradient Boosted Decision Trees (XGBoost/LightGBM) frequently outperform Deep Learning with lower compute costs and higher explainability.",
            'subject' => 'AI & Modern Tech Concepts',
            'chapter' => 'Neural Networks & Deep Learning',
            'topic' => 'Deep Learning vs Machine Learning'
        ];
    }
    // 11g. INNER JOIN vs LEFT JOIN
    elseif (strpos($cleanQuery, 'inner join') !== false && strpos($cleanQuery, 'left join') !== false) {
        $directMatch = [
            'p1' => "* **INNER JOIN vs. LEFT JOIN Comparison:**\n\n• **Row Matching:** `INNER JOIN` returns **only rows that have matching values in both tables**; `LEFT JOIN` (or `LEFT OUTER JOIN`) returns **all rows from the left table**, plus matching records from the right table.\n• **Unmatched Left Rows:** In `LEFT JOIN`, if a left row has no match in the right table, all columns from the right table are populated with `NULL`; `INNER JOIN` drops unmatched rows completely.\n• **Result Cardinality:** An `INNER JOIN` row count is $\le$ left table row count (assuming unique join keys); a `LEFT JOIN` row count is always $\ge$ left table row count.",
            'p2' => "* **Placement Interview Trick:**\nTo find records in Table A that have no corresponding record in Table B, use a `LEFT JOIN` with a NULL filter:\n```sql\nSELECT a.*\nFROM table_a a\nLEFT JOIN table_b b ON a.id = b.a_id\nWHERE b.a_id IS NULL;\n```",
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL Joins & Relational Algebra',
            'topic' => 'SQL Joins (INNER vs LEFT)'
        ];
    }
    // 11h. WHERE vs HAVING
    elseif (strpos($cleanQuery, 'where') !== false && strpos($cleanQuery, 'having') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false)) {
        $directMatch = [
            'p1' => "* **WHERE vs. HAVING Comparison (SQL):**\n\n• **Evaluation Stage:** `WHERE` filters individual rows **before** any aggregation or `GROUP BY` operation; `HAVING` filters aggregated groups **after** `GROUP BY` calculation.\n• **Aggregate Functions:** `WHERE` **cannot** evaluate aggregate functions (`WHERE AVG(salary) > 50000` is illegal); `HAVING` is specifically designed to filter on aggregate functions (`HAVING AVG(salary) > 50000`).\n• **Indexing:** `WHERE` clauses can utilize B-Tree indexes for fast row seeks; `HAVING` filters aggregated intermediate result partitions in memory.",
            'p2' => "* **SQL Query Execution Pipeline (Crucial for Interviews):**\n1. `FROM` & `JOIN`\n2. `WHERE` (row filtering)\n3. `GROUP BY` (grouping)\n4. `HAVING` (group filtering)\n5. `SELECT` (projection)\n6. `DISTINCT`\n7. `ORDER BY` (sorting)\n8. `LIMIT` / `OFFSET`",
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL Filtering & Grouping',
            'topic' => 'WHERE vs HAVING'
        ];
    }
    // 11i. is vs ==
    elseif ((strpos($normQuery, 'is') !== false && strpos($normQuery, '==') !== false) || $cleanQuery === 'is vs' || strpos($cleanQuery, 'is vs ==') !== false) {
        $directMatch = [
            'p1' => "* **`is` vs. `==` Comparison (Python):**\n\n• **Equality (`==`):** Tests for **value equality** — checks whether two objects have equivalent content by invoking the `__eq__()` method.\n• **Identity (`is`):** Compares **memory identity** — checks whether two variables reference the exact same object in heap memory (`id(a) == id(b)`).\n• **Example:**\n```python\na = [1, 2, 3]\nb = [1, 2, 3]\nprint(a == b)  # True (same values)\nprint(a is b)  # False (different memory addresses)\n```",
            'p2' => "* **Placement Interview Trap (Integer Interning & None):**\n• Always compare singletons with `is`: `if x is None:`, never `if x == None:`.\n• Python pre-allocates and interns small integers between -5 and 256. Therefore `x = 250; y = 250; x is y` evaluates to `True`, but integers outside that range allocate separate heap objects.",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Python Memory & Internals',
            'topic' => 'Object Identity vs Value Equality (`is` vs `==`)'
        ];
    }
    // 11j. Process vs Thread
    elseif (strpos($cleanQuery, 'process') !== false && strpos($cleanQuery, 'thread') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false)) {
        $directMatch = [
            'p1' => "* **Process vs. Thread Comparison:**\n\n• **Address Space:** A **Process** possesses an independent, isolated virtual memory space; **Threads** within the same process share the same code, data, and heap segments.\n• **Creation & Switching:** Creating and context switching processes involves heavy OS overhead (page table and TLB cache invalidation); Threads are lightweight and switch significantly faster.\n• **Communication:** Processes require Inter-Process Communication (IPC like sockets, pipes, shared memory); Threads communicate directly via shared memory (requiring synchronization primitives like mutexes to prevent race conditions).\n• **Crash Impact:** If one process crashes, other processes remain unaffected; if one thread encounters a fatal exception (like a segmentation fault), the entire parent process can crash.",
            'p2' => "* **Placement Takeaway:**\nUse multi-threading for I/O-bound or shared-memory tasks. Use multi-processing for CPU-bound tasks in languages with a Global Interpreter Lock (GIL) or where memory isolation is paramount.",
            'subject' => 'OS & Computer Networks',
            'chapter' => 'Processes vs Threads, Concurrency & Deadlocks',
            'topic' => 'Process vs Thread Comparison'
        ];
    }
    // 11k. TCP vs UDP
    elseif (strpos($cleanQuery, 'tcp') !== false && strpos($cleanQuery, 'udp') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false)) {
        $directMatch = [
            'p1' => "* **TCP vs. UDP Comparison:**\n\n• **Connection & Reliability:** **TCP** is connection-oriented and guarantees reliable, ordered byte-stream delivery via sequence numbers and ACKs; **UDP** is connectionless, unordered, and provides best-effort datagram delivery with zero delivery guarantees.\n• **Handshake:** TCP establishes connections using a 3-way handshake (SYN, SYN-ACK, ACK); UDP sends datagrams immediately without any connection setup.\n• **Header Overhead:** TCP has a minimum 20-byte header; UDP has a lightweight 8-byte header.\n• **Flow & Congestion Control:** TCP implements sliding window flow control and congestion avoidance (Slow Start, Fast Retransmit); UDP has zero congestion or flow control.",
            'p2' => "* **Placement Use Cases:**\n• **TCP:** Web browsing (HTTP/HTTPS), file transfer (FTP), secure shell (SSH), email (SMTP).\n• **UDP:** Real-time video/audio streaming, online multiplayer gaming, DNS queries, VoIP.",
            'subject' => 'OS & Computer Networks',
            'chapter' => 'TCP vs UDP, Three-Way Handshake & Flow Control',
            'topic' => 'TCP vs UDP Comparison'
        ];
    }
    // 11l. Stack vs Queue
    elseif (strpos($cleanQuery, 'stack') !== false && strpos($cleanQuery, 'queue') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false)) {
        $directMatch = [
            'p1' => "* **Stack vs. Queue Comparison:**\n\n• **Principle:** **Stack** operates on **LIFO** (Last In, First Out) — the newest element inserted is removed first; **Queue** operates on **FIFO** (First In, First Out) — the oldest element inserted is removed first.\n• **Primary Operations:** Stack uses `push()`, `pop()`, and `peek()`; Queue uses `enqueue()`, `dequeue()`, and `peek()`.\n• **Applications:** Stack is used in function call stacks, recursion, backtracking, undo/redo, and syntax parsing; Queue is used in BFS graph traversal, printer spooling, OS task scheduling, and message brokers.",
            'p2' => "* **Complexity Note:**\nBoth Stacks and Queues achieve O(1) constant time complexity for insertions and deletions when backed by dynamic arrays or doubly linked lists.",
            'subject' => 'Data Structures & Algorithms',
            'chapter' => 'Stacks, Queues & Monotonic Stack',
            'topic' => 'Stack vs Queue Comparison'
        ];
    }
    // 11m. Array vs Linked List
    elseif ((strpos($cleanQuery, 'array') !== false || strpos($cleanQuery, 'arrays') !== false) && strpos($cleanQuery, 'linked list') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false)) {
        $directMatch = [
            'p1' => "* **Array vs. Linked List Comparison:**\n\n• **Memory Layout:** Arrays store elements in **contiguous** memory blocks; Linked Lists store elements in non-contiguous nodes linked together via memory pointers.\n• **Random Access:** Arrays provide O(1) constant time random access via index `arr[i]`; Linked Lists require O(N) sequential pointer traversal.\n• **Insertion & Deletion:** Inserting or deleting at the beginning of an array is O(N) due to shifting elements; Linked Lists insert or delete at head in O(1) pointer update.\n• **Cache Locality:** Arrays exhibit superior CPU cache locality and spatial prefetching; Linked Lists suffer from cache misses and pointer memory overhead.",
            'p2' => "* **Placement Takeaway:**\nChoose arrays for read-heavy or indexed operations. Choose linked lists when dynamic size allocation and frequent head/tail insertion without memory reallocation are required.",
            'subject' => 'Data Structures & Algorithms',
            'chapter' => 'Arrays, Two Pointers & Sliding Window',
            'topic' => 'Array vs Linked List Comparison'
        ];
    }
    // 11n. Overloading vs Overriding
    elseif (strpos($cleanQuery, 'overloading') !== false && strpos($cleanQuery, 'overriding') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false)) {
        $directMatch = [
            'p1' => "* **Method Overloading vs. Method Overriding (Java/OOP):**\n\n• **Binding Time:** **Overloading** is resolved at **compile-time** (Static Binding); **Overriding** is resolved at **runtime** (Dynamic Method Dispatch).\n• **Class Context:** Overloading occurs within the same class; Overriding requires an inheritance relationship between parent and child classes.\n• **Method Signature:** Overloading requires different parameter types or parameter counts (return type alone cannot differentiate); Overriding requires identical method names and identical parameter signatures (return type can be covariant).\n• **Private & Static Methods:** Private and static methods can be overloaded, but **cannot** be overridden (static methods undergo method hiding).",
            'p2' => "* **Placement Takeaway:**\nOverriding enforces runtime polymorphism. In Java, always decorate overridden methods with `@Override` so the compiler catches accidental signature discrepancies.",
            'subject' => 'Java & OOPs Mastery',
            'chapter' => 'OOPs 4 Pillars: Encapsulation, Inheritance, Polymorphism, Abstraction',
            'topic' => 'Method Overloading vs Method Overriding'
        ];
    }
    // 11o. StateFlow vs SharedFlow
    elseif (strpos($cleanQuery, 'stateflow') !== false && strpos($cleanQuery, 'sharedflow') !== false && (strpos($cleanQuery, 'vs') !== false || strpos($cleanQuery, 'difference') !== false || strpos($cleanQuery, 'compare') !== false)) {
        $directMatch = [
            'p1' => "* **StateFlow vs. SharedFlow Comparison (Kotlin Coroutines):**\n\n• **State vs. Event:** **StateFlow** is a state-holder observable designed to represent persistent UI state (e.g. `UiState.Loading`); **SharedFlow** is an event bus designed for one-shot transient events (e.g. navigation, show snackbar).\n• **Initial Value:** StateFlow requires an initial value upon instantiation; SharedFlow does not require an initial value.\n• **Replay Cache:** StateFlow has a fixed replay cache of 1 (new subscribers immediately receive the latest value); SharedFlow has a configurable replay buffer (defaults to 0).\n• **Conflation:** StateFlow conflates consecutive identical values (will not re-emit if state has not changed); SharedFlow emits every single event regardless of value equality.",
            'p2' => "* **Placement Android Architecture Tip:**\nExpose `StateFlow<UiState>` from ViewModels for UI screens, and expose `SharedFlow<UiEvent>` for one-off actions that should not re-trigger when the user rotates their screen.",
            'subject' => 'Android & App Architecture',
            'chapter' => 'Kotlin Coroutines & StateFlow / SharedFlow for Async Work',
            'topic' => 'StateFlow vs SharedFlow Comparison'
        ];
    }
    // 12. How to / Code / Example
    // 12a. Reverse a string
    elseif (strpos($cleanQuery, 'reverse') !== false && (strpos($cleanQuery, 'string') !== false || strpos($cleanQuery, 'str') !== false)) {
        $directMatch = [
            'p1' => "* **How to Reverse a String in Python:**\n\n**Approach 1: Extended Slicing (Most Pythonic - $O(N)$ Time, $O(N)$ Space):**\n```python\ndef reverse_string(s: str) -> str:\n    return s[::-1]\n\nprint(reverse_string(\"placement\"))  # Output: tnemecalp\n```\n\n**Approach 2: Two-Pointer Technique (Mutable Character Array - $O(N)$ Time, $O(1)$ Auxiliary Space):**\n```python\ndef reverse_two_pointer(s: str) -> str:\n    chars = list(s)\n    left, right = 0, len(chars) - 1\n    while left < right:\n        chars[left], chars[right] = chars[right], chars[left]\n        left += 1\n        right -= 1\n    return \"\".join(chars)\n```",
            'p2' => "* **Placement Interview Tip:**\nBecause Python strings are strictly immutable, slicing `s[::-1]` creates a new string object in memory. If an interviewer asks to reverse a string in-place with $O(1)$ extra memory, convert to a `list` and use two pointers.",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Python Strings & Algorithms',
            'topic' => 'Reverse a String'
        ];
    }
    // 12b. Second highest salary
    elseif (strpos($cleanQuery, 'second highest salary') !== false || strpos($cleanQuery, '2nd highest salary') !== false || (strpos($cleanQuery, 'highest salary') !== false && strpos($cleanQuery, 'second') !== false)) {
        $directMatch = [
            'p1' => "* **Find Second Highest Salary in SQL:**\n\n**Approach 1: Correlated Subquery (Universal ANSI SQL):**\n```sql\nSELECT MAX(salary) AS second_highest_salary\nFROM employees\nWHERE salary < (\n    SELECT MAX(salary)\n    FROM employees\n);\n```\n\n**Approach 2: Window Function (`DENSE_RANK` - Handles ties correctly):**\n```sql\nWITH RankedSalaries AS (\n    SELECT salary,\n           DENSE_RANK() OVER (ORDER BY salary DESC) AS rnk\n    FROM employees\n)\nSELECT salary AS second_highest_salary\nFROM RankedSalaries\nWHERE rnk = 2\nLIMIT 1;\n```",
            'p2' => "* **Placement Interview Trap:**\nNever use `ORDER BY salary DESC LIMIT 1 OFFSET 1` without handling duplicates. If multiple employees share the top salary, `LIMIT 1 OFFSET 1` returns the highest salary again. Always use `DENSE_RANK()` or `MAX() < (SELECT MAX())`.",
            'subject' => 'SQL & Database Management',
            'chapter' => 'SQL Interview Queries',
            'topic' => 'Second Highest Salary Query'
        ];
    }
    // 12c. Palindrome
    elseif (strpos($cleanQuery, 'palindrome') !== false) {
        $directMatch = [
            'p1' => "* **Check Palindrome (Python):**\n\n```python\ndef is_palindrome(s: str) -> bool:\n    # Filter alphanumeric characters and convert to lowercase\n    clean = [c.lower() for c in s if c.isalnum()]\n    \n    left, right = 0, len(clean) - 1\n    while left < right:\n        if clean[left] != clean[right]:\n            return False\n        left += 1\n        right -= 1\n    return True\n\nprint(is_palindrome(\"A man, a plan, a canal: Panama\"))  # True\nprint(is_palindrome(\"race a car\"))  # False\n```",
            'p2' => "* **Complexity & Edge Cases:**\n• **Time Complexity:** $O(N)$ where $N$ is the length of the string.\n• **Space Complexity:** $O(1)$ auxiliary space if checking characters directly with index pointers and `s[left].isalnum()`.\n• **Edge Cases:** Empty strings and single-character strings are valid palindromes.",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Two-Pointer Algorithms',
            'topic' => 'Palindrome Verification'
        ];
    }
    // 12d. Fibonacci
    elseif (strpos($cleanQuery, 'fibonacci') !== false) {
        $directMatch = [
            'p1' => "* **Fibonacci Series in Python ($O(N)$ Time, $O(1)$ Space):**\n\n```python\ndef fibonacci_sequence(n: int) -> list[int]:\n    if n <= 0:\n        return []\n    if n == 1:\n        return [0]\n    \n    seq = [0, 1]\n    for _ in range(2, n):\n        seq.append(seq[-1] + seq[-2])\n    return seq\n\nprint(fibonacci_sequence(8))  # [0, 1, 1, 2, 3, 5, 8, 13]\n```",
            'p2' => "* **Placement Interview Note:**\nNaive recursion `fib(n-1) + fib(n-2)` takes exponential $O(2^N)$ time due to repeated subproblem calculations. Explain Dynamic Programming (Memoization / Tabulation) to achieve $O(N)$ time, or Matrix Exponentiation for $O(\log N)$ time.",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Algorithmic Problem Solving',
            'topic' => 'Fibonacci Sequence'
        ];
    }
    // 12e. Prime Number
    elseif (strpos($cleanQuery, 'prime number') !== false || strpos($cleanQuery, 'check prime') !== false || (strpos($cleanQuery, 'prime') !== false && (strpos($cleanQuery, 'number') !== false || strpos($cleanQuery, 'is prime') !== false || strpos($cleanQuery, 'check') !== false))) {
        $directMatch = [
            'p1' => "* **Check Prime Number ($O(\sqrt{N})$ Time Complexity):**\n\n```python\ndef is_prime(n: int) -> bool:\n    if n <= 1:\n        return False\n    if n <= 3:\n        return True\n    if n % 2 == 0 or n % 3 == 0:\n        return False\n    \n    # Check factors of 6k +/- 1 up to sqrt(n)\n    i = 5\n    while i * i <= n:\n        if n % i == 0 or n % (i + 2) == 0:\n            return False\n        i += 6\n    return True\n\nprint(is_prime(29))  # True\nprint(is_prime(1))   # False\n```",
            'p2' => "* **Mathematical Proof:**\nEvery composite integer $n$ has at least one prime divisor less than or equal to $\sqrt{n}$. If no divisor is found up to $\lfloor \sqrt{n} \rfloor$, $n$ is guaranteed to be prime.",
            'subject' => 'Python & Coding Logic',
            'chapter' => 'Mathematical Algorithms',
            'topic' => 'Prime Number Verification'
        ];
    }

    if ($directMatch !== null) {
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $directMatch['p1'],
                'paragraph2' => $directMatch['p2'],
                'answer' => $directMatch['p1'] . "\n\n" . $directMatch['p2'],
                'matched_subject' => $directMatch['subject'],
                'matched_chapter' => $directMatch['chapter'],
                'matched_topic' => $directMatch['topic'],
                'confidence_score' => 99.0,
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
        $subject = $bestChunk['subject'] ?? 'Placement Prep';
        $chapter = $bestChunk['chapter'] ?? $bestChunk['topic'] ?? '';
        $topic = $bestChunk['topic'] ?? $chapter;
        $def = trim($bestChunk['definition'] ?? '');
        $formula = trim($bestChunk['formula'] ?? $bestChunk['formulaOrCoreRule'] ?? '');
        $example = trim($bestChunk['worked_example'] ?? $bestChunk['workedExample'] ?? '');
        $content = trim($bestChunk['content'] ?? '');

        // Generate Real-Life Analogy
        $normSub = strtolower($subject);
        $normTop = strtolower($topic);
        if (strpos($normSub, 'python') !== false) {
            $analogy = (strpos($normTop, 'mutab') !== false)
                ? "Think of an immutable object as an engraved metal plaque: its content cannot change without casting an entirely new plaque. A mutable object is like a whiteboard where you can erase and rewrite items in-place."
                : "Think of $topic like a specialized tool in an engineer's toolkit, designed to simplify execution and prevent runtime bugs.";
            $interviewTip = "Top tech rounds test edge cases such as mutating containers while iterating, and difference between O(1) hash map operations vs O(N) linear scans.";
        } elseif (strpos($normSub, 'sql') !== false) {
            $analogy = (strpos($normTop, 'join') !== false)
                ? "Think of an INNER JOIN like two RSVP guest lists: only guests whose names appear on BOTH lists get admitted to the party. A LEFT JOIN admits everyone from the main list, adding details if available."
                : "Think of $topic as an architectural foundation for relational data integrity and deterministic query plans.";
            $interviewTip = "Interviewers evaluate query execution plans (EXPLAIN), sargable WHERE conditions, and index selectivity to avoid costly full-table scans.";
        } elseif (strpos($normSub, 'ai') !== false) {
            $analogy = (strpos($normTop, 'rag') !== false)
                ? "Think of RAG like giving an intelligent student an open-book library during an exam so they retrieve verified facts rather than relying solely on memory."
                : "Think of $topic like tuning soundboard equalizer knobs in real-time until audio distortion is minimized.";
            $interviewTip = "Expect questions contrasting fine-tuning vs retrieval architectures, handling hallucination, and balancing latency vs model accuracy.";
        } else {
            $analogy = "Think of relative speed and rates like walking on an airport moving walkway: your speeds combine when walking with the direction and subtract when walking against it.";
            $interviewTip = "Apply shortcut elimination: inspect unit digits, test divisibility rules, and eliminate mathematically absurd options first to solve under 45 seconds.";
        }

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
                $p2Parts[] = "* **Key Example / Code:** $example";
            }
            $p2Parts[] = "* **Placement Exam Focus & Takeaway:** High-yield technical interview topic—review key steps and definitions.";
            $p2 = implode("\n", $p2Parts);
            $fullAnswer = trim($p1 . "\n" . $p2);
        } elseif ($isElaborated) {
            $p1 = "{$bPrefix}**In-Depth Breakdown: $topic** ($subject · $chapter)\n\n**1. Theoretical Foundations & Mechanism:**\n$content\n\n**2. Real-Life Analogy:**\n$analogy" .
                  ($includeFormulas && !empty($formula) ? "\n\n**3. Mathematical Formulations & Derivations:**\n$formula" : "");
            $p2Parts = [];
            if ($includeExamples && !empty($example)) {
                $p2Parts[] = "**4. Practical Implementation & Code Snippet:**\n$example";
            }
            $p2Parts[] = "**5. Placement Interview Tip & Key Takeaway:**\n$interviewTip";
            $p2 = implode("\n\n", $p2Parts);
            $fullAnswer = trim($p1 . "\n\n" . $p2);
        } else {
            // Clean * bullet points, zero emojis by default
            $p1 = "{$bPrefix}**Concept & Definition** ($subject · $topic)\n$def\n\n{$bPrefix}**Real-Life Analogy:**\n$analogy";
            $p2Parts = [];
            if ($includeFormulas && !empty($formula)) {
                $p2Parts[] = "{$fPrefix}**Formula / Core Principle:** $formula";
            }
            if ($includeExamples && !empty($example)) {
                $p2Parts[] = "{$ePrefix}**Key Example & Code Snippet:** $example";
            }
            $p2Parts[] = "* **Placement Interview Tip & Takeaway:** $interviewTip";
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
    $unclearReply = "I don't have the exact answer for that yet. Try asking a specific question in Python, SQL, AI, Aptitude, DSA, Java, OS/CN, or Android!";

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
