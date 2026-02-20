<?php
/**
 * Dashboard - Main Page
 *
 * Displays the tile-based dashboard with data from various integrations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// If Microsoft sent the user here with OAuth callback params (wrong redirect_uri in Azure), send them to the real callback
$oauthCode = $_GET['code'] ?? '';
$oauthState = $_GET['state'] ?? '';
if ($oauthCode !== '' && $oauthState !== '') {
    redirect('/api/microsoft/callback.php?' . http_build_query(array_filter([
        'code' => $oauthCode,
        'state' => $oauthState,
        'error' => $_GET['error'] ?? '',
        'error_description' => $_GET['error_description'] ?? '',
    ])));
}

// Require authentication
Auth::require();

$user = Auth::user();
$userId = Auth::id();

// Ensure row_span column exists (migration)
try {
    $columns = Database::query("SHOW COLUMNS FROM tiles LIKE 'row_span'");
    if (empty($columns)) {
        Database::execute('ALTER TABLE tiles ADD COLUMN row_span TINYINT UNSIGNED DEFAULT 1 AFTER column_span');
    }
} catch (Exception $e) {
    // Migration failed, but continue - column might already exist
    error_log('Row span migration: ' . $e->getMessage());
}

// Update existing tiles to have default row_span if NULL
Database::execute(
    'UPDATE tiles SET row_span = 1 WHERE user_id = ? AND (row_span IS NULL OR row_span = 0)',
    [$userId]
);

// Ensure screen column exists (for dashboard tabs: main vs screen2)
try {
    $screenCol = Database::query("SHOW COLUMNS FROM tiles LIKE 'screen'");
    if (empty($screenCol)) {
        Database::execute("ALTER TABLE tiles ADD COLUMN screen VARCHAR(20) DEFAULT 'main' AFTER position");
        Database::execute("UPDATE tiles SET screen = 'main' WHERE screen IS NULL");
    }
} catch (Exception $e) {
    error_log('Screen column migration: ' . $e->getMessage());
}

// Set default width for Claude tiles that have never had column_span set (NULL only; respect user resizing)
Database::execute(
    'UPDATE tiles SET column_span = 2 WHERE user_id = ? AND tile_type = ? AND column_span IS NULL',
    [$userId, 'claude']
);

// Get user's tiles per screen (main = default/first tab, screen2 = second tab)
$tilesMain = Database::query(
    "SELECT * FROM tiles WHERE user_id = ? AND is_enabled = TRUE AND (screen IS NULL OR screen = 'main') ORDER BY position ASC",
    [$userId]
);
$tilesScreen2 = Database::query(
    "SELECT * FROM tiles WHERE user_id = ? AND is_enabled = TRUE AND screen = 'screen2' ORDER BY position ASC",
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

// Flash messages (e.g. after connecting Microsoft)
$flashSuccess = Session::flash('success');
$flashError = Session::flash('error');

// Session expiry for header indicator (backfill for existing sessions)
if (!Session::has('private_computer') && !Session::has('session_expires_at')) {
    Session::set('session_expires_at', time() + (int) config('session.lifetime', 604800));
}
$sessionExpiresAt = Session::get('session_expires_at');
$isPrivateComputer = (bool) Session::get('private_computer');

// Get user theme preferences
try {
    $columns = Database::query("SHOW COLUMNS FROM users LIKE 'theme_primary'");
    if (empty($columns)) {
        Database::execute('ALTER TABLE users ADD COLUMN theme_primary VARCHAR(7) DEFAULT "#0ea5e9" AFTER updated_at');
        Database::execute('ALTER TABLE users ADD COLUMN theme_secondary VARCHAR(7) DEFAULT "#6366f1" AFTER theme_primary');
        Database::execute('ALTER TABLE users ADD COLUMN theme_background VARCHAR(7) DEFAULT "#f3f4f6" AFTER theme_secondary');
        Database::execute('ALTER TABLE users ADD COLUMN theme_font VARCHAR(50) DEFAULT "system" AFTER theme_background');
    }
    // Check for header and tile theme columns
    $headerBgCol = Database::query("SHOW COLUMNS FROM users LIKE 'theme_header_bg'");
    if (empty($headerBgCol)) {
        Database::execute('ALTER TABLE users ADD COLUMN theme_header_bg VARCHAR(7) DEFAULT "#ffffff" AFTER theme_font');
        Database::execute('ALTER TABLE users ADD COLUMN theme_header_text VARCHAR(7) DEFAULT "#111827" AFTER theme_header_bg');
        Database::execute('ALTER TABLE users ADD COLUMN theme_tile_bg VARCHAR(7) DEFAULT "#ffffff" AFTER theme_header_text');
        Database::execute('ALTER TABLE users ADD COLUMN theme_tile_text VARCHAR(7) DEFAULT "#374151" AFTER theme_tile_bg');
    }
    // Dashboard screen labels (user-configurable names for Main / Screen 2)
    $screenLabelCol = Database::query("SHOW COLUMNS FROM users LIKE 'dashboard_screen1_label'");
    if (empty($screenLabelCol)) {
        Database::execute("ALTER TABLE users ADD COLUMN dashboard_screen1_label VARCHAR(50) DEFAULT 'Main' AFTER theme_tile_text");
        Database::execute("ALTER TABLE users ADD COLUMN dashboard_screen2_label VARCHAR(50) DEFAULT 'Screen 2' AFTER dashboard_screen1_label");
    }
} catch (Exception $e) {
    error_log('Theme migration: ' . $e->getMessage());
}

$userTheme = Database::queryOne(
    'SELECT theme_primary, theme_secondary, theme_background, theme_font, theme_header_bg, theme_header_text, theme_tile_bg, theme_tile_text, dashboard_screen1_label, dashboard_screen2_label FROM users WHERE id = ?',
    [$userId]
);

$theme = [
    'primary' => $userTheme['theme_primary'] ?? '#0ea5e9',
    'secondary' => $userTheme['theme_secondary'] ?? '#6366f1',
    'background' => $userTheme['theme_background'] ?? '#f3f4f6',
    'font' => $userTheme['theme_font'] ?? 'system',
    'header_bg' => $userTheme['theme_header_bg'] ?? '#ffffff',
    'header_text' => $userTheme['theme_header_text'] ?? '#111827',
    'tile_bg' => $userTheme['theme_tile_bg'] ?? '#ffffff',
    'tile_text' => $userTheme['theme_tile_text'] ?? '#374151',
];

$screenLabelMain = isset($userTheme['dashboard_screen1_label']) && $userTheme['dashboard_screen1_label'] !== '' ? $userTheme['dashboard_screen1_label'] : 'Main';
$screenLabelScreen2 = isset($userTheme['dashboard_screen2_label']) && $userTheme['dashboard_screen2_label'] !== '' ? $userTheme['dashboard_screen2_label'] : 'Screen 2';

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
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <?php
    // Load Google Fonts for web fonts
    $fontUrls = [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        'roboto' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap',
        'open-sans' => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap',
        'lato' => 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap',
        'montserrat' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap',
        'raleway' => 'https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap',
        'playfair' => 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap',
    ];
    if (isset($fontUrls[$theme['font']])): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?= $fontUrls[$theme['font']] ?>" rel="stylesheet">
    <?php endif; ?>
    <style>
        :root {
            --cb-primary: <?= e($theme['primary']) ?>;
            --cb-secondary: <?= e($theme['secondary']) ?>;
            --cb-background: <?= e($theme['background']) ?>;
            --cb-header-bg: <?= e($theme['header_bg']) ?>;
            --cb-header-text: <?= e($theme['header_text']) ?>;
            --cb-tile-bg: <?= e($theme['tile_bg']) ?>;
            --cb-tile-text: <?= e($theme['tile_text']) ?>;
        }
        body {
            background-color: var(--cb-background);
            <?php
            $fontFamilies = [
                'system' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
                'inter' => '"Inter", system-ui, sans-serif',
                'roboto' => '"Roboto", system-ui, sans-serif',
                'open-sans' => '"Open Sans", system-ui, sans-serif',
                'lato' => '"Lato", system-ui, sans-serif',
                'montserrat' => '"Montserrat", system-ui, sans-serif',
                'raleway' => '"Raleway", system-ui, sans-serif',
                'playfair' => '"Playfair Display", Georgia, serif',
                'serif' => 'Georgia, "Times New Roman", serif',
                'mono' => 'Menlo, Monaco, "Courier New", monospace',
            ];
            ?>
            font-family: <?= $fontFamilies[$theme['font']] ?? $fontFamilies['system'] ?>;
        }
        /* Header styling */
        header {
            background-color: var(--cb-header-bg) !important;
            color: var(--cb-header-text) !important;
        }
        header * {
            color: var(--cb-header-text) !important;
        }
        header a, header button {
            color: var(--cb-header-text) !important;
        }
        header svg {
            color: var(--cb-header-text) !important;
        }
        /* Tile styling */
        .tile {
            background-color: var(--cb-tile-bg) !important;
            color: var(--cb-tile-text) !important;
        }
        .tile-header {
            background-color: color-mix(in srgb, var(--cb-tile-bg) 98%, var(--cb-background)) !important;
        }
        .tile-title, .tile-content, .tile-content * {
            color: var(--cb-tile-text) !important;
        }
        /* Override Tailwind primary colors with CSS variables */
        .bg-primary-500, .bg-primary-600, .bg-primary-700 {
            background-color: var(--cb-primary) !important;
        }
        .text-primary-500, .text-primary-600, .text-primary-700 {
            color: var(--cb-primary) !important;
        }
        .border-primary-500, .border-primary-600 {
            border-color: var(--cb-primary) !important;
        }
        .ring-primary-500, .ring-primary-600 {
            --tw-ring-color: var(--cb-primary) !important;
        }
        .hover\:bg-primary-700:hover {
            background-color: color-mix(in srgb, var(--cb-primary) 90%, black) !important;
        }
    </style>
