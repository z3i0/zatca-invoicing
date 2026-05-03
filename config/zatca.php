<?php

/**
 * ZATCA E-Invoicing Configuration
 * 
 * This configuration file controls all aspects of the ZATCA integration
 * including environment selection, seller information, and certificate settings.
 * 
 * Supported Environments:
 * - sandbox: For initial development and testing with ZATCA Developer Portal
 * - simulation: For pre-production testing with real API structure
 * - production: Live environment for actual invoice submission
 */

return [

    /*
    |--------------------------------------------------------------------------
    | ZATCA Environment
    |--------------------------------------------------------------------------
    |
    | Determines which ZATCA API environment to use.
    | Options: 'sandbox', 'simulation', 'production'
    |
    | - sandbox: Development and initial testing
    | - simulation: Pre-production testing (requires real CSID)
    | - production: Live invoice submission
    |
    */
    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Base URLs and API settings for each environment.
    |
    */
    'api' => [
        'sandbox' => [
            'base_url' => env('ZATCA_SANDBOX_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal'),
            'username' => env('ZATCA_SANDBOX_USERNAME'),
            'password' => env('ZATCA_SANDBOX_PASSWORD'),
        ],
        'simulation' => [
            'base_url' => env('ZATCA_SIMULATION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation'),
            'username' => env('ZATCA_SIMULATION_USERNAME'),
            'password' => env('ZATCA_SIMULATION_PASSWORD'),
        ],
        'production' => [
            'base_url' => env('ZATCA_PRODUCTION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core'),
            'username' => env('ZATCA_PRODUCTION_USERNAME'),
            'password' => env('ZATCA_PRODUCTION_PASSWORD'),
        ],
        'version' => 'V2',
        'timeout' => env('ZATCA_API_TIMEOUT', 30),
        'retry_attempts' => env('ZATCA_API_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('ZATCA_API_RETRY_DELAY', 1000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Seller Information
    |--------------------------------------------------------------------------
    |
    | Default seller information used when generating invoices.
    | These can be overridden per-invoice if needed.
    |
    */
    'seller' => [
        'name_en' => env('ZATCA_SELLER_NAME_EN', ''),
        'name_ar' => env('ZATCA_SELLER_NAME_AR', ''),
        'vat_number' => env('ZATCA_VAT_NUMBER', ''), // 15-digit VAT number
        'registration_number' => env('ZATCA_REGISTRATION_NUMBER', ''),
        
        // Address information
        'street' => env('ZATCA_SELLER_STREET', ''),
        'building' => env('ZATCA_SELLER_BUILDING', ''),
        'city' => env('ZATCA_SELLER_CITY', ''),
        'district' => env('ZATCA_SELLER_DISTRICT', ''),
        'postal_code' => env('ZATCA_SELLER_POSTAL_CODE', ''),
        'country' => env('ZATCA_SELLER_COUNTRY', 'SA'),
        'country_subdivision' => env('ZATCA_SELLER_COUNTRY_SUBDIVISION', ''),
        
        // Additional info
        'industry' => env('ZATCA_SELLER_INDUSTRY', ''),
        'email' => env('ZATCA_SELLER_EMAIL', ''),
        'phone' => env('ZATCA_SELLER_PHONE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for CSR generation and certificate management.
    |
    */
    'certificate' => [
        // Storage paths (relative to storage_path())
        'storage_path' => env('ZATCA_CERT_STORAGE_PATH', 'zatca/certificates'),
        'csr_path' => env('ZATCA_CSR_PATH', 'zatca/certificates/csr.pem'),
        'private_key_path' => env('ZATCA_PRIVATE_KEY_PATH', 'zatca/certificates/private.pem'),
        'compliance_cert_path' => env('ZATCA_COMPLIANCE_CERT_PATH', 'zatca/certificates/compliance.pem'),
        'production_cert_path' => env('ZATCA_PRODUCTION_CERT_PATH', 'zatca/certificates/production.pem'),
        
        // CSR Configuration
        'organization' => env('ZATCA_CSR_ORGANIZATION', ''),
        'organization_unit' => env('ZATCA_CSR_ORGANIZATION_UNIT', ''),
        'common_name' => env('ZATCA_CSR_COMMON_NAME', ''),
        
        // Invoice types (4 digits, each digit is a boolean flag)
        // Format: [Standard][Simplified][Future][Future]
        // Example: 1100 = Standard + Simplified enabled
        'invoice_types' => env('ZATCA_INVOICE_TYPES', '1100'),
        
        // OTP from ZATCA portal for compliance certificate
        'otp' => env('ZATCA_OTP', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Configuration
    |--------------------------------------------------------------------------
    |
    | Default settings for invoice generation.
    |
    */
    'invoice' => [
        // Default currency
        'currency' => env('ZATCA_INVOICE_CURRENCY', 'SAR'),
        
        // Default tax rate (15% VAT in Saudi Arabia)
        'default_tax_rate' => env('ZATCA_DEFAULT_TAX_RATE', 15.0),
        
        // Whether to round amounts
        'round_amounts' => env('ZATCA_ROUND_AMOUNTS', true),
        
        // Number of decimal places
        'decimal_places' => env('ZATCA_DECIMAL_PLACES', 2),
        
        // Invoice prefix
        'prefix' => env('ZATCA_INVOICE_PREFIX', 'INV'),
        
        // QR Code settings
        'qr_size' => env('ZATCA_QR_SIZE', 300),
        'qr_format' => env('ZATCA_QR_FORMAT', 'png'), // png, svg
        
        // XML settings
        'xml_encoding' => 'UTF-8',
        'xml_version' => '1.0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security-related configuration for digital signatures and encryption.
    |
    */
    'security' => [
        // ECC curve for key generation
        'ecc_curve' => env('ZATCA_ECC_CURVE', 'secp256k1'),
        
        // Signature algorithm
        'signature_algorithm' => env('ZATCA_SIGNATURE_ALGORITHM', 'SHA256withECDSA'),
        
        // Hash algorithm
        'hash_algorithm' => env('ZATCA_HASH_ALGORITHM', 'sha256'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Control logging behavior for ZATCA operations.
    |
    */
    'logging' => [
        'enabled' => env('ZATCA_LOGGING_ENABLED', true),
        'channel' => env('ZATCA_LOG_CHANNEL', 'zatca'),
        'level' => env('ZATCA_LOG_LEVEL', 'debug'),
        
        // Log API requests and responses
        'log_api_requests' => env('ZATCA_LOG_API_REQUESTS', true),
        'log_api_responses' => env('ZATCA_LOG_API_RESPONSES', true),
        
        // Log sensitive data (set to false in production)
        'log_sensitive_data' => env('ZATCA_LOG_SENSITIVE_DATA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Settings
    |--------------------------------------------------------------------------
    |
    | Debug configuration for development and testing.
    |
    */
    'debug' => [
        'enabled' => env('ZATCA_DEBUG_ENABLED', false),
        'path' => env('ZATCA_DEBUG_PATH', 'zatca/debug'),
        'save_xml' => env('ZATCA_DEBUG_SAVE_XML', true),
        'save_responses' => env('ZATCA_DEBUG_SAVE_RESPONSES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Configure webhooks for receiving ZATCA notifications.
    |
    */
    'webhook' => [
        'enabled' => env('ZATCA_WEBHOOK_ENABLED', false),
        'secret' => env('ZATCA_WEBHOOK_SECRET', ''),
        'url' => env('ZATCA_WEBHOOK_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry & Queue Settings
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior and queue settings for invoice submission.
    |
    */
    'queue' => [
        'enabled' => env('ZATCA_QUEUE_ENABLED', false),
        'connection' => env('ZATCA_QUEUE_CONNECTION', 'default'),
        'queue' => env('ZATCA_QUEUE_NAME', 'zatca'),
        
        // Retry failed submissions
        'retry_failed' => env('ZATCA_RETRY_FAILED', true),
        'max_retries' => env('ZATCA_MAX_RETRIES', 3),
        'retry_after' => env('ZATCA_RETRY_AFTER', 300), // seconds
    ],
];
