<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

session_start();

header('Content-Type: application/html; charset=utf-8');

// ============================================================================
// LOAD PAYMENT CONFIGURATION
// ============================================================================

$configPath = __DIR__ . '/config/payments.php';
if (!file_exists($configPath)) {
    die('<html><body><h3>Errore: Configurazione pagamento non trovata</h3><a href="index.html">Torna alla home</a></body></html>');
}

$config = require $configPath;

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

require_once __DIR__ . '/api.php';

function logPaymentAttempt($message, $context = []) {
    $logMessage = date('Y-m-d H:i:s') . ' - PAYPAL CALLBACK: ' . $message;
    if (!empty($context)) {
        $logMessage .= ' Context: ' . json_encode($context);
    }
    error_log($logMessage);
}

function verifyPayPalIPN($ipnData, $businessEmail) {
    // TODO: Implement PayPal IPN verification
    // For basic implementation, we'll verify using PayPal's IPN validation endpoint
    // In production, you should:
    // 1. Send the IPN data back to PayPal for verification
    // 2. Verify the response is "VERIFIED"
    // 3. Verify the receiver_email matches your business email
    
    // Basic check for success parameter in return URL
    return true;
}

function handleSuccessfulPayment($userId, $plan, $paymentMethod) {
    $user = findUserById($userId);
    
    if (!$user) {
        logPaymentAttempt('User not found', ['userId' => $userId]);
        return false;
    }
    
    // Update user plan and subscription
    $user['plan'] = $plan;
    $user['subscription'] = [
        'status' => 'active',
        'plan' => $plan,
        'nextBillingDate' => date('Y-m-d', strtotime('+1 month')),
        'lastPaymentDate' => date('Y-m-d'),
        'paymentMethod' => $paymentMethod
    ];
    
    // Clear pending payment fields
    unset($user['subscription']['pendingSessionId']);
    unset($user['subscription']['pendingPaymentId']);
    
    $success = updateUser($user);
    
    if ($success) {
        logPaymentAttempt('Plan upgraded successfully', [
            'userId' => $userId,
            'plan' => $plan,
            'paymentMethod' => $paymentMethod
        ]);
        
        // Optional: Send confirmation email
        $subject = "Upgrade piano completato";
        $message = "Ciao {$user['name']},\n\nIl tuo piano è stato aggiornato a {$plan} con successo!\n\nGrazie,\nChatBot Hub";
        sendEmail($user['email'], $subject, $message);
    }
    
    return $success;
}

function showErrorPage($message) {
    echo '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Errore Pagamento - ChatBot Hub</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 500px;
            text-align: center;
        }
        h1 { color: #dc2626; }
        .btn {
            background: #04be6d;
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            display: inline-block;
            margin-top: 1rem;
        }
        .btn:hover { background: #03a85f; }
    </style>
</head>
<body>
    <div class="container">
        <h1>❌ Pagamento Fallito</h1>
        <p>' . htmlspecialchars($message) . '</p>
        <a href="index.html" class="btn">Torna alla Home</a>
    </div>
</body>
</html>';
}

function showSuccessPage($message) {
    echo '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento Completato - ChatBot Hub</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 500px;
            text-align: center;
        }
        h1 { color: #059669; }
        .btn {
            background: #04be6d;
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            display: inline-block;
            margin-top: 1rem;
        }
        .btn:hover { background: #03a85f; }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ Pagamento Completato</h1>
        <p>' . htmlspecialchars($message) . '</p>
        <a href="index.html" class="btn">Vai alla Dashboard</a>
    </div>
</body>
</html>';
}

// ============================================================================
// MAIN CALLBACK HANDLER
// ============================================================================

// Get GET and POST parameters
$status = $_GET['status'] ?? $_POST['status'] ?? '';
$paymentId = $_GET['paymentId'] ?? $_POST['paymentId'] ?? '';
$custom = $_GET['custom'] ?? $_POST['custom'] ?? '';
$userId = $_GET['userId'] ?? '';
$plan = $_GET['plan'] ?? '';

logPaymentAttempt('PayPal callback received', [
    'status' => $status,
    'paymentId' => $paymentId,
    'custom' => $custom,
    'userId' => $userId,
    'plan' => $plan
]);

// Handle return from PayPal checkout (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Parse custom field if provided: "userId|plan"
    if (!empty($custom)) {
        $parts = explode('|', $custom);
        if (count($parts) === 2) {
            $userId = $parts[0];
            $plan = $parts[1];
        }
    }
    
    // Check if payment was successful
    if ($status === 'success' && !empty($userId) && !empty($plan)) {
        // Verify IPN (in a real implementation)
        $verified = verifyPayPalIPN($_GET, $config['paypal']['business_email']);
        
        if ($verified) {
            $success = handleSuccessfulPayment($userId, $plan, 'paypal');
            
            if ($success) {
                showSuccessPage("Il tuo piano è stato aggiornato a {$plan}! Grazie per il pagamento.");
            } else {
                showErrorPage("Il pagamento è andato a buon fine ma c'è stato un errore nell'aggiornamento del tuo piano. Contatta il supporto.");
                logPaymentAttempt('Payment succeeded but plan update failed', ['userId' => $userId, 'plan' => $plan]);
            }
        } else {
            showErrorPage("Verifica pagamento fallita. Contatta il supporto se il problema persiste.");
            logPaymentAttempt('IPN verification failed');
        }
    } else if ($status === 'cancel') {
        showErrorPage("Hai annullato il pagamento. Nessun addebito è stato effettuato.");
    } else {
        showErrorPage("Parametri di risposta non validi.");
        logPaymentAttempt('Invalid callback parameters', [
            'status' => $status,
            'userId' => $userId,
            'plan' => $plan
        ]);
    }
} 
// Handle IPN (Instant Payment Notification) - POST request
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Parse custom field
    if (!empty($custom)) {
        $parts = explode('|', $custom);
        if (count($parts) === 2) {
            $userId = $parts[0];
            $plan = $parts[1];
        }
    }
    
    $paymentStatus = $_POST['payment_status'] ?? '';
    
    if ($paymentStatus === 'Completed' && !empty($userId) && !empty($plan)) {
        // Verify the payment is for our business
        $receiverEmail = $_POST['receiver_email'] ?? '';
        if ($receiverEmail !== $config['paypal']['business_email']) {
            logPaymentAttempt('Invalid receiver email', ['receiverEmail' => $receiverEmail]);
            die('Invalid receiver email');
        }
        
        // Verify currency and amount (optional but recommended)
        $mcCurrency = $_POST['mc_currency'] ?? '';
        $mcGross = $_POST['mc_gross'] ?? '';
        
        $expectedAmounts = ['basic' => '9.99', 'premium' => '19.99'];
        $expectedAmount = $expectedAmounts[$plan] ?? '';
        
        if ($mcCurrency !== 'EUR' || $mcGross !== $expectedAmount) {
            logPaymentAttempt('Invalid payment amount or currency', [
                'expected' => $expectedAmount . ' EUR',
                'received' => $mcGross . ' ' . $mcCurrency
            ]);
            die('Invalid payment amount or currency');
        }
        
        $success = handleSuccessfulPayment($userId, $plan, 'paypal');
        
        if ($success) {
            logPaymentAttempt('IPN processed successfully', ['userId' => $userId, 'plan' => $plan]);
        } else {
            logPaymentAttempt('IPN: Plan update failed', ['userId' => $userId, 'plan' => $plan]);
        }
    }
    
    // Always respond to PayPal's IPN
    http_response_code(200);
    echo 'IPN received';
}
