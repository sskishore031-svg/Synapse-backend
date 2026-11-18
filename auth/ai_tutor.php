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
    $preferredName = trim($input['preferred_name'] ?? $_GET['preferred_name'] ?? '');
    if (empty($preferredName) && !empty($user['name'])) {
        $preferredName = trim($user['name']);
    }
    if (empty($preferredName)) {
        $preferredName = 'friend';
    }

    $includeFormulas = isset($input['include_formulas']) ? filter_var($input['include_formulas'], FILTER_VALIDATE_BOOLEAN) : (isset($_GET['include_formulas']) ? filter_var($_GET['include_formulas'], FILTER_VALIDATE_BOOLEAN) : true);
    $includeExamples = isset($input['include_examples']) ? filter_var($input['include_examples'], FILTER_VALIDATE_BOOLEAN) : (isset($_GET['include_examples']) ? filter_var($_GET['include_examples'], FILTER_VALIDATE_BOOLEAN) : true);
    $useEmojis = isset($input['use_emojis']) ? filter_var($input['use_emojis'], FILTER_VALIDATE_BOOLEAN) : (isset($_GET['use_emojis']) ? filter_var($_GET['use_emojis'], FILTER_VALIDATE_BOOLEAN) : false);
    $consecutiveUnclear = intval($input['consecutive_unclear'] ?? $_GET['consecutive_unclear'] ?? 0);

    $normQuery = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $question)));
    $cleanWords = array_values(array_filter(preg_split('/\s+/', $normQuery)));

    // =========================================================================
    // STAGE 1: Dynamic Custom User Name / Nickname Memory
    // =========================================================================
    if (preg_match('/^(?:call\s+me|my\s+name\s+is|name\s+is)\s+([a-zA-Z]+)/i', $question, $m)) {
        $preferredName = ucfirst(strtolower($m[1]));
        $reply = "Got it, $preferredName! From now on I will call you $preferredName.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
                'preferred_name' => $preferredName,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    if (preg_match('/^(?:enoda|ennoda|en)\s+(?:name|per|peyar)(?:\s+is)?\s+([a-zA-Z]+)/i', $question, $m)) {
        $preferredName = ucfirst(strtolower($m[1]));
        $reply = "Got it, $preferredName! From now on I will call you $preferredName.";
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $reply,
                'paragraph2' => '',
                'answer' => $reply,
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
            $reply = "Got it, $preferredName! From now on I will call you $preferredName.";
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'question' => $question,
                    'paragraph1' => $reply,
                    'paragraph2' => '',
                    'answer' => $reply,
                    'preferred_name' => $preferredName,
                    'tutor_name' => 'SYNAPSE AI',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }
    }

    $askNameRequests = [
        'enoda name solu kupdu', 'enoda name solli kupdu', 'ennoda name solu kupdu', 'ennoda name solli kupdu',
        'call me by my name', 'call me my name', 'call me'
    ];
    foreach ($askNameRequests as $anr) {
        if ($normQuery === $anr || strpos($normQuery, $anr) !== false) {
            $reply = "Sure! What name would you like me to call you?";
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
    }

    // =========================================================================
    // STAGE 2: Formatting & Style Preferences (no formula, no example, no emoji)
    // =========================================================================
    $disableEmojiPhrases = ['dont use emoji', 'dont use emojis', 'do not use emoji', 'do not use emojis', 'no emoji', 'no emojis', 'stop emoji', 'without emoji', 'without emojis'];
    foreach ($disableEmojiPhrases as $dep) {
        if (strpos($normQuery, $dep) !== false) {
            $useEmojis = false;
            $reply = "Got it, $preferredName! I'll keep all responses clean with * bullet points and no emojis.";
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
            $reply = "Got it, $preferredName! I'll provide only pure concepts and definitions without formulas or examples for upcoming topics. What shall we learn?";
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
            $reply = "Got it, $preferredName! I'll omit formulas and focus on concepts and practical examples.";
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
            $reply = "Got it, $preferredName! I'll omit examples and focus on core definitions and formulas.";
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
        $reply = "Okay bye, $preferredName! We'll continue our studies tomorrow. Take care!";
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
    $smallTalk = [
        'how are you' => "I'm doing great and feeling energized, $preferredName! How's your study session going today?",
        'hows it going' => "I'm doing great, $preferredName! What subject shall we tackle today?",
        'good morning' => "Good morning, $preferredName! Ready to learn something exciting today? What subject are we tackling?",
        'good afternoon' => "Good afternoon, $preferredName! Hope you're having a productive day. What subject shall we explore?",
        'good evening' => "Good evening, $preferredName! Let's do some focused study tonight. What can I help you understand?",
        'good night' => "Good night, $preferredName! Rest well and keep learning tomorrow.",
        'gn' => "Good night, $preferredName! Rest well and keep learning tomorrow.",
        'thank you' => "You're very welcome, $preferredName! Always happy to help. Let me know if you have any other doubts!",
        'thanks' => "You're very welcome, $preferredName! Keep up the great effort!",
        'you are awesome' => "Thank you so much, $preferredName! You're awesome for putting in the effort to study. Let's keep it going!",
        'you are great' => "Thank you, $preferredName! I'm here anytime you need a study boost.",
        'i am bored' => "How about a quick 3-minute quiz or learning a fascinating fact in science or tech? Pick a subject and let's make it fun!",
        'motivate me' => "Every expert was once a beginner who refused to quit. Focus on mastering just one concept right now—progress adds up fast!",
        'who made you' => "I was built by the SYNAPSE AI team to be your personal university study companion and learning buddy!",
        'are you smart' => "I know 50+ college subjects inside and out, but my favorite thing is helping you become the smartest person in the room!",
        'hello friend' => "Hey $preferredName! Great to see you. What subject shall we dive into today?",
        'hi friend' => "Hey $preferredName! Ready for a great study session? What are we working on?",
        'hi' => "Hello, $preferredName! I'm SYNAPSE AI, your study friend. Ask me any concept, formula, or quiz topic to get started!",
        'hello' => "Hello, $preferredName! What subject or concept shall we study today?",
        'hey' => "Hey, $preferredName! What shall we learn today?",
        'vanakkam' => "Vanakkam, $preferredName! How can I help you with your studies today?",
        'namaste' => "Namaste, $preferredName! How can I assist you with your learning today?"
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
        $boundary = "That falls outside my academic and app scope, $preferredName. I'm SYNAPSE AI—built specifically to help you master your college subjects, solve study doubts, take quizzes, and organize revision notes!";
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
        $unclearReply = ($consecutiveUnclear >= 1)
            ? "Enakku theriyadhu, $preferredName. Please ask something related to your subjects, notes, or quizzes."
            : "Enakku puriyala, $preferredName. Konjam clear-aa enna subject or topic venum nu sollunga!";
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
    $assetPath = 'C:/Users/SCAD LAB/AndroidStudioProjects/SYNAPSE/app/src/main/assets/educational_knowledge_base.json';
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
    $isElaborated = (strpos($normQuery, 'elaborate') !== false || strpos($normQuery, 'in detail') !== false || strpos($normQuery, 'deep dive') !== false);

    if ($bestChunk !== null && $bestScore >= 12.0 && $normalizedScore >= 0.25) {
        $subject = $bestChunk['subject'] ?? 'University Curriculum';
        $chapter = $bestChunk['chapter'] ?? $bestChunk['topic'] ?? '';
        $topic = $bestChunk['topic'] ?? $chapter;
        $def = trim($bestChunk['definition'] ?? '');
        $formula = trim($bestChunk['formula'] ?? $bestChunk['formulaOrCoreRule'] ?? '');
        $example = trim($bestChunk['worked_example'] ?? $bestChunk['workedExample'] ?? '');
        $content = trim($bestChunk['content'] ?? '');

        $bPrefix = $useEmojis ? "📘 " : "* ";
        $fPrefix = $useEmojis ? "⚡ " : "* ";
        $ePrefix = $useEmojis ? "💡 " : "* ";

        if ($isElaborated) {
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
    $academicWords = ['syllabus', 'curriculum', 'study', 'exam', 'college', 'university', 'engineering', 'degree', 'course', 'major'];
    $isAcademic = false;
    foreach ($academicWords as $aw) {
        if (strpos($normQuery, $aw) !== false) {
            $isAcademic = true;
            break;
        }
    }

    if ($isAcademic) {
        $cleanTopic = ucwords(preg_replace('/(?i)^(what is|explain|tell me about|how does|what are|define)\s+/', '', $question));
        $p1 = "* **SYNAPSE AI University Study Companion**\n\n$cleanTopic is an important academic topic in higher education curricula. Mastering this concept equips students with rigorous conceptual grounding, mathematical logic, and analytical problem-solving methodologies.";
        $p2 = "* **Core Study Framework & Key Principles:**\n\n• **Theoretical Foundations:** Review primary definitions, governing laws, and underlying assumptions for $cleanTopic.\n• **Analytical Derivations:** Break down complex multi-step problems, verify boundary conditions, and analyze tradeoffs.\n• **Real-World Application:** Relate theoretical equations to modern scientific and engineering implementations.\n\nWhich specific subject or chapter are you studying? Let me know your question and we'll break it down together!";

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'question' => $question,
                'paragraph1' => $p1,
                'paragraph2' => $p2,
                'answer' => $p1 . "\n\n" . $p2,
                'matched_subject' => 'University Curriculum',
                'matched_chapter' => $cleanTopic,
                'matched_topic' => $cleanTopic,
                'confidence_score' => 70.0,
                'tutor_name' => 'SYNAPSE AI',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // Unclear query fallback (zero hardcoded topic fallback!)
    $unclearReply = ($consecutiveUnclear >= 1)
        ? "Enakku theriyadhu, $preferredName. Please ask something related to your subjects, notes, or quizzes."
        : "Enakku puriyala, $preferredName. Konjam clear-aa enna subject or topic venum nu sollunga!";

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
