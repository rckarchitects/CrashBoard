<?php
/**
 * Dashboard - Main Page
 *
 * Displays the tile-based dashboard with data from various integrations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require authentication
Auth::require();

$user = Auth::user();
$userId = Auth::id();

// Get user's tiles
$tiles = Database::query(
    'SELECT * FROM tiles WHERE user_id = ? AND is_enabled = TRUE ORDER BY position ASC',
    [$userId]
);

// Get connected providers
$connectedProviders = Database::query(
    'SELECT provider, expires_at FROM oauth_tokens WHERE user_id = ?',
    [$userId]
);
$providers = [];
foreach ($connectedProviders as $p) {
    $providers[$p['provider']] = $p['expires_at'];
}

$pageTitle = 'Dashboard - CrashBoard';
$refreshInterval = config('refresh.default_interval', 300) * 1000; // Convert to milliseconds
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
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <h1 class="text-xl font-bold text-gray-900">CrashBoard</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-500">
                        Welcome, <span class="font-medium text-gray-700"><?= e($user['username']) ?></span>
                    </span>
                    <a
                        href="/settings.php"
                        class="text-gray-500 hover:text-gray-700 p-2 rounded-lg hover:bg-gray-100 transition-colors"
                        title="Settings"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </a>
                    <a
                        href="/logout.php"
                        class="text-gray-500 hover:text-gray-700 p-2 rounded-lg hover:bg-gray-100 transition-colors"
                        title="Logout"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Connection Status Banner -->
        <?php if (empty($providers)): ?>
        <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-amber-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-amber-800">No services connected</h3>
                    <p class="mt-1 text-sm text-amber-700">
                        <a href="/settings.php" class="font-medium underline hover:text-amber-900">Connect your accounts</a>
                        to see data in your dashboard tiles.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Refresh Controls -->
        <div class="mb-6 flex justify-between items-center">
            <div class="flex items-center space-x-2 text-sm text-gray-500">
                <span id="lastUpdate">Last updated: just now</span>
                <button
                    id="refreshAll"
                    class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors"
                >
                    <svg class="w-4 h-4 mr-1.5" id="refreshIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Refresh All
                </button>
            </div>
            <div class="text-sm text-gray-500">
                Auto-refresh: <span id="autoRefreshStatus" class="font-medium text-green-600">enabled</span>
            </div>
        </div>

        <!-- Tiles Grid -->
        <div id="tilesContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php if (empty($tiles)): ?>
            <!-- Default tiles when none configured -->
            <div class="tile" data-tile-type="email" data-tile-id="0">
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        Inbox
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content">
                    <div class="tile-placeholder">
                        <p>Connect Microsoft 365 to view emails</p>
                        <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                    </div>
                </div>
            </div>

            <div class="tile" data-tile-type="calendar" data-tile-id="0">
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        Calendar
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content">
                    <div class="tile-placeholder">
                        <p>Connect Microsoft 365 to view calendar</p>
                        <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                    </div>
                </div>
            </div>

            <div class="tile" data-tile-type="todo" data-tile-id="0">
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        Tasks
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content">
                    <div class="tile-placeholder">
                        <p>Connect Microsoft 365 to view tasks</p>
                        <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                    </div>
                </div>
            </div>

            <div class="tile" data-tile-type="crm" data-tile-id="0">
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        CRM Actions
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content">
                    <div class="tile-placeholder">
                        <p>Connect OnePageCRM to view actions</p>
                        <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                    </div>
                </div>
            </div>

            <div class="tile tile-wide" data-tile-type="claude" data-tile-id="0">
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                        AI Assistant
                    </h3>
                </div>
                <div class="tile-content">
                    <div class="claude-interface">
                        <div id="claudeMessages" class="claude-messages">
                            <div class="claude-welcome">
                                <p>Ask me anything about your dashboard data, or request summaries of your emails, tasks, and calendar.</p>
                            </div>
                        </div>
                        <form id="claudeForm" class="claude-input-form">
                            <input
                                type="text"
                                id="claudeInput"
                                placeholder="Ask a question or request a summary..."
                                class="claude-input"
                                autocomplete="off"
                            >
                            <button type="submit" class="claude-submit">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($tiles as $tile): ?>
                <div class="tile <?= $tile['column_span'] > 1 ? 'tile-wide' : '' ?>" data-tile-type="<?= e($tile['tile_type']) ?>" data-tile-id="<?= $tile['id'] ?>">
                    <div class="tile-header">
                        <h3 class="tile-title"><?= e($tile['title'] ?? ucfirst($tile['tile_type'])) ?></h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                    </div>
                    <div class="tile-content">
                        <div class="tile-loading">
                            <div class="loading-spinner"></div>
                            <p>Loading...</p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="mt-auto py-4 text-center text-sm text-gray-500">
        <p>CrashBoard &copy; <?= date('Y') ?></p>
    </footer>

    <!-- CSRF Token for AJAX -->
    <script>
        window.CSRF_TOKEN = '<?= Session::getCsrfToken() ?>';
        window.REFRESH_INTERVAL = <?= $refreshInterval ?>;
    </script>
    <script src="/assets/js/dashboard.js"></script>
</body>
</html>
