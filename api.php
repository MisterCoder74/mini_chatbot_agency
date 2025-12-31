<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Data files
$usersFile = 'data/users.json';


// Ensure data directory exists
if (!file_exists('data')) {
    mkdir('data', 0755, true);
}

// Initialize files if they don't exist
if (!file_exists($usersFile)) {
    file_put_contents($usersFile, json_encode([]));
}


// Helper functions
function loadUsers() {
    global $usersFile;
    $data = file_get_contents($usersFile);
    return json_decode($data, true) ?: [];
}

function saveUsers($users) {
    global $usersFile;
    return file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}


function findUserById($userId) {
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['id'] === $userId) {
            return $user;
        }
    }
    return null;
}

function findUserByEmail($email) {
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['email'] === $email) {
            return $user;
        }
    }
    return null;
}

function updateUser($userData) {
    $users = loadUsers();
    for ($i = 0; $i < count($users); $i++) {
        if ($users[$i]['id'] === $userData['id']) {
            $users[$i] = $userData;
            saveUsers($users);
            return true;
        }
    }
    return false;
}

function resetUsageIfNeeded(&$user) {
$today = date('Y-m-d');
$currentMonth = date('Y-m');

// Reset giornaliero immagini
if (!isset($user['usage']['lastReset']) || $user['usage']['lastReset'] !== $today) {
$user['usage']['images'] = 0;
$user['usage']['lastReset'] = $today;
}

// Reset mensile messaggi
if (!isset($user['usage']['lastMessageReset']) || substr($user['usage']['lastMessageReset'], 0, 7) !== $currentMonth) {
$user['usage']['messages'] = 0;
$user['usage']['lastMessageReset'] = $today;
}

updateUser($user);
}

function getPlanLimits($plan) {
    $limits = [
        'free' => ['messages' => 100, 'images' => 3],
        'basic' => ['messages' => 5000, 'images' => 10],
        'premium' => ['messages' => PHP_INT_MAX, 'images' => PHP_INT_MAX]
    ];
    return $limits[$plan] ?? $limits['free'];
}

function getHistoryLimit($plan) {
switch ($plan) {
case 'premium':
return 100;
case 'basic':
return 50;
default:
return 20;
}
}

function canSendMessage($user) {
    $limits = getPlanLimits($user['plan']);
    return $user['plan'] === 'premium' || $user['usage']['messages'] < $limits['messages'];
}

function canGenerateImage($user) {
    resetUsageIfNeeded($user);
    $limits = getPlanLimits($user['plan']);
    return $user['plan'] === 'premium' || $user['usage']['images'] < $limits['images'];
}

function generateId() {
    return uniqid('', true);
}

// OpenAI API call
function callOpenAI($message, $personality = '', $model = 'gpt-4o-mini') {
    // Get API key from user settings or global settings
    $user = findUserById($_SESSION['user_id']);
    $apiKey = $user['settings']['openaiKey'] ?? '';
    
    if (empty($apiKey)) {
        return "Errore: API Key OpenAI non configurata. Vai nelle impostazioni per configurarla.";
    }
    
    $messages = [];
    
    // Add system personality if provided
    if (!empty($personality)) {
        $messages[] = [
            'role' => 'system',
            'content' => $personality
        ];
    }
    
    // Add user message
    $messages[] = [
        'role' => 'user',
        'content' => $message
    ];
    
    $data = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 5000,
        'temperature' => 0.7
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return "Errore API OpenAI: " . $httpCode;
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['error'])) {
        return "Errore OpenAI: " . $result['error']['message'];
    }
    
    return $result['choices'][0]['message']['content'] ?? 'Risposta non disponibile';
}

function callDallE($prompt) {
    // Get API key from user settings
    $user = findUserById($_SESSION['user_id']);
    $apiKey = $user['settings']['openaiKey'] ?? '';
    
    if (empty($apiKey)) {
        return ['error' => 'API Key OpenAI non configurata'];
    }
    
    $data = [
        'model' => 'dall-e-3',
        'prompt' => $prompt,
        'n' => 1,
        'size' => '1024x1024',
        'quality' => 'standard'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/images/generations');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => 'Errore API DALL-E: ' . $httpCode];
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['error'])) {
        return ['error' => 'Errore DALL-E: ' . $result['error']['message']];
    }
    
    return ['url' => $result['data'][0]['url'] ?? null];
}

