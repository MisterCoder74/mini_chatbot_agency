# Stripe & PayPal Payment Integration

This document describes the payment integration implementation for the ChatBot Hub application.

## Overview

The payment integration allows users to upgrade their plan from Free to Basic (€9.99/month) or Premium (€19.99/month) using either Stripe or PayPal as payment methods.

## Architecture

### Backend Components

1. **api.php - initiatePayment Action**
   - Validates user authentication
   - Validates plan (basic/premium) and payment method (stripe/paypal)
   - Generates Stripe checkout URL or PayPal form data
   - Stores pending payment in user's subscription object
   - Returns payment URL or form to frontend

2. **stripeCallback.php**
   - Webhook endpoint for Stripe payment notifications
   - Handles `checkout.session.completed` and `payment_intent.succeeded` events
   - Verifies webhook signature (TODO: implement real verification)
   - Updates user plan and subscription status
   - Logs all payment attempts to error.log
   - Sends optional confirmation email

3. **paypalCallback.php**
   - Return URL handler for PayPal checkout (GET request)
   - IPN (Instant Payment Notification) handler (POST request)
   - Verifies IPN (TODO: implement real verification)
   - Updates user plan and subscription status
   - Displays success/error pages in Italian
   - Logs all payment attempts to error.log
   - Sends optional confirmation email

### Frontend Components

1. **Payment Modal (index.html)**
   - Shows when user clicks "Aggiorna Piano" button
   - Displays selected plan and price
   - Provides two payment options:
     - 💳 Paga con Stripe
     - 🅿️ Paga con PayPal
   - Cancel button to close modal

2. **Payment Functions (index.html)**
   - `upgradePlan(plan)`: Shows payment modal for selected plan
   - `showPaymentModal(plan)`: Displays the modal with plan details
   - `closePaymentModal()`: Hides the modal and resets state
   - `initiateStripePayment()`: Calls API and redirects to Stripe
   - `initiatePayPalPayment()`: Calls API, creates form, submits to PayPal

### Configuration

1. **config/payments.php** (gitignored)
   - Contains actual API keys and credentials
   - Should be created from the example file
   - Use environment variables in production

2. **config/payments.example.php**
   - Template with all configuration options
   - Documents security requirements
   - Shows example values
   - Kept in version control

## User Subscription Object

```json
{
  "subscription": {
    "status": "active|pending_payment|cancelled",
    "plan": "free|basic|premium",
    "nextBillingDate": "2024-02-12",
    "lastPaymentDate": "2024-01-12",
    "paymentMethod": "stripe|paypal|none",
    "pendingSessionId": "stripe_session_id", // Stripe only
    "pendingPaymentId": "paypal_payment_id" // PayPal only
  }
}
```

## Payment Flow

### Stripe Flow

1. User clicks "Aggiorna Piano" on a plan card
2. Payment modal opens with plan details
3. User selects "💳 Paga con Stripe"
4. Frontend calls `initiatePayment` API with plan and method
5. Backend generates Stripe checkout URL and stores pending payment
6. Frontend redirects user to Stripe checkout
7. User completes payment on Stripe's hosted page
8. Stripe sends webhook to stripeCallback.php
9. Backend verifies payment (basic: accepts all)
10. Backend updates user plan and subscription
11. Backend sends confirmation email (optional)
12. User sees success page and can return to dashboard

### PayPal Flow

1. User clicks "Aggiorna Piano" on a plan card
2. Payment modal opens with plan details
3. User selects "🅿️ Paga con PayPal"
4. Frontend calls `initiatePayment` API with plan and method
5. Backend generates PayPal form data and stores pending payment
6. Frontend creates HTML form and submits to PayPal
7. User completes payment on PayPal's hosted page
8. PayPal redirects to paypalCallback.php (GET request)
9. Backend verifies payment (basic: accepts success parameter)
10. Backend updates user plan and subscription
11. Backend displays Italian success page
12. PayPal also sends IPN (POST request) as backup
13. Backend sends confirmation email (optional)
14. User clicks to return to dashboard

## Security Considerations

### Implemented

- ✅ No hardcoded API keys in code
- ✅ Configuration file gitignored (.gitignore updated)
- ✅ Input sanitization on all payment parameters
- ✅ User authentication required for payment initiation
- ✅ Plan validation (only basic/premium allowed)
- ✅ Payment method validation (only stripe/paypal allowed)
- ✅ File locking for user updates (concurrent access protection)
- ✅ HTTPS only requirement (documented in comments)
- ✅ Payment attempts logged to error.log
- ✅ No credit card data stored (Stripe/PayPal hosted pages)
- ✅ Sensitive fields removed from API responses

### TODO (Production Readiness)

- ⏳ Implement real Stripe signature verification using Stripe SDK
- ⏳ Implement real PayPal IPN verification
- ⏳ Configure environment variables for sensitive keys
- ⏳ Implement recurring billing handling (webhooks)
- ⏳ Add webhook retry logic
- ⏳ Implement refund handling
- ⏳ Add webhook signature validation in API calls
- ⏳ Add rate limiting for payment initiation
- ⏳ Implement payment timeout handling

## Configuration Guide

### Step 1: Create Payment Config File

