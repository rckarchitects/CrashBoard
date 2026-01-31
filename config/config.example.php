<?php
/**
 * CrashBoard Configuration Template
 *
 * Copy this file to config.php and fill in your actual values.
 * NEVER commit config.php to version control!
 */

return [
    // Application settings
    'app' => [
        'name' => 'CrashBoard',
        'url' => 'https://yourdomain.com',  // Your dashboard URL (no trailing slash)
        'debug' => false,                    // Set to true for development
        'timezone' => 'Europe/London',       // Your timezone
    ],

    // Database configuration
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'crashboard',
        'username' => 'your_db_user',
        'password' => 'your_db_password',
        'charset' => 'utf8mb4',
    ],

    // Session configuration
    'session' => [
        'name' => 'crashboard_session',
        'lifetime' => 7200,                  // 2 hours in seconds
        'secure' => true,                    // Set to false if not using HTTPS
        'httponly' => true,
    ],

    // Microsoft 365 / Azure AD configuration
    // Register app at: https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps
    'microsoft' => [
        'client_id' => 'your-azure-app-client-id',
        'client_secret' => 'your-azure-app-client-secret',
        'tenant_id' => 'your-azure-tenant-id',  // Use 'common' for multi-tenant
        'redirect_uri' => 'https://yourdomain.com/api/microsoft/callback.php',
        'scopes' => [
            'offline_access',
            'User.Read',
            'Mail.Read',
            'Calendars.Read',
            'Tasks.Read',
        ],
    ],

    // OnePageCRM configuration
    // Get API credentials from: https://app.onepagecrm.com/app/api
    'onepagecrm' => [
        'user_id' => 'your-onepagecrm-user-id',
        'api_key' => 'your-onepagecrm-api-key',
        'base_url' => 'https://app.onepagecrm.com/api/v3',
    ],

    // Claude API configuration
    // Get API key from: https://console.anthropic.com/
    'claude' => [
        'api_key' => 'your-claude-api-key',
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
    ],

    // Tile refresh settings (in seconds)
    'refresh' => [
        'default_interval' => 300,           // 5 minutes
        'email' => 300,
        'calendar' => 600,                   // 10 minutes
        'todo' => 300,
        'crm' => 600,
    ],

    // Cache settings
    'cache' => [
        'enabled' => true,
        'default_ttl' => 300,                // 5 minutes
    ],

    // Security settings
    'security' => [
        'bcrypt_cost' => 12,
        'rate_limit_attempts' => 5,          // Max login attempts
        'rate_limit_window' => 900,          // 15 minutes lockout
    ],

    // Encryption key for storing OAuth tokens
    // Generate with: bin2hex(random_bytes(32))
    'encryption_key' => 'generate-a-64-character-hex-string-here',
];
