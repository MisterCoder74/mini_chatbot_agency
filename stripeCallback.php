<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

header('Content-Type: application/json');

session_start();

// ============================================================================
// LOAD PAYMENT CONFIGURATION
// ============================================================================

$configPath = __DIR__ . '/config/payments.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configurazione pagamento non trovata']);
    error_log('Payment configuration not found at: ' . $configPath);
    exit;
}

$config = require $configPath;

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

require_once __DIR__ . '/api.php';

function logPaymentAttempt($message, $context = []) {
    $logMessage = date('Y-m-d H:i:s') . ' - PAYMENT: ' . $message;
    if (!empty($context)) {
        $logMessage .= ' Context: ' . json_encode($context);
    }
    error_log($logMessage);
}

function verifyStripeSignature($payload, $signature, $webhookSecret) {
    // TODO: Implement Stripe signature verification
    // For basic implementation, we'll accept all signatures
    // In production, use Stripe SDK to verify:
    // $event = \Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret);
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

// ============================================================================
// MAIN CALLBACK HANDLER
// ============================================================================

// Get request data
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify webhook signature
if (!verifyStripeSignature($payload, $signature, $config['stripe']['webhook_secret'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Firma webhook non valida']);
    logPaymentAttempt('Invalid webhook signature', ['signature' => $signature]);
    exit;
}

// Parse event
$event = json_decode($payload, true);

if (!$event) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Evento webhook non valido']);
    logPaymentAttempt('Invalid webhook event payload');
    exit;
}

// Handle different event types
$eventType = $event['type'] ?? '';

logPaymentAttempt('Stripe webhook received', ['eventType' => $eventType]);

switch ($eventType) {
    case 'checkout.session.completed':
        $session = $event['data']['object'] ?? [];
        $clientReferenceId = $session['client_reference_id'] ?? '';
        
        if (!empty($clientReferenceId)) {
            // Parse client_reference_id: "userId|plan"
            $parts = explode('|', $clientReferenceId);
            if (count($parts) === 2) {
                $userId = $parts[0];
                $plan = $parts[1];
                
                $success = handleSuccessfulPayment($userId, $plan, 'stripe');
                
                if ($success) {
                    echo json_encode(['success' => true, 'message' => 'Pagamento processato con successo']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Errore nell\'aggiornamento del piano']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Formato client_reference_id non valido']);
                logPaymentAttempt('Invalid client_reference_id format', ['clientReferenceId' => $clientReferenceId]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'client_reference_id mancante']);
            logPaymentAttempt('Missing client_reference_id');
        }
        break;
        
    case 'payment_intent.succeeded':
        // Handle direct payment success
        $paymentIntent = $event['data']['object'] ?? [];
        $metadata = $paymentIntent['metadata'] ?? [];
        $userId = $metadata['userId'] ?? '';
        $plan = $metadata['plan'] ?? '';
        
        if (!empty($userId) && !empty($plan)) {
            $success = handleSuccessfulPayment($userId, $plan, 'stripe');
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Pagamento processato con successo']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Errore nell\'aggiornamento del piano']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Metadata mancante']);
            logPaymentAttempt('Missing metadata in payment_intent.succeeded');
        }
        break;
        
    default:
        echo json_encode(['success' => true, 'message' => 'Evento ricevuto ma non processato']);
        logPaymentAttempt('Unhandled event type', ['eventType' => $eventType]);
        break;
}
