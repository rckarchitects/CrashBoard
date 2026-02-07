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
        // 'samesite' => 'Lax',               // Default Lax so OAuth redirects keep the session (Strict would drop it)
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
        // Tasks tile: which tasks to show
        // 'my_day'  = only tasks you added to "My Day" in To Do (requires Graph beta; may not work on all tenants)
        // 'all'     = all incomplete tasks from your default Tasks list (reliable fallback)
        'todo_show' => 'all',
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

    // Weather configuration
    // Uses Open-Meteo API (free, no API key required)
    // Find your coordinates at: https://www.latlong.net/
    'weather' => [
        'latitude' => 51.5074,               // Your location latitude (e.g., London)
        'longitude' => -0.1278,              // Your location longitude
        'location_name' => 'London',         // Display name for your location
        'units' => 'celsius',                // 'celsius' or 'fahrenheit'
    ],

    // Tile refresh settings (in seconds)
    'refresh' => [
        'default_interval' => 300,           // 5 minutes
        'email' => 300,
        'calendar' => 600,                   // 10 minutes
        'todo' => 300,
        'crm' => 600,
        'weather' => 1800,                   // 30 minutes
    ],

    // Cache settings
    'cache' => [
        'enabled' => true,
        'default_ttl' => 300,                // 5 minutes
    ],

    // Cron refresh (pre-load tile data so dashboard loads faster)
    // Generate a secret: php -r "echo bin2hex(random_bytes(16));"
    'cron' => [
        'secret' => '',                      // Leave empty to disable cron refresh
        'interval_minutes' => 15,            // Recommended interval (for display in settings)
        'base_url' => '',                   // e.g. https://your-domain.com (for cron-refresh to call tiles.php)
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
