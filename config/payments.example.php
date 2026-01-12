<?php

/**
 * Payment Configuration Template
 * 
 * Copy this file to 'payments.php' and configure with your actual credentials.
 * 
 * SECURITY NOTES:
 * - NEVER commit your actual API keys to version control
 * - Store the real 'payments.php' file outside the web root if possible
 * - Use environment variables for sensitive data in production
 * - The 'payments.example.php' file should be kept in version control as a template
 * - The real 'payments.php' should be in .gitignore
 */

return [
    'stripe' => [
        // TODO: Configure real Stripe keys from environment or secure storage
        // Get your keys from https://dashboard.stripe.com/apikeys
        
        // Public key (pk_test_xxx for test, pk_live_xxx for production)
        'public_key' => 'pk_test_...',

        // Secret key (sk_test_xxx for test, sk_live_xxx for production)
        'secret_key' => 'sk_test_...',

        // Webhook secret (whsec_xxx) - configure in Stripe Dashboard > Webhooks
        // This endpoint receives payment confirmation webhooks from Stripe
        'webhook_secret' => 'whsec_...',

        // Webhook endpoint URL
        'webhook_endpoint' => 'https://yourdomain.com/stripeCallback.php',

        // Checkout configuration
        'success_url' => 'https://yourdomain.com/index.html',
        'cancel_url' => 'https://yourdomain.com/index.html',

        // Plan prices in EUR
        'prices' => [
            'basic' => [
                'amount' => 9.99,
                'currency' => 'EUR',
                'interval' => 'month'
            ],
            'premium' => [
                'amount' => 19.99,
                'currency' => 'EUR',
                'interval' => 'month'
            ]
        ]
    ],

    'paypal' => [
        // TODO: Configure PayPal merchant account details
        // Get your business email from https://www.paypal.com/business

        // Your PayPal business email (merchant account)
        'business_email' => 'merchant@example.com',

        // PayPal API credentials (optional, for advanced features)
        // For basic integration, only business_email is needed
        'api_credentials' => [
            'client_id' => '',
            'client_secret' => '',
            'mode' => 'sandbox' // Use 'sandbox' for testing, 'live' for production
        ],

        // Return URLs after PayPal checkout
        'return_url' => 'https://yourdomain.com/paypalCallback.php?status=success',
        'cancel_url' => 'https://yourdomain.com/paypalCallback.php?status=cancel',
        'notify_url' => 'https://yourdomain.com/paypalCallback.php', // IPN endpoint

        // Plan prices in EUR
        'prices' => [
            'basic' => [
                'amount' => '9.99',
                'currency' => 'EUR'
            ],
            'premium' => [
                'amount' => '19.99',
                'currency' => 'EUR'
            ]
        ]
    ]
];

/**
 * Environment Variable Configuration (Recommended for Production)
 * 
 * Instead of hardcoding values above, you can use environment variables:
 * 
 * return [
 *     'stripe' => [
 *         'public_key' => getenv('STRIPE_PUBLIC_KEY') ?: 'pk_test_...',
 *         'secret_key' => getenv('STRIPE_SECRET_KEY') ?: 'sk_test_...',
 *         'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_...',
 *         'webhook_endpoint' => getenv('SITE_URL') . '/stripeCallback.php',
 *     ],
 *     'paypal' => [
 *         'business_email' => getenv('PAYPAL_BUSINESS_EMAIL') ?: 'merchant@example.com',
 *         'api_credentials' => [
 *             'client_id' => getenv('PAYPAL_CLIENT_ID') ?: '',
 *             'client_secret' => getenv('PAYPAL_CLIENT_SECRET') ?: '',
 *             'mode' => getenv('PAYPAL_MODE') ?: 'sandbox'
 *         ],
 *         'return_url' => getenv('SITE_URL') . '/paypalCallback.php?status=success',
 *         'cancel_url' => getenv('SITE_URL') . '/paypalCallback.php?status=cancel',
 *     ]
 * ];
 */