// API Routes
switch ($action) {
    case 'checkAuth':
        if (isset($_SESSION['user_id'])) {
            $user = findUserById($_SESSION['user_id']);
            if ($user) {
                resetUsageIfNeeded($user);
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false]);
            }
        } else {
            echo json_encode(['success' => false]);
        }
        break;

    case 'register':
        $name = $input['name'] ?? '';
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($name) || empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Tutti i campi sono obbligatori']);
            break;
        }
        
        if (findUserByEmail($email)) {
            echo json_encode(['success' => false, 'message' => 'Email già registrata']);
            break;
        }
        
        $users = loadUsers();
        $newUser = [
            'id' => generateId(),
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'plan' => 'free',
            'usage' => [
                'messages' => 0,
                'images' => 0,
                'lastReset' => date('Y-m-d')
            ],
            'bots' => [],
            'settings' => []
        ];
        
        $users[] = $newUser;
        saveUsers($users);
        
        echo json_encode(['success' => true, 'message' => 'Registrazione completata']);
        break;

    case 'login':
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Email e password richiesti']);
            break;
        }
        
        $user = findUserByEmail($email);
        
        // Confronto diretto delle password in chiaro
    if ($user && $user['password'] === $password) {
        $_SESSION['user_id'] = $user['id'];
        unset($user['password']); // Non inviare la password al client
        resetUsageIfNeeded($user);
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Credenziali non valide']);
    }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'upgradePlan':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $plan = $input['plan'] ?? '';
        $allowedPlans = ['basic', 'premium'];
        
        if (!in_array($plan, $allowedPlans)) {
            echo json_encode(['success' => false, 'message' => 'Piano non valido']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $user['plan'] = $plan;
        updateUser($user);
        
        echo json_encode(['success' => true, 'message' => 'Piano aggiornato']);
        break;

    case 'createBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $name = $input['name'] ?? '';
        $personality = $input['personality'] ?? '';
        $model = $input['model'] ?? 'gpt-3.5-turbo';
        
        if (empty($name) || empty($personality)) {
            echo json_encode(['success' => false, 'message' => 'Nome e personalità richiesti']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $newBot = [
            'id' => generateId(),
            'name' => $name,
            'personality' => $personality,
            'model' => $model,
            'conversations' => [],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $user['bots'][] = $newBot;
        updateUser($user);
        
        echo json_encode(['success' => true, 'bot' => $newBot]);
        break;

    case 'getBots':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        echo json_encode(['success' => true, 'bots' => $user['bots'] ?? []]);
        break;

    case 'getBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $botId = $input['botId'] ?? '';
        if (empty($botId)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID richiesto']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $bot = null;
        foreach ($user['bots'] as $userBot) {
            if ($userBot['id'] === $botId) {
                $bot = $userBot;
                break;
            }
        }
        
        if (!$bot) {
            echo json_encode(['success' => false, 'message' => 'Bot non trovato']);
            break;
        }
        
        echo json_encode(['success' => true, 'bot' => $bot]);
        break;

    case 'deleteBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $botId = $input['botId'] ?? '';
        if (empty($botId)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID richiesto']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $newBots = [];
        $found = false;
        foreach ($user['bots'] as $bot) {
            if ($bot['id'] !== $botId) {
                $newBots[] = $bot;
            } else {
                $found = true;
            }
        }
        
        if (!$found) {
            echo json_encode(['success' => false, 'message' => 'Bot non trovato']);
            break;
        }
        
        $user['bots'] = $newBots;
        updateUser($user);
        
        echo json_encode(['success' => true]);
        break;

    case 'sendMessage':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $botId = $input['botId'] ?? '';
        $message = $input['message'] ?? '';
        $history = $input['history'] ?? [];
        
        if (empty($botId) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID e messaggio richiesti']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        if (!canSendMessage($user)) {
            echo json_encode(['success' => false, 'message' => 'Limite messaggi raggiunto']);
            break;
        }
        
        // Find bot
        $bot = null;
        $botIndex = -1;
        foreach ($user['bots'] as $index => $userBot) {
            if ($userBot['id'] === $botId) {
                $bot = $userBot;
                $botIndex = $index;
                break;
            }
        }
        
        if (!$bot) {
            echo json_encode(['success' => false, 'message' => 'Bot non trovato']);
            break;
        }
        
        // Generate response using real OpenAI API
        $response = callOpenAI($message, $bot['personality'], $bot['model']);

        // Add assistant reply to history before saving
        $history[] = [
        'role' => 'assistant',
        'content' => $response
        ];        

        // Calcola limite in base al piano dell'utente
        $maxMessages = getHistoryLimit($user['plan']);
                
        // Verifica se siamo vicini alla saturazione (solo per premium)
        $nearLimit = false;
        if ($user['plan'] === 'premium' && count($history) >= ($maxMessages - 4)) {
        $nearLimit = true;
        }               

        // Mantieni solo la parte finale della history
        if (count($history) > $maxMessages) {
        $history = array_slice($history, -$maxMessages);
        }
                
        // Update conversation history
        $user['bots'][$botIndex]['conversations'] = $history;
        
        // Update usage
        $user['usage']['messages']++;
        updateUser($user);
        
        echo json_encode([
        'success' => true,
        'response' => $response,
        'usage' => $user['usage'],
        'conversation' => $user['bots'][$botIndex]['conversations'],
        'nearLimit' => $nearLimit // ⚠️ obbligatorio
        ]);
        break;

    case 'generateImage':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $botId = $input['botId'] ?? '';
        $prompt = $input['prompt'] ?? '';
        
        if (empty($botId) || empty($prompt)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID e prompt richiesti']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        if (!canGenerateImage($user)) {
            echo json_encode(['success' => false, 'message' => 'Limite immagini raggiunto']);
            break;
        }
        
        // Generate image using real DALL-E API
        $imageResult = callDallE($prompt);
        
        if (isset($imageResult['error'])) {
            echo json_encode(['success' => false, 'message' => $imageResult['error']]);
            break;
        }
        
        $imageUrl = $imageResult['url'];
        
        // Update usage
        $user['usage']['images']++;
        updateUser($user);

        echo json_encode([
        'success' => true,
        'imageUrl' => $imageUrl,
        'usage' => $user['usage'] // <-- aggiunto
        ]);
        break;

    case 'saveSettings':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $openaiKey = $input['openaiKey'] ?? '';
        
        if (empty($openaiKey)) {
            echo json_encode(['success' => false, 'message' => 'API Key richiesta']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $user['settings']['openaiKey'] = $openaiKey;
        updateUser($user);
        
        echo json_encode(['success' => true, 'message' => 'Impostazioni salvate']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Azione non valida']);
        break;
}
?>