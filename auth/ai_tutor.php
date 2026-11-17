<?php
// AI Tutor API Endpoint - Synapse AI Backend with RAG Weighted Scoring & Stemming
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-User-Email, X-User-Id, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

    $normQuery = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $question)));

    // 1. Identity & Tanglish Pre-Retrieval Routing
    $identityPhrases = [
        'who are you', 'what is your name', 'whats your name', 'tell me your name',
        'nee yaru', 'neenga yaru', 'un peru enna', 'unga peru enna', 'un per enna', 'unga per enna',
        'nee yar', 'neenga yar', 'yaaru nee', 'yaar nee', 'yaaru neenga', 'yaar neenga',
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
        'how are you' => "I'm doing great and feeling energized! How's your study session going today?",
        'hows it going' => "I'm doing great! What subject shall we tackle today?",
        'good morning' => "Good morning! Ready to learn something exciting today? What subject are we tackling?",
        'good afternoon' => "Good afternoon! Hope you're having a productive day. What subject shall we explore?",
        'good evening' => "Good evening! Let's do some focused study tonight. What can I help you understand?",
        'thank you' => "You're very welcome! Always happy to help. Let me know if you have any other doubts!",
        'thanks' => "You're very welcome! Keep up the great effort!",
        'you are awesome' => "Thank you so much! You're awesome for putting in the effort to study. Let's keep it going!",
        'you are great' => "Thank you! I'm here anytime you need a study boost.",
        'i am bored' => "How about a quick 3-minute quiz or learning a fascinating fact in science or tech? Pick a subject and let's make it fun!",
        'im bored' => "How about a quick 3-minute quiz or learning a fascinating fact in science or tech? Pick a subject and let's make it fun!",
        'i am stressed about exams' => "Take a deep breath! You've got this. Let's break your revision down into bite-sized 10-minute topics so you feel confident and in control.",
        'stressed about exams' => "Take a deep breath! You've got this. Let's tackle one small topic at a time.",
        'motivate me' => "Every expert was once a beginner who refused to quit. Focus on mastering just one concept right now—progress adds up fast!",
        'who made you' => "I was built by the SYNAPSE AI team to be your personal university study companion and learning buddy!",
        'are you smart' => "I know 50+ college subjects inside and out, but my favorite thing is helping you become the smartest person in the room!",
        'hello friend' => "Hey friend! Great to see you. What subject shall we dive into today?",
        'hi friend' => "Hey friend! Ready for a great study session? What are we working on?"
    ];

    foreach ($smallTalk as $key => $reply) {
        if ($normQuery === $key || strpos($normQuery, $key) !== false) {
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

    // 2. Out-of-Scope Pre-Check (Fast Guardrail)
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
        $boundary = "Sorry, that's not relatable to our studies or app! Ask me any subject concept, quiz, or study note instead.";
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

    // 1. Stop-Words & Generic Subject Words
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
        if (substr($w, -3) === 'ics') return $w; // Preserves physics, mechanics, optics, robotics
        if (substr($w, -3) === 'ies' && strlen($w) > 4) return substr($w, 0, -3) . 'y';
        if (substr($w, -3) === 'ves' && strlen($w) > 4) return substr($w, 0, -1); // waves -> wave, derivatives -> derivative
        if (preg_match('/(shes|ches|sses|xes|zes)$/', $w)) return substr($w, 0, -2);
        if (substr($w, -1) === 's' && substr($w, -2) !== 'ss' && substr($w, -2) !== 'us' && substr($w, -2) !== 'is' && strlen($w) > 3) {
            return substr($w, 0, -1); // equations -> equation, laws -> law, algorithms -> algorithm
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

    // 2. Load Knowledge Base Chunks
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

    // If file could not be read, provide comprehensive core university chunks fallback
    if (empty($chunks)) {
        $chunks = [
            [
                'chunk_id' => 'PHYS_001',
                'subject' => 'Physics',
                'chapter' => "Newton's Laws of Motion & Classical Mechanics",
                'topic' => "Newton's Laws of Motion & Classical Mechanics",
                'keywords' => ['newton', 'newton law', 'newtons laws', "newton's laws", 'classical mechanics', 'force', 'mass', 'acceleration', 'f = ma', 'inertia', 'momentum'],
                'definition' => "Newton's laws of motion define the relationship between the forces acting on a body and its resulting motion under classical mechanics.",
                'formula' => "First Law: Inertia (sum(F)=0 -> v=const). Second Law: F = m * a (Force = mass * acceleration). Third Law: F_AB = -F_BA.",
                'worked_example' => "Accelerating a 1,200 kg car at 2.5 m/s^2 requires net horizontal force F = 1,200 * 2.5 = 3,000 N.",
                'content' => "Classical mechanics assumes speeds much lower than the speed of light where mass remains invariant."
            ],
            [
                'chunk_id' => 'PHYS_002',
                'subject' => 'Physics',
                'chapter' => 'Waves, Oscillations & Wave Mechanics',
                'topic' => 'Waves, Oscillations & Wave Mechanics',
                'keywords' => ['wave', 'waves', 'wavelength', 'frequency', 'amplitude', 'transverse', 'longitudinal', 'sound', 'oscillation', 'oscillations', 'v = f lambda', 'wave mechanics', 'doppler effect'],
                'definition' => 'A wave is a disturbance or oscillation that transfers energy through space or a medium without permanent displacement of matter.',
                'formula' => 'Wave speed: v = f * lambda. Time period: T = 1 / f. Angular frequency: omega = 2 * pi * f. Wave number: k = 2 * pi / lambda.',
                'worked_example' => 'Transverse waves include light and electromagnetic waves. Longitudinal waves include sound waves in air at 343 m/s. For a 440 Hz sound wave in air: lambda = 343 / 440 = 0.78 meters.',
                'content' => 'Waves are categorized into Transverse waves (oscillations perpendicular to propagation direction, e.g. light and electromagnetic waves) and Longitudinal waves (oscillations parallel to propagation direction, e.g. acoustic sound waves in air at 343 m/s). Mechanical waves require an elastic material medium, while electromagnetic waves propagate across vacuum.'
            ],
            [
                'chunk_id' => 'MATH_003',
                'subject' => 'Mathematics',
                'chapter' => 'Linear Algebra, Matrices & Vector Spaces',
                'topic' => 'Eigenvalues and Eigenvectors',
                'keywords' => ['eigenvalue', 'eigenvalues', 'eigenvector', 'eigenvectors', 'characteristic equation', 'determinant', 'matrix', 'matrices', 'linear algebra', 'av = lambda v'],
                'definition' => 'An eigenvalue is a scalar lambda associated with a linear transformation matrix A such that Av = lambda * v for non-zero eigenvector v.',
                'formula' => 'Characteristic equation: det(A - lambda * I) = 0. Trace equals sum of eigenvalues; determinant equals product of eigenvalues.',
                'worked_example' => 'For A = [[4, 1], [2, 3]], det(A - lambda*I) = (4 - lambda)(3 - lambda) - 2 = lambda^2 - 7*lambda + 10 = 0 -> lambda_1 = 5, lambda_2 = 2.',
                'content' => 'Eigenvalues govern matrix diagonalization, stability in differential systems, Principal Component Analysis, and quantum state measurements.'
            ],
            [
                'chunk_id' => 'AI_002',
                'subject' => 'Artificial Intelligence',
                'chapter' => 'Artificial Neural Networks & Backpropagation',
                'topic' => 'Backpropagation Algorithm and Gradient Descent',
                'keywords' => ['backpropagation', 'backprop', 'gradient descent', 'chain rule', 'loss function', 'neural network', 'weights update', 'deep learning'],
                'definition' => 'Backpropagation is an optimization algorithm that computes the gradient of a neural network loss function with respect to weights using the mathematical chain rule.',
                'formula' => 'Weight update: W := W - eta * (dLoss / dW). Error delta_j = (dLoss / dz_j).',
                'worked_example' => 'In a single-neuron regression, if predicted y_hat = 0.8 and true y = 1.0, error is -0.2. The gradient updates input weights backwards to minimize squared error.',
                'content' => 'Backpropagation propagates errors backwards from output layer to input layer, enabling multi-layer perceptrons and deep architectures to learn complex representations.'
            ]
        ];
    }

    // 3. Normalized Scoring with Stemming
    $normQuery = strtolower(trim($question));
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

        // Score Concept Tokens
        foreach ($conceptTokens as $ct) {
            $cStem = synapse_stem($ct);
            $tokenMatched = false;

            // Chapter Title / Topic Match (+15 points per matched token)
            if (in_array($ct, $titleTokens, true) || in_array($cStem, $titleStems, true) || strpos($normTopic, $ct) !== false || strpos($normChapter, $ct) !== false || strpos($normTopic, $cStem) !== false) {
                $score += 15.0;
                $tokenMatched = true;
            }

            // Keyword Match (+10 points per matched token)
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

            // Definition / Content Body Match (+3 points per matched token)
            if (in_array($cStem, $bodyStems, true) || strpos($normDef, $ct) !== false || strpos($normContent, $ct) !== false || strpos($normDef, $cStem) !== false) {
                $score += 3.0;
                $tokenMatched = true;
            }

            if ($tokenMatched) {
                $matchedConceptTokens++;
            }
        }

        // Multi-word phrase bonus (+15 points)
        foreach ($kwPhrases as $kw) {
            if (strlen($kw) >= 4 && strpos($normQuery, $kw) !== false && !in_array($kw, $genericSubjectWords, true)) {
                $score += 15.0;
                $matchedConceptTokens++;
            }
        }

        // Generic Subject Name Match (+1 point tie-breaker ONLY when concept matched)
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

        if ($isElaborated) {
            $p1 = "🔍 **In-Depth Breakdown: $topic** ($subject · $chapter)\n\n**1. Theoretical Foundations & Mechanism:**\n$content\n\n**2. Mathematical Formulations & Derivations:**\n$formula";
            $p2 = "**3. Comprehensive Practical Application:**\n$example\n\n**4. High-Yield Exam Takeaway:**\nMastering $topic requires remembering key governing assumptions, units, and boundary conditions.";
            $fullAnswer = $p1 . "\n\n" . $p2;
        } else {
            // Concise-by-default (3-5 lines max: 1-sentence definition + key formula/rule + 1 quick example)
            $p1 = "📘 **$topic** ($subject)\n$def\n• **Rule/Formula:** $formula\n• **Example:** $example";
            $p2 = "";
            $fullAnswer = $p1;
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
        $p1 = "🎓 **SYNAPSE AI University Study Companion**\n\n$cleanTopic is an important academic topic in higher education curricula. Mastering this concept equips students with rigorous conceptual grounding, mathematical logic, and analytical problem-solving methodologies.";
        $p2 = "⚡ **Core Study Framework & Key Principles:**\n\n• **Theoretical Foundations:** Review primary definitions, governing laws, and underlying assumptions for $cleanTopic.\n• **Analytical Derivations:** Break down complex multi-step problems, verify boundary conditions, and analyze tradeoffs.\n• **Real-World Application:** Relate theoretical equations to modern scientific and engineering implementations.\n\nWhich specific subject or chapter are you studying? Let me know your question and we'll break it down together!";

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

    // Strict Out-of-Scope Guardrail Response (zero random hallucinations)
    $boundary = "That falls outside my academic and app scope. I'm SYNAPSE AI—built specifically to help you master your college subjects, solve study doubts, take quizzes, and organize revision notes!";
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

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'AI Tutor error: ' . $e->getMessage()
    ]);
}