Copy the example file and add your actual credentials:

```bash
cp config/payments.example.php config/payments.php
```

### Step 2: Configure Stripe

1. Get your Stripe API keys from https://dashboard.stripe.com/apikeys
2. Update `config/payments.php`:
   ```php
   'stripe' => [
       'public_key' => 'pk_test_...', // or pk_live_... for production
       'secret_key' => 'sk_test_...', // or sk_live_... for production
       'webhook_secret' => 'whsec_...', // Get from Stripe Dashboard > Webhooks
   ]
   ```

3. Create webhook in Stripe Dashboard:
   - Endpoint: `https://yourdomain.com/stripeCallback.php`
   - Events: `checkout.session.completed`, `payment_intent.succeeded`

### Step 3: Configure PayPal

1. Create a PayPal Business account
2. Update `config/payments.php`:
   ```php
   'paypal' => [
       'business_email' => 'your-business@paypal.com',
       'return_url' => 'https://yourdomain.com/paypalCallback.php?status=success',
       'cancel_url' => 'https://yourdomain.com/paypalCallback.php?status=cancel',
   ]
   ```

### Step 4: Update URLs

Replace placeholder URLs with your actual domain:
- `https://yourdomain.com/` → Your actual domain
- Update in both config and callback files

## Testing Checklist

### Basic Integration Testing (No Real Payments)

- [ ] Click "Aggiorna Piano" on Basic plan
- [ ] Verify payment modal opens with correct price (€9.99/mese)
- [ ] Click "💳 Paga con Stripe"
- [ ] Verify loading message and redirect
- [ ] Note: Will redirect to Stripe (will fail without real keys)
- [ ] Click "Aggiorna Piano" on Premium plan
- [ ] Verify payment modal opens with correct price (€19.99/mese)
- [ ] Click "🅿️ Paga con PayPal"
- [ ] Verify loading message and form submission
- [ ] Note: Will redirect to PayPal (will fail without real credentials)

### Success Scenario Testing (With Test Keys)

- [ ] Complete a Stripe test payment
- [ ] Verify user plan updates in users.json
- [ ] Check subscription object has correct status and dates
- [ ] Verify plan is reflected in UI on dashboard load
- [ ] Check confirmation email (if configured)
- [ ] Verify success page displays in Italian
- [ ] Complete a PayPal test payment
- [ ] Verify same checks as Stripe

### Error Scenario Testing

- [ ] Try to upgrade when already on same plan → Error message
- [ ] Try to upgrade to lower plan → Error message
- [ ] Try payment without authentication → Error message
- [ ] Try with invalid plan → Error message
- [ ] Try with invalid payment method → Error message
- [ ] Cancel payment → Return to dashboard
- [ ] Check error.log for payment attempts

### Security Testing

- [ ] Verify config/payments.php is in .gitignore
- [ ] Verify no API keys in code (search for 'pk_' and 'sk_')
- [ ] Verify payment initiation requires login
- [ ] Verify file locking works (concurrent payment attempts)
- [ ] Check error.log contains no sensitive data
- [ ] Verify session is maintained through payment flow

## File Structure

```
project/
├── api.php                          # Main API with initiatePayment action
├── index.html                       # Frontend with payment modal and functions
├── stripeCallback.php               # Stripe webhook handler
├── paypalCallback.php               # PayPal return URL + IPN handler
├── config/
│   ├── payments.example.php         # Configuration template (in git)
│   └── payments.php                # Actual config (gitignored)
├── data/
│   └── users.json                  # User data with subscription objects
├── error.log                       # Payment attempt logs
└── .gitignore                      # Updated to ignore payments.php
```

## Known Limitations

1. **Basic Implementation Only**: Currently accepts any "success" parameter without real payment verification
2. **No Real Keys**: Uses placeholder keys that won't work with actual payment providers
3. **One-time Payments**: No recurring billing implemented (webhooks need enhancement)
4. **No Refunds**: Refund handling not implemented
5. **Test Environment Only**: Not production-ready without real Stripe/PayPal configuration

## Future Enhancements

1. Implement proper webhook signature verification
2. Add recurring billing support
3. Implement payment history tracking
4. Add discount coupon support
5. Implement proration for plan upgrades
6. Add admin dashboard for payment management
7. Implement webhook retry mechanism
8. Add rate limiting for payment initiation
9. Implement fraud detection
10. Add multi-currency support

## Error Messages (Italian)

All error messages are in Italian as per requirements:
- "Non autenticato" - Not authenticated
- "Piano non valido" - Invalid plan
- "Metodo di pagamento non valido" - Invalid payment method
- "Utente non trovato" - User not found
- "Configurazione pagamento non trovata" - Payment configuration not found
- "Firma webhook non valida" - Invalid webhook signature
- "Il pagamento è andato a buon fine ma c'è stato un errore nell'aggiornamento del tuo piano. Contatta il supporto." - Payment succeeded but plan update failed

## Support

For issues or questions:
1. Check error.log for detailed error messages
2. Verify payment configuration is correct
3. Ensure file permissions allow writing to data/ and config/
4. Check that PHP error logging is enabled
5. Verify webhook endpoints are publicly accessible (for production)

## License

This implementation follows the same license as the main project.
