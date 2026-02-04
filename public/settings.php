<?php
/**
 * Settings Page
 *
 * Manage account connections and preferences.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require authentication
Auth::require();

$user = Auth::user();
$userId = Auth::id();

// Get connected providers
$connectedProviders = Database::query(
    'SELECT provider, expires_at, updated_at FROM oauth_tokens WHERE user_id = ?',
    [$userId]
);
$providers = [];
foreach ($connectedProviders as $p) {
    $providers[$p['provider']] = [
        'expires_at' => $p['expires_at'],
        'updated_at' => $p['updated_at']
    ];
}

// Handle form submissions
if (isPost()) {
    Auth::requireCsrf();

    $action = post('action');

    switch ($action) {
        case 'update_password':
            $currentPassword = post('current_password', '');
            $newPassword = post('new_password', '');
            $confirmPassword = post('confirm_password', '');

            if ($newPassword !== $confirmPassword) {
                Session::setFlash('error', 'New passwords do not match.');
            } else {
                $result = Auth::changePassword($userId, $currentPassword, $newPassword);
                if ($result['success']) {
                    Session::setFlash('success', 'Password updated successfully.');
                } else {
                    Session::setFlash('error', $result['error']);
                }
            }
            break;

        case 'save_onepagecrm':
            $crmUserId = trim(post('crm_user_id', ''));
            $crmApiKey = trim(post('crm_api_key', ''));

            if (empty($crmUserId) || empty($crmApiKey)) {
                Session::setFlash('error', 'Please provide both User ID and API Key.');
            } elseif (!config('encryption_key') || config('encryption_key') === 'generate-a-64-character-hex-string-here') {
                Session::setFlash('error', 'Encryption key not configured. Please set a valid encryption_key in config/config.php (use: php -r "echo bin2hex(random_bytes(32));")');
            } else {
                try {
                    // Encrypt and store credentials
                    $credentials = encrypt(json_encode([
                        'user_id' => $crmUserId,
                        'api_key' => $crmApiKey
                    ]));

                    $existing = Database::queryOne(
                        'SELECT id FROM oauth_tokens WHERE user_id = ? AND provider = ?',
                        [$userId, 'onepagecrm']
                    );

                    if ($existing) {
                        Database::execute(
                            'UPDATE oauth_tokens SET access_token = ?, updated_at = NOW() WHERE user_id = ? AND provider = ?',
                            [$credentials, $userId, 'onepagecrm']
                        );
                    } else {
                        Database::execute(
                            'INSERT INTO oauth_tokens (user_id, provider, access_token) VALUES (?, ?, ?)',
                            [$userId, 'onepagecrm', $credentials]
                        );
                    }

                    cacheClear("crm_{$userId}");
                    Session::setFlash('success', 'OnePageCRM credentials saved.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to save credentials: ' . $e->getMessage());
                }
            }
            break;

        case 'disconnect_onepagecrm':
            Database::execute(
                'DELETE FROM oauth_tokens WHERE user_id = ? AND provider = ?',
                [$userId, 'onepagecrm']
            );
            cacheClear("crm_{$userId}");
            Session::setFlash('success', 'OnePageCRM disconnected.');
            break;

        case 'clear_cache':
            $cacheType = post('cache_type', 'all');
            if ($cacheType === 'all') {
                cacheClear("email_{$userId}");
                cacheClear("calendar_{$userId}");
                cacheClear("todo_{$userId}");
                cacheClear("crm_{$userId}");
                cacheClear("weather_{$userId}");
                Session::setFlash('success', 'All tile caches cleared.');
            } else {
                cacheClear("{$cacheType}_{$userId}");
                Session::setFlash('success', ucfirst($cacheType) . ' cache cleared.');
            }
            break;

        case 'save_weather':
            $latitude = trim(post('weather_latitude', ''));
            $longitude = trim(post('weather_longitude', ''));
            $locationName = trim(post('weather_location', ''));
            $units = post('weather_units', 'celsius');

            if (empty($latitude) || empty($longitude) || empty($locationName)) {
                Session::setFlash('error', 'Please provide location name, latitude, and longitude.');
            } elseif (!is_numeric($latitude) || !is_numeric($longitude)) {
                Session::setFlash('error', 'Latitude and longitude must be numeric values.');
            } elseif ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                Session::setFlash('error', 'Invalid coordinates. Latitude must be -90 to 90, longitude -180 to 180.');
            } else {
                try {
                    // Store weather settings in database
                    $weatherSettingsJson = json_encode([
                        'latitude' => (float)$latitude,
                        'longitude' => (float)$longitude,
                        'location_name' => $locationName,
                        'units' => $units
                    ]);

                    $existing = Database::queryOne(
                        'SELECT id FROM oauth_tokens WHERE user_id = ? AND provider = ?',
                        [$userId, 'weather']
                    );

                    if ($existing) {
                        Database::execute(
                            'UPDATE oauth_tokens SET access_token = ?, updated_at = NOW() WHERE user_id = ? AND provider = ?',
                            [$weatherSettingsJson, $userId, 'weather']
                        );
                    } else {
                        Database::execute(
                            'INSERT INTO oauth_tokens (user_id, provider, access_token) VALUES (?, ?, ?)',
                            [$userId, 'weather', $weatherSettingsJson]
                        );
                    }

                    cacheClear("weather_{$userId}");

                    // Ensure weather tile exists in tiles table
                    $existingTile = Database::queryOne(
                        'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ?',
                        [$userId, 'weather']
                    );

                    if (!$existingTile) {
                        // Get the max position to add weather tile at the end
                        $maxPos = Database::queryOne(
                            'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ?',
                            [$userId]
                        );
                        $newPosition = ($maxPos['max_pos'] ?? 0) + 1;

                        Database::execute(
                            'INSERT INTO tiles (user_id, tile_type, title, position, column_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?)',
                            [$userId, 'weather', 'Weather', $newPosition, 1, true]
                        );
                    }

                    Session::setFlash('success', 'Weather location saved.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Database error: ' . $e->getMessage());
                }
            }
            break;

        case 'remove_weather':
            Database::execute(
                'DELETE FROM oauth_tokens WHERE user_id = ? AND provider = ?',
                [$userId, 'weather']
            );
            // Also remove the weather tile from dashboard
            Database::execute(
                'DELETE FROM tiles WHERE user_id = ? AND tile_type = ?',
                [$userId, 'weather']
            );
            cacheClear("weather_{$userId}");
            Session::setFlash('success', 'Weather settings removed.');
            break;
    }

    redirect('/settings.php');
}

// Get flash messages
$success = Session::flash('success');
$error = Session::flash('error');

// Get cache status for each tile type
function getCacheStatus(int $userId, string $type): ?array
{
    $cacheKey = "{$type}_{$userId}";
    $result = Database::queryOne(
        'SELECT expires_at, created_at FROM api_cache WHERE cache_key = ? AND expires_at > NOW()',
        [$cacheKey]
    );

    if (!$result) {
        return null;
    }

    $expiresAt = strtotime($result['expires_at']);
    $remainingSeconds = $expiresAt - time();

    return [
        'expires_at' => $result['expires_at'],
        'created_at' => $result['created_at'],
        'remaining_seconds' => $remainingSeconds,
        'remaining_formatted' => formatRemainingTime($remainingSeconds)
    ];
}

function formatRemainingTime(int $seconds): string
{
    if ($seconds <= 0) {
        return 'Expired';
    }
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        $mins = floor($seconds / 60);
        $secs = $seconds % 60;
        return $mins . 'm ' . $secs . 's';
    }
    $hours = floor($seconds / 3600);
    $mins = floor(($seconds % 3600) / 60);
    return $hours . 'h ' . $mins . 'm';
}

$cacheStatus = [
    'email' => getCacheStatus($userId, 'email'),
    'calendar' => getCacheStatus($userId, 'calendar'),
    'todo' => getCacheStatus($userId, 'todo'),
    'crm' => getCacheStatus($userId, 'crm'),
    'weather' => getCacheStatus($userId, 'weather'),
];

// Get weather settings
$weatherSettings = null;
try {
    $weatherRow = Database::queryOne(
        'SELECT access_token FROM oauth_tokens WHERE user_id = ? AND provider = ?',
        [$userId, 'weather']
    );
    if ($weatherRow) {
        $weatherSettings = json_decode($weatherRow['access_token'], true);
    }
} catch (Exception $e) {
    // Weather provider not yet added to database ENUM - this is expected until migration is run
}

$pageTitle = 'Settings - CrashBoard';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="h-full bg-gray-100">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <a href="/index.php" class="text-gray-500 hover:text-gray-700 mr-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                    <h1 class="text-xl font-bold text-gray-900">Settings</h1>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($success): ?>
        <div class="mb-6 rounded-lg bg-green-50 border border-green-200 p-4">
            <div class="flex">
                <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <p class="ml-3 text-sm text-green-700"><?= e($success) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="mb-6 rounded-lg bg-red-50 border border-red-200 p-4">
            <div class="flex">
                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <p class="ml-3 text-sm text-red-700"><?= e($error) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Connected Services -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Connected Services</h2>
                <p class="mt-1 text-sm text-gray-500">Manage your connected accounts and API integrations.</p>
            </div>

            <div class="divide-y divide-gray-200">
                <!-- Microsoft 365 -->
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M11.5 2.5v9h-9v-9h9zm1 0h9v9h-9v-9zm-1 10v9h-9v-9h9zm1 0h9v9h-9v-9z"/>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-900">Microsoft 365</h3>
                                <p class="text-sm text-gray-500">Email, Calendar, and To Do</p>
                            </div>
                        </div>
                        <div>
                            <?php if (isset($providers['microsoft'])): ?>
                            <div class="flex items-center space-x-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Connected
                                </span>
                                <form action="" method="POST" class="inline">
                                    <?= Session::csrfField() ?>
                                    <input type="hidden" name="action" value="disconnect_microsoft">
                                    <a href="/api/microsoft/auth.php?action=disconnect&_csrf_token=<?= urlencode(Session::getCsrfToken()) ?>" class="text-sm text-red-600 hover:text-red-700">
                                        Disconnect
                                    </a>
                                </form>
                            </div>
                            <?php else: ?>
                            <a href="/api/microsoft/auth.php?action=connect" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Connect
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- OnePageCRM -->
                <div class="p-6">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-900">OnePageCRM</h3>
                                <p class="text-sm text-gray-500">CRM Actions and Tasks</p>
                            </div>
                        </div>
                        <?php if (isset($providers['onepagecrm'])): ?>
                        <div class="flex items-center space-x-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                Connected
                            </span>
                            <form action="" method="POST" class="inline">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action" value="disconnect_onepagecrm">
                                <button type="submit" class="text-sm text-red-600 hover:text-red-700">
                                    Disconnect
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!isset($providers['onepagecrm'])): ?>
                    <form action="" method="POST" class="mt-4 space-y-4">
                        <?= Session::csrfField() ?>
                        <input type="hidden" name="action" value="save_onepagecrm">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="crm_user_id" class="block text-sm font-medium text-gray-700">User ID</label>
                                <input
                                    type="text"
                                    id="crm_user_id"
                                    name="crm_user_id"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                    placeholder="Your OnePageCRM User ID"
                                >
                            </div>
                            <div>
                                <label for="crm_api_key" class="block text-sm font-medium text-gray-700">API Key</label>
                                <input
                                    type="password"
                                    id="crm_api_key"
                                    name="crm_api_key"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                    placeholder="Your API Key"
                                >
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <a href="https://app.onepagecrm.com/app/api" target="_blank" class="text-sm text-primary-600 hover:text-primary-700">
                                Get your API credentials
                            </a>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                                Save & Connect
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Weather Settings -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Weather Settings</h2>
                <p class="mt-1 text-sm text-gray-500">Configure your location for weather forecasts. Uses Open-Meteo (free, no API key required).</p>
            </div>

            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-sky-100 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-sm font-medium text-gray-900">Weather Forecast</h3>
                            <p class="text-sm text-gray-500">Current conditions and 5-day forecast</p>
                        </div>
                    </div>
                    <?php if ($weatherSettings): ?>
                    <div class="flex items-center space-x-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            <?= e($weatherSettings['location_name'] ?? 'Configured') ?>
                        </span>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="remove_weather">
                            <button type="submit" class="text-sm text-red-600 hover:text-red-700">
                                Remove
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <form action="" method="POST" class="space-y-4">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="save_weather">

                    <div>
                        <label for="weather_location" class="block text-sm font-medium text-gray-700">Location Name</label>
                        <input
                            type="text"
                            id="weather_location"
                            name="weather_location"
                            value="<?= e($weatherSettings['location_name'] ?? '') ?>"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                            placeholder="e.g., London, New York, Tokyo"
                        >
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="weather_latitude" class="block text-sm font-medium text-gray-700">Latitude</label>
                            <input
                                type="text"
                                id="weather_latitude"
                                name="weather_latitude"
                                value="<?= e((string)($weatherSettings['latitude'] ?? '')) ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                placeholder="e.g., 51.5074"
                            >
                        </div>
                        <div>
                            <label for="weather_longitude" class="block text-sm font-medium text-gray-700">Longitude</label>
                            <input
                                type="text"
                                id="weather_longitude"
                                name="weather_longitude"
                                value="<?= e((string)($weatherSettings['longitude'] ?? '')) ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                placeholder="e.g., -0.1278"
                            >
                        </div>
                    </div>

                    <div>
                        <label for="weather_units" class="block text-sm font-medium text-gray-700">Temperature Units</label>
                        <select
                            id="weather_units"
                            name="weather_units"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                        >
                            <option value="celsius" <?= ($weatherSettings['units'] ?? 'celsius') === 'celsius' ? 'selected' : '' ?>>Celsius (°C)</option>
                            <option value="fahrenheit" <?= ($weatherSettings['units'] ?? '') === 'fahrenheit' ? 'selected' : '' ?>>Fahrenheit (°F)</option>
                        </select>
                    </div>

                    <div class="flex items-center justify-between">
                        <a href="https://www.latlong.net/" target="_blank" class="text-sm text-primary-600 hover:text-primary-700">
                            Find your coordinates →
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500">
                            Save Location
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Cache Management -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Cache Management</h2>
                <p class="mt-1 text-sm text-gray-500">View and clear cached tile data. Tiles cache API responses to improve performance.</p>
            </div>

            <div class="p-6">
                <div class="space-y-4 mb-6">
                    <?php
                    $tileTypes = [
                        'email' => ['name' => 'Email', 'icon' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', 'color' => 'blue'],
                        'calendar' => ['name' => 'Calendar', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'color' => 'green'],
                        'todo' => ['name' => 'Tasks', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'color' => 'purple'],
                        'crm' => ['name' => 'CRM Actions', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'color' => 'orange'],
                        'weather' => ['name' => 'Weather', 'icon' => 'M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z', 'color' => 'sky'],
                    ];
                    ?>

                    <?php foreach ($tileTypes as $type => $info): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-<?= $info['color'] ?>-100 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-4 h-4 text-<?= $info['color'] ?>-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $info['icon'] ?>"/>
                                </svg>
                            </div>
                            <div>
                                <span class="text-sm font-medium text-gray-900"><?= $info['name'] ?></span>
                                <?php if ($cacheStatus[$type]): ?>
                                <p class="text-xs text-gray-500">
                                    Expires in: <span class="font-medium text-<?= $info['color'] ?>-600"><?= $cacheStatus[$type]['remaining_formatted'] ?></span>
                                </p>
                                <?php else: ?>
                                <p class="text-xs text-gray-400">No cache</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($cacheStatus[$type]): ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="clear_cache">
                            <input type="hidden" name="cache_type" value="<?= $type ?>">
                            <button type="submit" class="text-xs text-red-600 hover:text-red-700 font-medium">
                                Clear
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <form action="" method="POST">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="clear_cache">
                    <input type="hidden" name="cache_type" value="all">
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Clear All Caches
                    </button>
                </form>
            </div>
        </section>

        <!-- Account Settings -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Account Settings</h2>
                <p class="mt-1 text-sm text-gray-500">Update your account information and password.</p>
            </div>

            <div class="p-6">
                <div class="mb-6">
                    <h3 class="text-sm font-medium text-gray-900 mb-2">Account Information</h3>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm text-gray-500">Username</dt>
                            <dd class="text-sm font-medium text-gray-900"><?= e($user['username']) ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Email</dt>
                            <dd class="text-sm font-medium text-gray-900"><?= e($user['email']) ?></dd>
                        </div>
                    </dl>
                </div>

                <h3 class="text-sm font-medium text-gray-900 mb-4">Change Password</h3>
                <form action="" method="POST" class="space-y-4 max-w-md">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="update_password">

                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700">Current Password</label>
                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            required
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                        >
                    </div>

                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            required
                            minlength="8"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                        >
                        <p class="mt-1 text-xs text-gray-500">Minimum 8 characters</p>
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            required
                            minlength="8"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                        >
                    </div>

                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Update Password
                    </button>
                </form>
            </div>
        </section>

        <!-- API Configuration Info -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Configuration Help</h2>
            </div>
            <div class="p-6 prose prose-sm max-w-none">
                <h3>Microsoft 365 Setup</h3>
                <ol class="text-gray-600">
                    <li>Go to <a href="https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps" target="_blank" class="text-primary-600">Azure Portal - App Registrations</a></li>
                    <li>Create a new registration with redirect URI pointing to your callback URL</li>
                    <li>Note the Application (client) ID and Directory (tenant) ID</li>
                    <li>Create a client secret under Certificates & Secrets</li>
                    <li>Add these values to your <code>config/config.php</code> file</li>
                </ol>

                <h3>Claude AI Setup</h3>
                <ol class="text-gray-600">
                    <li>Get your API key from <a href="https://console.anthropic.com/" target="_blank" class="text-primary-600">Anthropic Console</a></li>
                    <li>Add the API key to your <code>config/config.php</code> file</li>
                </ol>
            </div>
        </section>
    </main>
</body>
</html>
