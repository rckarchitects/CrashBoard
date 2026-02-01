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
    }

    redirect('/settings.php');
}

// Get flash messages
$success = Session::flash('success');
$error = Session::flash('error');

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