</head>
<body class="h-full overflow-x-hidden" style="background-color: var(--cb-background);">
    <!-- Header -->
    <header id="mainHeader" class="shadow-sm border-b overflow-x-hidden fixed top-0 left-0 right-0 z-50 transition-transform duration-300 ease-out" style="background-color: var(--cb-header-bg); border-color: color-mix(in srgb, var(--cb-header-text) 20%, transparent);">
        <div class="max-w-[1536px] mx-auto px-3 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center min-h-12 py-2 gap-2">
                <h1 class="text-base sm:text-xl font-bold shrink-0">CrashBoard</h1>
                <!-- Desktop: time, session, last update, actions (hidden only on small screens) -->
                <div id="headerNavDesktop" class="flex items-center gap-2 lg:gap-4 shrink-0 flex-wrap justify-end max-md:hidden">
                    <span class="header-date-time text-xs sm:text-sm opacity-90 tabular-nums"></span>
                    <span class="session-expiry-indicator text-xs sm:text-sm opacity-90" title="Session time remaining"></span>
                    <span class="last-update-display text-xs sm:text-sm opacity-90">Last updated: just now</span>
                    <button type="button" id="reorderTiles" class="inline-flex items-center justify-center gap-1.5 p-1.5 sm:p-2 rounded-lg opacity-90 hover:opacity-100 transition-opacity" title="Reorder tiles">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/></svg>
                        <span class="hidden sm:inline">Reorder</span>
                    </button>
                    <div id="reorderControls" class="hidden flex items-center gap-2">
                        <span class="text-xs sm:text-sm font-medium opacity-90">Drag to reorder</span>
                        <button type="button" id="saveOrder" class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs sm:text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2" style="background-color: var(--cb-primary);">
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Save
                        </button>
                        <button type="button" id="cancelReorder" class="inline-flex items-center px-2 py-1 rounded-lg text-xs sm:text-sm font-medium opacity-90 hover:opacity-100 border border-current transition-opacity focus:outline-none focus:ring-2 focus:ring-offset-2">
                            Cancel
                        </button>
                    </div>
                    <a href="/settings.php" class="p-1.5 sm:p-2 rounded-lg opacity-90 hover:opacity-100 transition-opacity" title="Settings">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </a>
                    <a href="/logout.php" class="p-1.5 sm:p-2 rounded-lg opacity-90 hover:opacity-100 transition-opacity" title="Logout">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </a>
                </div>
                <!-- Mobile only: hamburger toggles menu -->
                <button type="button" id="headerMenuButton" class="hidden max-md:inline-flex p-2 rounded-lg opacity-90 hover:opacity-100 transition-opacity focus:outline-none focus:ring-2 focus:ring-offset-2 items-center justify-center" style="--tw-ring-color: var(--cb-primary);" aria-expanded="false" aria-controls="headerMenuPanel" aria-label="Open menu">
                    <svg class="w-6 h-6" id="headerMenuIconOpen" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg class="w-6 h-6 hidden" id="headerMenuIconClose" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <!-- Mobile menu panel (collapsible; only visible on small screens when opened) -->
            <div id="headerMenuPanel" class="hidden max-md:flex max-md:flex-col pb-3 pt-1 border-t md:hidden" style="border-color: color-mix(in srgb, var(--cb-header-text) 20%, transparent);" aria-hidden="true">
                <div class="flex flex-col gap-3 mt-2">
                    <span class="header-date-time text-sm opacity-90 tabular-nums"></span>
                    <span class="session-expiry-indicator text-sm opacity-90" title="Session time remaining"></span>
                    <span class="last-update-display text-sm opacity-90">Last updated: just now</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" id="reorderTilesMobile" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg opacity-90 hover:opacity-100 transition-opacity border border-current text-sm" title="Reorder tiles">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/></svg>
                            Reorder
                        </button>
                        <div id="reorderControlsMobile" class="hidden flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-medium opacity-90">Drag to reorder</span>
                            <button type="button" id="saveOrderMobile" class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2" style="background-color: var(--cb-primary);">Save</button>
                            <button type="button" id="cancelReorderMobile" class="inline-flex items-center px-2 py-1 rounded-lg text-sm font-medium opacity-90 hover:opacity-100 border border-current">Cancel</button>
                        </div>
                        <a href="/settings.php" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg opacity-90 hover:opacity-100 transition-opacity border border-current text-sm">Settings</a>
                        <a href="/logout.php" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg opacity-90 hover:opacity-100 transition-opacity border border-current text-sm">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main id="dashboardMain" class="max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Hover zone for header reveal -->
        <div id="headerHoverZone" class="fixed top-0 left-0 right-0 h-12 z-40 pointer-events-none"></div>
        <?php if ($flashSuccess): ?>
        <div class="mb-6 rounded-lg bg-green-50 border border-green-200 p-4">
            <div class="flex">
                <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <p class="ml-3 text-sm text-green-700"><?= e($flashSuccess) ?></p>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
        <div class="mb-6 rounded-lg bg-red-50 border border-red-200 p-4">
            <div class="flex">
                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <p class="ml-3 text-sm text-red-700"><?= e($flashError) ?></p>
            </div>
        </div>
        <?php endif; ?>
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

        <!-- Dashboard Tabs -->
        <div class="dashboard-tabs flex gap-1 mb-6 border-b" style="border-color: color-mix(in srgb, var(--cb-header-text) 15%, transparent);" role="tablist" aria-label="Dashboard screens">
            <button type="button" class="dashboard-tab px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2" style="--tw-ring-color: var(--cb-primary);" role="tab" aria-selected="true" data-tab="main" id="tab-main"><?= e($screenLabelMain) ?></button>
            <button type="button" class="dashboard-tab px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 opacity-70 hover:opacity-100" style="--tw-ring-color: var(--cb-primary);" role="tab" aria-selected="false" data-tab="screen2" id="tab-screen2"><?= e($screenLabelScreen2) ?></button>
        </div>

        <!-- Panel: Main -->
        <div id="dashboardScreenMain" class="dashboard-panel" role="tabpanel" data-screen="main" aria-labelledby="tab-main">
        <div id="tilesContainer" class="tiles-grid">
            <?php if (empty($tilesMain)): ?>
            <!-- Default tiles when none configured -->
            <?php
            // Get default refresh intervals for default tiles
            $defaultEmailInterval = config('refresh.email', config('refresh.default_interval', 300)) * 1000;
            $defaultCalendarInterval = config('refresh.calendar', config('refresh.default_interval', 300)) * 1000;
            $defaultTodoInterval = config('refresh.todo', config('refresh.default_interval', 300)) * 1000;
            $defaultCrmInterval = config('refresh.crm', config('refresh.default_interval', 300)) * 1000;
            $defaultWeatherInterval = config('refresh.weather', config('refresh.default_interval', 300)) * 1000;
            ?>
            <div class="tile tile-resizable" data-tile-type="email" data-tile-id="0" data-column-span="1" data-row-span="1" data-refresh-interval="<?= $defaultEmailInterval ?>" style="grid-column: span 1; grid-row: span 1;">
                <div class="tile-resize-handle tile-resize-handle-se" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-e" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-s" title="Drag to resize"></div>
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <span class="tile-title-text">Inbox</span>
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content"><div class="tile-content-inner">
                    <div class="tile-placeholder">
                        <p>Connect Microsoft 365 to view emails</p>
                        <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                    </div>
                </div>
                </div>
                </div>
            </div>

            <div class="tile tile-resizable" data-tile-type="calendar" data-tile-id="0" data-column-span="1" data-row-span="1" data-refresh-interval="<?= $defaultCalendarInterval ?>" style="grid-column: span 1; grid-row: span 1;">
                <div class="tile-resize-handle tile-resize-handle-se" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-e" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-s" title="Drag to resize"></div>
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span class="tile-title-text">Calendar</span>
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content"><div class="tile-content-inner">
                    <div class="tile-placeholder">
                        <p>Connect Microsoft 365 to view calendar</p>
                        <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                    </div>
                </div>
                </div>
            </div>

            <div class="tile tile-resizable" data-tile-type="todo" data-tile-id="0" data-column-span="1" data-row-span="1" data-refresh-interval="<?= $defaultTodoInterval ?>" style="grid-column: span 1; grid-row: span 1;">
                <div class="tile-resize-handle tile-resize-handle-se" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-e" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-s" title="Drag to resize"></div>
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        <span class="tile-title-text">Tasks</span>
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content"><div class="tile-content-inner">
                    <div class="tile-placeholder">
                        <p>Connect Microsoft 365 to view tasks</p>
                        <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                    </div>
                </div>
                </div>
            </div>

            <div class="tile tile-resizable" data-tile-type="crm" data-tile-id="0" data-column-span="1" data-row-span="1" data-refresh-interval="<?= $defaultCrmInterval ?>" style="grid-column: span 1; grid-row: span 1;">
                <div class="tile-resize-handle tile-resize-handle-se" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-e" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-s" title="Drag to resize"></div>
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <span class="tile-title-text">CRM Actions</span>
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content"><div class="tile-content-inner">
                    <div class="tile-placeholder">
                        <p>Connect OnePageCRM to view actions</p>
                        <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                    </div>
                </div>
                </div>
            </div>

            <div class="tile tile-resizable" data-tile-type="weather" data-tile-id="0" data-column-span="1" data-row-span="1" data-refresh-interval="<?= $defaultWeatherInterval ?>" style="grid-column: span 1; grid-row: span 1;">
                <div class="tile-resize-handle tile-resize-handle-se" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-e" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-s" title="Drag to resize"></div>
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                        </svg>
                        <span class="tile-title-text">Weather</span>
                    </h3>
                    <button class="tile-refresh" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content"><div class="tile-content-inner">
                    <div class="tile-placeholder">
                        <p>Configure your location to see weather</p>
                        <a href="/settings.php" class="tile-connect-btn">Configure Weather</a>
                    </div>
                </div>
                </div>
            </div>

            <div class="tile tile-resizable" data-tile-type="claude" data-tile-id="0" data-column-span="2" data-row-span="1" style="grid-column: span 2; grid-row: span 1;">
                <div class="tile-resize-handle tile-resize-handle-se" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-e" title="Drag to resize"></div>
                <div class="tile-resize-handle tile-resize-handle-s" title="Drag to resize"></div>
                <div class="tile-header">
                    <h3 class="tile-title">
                        <svg class="w-5 h-5 mr-2 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                        <span class="tile-title-text">AI Assistant</span>
                    </h3>
                    <button class="tile-refresh" title="Refresh Suggestions" onclick="window.refreshSuggestions()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <div class="tile-content"><div class="tile-content-inner">
                    <div class="claude-interface">
                        <div id="claudeMessages" class="claude-messages">
                            <!-- AI Suggestions Section -->
                            <div class="ai-suggestions">
                                <div class="suggestions-loading">
                                    <div class="loading-spinner"></div>
                                    <p>Analyzing your dashboard...</p>
                                </div>
                            </div>
                            <!-- Chat Section (hidden initially, shown when user asks a question) -->
                            <div class="claude-chat hidden"></div>
                        </div>
                        <form id="claudeForm" class="claude-input-form">
                            <input
                                type="text"
                                id="claudeInput"
                                placeholder="Ask a question about your data..."
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
            </div>
            <?php else: ?>
                <?php foreach ($tilesMain as $tile): ?>
                <?php
                $columnSpan = isset($tile['column_span']) ? (int)$tile['column_span'] : ($tile['tile_type'] === 'claude' ? 2 : 1);
                $rowSpan = isset($tile['row_span']) ? (int)$tile['row_span'] : 1;
                $columnSpan = max(1, min(5, $columnSpan));
                $rowSpan = max(1, min(5, $rowSpan));
                
                // Get refresh interval from settings JSON or use default from config
                $settings = !empty($tile['settings']) ? json_decode($tile['settings'], true) : [];
                $refreshInterval = isset($settings['refresh_interval']) ? (int)$settings['refresh_interval'] : null;
                if ($refreshInterval === null) {
                    // Use config defaults per tile type
                    $tileType = $tile['tile_type'];
                    $refreshInterval = config("refresh.{$tileType}", config('refresh.default_interval', 300));
                }
                // Convert to milliseconds for JavaScript
                $refreshIntervalMs = $refreshInterval * 1000;
                ?>
                <div class="tile tile-resizable" 
                     data-tile-type="<?= e($tile['tile_type']) ?>" 
                     data-tile-id="<?= $tile['id'] ?>"
                     data-column-span="<?= $columnSpan ?>"
                     data-row-span="<?= $rowSpan ?>"
                     data-refresh-interval="<?= $refreshIntervalMs ?>"
                     style="grid-column: span <?= $columnSpan ?>; grid-row: span <?= $rowSpan ?>;">
                    <div class="tile-resize-handle tile-resize-handle-se" title="Drag to resize"></div>
                    <div class="tile-resize-handle tile-resize-handle-e" title="Drag to resize"></div>
                    <div class="tile-resize-handle tile-resize-handle-s" title="Drag to resize"></div>
                    <div class="tile-header">
                        <?php if ($tile['tile_type'] === 'claude'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'AI Assistant') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh Suggestions" onclick="window.refreshSuggestions()">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'notes'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Quick Notes') ?></span>
                        </h3>
                        <?php elseif ($tile['tile_type'] === 'notes-list'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Saved Notes') ?></span>
                        </h3>
                        <?php elseif ($tile['tile_type'] === 'bookmarks'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Bookmarks') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'link-board'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Link board') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'calendar-next'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Next event') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'next-event'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Next event') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'flagged-email'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/>
                                <line x1="4" y1="22" x2="4" y2="15"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Flagged email reminder') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh (pick another)">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'flagged-email-count'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/>
                                <line x1="4" y1="22" x2="4" y2="15"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Flagged Emails') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'overdue-tasks-count'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Overdue Tasks') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'email'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Inbox') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'calendar'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Calendar') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'calendar-heatmap'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Calendar heat map') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'availability'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Availability') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'todo'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Tasks') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'todo-personal'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Personal Tasks') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'planner-overview'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Planner overview') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'crm'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'CRM Actions') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'train-departures'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Train Departures') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php elseif ($tile['tile_type'] === 'weather'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Weather') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php else: ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 012-2h-2a2 2 0 012 2v2a2 2 0 01-2 2H8a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V6a2 2 0 01-2-2z"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? ucfirst($tile['tile_type'])) ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="tile-content"><div class="tile-content-inner">
                        <div class="tile-loading">
                            <div class="loading-spinner"></div>
                            <p>Loading...</p>
                        </div>
                    </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        </div>

        <!-- Panel: Screen 2 -->
        <div id="dashboardScreen2" class="dashboard-panel hidden" role="tabpanel" data-screen="screen2" aria-labelledby="tab-screen2" aria-hidden="true">
        <div class="tiles-grid" id="tilesContainerScreen2">
            <?php if (empty($tilesScreen2)): ?>
            <div class="col-span-full rounded-xl border-2 border-dashed p-8 text-center" style="border-color: color-mix(in srgb, var(--cb-tile-text) 25%, transparent); color: var(--cb-tile-text);">
                <p class="text-base font-medium opacity-90"><?= e($screenLabelScreen2) ?></p>
                <p class="mt-2 text-sm opacity-75">Add more dashboard tiles here in due course. You can configure new tiles from Settings and assign them to this screen.</p>
            </div>
            <?php else: ?>
                <?php foreach ($tilesScreen2 as $tile): ?>
                <?php
                $columnSpan = isset($tile['column_span']) ? (int)$tile['column_span'] : ($tile['tile_type'] === 'claude' ? 2 : 1);
                $rowSpan = isset($tile['row_span']) ? (int)$tile['row_span'] : 1;
                $columnSpan = max(1, min(5, $columnSpan));
                $rowSpan = max(1, min(5, $rowSpan));
                $settings = !empty($tile['settings']) ? json_decode($tile['settings'], true) : [];
                $refreshInterval = isset($settings['refresh_interval']) ? (int)$settings['refresh_interval'] : null;
                if ($refreshInterval === null) {
                    $refreshInterval = config("refresh.{$tile['tile_type']}", config('refresh.default_interval', 300));
                }
                $refreshIntervalMs = $refreshInterval * 1000;
                ?>
                <div class="tile tile-resizable" data-tile-type="<?= e($tile['tile_type']) ?>" data-tile-id="<?= $tile['id'] ?>" data-column-span="<?= $columnSpan ?>" data-row-span="<?= $rowSpan ?>" data-refresh-interval="<?= $refreshIntervalMs ?>" style="grid-column: span <?= $columnSpan ?>; grid-row: span <?= $rowSpan ?>;">
                    <div class="tile-resize-handle tile-resize-handle-se" title="Drag to resize"></div>
                    <div class="tile-resize-handle tile-resize-handle-e" title="Drag to resize"></div>
                    <div class="tile-resize-handle tile-resize-handle-s" title="Drag to resize"></div>
                    <div class="tile-header">
                        <?php if ($tile['tile_type'] === 'planner-overview'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Planner overview') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></button>
                        <?php elseif ($tile['tile_type'] === 'link-board'): ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                            </svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? 'Link board') ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></button>
                        <?php else: ?>
                        <h3 class="tile-title">
                            <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 012-2h-2a2 2 0 012 2v2a2 2 0 01-2 2H8a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V6a2 2 0 01-2-2z"/></svg>
                            <span class="tile-title-text"><?= e($tile['title'] ?? ucfirst($tile['tile_type'])) ?></span>
                        </h3>
                        <button class="tile-refresh" title="Refresh"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></button>
                        <?php endif; ?>
                    </div>
                    <div class="tile-content"><div class="tile-content-inner"><div class="tile-loading"><div class="loading-spinner"></div><p>Loading...</p></div></div></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
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
        window.SESSION_EXPIRES_AT = <?= $sessionExpiresAt !== null ? (int) $sessionExpiresAt : 'null' ?>;
        window.SESSION_PRIVATE_COMPUTER = <?= $isPrivateComputer ? 'true' : 'false' ?>;
        window.DASHBOARD_SCREEN_LABELS = <?= json_encode(['main' => $screenLabelMain, 'screen2' => $screenLabelScreen2], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= dashboard_script_url() ?>"></script>
</body>
</html>
