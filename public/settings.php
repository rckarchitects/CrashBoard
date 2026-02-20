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
                cacheClear("calendar_heatmap_v2_{$userId}");
                cacheClear("availability_{$userId}");
                cacheClear("calendar_next_{$userId}_%");
                cacheClear("calendar_next_event_{$userId}_%");
                cacheClear("todo_{$userId}");
                cacheClear("crm_{$userId}");
                cacheClear("weather_{$userId}");
                cacheClear("flagged_email_{$userId}");
                cacheClear("flagged_email_count_{$userId}");
                cacheClear("overdue_tasks_count_{$userId}");
                cacheClear("train_departures_{$userId}_%");
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

        case 'save_theme':
            // Ensure theme columns exist (migration)
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
                $screenLabelCol = Database::query("SHOW COLUMNS FROM users LIKE 'dashboard_screen1_label'");
                if (empty($screenLabelCol)) {
                    Database::execute("ALTER TABLE users ADD COLUMN dashboard_screen1_label VARCHAR(50) DEFAULT 'Main' AFTER theme_tile_text");
                    Database::execute("ALTER TABLE users ADD COLUMN dashboard_screen2_label VARCHAR(50) DEFAULT 'Screen 2' AFTER dashboard_screen1_label");
                }
            } catch (Exception $e) {
                error_log('Theme migration: ' . $e->getMessage());
            }

            // Validate and sanitize theme values
            $primary = trim(post('theme_primary', '#0ea5e9'));
            $secondary = trim(post('theme_secondary', '#6366f1'));
            $background = trim(post('theme_background', '#f3f4f6'));
            $font = trim(post('theme_font', 'system'));
            $headerBg = trim(post('theme_header_bg', '#ffffff'));
            $headerText = trim(post('theme_header_text', '#111827'));
            $tileBg = trim(post('theme_tile_bg', '#ffffff'));
            $tileText = trim(post('theme_tile_text', '#374151'));

            // Validate hex colors
            $colorFields = [
                'primary' => $primary,
                'secondary' => $secondary,
                'background' => $background,
                'header_bg' => $headerBg,
                'header_text' => $headerText,
                'tile_bg' => $tileBg,
                'tile_text' => $tileText,
            ];
            
            foreach ($colorFields as $field => $value) {
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                    Session::setFlash('error', "Invalid {$field} color format.");
                    redirect('/settings.php');
                }
            }

            // Validate font
            $allowedFonts = ['system', 'serif', 'mono', 'inter', 'roboto', 'open-sans', 'lato', 'montserrat', 'raleway', 'playfair'];
            if (!in_array($font, $allowedFonts)) {
                $font = 'system';
            }

            $screen1Label = substr(trim(post('dashboard_screen1_label', 'Main')), 0, 50) ?: 'Main';
            $screen2Label = substr(trim(post('dashboard_screen2_label', 'Screen 2')), 0, 50) ?: 'Screen 2';

            // Save to database
            try {
                Database::execute(
                    'UPDATE users SET theme_primary = ?, theme_secondary = ?, theme_background = ?, theme_font = ?, theme_header_bg = ?, theme_header_text = ?, theme_tile_bg = ?, theme_tile_text = ?, dashboard_screen1_label = ?, dashboard_screen2_label = ? WHERE id = ?',
                    [$primary, $secondary, $background, $font, $headerBg, $headerText, $tileBg, $tileText, $screen1Label, $screen2Label, $userId]
                );
                Session::setFlash('success', 'Theme and dashboard screen names saved successfully.');
            } catch (Exception $e) {
                Session::setFlash('error', 'Failed to save theme: ' . $e->getMessage());
            }
            break;

        case 'save_planner_overview_limits':
            $maxPlans = (int) post('planner_overview_max_plans', 10);
            $maxTasksPerPlan = (int) post('planner_overview_max_tasks_per_plan', 15);
            $maxPlans = max(1, min(50, $maxPlans));
            $maxTasksPerPlan = max(1, min(100, $maxTasksPerPlan));
            try {
                Database::execute(
                    'UPDATE users SET planner_overview_max_plans = ?, planner_overview_max_tasks_per_plan = ? WHERE id = ?',
                    [$maxPlans, $maxTasksPerPlan, $userId]
                );
                cacheClear("planner_overview_v2_{$userId}");
                Session::setFlash('success', 'Planner overview limits saved. Refresh your dashboard to see the change.');
            } catch (Exception $e) {
                Session::setFlash('error', 'Failed to save: ' . $e->getMessage());
            }
            break;

        case 'add_notes_tile':
            // Check if notes tile already exists
            $existing = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'notes']
            );

            if ($existing) {
                Session::setFlash('error', 'Notes tile already exists on your dashboard.');
            } else {
                // Get the max position to add at the end
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;

                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'notes', 'Quick Notes', $newPosition, 1, 1, true]
                    );
                    Session::setFlash('success', 'Notes tile added successfully! Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add notes tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_notes_list_tile':
            // Check if notes-list tile already exists
            $existing = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'notes-list']
            );

            if ($existing) {
                Session::setFlash('error', 'Notes list tile already exists on your dashboard.');
            } else {
                // Get the max position to add at the end
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;

                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'notes-list', 'Saved Notes', $newPosition, 1, 1, true]
                    );
                    Session::setFlash('success', 'Notes list tile added successfully! Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add notes list tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_bookmarks_tile':
            $existing = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'bookmarks']
            );
            if ($existing) {
                Session::setFlash('error', 'Bookmarks tile already exists on your dashboard.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'bookmarks', 'Bookmarks', $newPosition, 1, 1, true]
                    );
                    Session::setFlash('success', 'Bookmarks tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add bookmarks tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_link_board_tile':
            $existing = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'link-board']
            );
            if ($existing) {
                Session::setFlash('error', 'Link board tile already exists on your dashboard.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'link-board', 'Link board', $newPosition, 2, 1, true]
                    );
                    Session::setFlash('success', 'Link board tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add link board tile: ' . $e->getMessage());
                }
            }
            break;

        case 'generate_link_board_share_key':
            $newKey = bin2hex(random_bytes(32));
            try {
                Database::execute(
                    'UPDATE users SET link_board_share_key = ? WHERE id = ?',
                    [$newKey, $userId]
                );
                Session::setFlash('success', 'Share link generated. Use the URL below from your phone or iOS Shortcuts.');
            } catch (Exception $e) {
                Session::setFlash('error', 'Failed to generate share link: ' . $e->getMessage());
            }
            break;

        case 'save_link_board_share_category':
            $catId = post('link_board_share_category_id');
            $catId = $catId === '' || $catId === null ? null : (int) $catId;
            if ($catId !== null && $catId <= 0) {
                $catId = null;
            }
            if ($catId !== null) {
                $owned = Database::queryOne('SELECT id FROM link_board_categories WHERE id = ? AND user_id = ?', [$catId, $userId]);
                if (!$owned) {
                    $catId = null;
                }
            }
            try {
                Database::execute(
                    'UPDATE users SET link_board_share_category_id = ? WHERE id = ?',
                    [$catId, $userId]
                );
                Session::setFlash('success', 'Default category for shared links saved.');
            } catch (Exception $e) {
                Session::setFlash('error', 'Failed to save: ' . $e->getMessage());
            }
            break;

        case 'add_calendar_next_tile':
            $title = trim(post('calendar_next_title', 'Next event'));
            $category = trim(post('calendar_next_category', ''));
            if ($category === '') {
                Session::setFlash('error', 'Please enter an Outlook category name.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                $settings = json_encode(['category' => $category]);
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled, settings) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'calendar-next', $title ?: 'Next event', $newPosition, 1, 1, true, $settings]
                    );
                    Session::setFlash('success', 'Next event tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'update_calendar_next_tile':
            $tileId = (int) post('calendar_next_tile_id', 0);
            $title = trim(post('calendar_next_title', 'Next event'));
            $category = trim(post('calendar_next_category', ''));
            if ($tileId <= 0) {
                Session::setFlash('error', 'Invalid tile.');
            } elseif ($category === '') {
                Session::setFlash('error', 'Please enter an Outlook category name.');
            } else {
                $existing = Database::queryOne(
                    'SELECT id FROM tiles WHERE id = ? AND user_id = ? AND tile_type = ?',
                    [$tileId, $userId, 'calendar-next']
                );
                if (!$existing) {
                    Session::setFlash('error', 'Tile not found.');
                } else {
                    $settings = json_encode(['category' => $category]);
                    try {
                        Database::execute(
                            'UPDATE tiles SET title = ?, settings = ? WHERE id = ? AND user_id = ?',
                            [$title ?: 'Next event', $settings, $tileId, $userId]
                        );
                        cacheClear("calendar_next_{$userId}_{$tileId}");
                        Session::setFlash('success', 'Next event tile updated. Refresh your dashboard to see changes.');
                    } catch (Exception $e) {
                        Session::setFlash('error', 'Failed to update tile: ' . $e->getMessage());
                    }
                }
            }
            break;

        case 'add_next_event_tile':
            $hasNextEventTile = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'next-event']
            );
            if ($hasNextEventTile) {
                Session::setFlash('error', 'Next event tile is already on your dashboard.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'next-event', 'Next event', $newPosition, 1, 1, true]
                    );
                    Session::setFlash('success', 'Next event tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_calendar_heatmap_tile':
            $hasCalendarHeatmapTile = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'calendar-heatmap']
            );
            if ($hasCalendarHeatmapTile) {
                Session::setFlash('error', 'Calendar heat map tile is already on your dashboard.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'calendar-heatmap', 'Calendar heat map', $newPosition, 1, 1, true]
                    );
                    Session::setFlash('success', 'Calendar heat map tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_flagged_email_tile':
            $hasFlaggedTile = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'flagged-email']
            );
            if ($hasFlaggedTile) {
                Session::setFlash('error', 'Flagged email reminder tile is already on your dashboard.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'flagged-email', 'Flagged email reminder', $newPosition, 1, 1, true]
                    );
                    Session::setFlash('success', 'Flagged email reminder tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_availability_tile':
            $hasAvailabilityTile = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'availability']
            );
            if ($hasAvailabilityTile) {
                Session::setFlash('error', 'Availability tile is already on your dashboard.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'availability', 'Availability', $newPosition, 1, 1, true]
                    );
                    Session::setFlash('success', 'Availability tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_flagged_email_count_tile':
            $hasFlaggedCountTile = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'flagged-email-count']
            );
            if ($hasFlaggedCountTile) {
                Session::setFlash('error', 'Flagged email count tile is already on your dashboard.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'flagged-email-count', 'Flagged Emails', $newPosition, 1, 1, true]
                    );
                    Session::setFlash('success', 'Flagged email count tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_overdue_tasks_count_tile':
            $hasOverdueCountTile = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'overdue-tasks-count']
            );
            if ($hasOverdueCountTile) {
                Session::setFlash('error', 'Overdue tasks count tile is already on your dashboard.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'overdue-tasks-count', 'Overdue Tasks', $newPosition, 1, 1, true]
                    );
                    cacheClear("overdue_tasks_count_{$userId}");
                    Session::setFlash('success', 'Overdue tasks count tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_todo_personal_tile':
            $hasTile = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'todo-personal']
            );
            if ($hasTile) {
                Session::setFlash('error', 'Personal Tasks tile already exists.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'todo-personal', 'Personal Tasks', $newPosition, 1, 1, true]
                    );
                    cacheClear("todo_personal_{$userId}");
                    Session::setFlash('success', 'Personal Tasks tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_planner_overview_tile':
            $hasPlannerOverviewTile = Database::queryOne(
                'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                [$userId, 'planner-overview']
            );
            if ($hasPlannerOverviewTile) {
                Session::setFlash('error', 'Planner overview tile already exists.');
            } else {
                $maxPos = Database::queryOne(
                    'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                    [$userId]
                );
                $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                try {
                    Database::execute(
                        'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, 'planner-overview', 'Planner overview', $newPosition, 2, 1, true]
                    );
                    cacheClear("planner_overview_v2_{$userId}");
                    Session::setFlash('success', 'Planner overview tile added. Refresh your dashboard to see it.');
                } catch (Exception $e) {
                    Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                }
            }
            break;

        case 'add_train_departures_tile':
            $originCrs = strtoupper(trim(post('train_origin_crs', '')));
            $originName = trim(post('train_origin_name', ''));
            $destinationCrs = strtoupper(trim(post('train_destination_crs', '')));
            $destinationName = trim(post('train_destination_name', ''));
            $numDepartures = (int) post('train_num_departures', 5);
            $numDepartures = max(1, min(10, $numDepartures));
            
            if (empty($originCrs) || empty($destinationCrs)) {
                Session::setFlash('error', 'Please provide both origin and destination station codes.');
            } elseif (!preg_match('/^[A-Z]{3}$/', $originCrs) || !preg_match('/^[A-Z]{3}$/', $destinationCrs)) {
                Session::setFlash('error', 'Station codes must be 3 uppercase letters (e.g., PAD, BRI, MAN).');
            } else {
                $hasTile = Database::queryOne(
                    'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                    [$userId, 'train-departures']
                );
                if ($hasTile) {
                    Session::setFlash('error', 'Train departures tile already exists.');
                } else {
                    $maxPos = Database::queryOne(
                        'SELECT MAX(position) as max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE',
                        [$userId]
                    );
                    $newPosition = ($maxPos['max_pos'] ?? 0) + 1;
                    $settings = json_encode([
                        'origin_crs' => $originCrs,
                        'origin_name' => $originName ?: $originCrs,
                        'destination_crs' => $destinationCrs,
                        'destination_name' => $destinationName ?: $destinationCrs,
                        'num_departures' => $numDepartures
                    ]);
                    try {
                        Database::execute(
                            'INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled, settings) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                            [$userId, 'train-departures', 'Train Departures', $newPosition, 1, 1, true, $settings]
                        );
                        cacheClear("train_departures_{$userId}_%");
                        Session::setFlash('success', 'Train departures tile added. Refresh your dashboard to see it.');
                    } catch (Exception $e) {
                        Session::setFlash('error', 'Failed to add tile: ' . $e->getMessage());
                    }
                }
            }
            break;

        case 'update_train_departures_tile':
            $tileId = (int) post('train_tile_id', 0);
            $originCrs = strtoupper(trim(post('train_origin_crs', '')));
            $originName = trim(post('train_origin_name', ''));
            $destinationCrs = strtoupper(trim(post('train_destination_crs', '')));
            $destinationName = trim(post('train_destination_name', ''));
            $numDepartures = (int) post('train_num_departures', 5);
            $numDepartures = max(1, min(10, $numDepartures));
            
            if ($tileId <= 0) {
                Session::setFlash('error', 'Invalid tile.');
            } elseif (empty($originCrs) || empty($destinationCrs)) {
                Session::setFlash('error', 'Please provide both origin and destination station codes.');
            } elseif (!preg_match('/^[A-Z]{3}$/', $originCrs) || !preg_match('/^[A-Z]{3}$/', $destinationCrs)) {
                Session::setFlash('error', 'Station codes must be 3 uppercase letters (e.g., PAD, BRI, MAN).');
            } else {
                $existing = Database::queryOne(
                    'SELECT id FROM tiles WHERE id = ? AND user_id = ? AND tile_type = ?',
                    [$tileId, $userId, 'train-departures']
                );
                if (!$existing) {
                    Session::setFlash('error', 'Tile not found.');
                } else {
                    $settings = json_encode([
                        'origin_crs' => $originCrs,
                        'origin_name' => $originName ?: $originCrs,
                        'destination_crs' => $destinationCrs,
                        'destination_name' => $destinationName ?: $destinationCrs,
                        'num_departures' => $numDepartures
                    ]);
                    try {
                        Database::execute(
                            'UPDATE tiles SET settings = ? WHERE id = ? AND user_id = ?',
                            [$settings, $tileId, $userId]
                        );
                        cacheClear("train_departures_{$userId}_{$tileId}");
                        Session::setFlash('success', 'Train departures tile updated. Refresh your dashboard to see changes.');
                    } catch (Exception $e) {
                        Session::setFlash('error', 'Failed to update tile: ' . $e->getMessage());
                    }
                }
            }
            break;

        case 'save_email_preview':
            // Ensure email_preview_chars column exists (migration)
            try {
                $col = Database::query("SHOW COLUMNS FROM users LIKE 'email_preview_chars'");
                if (empty($col)) {
                    Database::execute('ALTER TABLE users ADD COLUMN email_preview_chars INT UNSIGNED DEFAULT 320 AFTER updated_at');
                }
            } catch (Exception $e) {
                error_log('Email preview chars migration: ' . $e->getMessage());
            }

            $chars = (int) post('email_preview_chars', 320);
            $chars = max(100, min(2000, $chars));

            try {
                Database::execute(
                    'UPDATE users SET email_preview_chars = ? WHERE id = ?',
                    [$chars, $userId]
                );
                cacheClear("email_{$userId}_%");
                Session::setFlash('success', 'Email preview length saved.');
            } catch (Exception $e) {
                Session::setFlash('error', 'Failed to save: ' . $e->getMessage());
            }
            break;

        case 'save_tile_refresh_rates':
            $tileRefreshRates = post('tile_refresh_rates', []);
            $updated = 0;
            $errors = [];

            foreach ($tileRefreshRates as $tileId => $refreshInterval) {
                $tileId = (int) $tileId;
                $refreshInterval = (int) $refreshInterval;
                
                // Validate: refresh interval must be between 30 seconds (30) and 1 hour (3600)
                if ($refreshInterval < 30 || $refreshInterval > 3600) {
                    $errors[] = "Tile ID {$tileId}: Refresh interval must be between 30 seconds and 1 hour.";
                    continue;
                }

                // Verify tile belongs to user
                $tile = Database::queryOne(
                    'SELECT id, settings FROM tiles WHERE id = ? AND user_id = ?',
                    [$tileId, $userId]
                );

                if (!$tile) {
                    $errors[] = "Tile ID {$tileId}: Tile not found.";
                    continue;
                }

                // Get existing settings or create new
                $settings = !empty($tile['settings']) ? json_decode($tile['settings'], true) : [];
                if (!is_array($settings)) {
                    $settings = [];
                }

                // Update refresh interval
                $settings['refresh_interval'] = $refreshInterval;

                try {
                    Database::execute(
                        'UPDATE tiles SET settings = ? WHERE id = ? AND user_id = ?',
                        [json_encode($settings), $tileId, $userId]
                    );
                    $updated++;
                } catch (Exception $e) {
                    $errors[] = "Tile ID {$tileId}: " . $e->getMessage();
                }
            }

            if (!empty($errors)) {
                Session::setFlash('error', 'Some refresh rates could not be saved: ' . implode(' ', $errors));
            } elseif ($updated > 0) {
                Session::setFlash('success', "Refresh rates updated for {$updated} tile(s). Refresh your dashboard to see changes.");
            } else {
                Session::setFlash('info', 'No changes to save.');
            }
            break;
    }

    redirect('/settings.php');
}

// Get flash messages
$success = Session::flash('success');
$error = Session::flash('error');

// Ensure email_preview_chars column exists (migration)
try {
    $emailPreviewCol = Database::query("SHOW COLUMNS FROM users LIKE 'email_preview_chars'");
    if (empty($emailPreviewCol)) {
        Database::execute('ALTER TABLE users ADD COLUMN email_preview_chars INT UNSIGNED DEFAULT 320 AFTER updated_at');
    }
    $screenLabelCol = Database::query("SHOW COLUMNS FROM users LIKE 'dashboard_screen1_label'");
    if (empty($screenLabelCol)) {
        Database::execute("ALTER TABLE users ADD COLUMN dashboard_screen1_label VARCHAR(50) DEFAULT 'Main'");
        Database::execute("ALTER TABLE users ADD COLUMN dashboard_screen2_label VARCHAR(50) DEFAULT 'Screen 2'");
    }
    $plannerMaxPlansCol = Database::query("SHOW COLUMNS FROM users LIKE 'planner_overview_max_plans'");
    if (empty($plannerMaxPlansCol)) {
        Database::execute('ALTER TABLE users ADD COLUMN planner_overview_max_plans TINYINT UNSIGNED DEFAULT NULL');
        Database::execute('ALTER TABLE users ADD COLUMN planner_overview_max_tasks_per_plan TINYINT UNSIGNED DEFAULT NULL');
    }
    $shareKeyCol = Database::query("SHOW COLUMNS FROM users LIKE 'link_board_share_key'");
    if (empty($shareKeyCol)) {
        Database::execute('ALTER TABLE users ADD COLUMN link_board_share_key VARCHAR(64) NULL DEFAULT NULL');
        Database::execute('ALTER TABLE users ADD COLUMN link_board_share_category_id INT UNSIGNED NULL DEFAULT NULL');
        Database::execute('CREATE UNIQUE INDEX idx_users_link_board_share_key ON users (link_board_share_key)');
    }
} catch (Exception $e) {
    error_log('Settings migration: ' . $e->getMessage());
}

// Get user theme and email preview preferences from database
$userTheme = Database::queryOne(
    'SELECT theme_primary, theme_secondary, theme_background, theme_font, theme_header_bg, theme_header_text, theme_tile_bg, theme_tile_text, email_preview_chars, dashboard_screen1_label, dashboard_screen2_label, planner_overview_max_plans, planner_overview_max_tasks_per_plan FROM users WHERE id = ?',
    [$userId]
);

$emailPreviewChars = (int) ($userTheme['email_preview_chars'] ?? 320);
$emailPreviewChars = max(100, min(2000, $emailPreviewChars));

$themePreview = [
    'primary' => $userTheme['theme_primary'] ?? '#0ea5e9',
    'secondary' => $userTheme['theme_secondary'] ?? '#6366f1',
    'background' => $userTheme['theme_background'] ?? '#f3f4f6',
    'font' => $userTheme['theme_font'] ?? 'system',
    'header_bg' => $userTheme['theme_header_bg'] ?? '#ffffff',
    'header_text' => $userTheme['theme_header_text'] ?? '#111827',
    'tile_bg' => $userTheme['theme_tile_bg'] ?? '#ffffff',
    'tile_text' => $userTheme['theme_tile_text'] ?? '#374151',
];

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
    'calendar_heatmap_v2' => getCacheStatus($userId, 'calendar_heatmap_v2'),
    'availability' => getCacheStatus($userId, 'availability'),
    'todo' => getCacheStatus($userId, 'todo'),
    'crm' => getCacheStatus($userId, 'crm'),
    'weather' => getCacheStatus($userId, 'weather'),
    'flagged_email' => getCacheStatus($userId, 'flagged_email'),
    'flagged_email_count' => getCacheStatus($userId, 'flagged_email_count'),
    'overdue_tasks_count' => getCacheStatus($userId, 'overdue_tasks_count'),
    'planner_overview' => getCacheStatus($userId, 'planner_overview_v2'),
    'train_departures' => null, // Per-tile caching, handled separately
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

// Get all tiles for refresh rate configuration
$userTiles = Database::query(
    'SELECT id, tile_type, title, settings FROM tiles WHERE user_id = ? AND is_enabled = TRUE ORDER BY position ASC',
    [$userId]
);

// Helper function to get refresh interval for a tile
function getTileRefreshInterval(array $tile): int {
    $settings = !empty($tile['settings']) ? json_decode($tile['settings'], true) : [];
    if (isset($settings['refresh_interval'])) {
        return (int) $settings['refresh_interval'];
    }
    // Use config defaults per tile type
    $tileType = $tile['tile_type'];
    return config("refresh.{$tileType}", config('refresh.default_interval', 300));
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
    if (isset($fontUrls[$themePreview['font']])): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?= $fontUrls[$themePreview['font']] ?>" rel="stylesheet">
    <?php endif; ?>
    <style>
        :root {
            --cb-primary: <?= e($themePreview['primary']) ?>;
            --cb-secondary: <?= e($themePreview['secondary']) ?>;
            --cb-background: <?= e($themePreview['background']) ?>;
            --cb-header-bg: <?= e($themePreview['header_bg']) ?>;
            --cb-header-text: <?= e($themePreview['header_text']) ?>;
            --cb-tile-bg: <?= e($themePreview['tile_bg']) ?>;
            --cb-tile-text: <?= e($themePreview['tile_text']) ?>;
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
            font-family: <?= $fontFamilies[$themePreview['font']] ?? $fontFamilies['system'] ?>;
        }
        /* Header styling */
        header {
            background-color: var(--cb-header-bg) !important;
            color: var(--cb-header-text) !important;
        }
        header * {
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
        .tile-title, .tile-content {
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
<body class="h-full" style="background-color: var(--cb-background);">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-[1536px] mx-auto px-4 sm:px-6 lg:px-8">
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
    <main class="max-w-[1536px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
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

        <!-- Email (Inbox) preview -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Email (Inbox)</h2>
                <p class="mt-1 text-sm text-gray-500">How much of each email to show in the popup when you click a message in the Inbox tile.</p>
            </div>
            <div class="p-6">
                <form action="" method="POST" class="flex flex-wrap items-end gap-4">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="save_email_preview">
                    <div>
                        <label for="email_preview_chars" class="block text-sm font-medium text-gray-700">Preview length in popup (characters)</label>
                        <input
                            type="number"
                            id="email_preview_chars"
                            name="email_preview_chars"
                            value="<?= (int) $emailPreviewChars ?>"
                            min="100"
                            max="2000"
                            step="1"
                            class="mt-1 block w-32 px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                        >
                        <p class="mt-1 text-xs text-gray-500">100–2000. List view shows the first 100 characters. Values over 255 load full message bodies (inbox may load slightly slower).</p>
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Save
                    </button>
                </form>
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

        <!-- Tiles Management -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Tiles Management</h2>
                <p class="mt-1 text-sm text-gray-500">Add additional tiles to your dashboard.</p>
            </div>

            <div class="p-6">
                <div class="space-y-4">
                    <!-- Quick Notes Tile -->
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Quick Notes</h3>
                                <p class="text-sm text-gray-500 mt-1">A simple note-taking tile with auto-save functionality. Perfect for jotting down quick thoughts and reminders.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php
                        $hasNotesTile = Database::queryOne(
                            'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                            [$userId, 'notes']
                        );
                        ?>
                        <?php if ($hasNotesTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_notes_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Saved Notes List Tile -->
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Saved Notes List</h3>
                                <p class="text-sm text-gray-500 mt-1">View and access your saved notes. Click on any note to load it back into the Quick Notes tile for editing.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php
                        $hasNotesListTile = Database::queryOne(
                            'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                            [$userId, 'notes-list']
                        );
                        ?>
                        <?php if ($hasNotesListTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_notes_list_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bookmarks Tile -->
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Bookmarks</h3>
                                <p class="text-sm text-gray-500 mt-1">Save URLs and open them from a tile. Shows favicons; click to open in a new tab.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php
                        $hasBookmarksTile = Database::queryOne(
                            'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                            [$userId, 'bookmarks']
                        );
                        ?>
                        <?php if ($hasBookmarksTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_bookmarks_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Link board Tile -->
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Link board</h3>
                                <p class="text-sm text-gray-500 mt-1">Organise URLs in categories (kanban-style). Drag links between columns. Optional AI-generated page summaries.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php
                        $hasLinkBoardTile = Database::queryOne(
                            'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                            [$userId, 'link-board']
                        );
                        ?>
                        <?php if ($hasLinkBoardTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_link_board_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Link board: Share from phone -->
                    <?php
                    $linkBoardShare = Database::queryOne(
                        'SELECT link_board_share_key, link_board_share_category_id FROM users WHERE id = ?',
                        [$userId]
                    );
                    $linkBoardShareKey = $linkBoardShare['link_board_share_key'] ?? null;
                    $linkBoardShareCategoryId = isset($linkBoardShare['link_board_share_category_id']) ? (int) $linkBoardShare['link_board_share_category_id'] : null;
                    $linkBoardCategories = [];
                    $catTable = Database::queryOne("SHOW TABLES LIKE 'link_board_categories'");
                    if (!empty($catTable)) {
                        $linkBoardCategories = Database::query(
                            'SELECT id, name FROM link_board_categories WHERE user_id = ? ORDER BY position ASC, id ASC',
                            [$userId]
                        );
                    }
                    $receiveUrlBase = rtrim(config('app.url', ''), '/') . '/receiveurl.php';
                    ?>
                    <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                </svg>
                            </div>
                            <div class="ml-4 flex-1 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Link board: Share from phone</h3>
                                <p class="text-sm text-gray-500 mt-1">Generate a secret URL to add links to your Link board from your iPhone share sheet (e.g. via Shortcuts). No login required on the device.</p>
                                <?php if ($linkBoardShareKey): ?>
                                <div class="mt-4 space-y-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Endpoint (POST only)</label>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <input type="text" id="link-board-share-url" readonly value="<?= e($receiveUrlBase) ?>" class="block flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm bg-gray-50 font-mono">
                                            <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('link-board-share-url').value)" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">Copy URL</button>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">In Shortcuts: use <strong>Get contents of URL</strong> or <strong>Post</strong> with method POST. Send <code class="bg-gray-200 px-1 rounded">key</code> and <code class="bg-gray-200 px-1 rounded">url</code> in the request body (form or JSON). The shared link goes in <code class="bg-gray-200 px-1 rounded">url</code> — no encoding needed.</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Your secret key (use as <code class="bg-gray-200 px-1 rounded">key</code> in POST body)</label>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <input type="text" id="link-board-share-key" readonly value="<?= e($linkBoardShareKey) ?>" class="block flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm bg-gray-50 font-mono">
                                            <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('link-board-share-key').value)" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">Copy key</button>
                                        </div>
                                    </div>
                                    <form action="" method="POST" class="flex flex-wrap items-end gap-3">
                                        <?= Session::csrfField() ?>
                                        <input type="hidden" name="action" value="save_link_board_share_category">
                                        <div>
                                            <label for="link_board_share_category_id" class="block text-xs font-medium text-gray-600 mb-1">Default category for shared links</label>
                                            <select id="link_board_share_category_id" name="link_board_share_category_id" class="block rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">First category (auto)</option>
                                                <?php foreach ($linkBoardCategories as $c): ?>
                                                <option value="<?= (int)$c['id'] ?>" <?= $linkBoardShareCategoryId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">Save category</button>
                                    </form>
                                    <form action="" method="POST" class="inline">
                                        <?= Session::csrfField() ?>
                                        <input type="hidden" name="action" value="generate_link_board_share_key">
                                        <button type="submit" class="text-sm text-amber-600 hover:text-amber-800" onclick="return confirm('Regenerate key? Your current share URL will stop working.');">Regenerate secret key</button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <form action="" method="POST" class="mt-4">
                                    <?= Session::csrfField() ?>
                                    <input type="hidden" name="action" value="generate_link_board_share_key">
                                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">Generate share link</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Next event by category (Outlook calendar) -->
                    <?php
                    $calendarNextTile = Database::queryOne(
                        'SELECT id, title, settings FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE ORDER BY position ASC LIMIT 1',
                        [$userId, 'calendar-next']
                    );
                    $calendarNextTitle = 'Next event';
                    $calendarNextCategory = '';
                    $calendarNextTileId = 0;
                    if ($calendarNextTile) {
                        $calendarNextTitle = $calendarNextTile['title'] ?? $calendarNextTitle;
                        $calendarNextTileId = (int) $calendarNextTile['id'];
                        $settingsDecoded = json_decode($calendarNextTile['settings'] ?? '{}', true);
                        $calendarNextCategory = is_array($settingsDecoded) ? trim($settingsDecoded['category'] ?? '') : '';
                    }
                    ?>
                    <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-sky-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Next event by category</h3>
                                <p class="text-sm text-gray-500 mt-1">Shows the date and details of the next calendar event with a specific Outlook category. Connect Microsoft 365 first.</p>
                                <form action="" method="POST" class="mt-4 flex flex-wrap items-end gap-3">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action" value="<?= $calendarNextTileId ? 'update_calendar_next_tile' : 'add_calendar_next_tile' ?>">
                                <?php if ($calendarNextTileId): ?>
                                <input type="hidden" name="calendar_next_tile_id" value="<?= $calendarNextTileId ?>">
                                <?php endif; ?>
                                <div>
                                    <label for="calendar_next_title" class="block text-xs font-medium text-gray-600 mb-1">Tile title</label>
                                    <input type="text" id="calendar_next_title" name="calendar_next_title" value="<?= e($calendarNextTitle) ?>" maxlength="100"
                                        class="block w-40 rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                </div>
                                <div>
                                    <label for="calendar_next_category" class="block text-xs font-medium text-gray-600 mb-1">Outlook category <span class="text-red-500">*</span></label>
                                    <input type="text" id="calendar_next_category" name="calendar_next_category" value="<?= e($calendarNextCategory) ?>" required placeholder="e.g. Red Category, Personal"
                                        class="block w-48 rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                </div>
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500">
                                    <?= $calendarNextTileId ? 'Update tile' : 'Add Tile' ?>
                                </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Next event (prominent with countdown) -->
                    <?php
                    $hasNextEventTile = Database::queryOne(
                        'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                        [$userId, 'next-event']
                    );
                    ?>
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Next event</h3>
                                <p class="text-sm text-gray-500 mt-1">Shows your next calendar event prominently with a live countdown to the start time. Connect Microsoft 365 first.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php if ($hasNextEventTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_next_event_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Calendar heat map tile -->
                    <?php
                    $hasCalendarHeatmapTile = Database::queryOne(
                        'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                        [$userId, 'calendar-heatmap']
                    );
                    ?>
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Calendar heat map</h3>
                                <p class="text-sm text-gray-500 mt-1">Shows the current week plus the next four weeks (weekdays only) with background shading by number of events per day. Connect Microsoft 365 first.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php if ($hasCalendarHeatmapTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_calendar_heatmap_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Availability tile -->
                    <?php
                    $hasAvailabilityTile = Database::queryOne(
                        'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                        [$userId, 'availability']
                    );
                    ?>
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Availability</h3>
                                <p class="text-sm text-gray-500 mt-1">Shows your available meeting times for the coming fortnight (starting next week). Finds gaps in your calendar with a 1-hour buffer. Includes a button to copy the list to your clipboard. Connect Microsoft 365 first.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php if ($hasAvailabilityTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_availability_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Train departures tile -->
                    <?php
                    $trainDeparturesTile = Database::queryOne(
                        'SELECT id, settings FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE ORDER BY position ASC LIMIT 1',
                        [$userId, 'train-departures']
                    );
                    $trainTileId = 0;
                    $trainOriginCrs = '';
                    $trainOriginName = '';
                    $trainDestinationCrs = '';
                    $trainDestinationName = '';
                    $trainNumDepartures = 5;
                    if ($trainDeparturesTile) {
                        $trainTileId = (int) $trainDeparturesTile['id'];
                        $settingsDecoded = json_decode($trainDeparturesTile['settings'] ?? '{}', true);
                        if (is_array($settingsDecoded)) {
                            $trainOriginCrs = trim($settingsDecoded['origin_crs'] ?? '');
                            $trainOriginName = trim($settingsDecoded['origin_name'] ?? '');
                            $trainDestinationCrs = trim($settingsDecoded['destination_crs'] ?? '');
                            $trainDestinationName = trim($settingsDecoded['destination_name'] ?? '');
                            $trainNumDepartures = isset($settingsDecoded['num_departures']) ? (int)$settingsDecoded['num_departures'] : 5;
                        }
                    }
                    ?>
                    <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Train Departures</h3>
                                <p class="text-sm text-gray-500 mt-1">Shows the next few departures from a UK railway station to a destination station. Uses National Rail data via Huxley 2 API. Station codes must be 3-letter CRS codes (e.g., PAD for London Paddington, BRI for Bristol Temple Meads).</p>
                                <form action="" method="POST" class="mt-4 space-y-4">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action" value="<?= $trainTileId ? 'update_train_departures_tile' : 'add_train_departures_tile' ?>">
                                <?php if ($trainTileId): ?>
                                <input type="hidden" name="train_tile_id" value="<?= $trainTileId ?>">
                                <?php endif; ?>
                                <div class="grid grid-cols-2 gap-x-6 gap-y-4 max-w-xl">
                                    <div>
                                        <label for="train_origin_crs" class="block text-xs font-medium text-gray-600 mb-1">Origin CRS <span class="text-red-500">*</span></label>
                                        <input type="text" id="train_origin_crs" name="train_origin_crs" value="<?= e($trainOriginCrs) ?>" required placeholder="PAD" maxlength="3" pattern="[A-Z]{3}" style="text-transform: uppercase;"
                                            class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                        <p class="mt-1 text-xs text-gray-400 min-h-[1.25rem]">3 letters</p>
                                    </div>
                                    <div>
                                        <label for="train_origin_name" class="block text-xs font-medium text-gray-600 mb-1">Origin Name</label>
                                        <input type="text" id="train_origin_name" name="train_origin_name" value="<?= e($trainOriginName) ?>" placeholder="London Paddington" maxlength="50"
                                            class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                        <p class="mt-1 text-xs text-gray-400 min-h-[1.25rem]">&nbsp;</p>
                                    </div>
                                    <div>
                                        <label for="train_destination_crs" class="block text-xs font-medium text-gray-600 mb-1">Destination CRS <span class="text-red-500">*</span></label>
                                        <input type="text" id="train_destination_crs" name="train_destination_crs" value="<?= e($trainDestinationCrs) ?>" required placeholder="BRI" maxlength="3" pattern="[A-Z]{3}" style="text-transform: uppercase;"
                                            class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                        <p class="mt-1 text-xs text-gray-400 min-h-[1.25rem]">3 letters</p>
                                    </div>
                                    <div>
                                        <label for="train_destination_name" class="block text-xs font-medium text-gray-600 mb-1">Destination Name</label>
                                        <input type="text" id="train_destination_name" name="train_destination_name" value="<?= e($trainDestinationName) ?>" placeholder="Bristol Temple Meads" maxlength="50"
                                            class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                        <p class="mt-1 text-xs text-gray-400 min-h-[1.25rem]">&nbsp;</p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-end gap-4">
                                    <div>
                                        <label for="train_num_departures" class="block text-xs font-medium text-gray-600 mb-1">Number to show</label>
                                        <input type="number" id="train_num_departures" name="train_num_departures" value="<?= $trainNumDepartures ?>" min="1" max="10"
                                            class="block w-20 rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                    </div>
                                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                        <?= $trainTileId ? 'Update tile' : 'Add Tile' ?>
                                    </button>
                                </div>
                                </form>
                                <p class="mt-2 text-xs text-gray-500">
                                    <strong>Common station codes:</strong> PAD (London Paddington), BRI (Bristol Temple Meads), MAN (Manchester Piccadilly), EUS (London Euston), KGX (London King's Cross), VIC (London Victoria), BHM (Birmingham New Street), LDS (Leeds), LIV (Liverpool Lime Street), EDI (Edinburgh Waverley)
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Flagged email reminder tile -->
                    <?php
                    $hasFlaggedEmailTile = Database::queryOne(
                        'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                        [$userId, 'flagged-email']
                    );
                    ?>
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/>
                                    <line x1="4" y1="22" x2="4" y2="15" stroke-width="2"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Flagged email reminder</h3>
                                <p class="text-sm text-gray-500 mt-1">Shows a random flagged email from your Microsoft inbox as a reminder to respond or unflag it. Refreshing the tile picks another. Connect Microsoft 365 first.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php if ($hasFlaggedEmailTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_flagged_email_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Flagged email count tile -->
                    <?php
                    $hasFlaggedEmailCountTile = Database::queryOne(
                        'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                        [$userId, 'flagged-email-count']
                    );
                    ?>
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/>
                                    <line x1="4" y1="22" x2="4" y2="15" stroke-width="2"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Flagged email count</h3>
                                <p class="text-sm text-gray-500 mt-1">Displays the total number of flagged emails in your Microsoft inbox as a large centered number. Connect Microsoft 365 first.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php if ($hasFlaggedEmailCountTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_flagged_email_count_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Overdue tasks count tile -->
                    <?php
                    $hasOverdueTasksCountTile = Database::queryOne(
                        'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                        [$userId, 'overdue-tasks-count']
                    );
                    ?>
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Overdue tasks count</h3>
                                <p class="text-sm text-gray-500 mt-1">Displays the total number of incomplete Microsoft To Do tasks that are overdue (due date in the past), as a large centered number. Same style as the flagged email count tile. Connect Microsoft 365 first.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php if ($hasOverdueTasksCountTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_overdue_tasks_count_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Personal Tasks tile -->
                    <?php
                    $hasTodoPersonalTile = Database::queryOne(
                        'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                        [$userId, 'todo-personal']
                    );
                    ?>
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Personal Tasks</h3>
                                <p class="text-sm text-gray-500 mt-1">Shows all incomplete tasks from your personal Microsoft To Do list. Separate from Planner tasks - this tile always shows your To Do tasks regardless of the Tasks tile configuration. Connect Microsoft 365 first.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php if ($hasTodoPersonalTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_todo_personal_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Planner overview -->
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="flex items-start flex-1 min-w-0">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                </svg>
                            </div>
                            <div class="ml-4 min-w-0">
                                <h3 class="text-sm font-medium text-gray-900">Planner overview</h3>
                                <p class="text-sm text-gray-500 mt-1">Large tile showing all outstanding Microsoft Planner tasks assigned to you, grouped by plan in columns. Connect Microsoft 365 first.</p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 w-28 text-right">
                        <?php
                        $hasPlannerOverviewTile = Database::queryOne(
                            'SELECT id FROM tiles WHERE user_id = ? AND tile_type = ? AND is_enabled = TRUE',
                            [$userId, 'planner-overview']
                        );
                        ?>
                        <?php if ($hasPlannerOverviewTile): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Added
                        </span>
                        <?php else: ?>
                        <form action="" method="POST" class="inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="add_planner_overview_tile">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500">
                                Add Tile
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Tile Refresh Rates -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Tile Refresh Rates</h2>
                <p class="mt-1 text-sm text-gray-500">Configure how often each tile automatically refreshes its data. Values are in seconds (minimum 30, maximum 3600).</p>
            </div>

            <div class="p-6">
                <?php if (empty($userTiles)): ?>
                <p class="text-sm text-gray-500">No tiles configured. Add tiles from the Tiles Management section above.</p>
                <?php else: ?>
                <form action="" method="POST" class="space-y-4">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="save_tile_refresh_rates">
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tile</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Refresh Interval</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Preview</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($userTiles as $tile): ?>
                                <?php
                                $tileType = $tile['tile_type'];
                                $tileTitle = $tile['title'] ?? ucfirst(str_replace(['-', '_'], ' ', $tileType));
                                $currentInterval = getTileRefreshInterval($tile);
                                
                                // Skip tiles that don't auto-refresh
                                if (in_array($tileType, ['claude', 'notes', 'notes-list', 'bookmarks', 'link-board'])) {
                                    continue;
                                }
                                
                                // Format preview
                                $preview = '';
                                if ($currentInterval < 60) {
                                    $preview = $currentInterval . ' seconds';
                                } elseif ($currentInterval < 3600) {
                                    $mins = floor($currentInterval / 60);
                                    $secs = $currentInterval % 60;
                                    $preview = $mins . 'm' . ($secs > 0 ? ' ' . $secs . 's' : '');
                                } else {
                                    $hours = floor($currentInterval / 3600);
                                    $mins = floor(($currentInterval % 3600) / 60);
                                    $preview = $hours . 'h' . ($mins > 0 ? ' ' . $mins . 'm' : '');
                                }
                                ?>
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= e($tileTitle) ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        <?= e($tileType) ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex items-center space-x-2">
                                            <input
                                                type="number"
                                                name="tile_refresh_rates[<?= $tile['id'] ?>]"
                                                value="<?= $currentInterval ?>"
                                                min="30"
                                                max="3600"
                                                step="30"
                                                class="w-24 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                            >
                                            <span class="text-sm text-gray-500">seconds</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        <?= e($preview) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $refreshableTiles = array_filter($userTiles, function($tile) {
                        return !in_array($tile['tile_type'], ['claude', 'notes', 'notes-list', 'bookmarks', 'link-board']);
                    });
                    ?>
                    <?php if (!empty($refreshableTiles)): ?>
                    <div class="flex items-center justify-end pt-4 border-t border-gray-200">
                        <button
                            type="submit"
                            class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                        >
                            Save Refresh Rates
                        </button>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-500 pt-4">No tiles support auto-refresh. Tiles like Claude AI, Notes, and Bookmarks refresh manually.</p>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
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
                        'calendar_heatmap_v2' => ['name' => 'Calendar heat map', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'color' => 'teal'],
                        'availability' => ['name' => 'Availability', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'color' => 'indigo'],
                        'todo' => ['name' => 'Tasks', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'color' => 'purple'],
                        'todo-personal' => ['name' => 'Personal Tasks', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'color' => 'blue'],
                        'crm' => ['name' => 'CRM Actions', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'color' => 'orange'],
                        'weather' => ['name' => 'Weather', 'icon' => 'M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z', 'color' => 'sky'],
                        'flagged_email' => ['name' => 'Flagged email', 'icon' => 'M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z', 'color' => 'amber'],
                        'flagged_email_count' => ['name' => 'Flagged email count', 'icon' => 'M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z', 'color' => 'amber'],
                        'overdue_tasks_count' => ['name' => 'Overdue tasks count', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'color' => 'amber'],
                        'planner_overview' => ['name' => 'Planner overview', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01', 'color' => 'purple'],
                        'train_departures' => ['name' => 'Train Departures', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4', 'color' => 'red'],
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

        <!-- Tile refresh (Cron) -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Tile refresh (Cron)</h2>
                <p class="mt-1 text-sm text-gray-500">Run a scheduled job to refresh tile data so the dashboard loads faster. Note tiles and the AI assistant (suggestions) are excluded and load only on page load or manual refresh.</p>
            </div>
            <div class="p-6">
                <?php
                $cronSecret = config('cron.secret', '');
                $cronInterval = (int) config('cron.interval_minutes', 5);
                $cronUrl = baseUrl('api/cron-refresh.php');
                ?>
                <?php if ($cronSecret === ''): ?>
                <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 mb-4">
                    <p class="text-sm text-amber-800">Set <code class="bg-amber-100 px-1 rounded">cron.secret</code> in <code class="bg-amber-100 px-1 rounded">config/config.php</code> to enable cron refresh. Generate one with: <code class="bg-amber-100 px-1 rounded">php -r "echo bin2hex(random_bytes(16));"</code></p>
                </div>
                <?php endif; ?>
                <div class="space-y-3 text-sm">
                    <p class="font-medium text-gray-700">Cron URL (refreshes all users)</p>
                    <p class="font-mono text-gray-800 break-all bg-gray-50 p-3 rounded-lg border border-gray-200"><?= e($cronUrl) ?>?token=<?= $cronSecret !== '' ? 'YOUR_SECRET' : '…' ?></p>
                    <p class="text-gray-600">Pass the secret via query <code>token</code> or header <code>X-Cron-Token</code>. Keep the secret private.</p>
                    <p class="font-medium text-gray-700 mt-4">Example (run every <?= $cronInterval ?> minutes)</p>
                    <pre class="text-xs bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto">*/<?= $cronInterval ?> * * * * curl -s "<?= e($cronUrl) ?>?token=YOUR_SECRET"</pre>
                    <p class="text-gray-500">Use the same base URL as your app (e.g. <code>https://your-domain.com</code>). If the cron runs on another host, set <code>cron.base_url</code> in config.</p>
                </div>
            </div>
        </section>

        <!-- Appearance & Theme -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Appearance & Theme</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Customize your dashboard's appearance with personalized colors and typography.
                </p>
            </div>

            <div class="p-6">
                <form action="" method="POST" class="space-y-6 max-w-xl">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="save_theme">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label for="theme_primary" class="block text-sm font-medium text-gray-700">
                                Primary colour
                            </label>
                            <div class="mt-2 flex items-center space-x-3">
                                <input
                                    type="color"
                                    id="theme_primary"
                                    name="theme_primary"
                                    value="<?= e($themePreview['primary']) ?>"
                                    class="h-9 w-9 border border-gray-300 rounded cursor-pointer"
                                >
                                <input
                                    type="text"
                                    value="<?= e($themePreview['primary']) ?>"
                                    class="mt-0.5 flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white"
                                    oninput="document.getElementById('theme_primary').value = this.value"
                                >
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Used for buttons, highlights, and accents.
                            </p>
                        </div>

                        <div>
                            <label for="theme_secondary" class="block text-sm font-medium text-gray-700">
                                Secondary colour
                            </label>
                            <div class="mt-2 flex items-center space-x-3">
                                <input
                                    type="color"
                                    id="theme_secondary"
                                    name="theme_secondary"
                                    value="<?= e($themePreview['secondary']) ?>"
                                    class="h-9 w-9 border border-gray-300 rounded cursor-pointer"
                                >
                                <input
                                    type="text"
                                    value="<?= e($themePreview['secondary']) ?>"
                                    class="mt-0.5 flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white"
                                    oninput="document.getElementById('theme_secondary').value = this.value"
                                >
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Used for secondary buttons and badges.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label for="theme_background" class="block text-sm font-medium text-gray-700">
                                Background colour
                            </label>
                            <div class="mt-2 flex items-center space-x-3">
                                <input
                                    type="color"
                                    id="theme_background"
                                    name="theme_background"
                                    value="<?= e($themePreview['background']) ?>"
                                    class="h-9 w-9 border border-gray-300 rounded cursor-pointer"
                                >
                                <input
                                    type="text"
                                    value="<?= e($themePreview['background']) ?>"
                                    class="mt-0.5 flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white"
                                    oninput="document.getElementById('theme_background').value = this.value"
                                >
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Overall page background tone.
                            </p>
                        </div>

                        <div>
                            <label for="theme_font" class="block text-sm font-medium text-gray-700">
                                Font family
                            </label>
                            <select
                                id="theme_font"
                                name="theme_font"
                                class="mt-2 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                            >
                                <option value="system" <?= $themePreview['font'] === 'system' ? 'selected' : '' ?>>
                                    System Default (San-serif)
                                </option>
                                <option value="inter" <?= $themePreview['font'] === 'inter' ? 'selected' : '' ?>>
                                    Inter (Modern Sans-serif)
                                </option>
                                <option value="roboto" <?= $themePreview['font'] === 'roboto' ? 'selected' : '' ?>>
                                    Roboto (Clean & Readable)
                                </option>
                                <option value="open-sans" <?= $themePreview['font'] === 'open-sans' ? 'selected' : '' ?>>
                                    Open Sans (Friendly)
                                </option>
                                <option value="lato" <?= $themePreview['font'] === 'lato' ? 'selected' : '' ?>>
                                    Lato (Warm & Professional)
                                </option>
                                <option value="montserrat" <?= $themePreview['font'] === 'montserrat' ? 'selected' : '' ?>>
                                    Montserrat (Geometric)
                                </option>
                                <option value="raleway" <?= $themePreview['font'] === 'raleway' ? 'selected' : '' ?>>
                                    Raleway (Elegant)
                                </option>
                                <option value="playfair" <?= $themePreview['font'] === 'playfair' ? 'selected' : '' ?>>
                                    Playfair Display (Classic Serif)
                                </option>
                                <option value="serif" <?= $themePreview['font'] === 'serif' ? 'selected' : '' ?>>
                                    System Serif (Georgia)
                                </option>
                                <option value="mono" <?= $themePreview['font'] === 'mono' ? 'selected' : '' ?>>
                                    Monospace (Code-style)
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Controls typography across the entire dashboard.
                            </p>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Header Styling</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div>
                                <label for="theme_header_bg" class="block text-sm font-medium text-gray-700">
                                    Header background
                                </label>
                                <div class="mt-2 flex items-center space-x-3">
                                    <input
                                        type="color"
                                        id="theme_header_bg"
                                        name="theme_header_bg"
                                        value="<?= e($themePreview['header_bg']) ?>"
                                        class="h-9 w-9 border border-gray-300 rounded cursor-pointer"
                                    >
                                    <input
                                        type="text"
                                        value="<?= e($themePreview['header_bg']) ?>"
                                        class="mt-0.5 flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white"
                                        oninput="document.getElementById('theme_header_bg').value = this.value"
                                    >
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    Top navigation bar background color.
                                </p>
                            </div>

                            <div>
                                <label for="theme_header_text" class="block text-sm font-medium text-gray-700">
                                    Header text
                                </label>
                                <div class="mt-2 flex items-center space-x-3">
                                    <input
                                        type="color"
                                        id="theme_header_text"
                                        name="theme_header_text"
                                        value="<?= e($themePreview['header_text']) ?>"
                                        class="h-9 w-9 border border-gray-300 rounded cursor-pointer"
                                    >
                                    <input
                                        type="text"
                                        value="<?= e($themePreview['header_text']) ?>"
                                        class="mt-0.5 flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white"
                                        oninput="document.getElementById('theme_header_text').value = this.value"
                                    >
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    Text color in the header navigation.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Tile Styling</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div>
                                <label for="theme_tile_bg" class="block text-sm font-medium text-gray-700">
                                    Tile background
                                </label>
                                <div class="mt-2 flex items-center space-x-3">
                                    <input
                                        type="color"
                                        id="theme_tile_bg"
                                        name="theme_tile_bg"
                                        value="<?= e($themePreview['tile_bg']) ?>"
                                        class="h-9 w-9 border border-gray-300 rounded cursor-pointer"
                                    >
                                    <input
                                        type="text"
                                        value="<?= e($themePreview['tile_bg']) ?>"
                                        class="mt-0.5 flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white"
                                        oninput="document.getElementById('theme_tile_bg').value = this.value"
                                    >
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    Background color for dashboard tiles.
                                </p>
                            </div>

                            <div>
                                <label for="theme_tile_text" class="block text-sm font-medium text-gray-700">
                                    Tile text
                                </label>
                                <div class="mt-2 flex items-center space-x-3">
                                    <input
                                        type="color"
                                        id="theme_tile_text"
                                        name="theme_tile_text"
                                        value="<?= e($themePreview['tile_text']) ?>"
                                        class="h-9 w-9 border border-gray-300 rounded cursor-pointer"
                                    >
                                    <input
                                        type="text"
                                        value="<?= e($themePreview['tile_text']) ?>"
                                        class="mt-0.5 flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white"
                                        oninput="document.getElementById('theme_tile_text').value = this.value"
                                    >
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    Default text color within tiles.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-medium text-gray-900 mb-3">Dashboard screen names</h3>
                        <p class="text-xs text-gray-500 mb-4">Customise the labels for your dashboard tabs (e.g. "Home" and "Work").</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="dashboard_screen1_label" class="block text-sm font-medium text-gray-700">First screen</label>
                                <input
                                    type="text"
                                    id="dashboard_screen1_label"
                                    name="dashboard_screen1_label"
                                    maxlength="50"
                                    value="<?= e($userTheme['dashboard_screen1_label'] ?? 'Main') ?>"
                                    placeholder="Main"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                >
                            </div>
                            <div>
                                <label for="dashboard_screen2_label" class="block text-sm font-medium text-gray-700">Second screen</label>
                                <input
                                    type="text"
                                    id="dashboard_screen2_label"
                                    name="dashboard_screen2_label"
                                    maxlength="50"
                                    value="<?= e($userTheme['dashboard_screen2_label'] ?? 'Screen 2') ?>"
                                    placeholder="Screen 2"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                >
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <p class="text-xs text-gray-500">
                            Your theme and dashboard screen names are saved and applied across the dashboard.
                        </p>
                        <button
                            type="submit"
                            class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
                        >
                            Save Theme
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Planner overview limits -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Planner overview</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Control how many plans and tasks are shown in the Planner overview tile. The large number at the top shows your total incomplete tasks; the columns below show up to these limits.
                </p>
            </div>
            <div class="p-6">
                <?php
                $plannerMaxPlans = $userTheme['planner_overview_max_plans'] ?? config('planner_overview.max_plans', 10);
                $plannerMaxTasksPerPlan = $userTheme['planner_overview_max_tasks_per_plan'] ?? config('planner_overview.max_tasks_per_plan', 15);
                $plannerMaxPlans = max(1, min(50, (int) $plannerMaxPlans));
                $plannerMaxTasksPerPlan = max(1, min(100, (int) $plannerMaxTasksPerPlan));
                ?>
                <form action="" method="POST" class="space-y-4 max-w-xl">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="save_planner_overview_limits">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label for="planner_overview_max_plans" class="block text-sm font-medium text-gray-700">Max plan columns</label>
                            <input type="number" id="planner_overview_max_plans" name="planner_overview_max_plans" min="1" max="50" value="<?= (int) $plannerMaxPlans ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                            <p class="mt-1 text-xs text-gray-500">Number of plans (columns) to show (1–50).</p>
                        </div>
                        <div>
                            <label for="planner_overview_max_tasks_per_plan" class="block text-sm font-medium text-gray-700">Max tasks per plan</label>
                            <input type="number" id="planner_overview_max_tasks_per_plan" name="planner_overview_max_tasks_per_plan" min="1" max="100" value="<?= (int) $plannerMaxTasksPerPlan ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                            <p class="mt-1 text-xs text-gray-500">Tasks per column (1–100).</p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between pt-2">
                        <p class="text-xs text-gray-500">Clearing the planner cache after save may be needed to see changes immediately.</p>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">Save limits</button>
                    </div>
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
    <script>
        // Sync color inputs with text inputs
        document.addEventListener('DOMContentLoaded', function() {
            const colorInputs = ['theme_primary', 'theme_secondary', 'theme_background', 'theme_header_bg', 'theme_header_text', 'theme_tile_bg', 'theme_tile_text'];
            colorInputs.forEach(id => {
                const colorPicker = document.getElementById(id);
                const textInput = colorPicker?.nextElementSibling;
                if (colorPicker && textInput) {
                    colorPicker.addEventListener('input', function() {
                        textInput.value = this.value;
                    });
                    textInput.addEventListener('input', function() {
                        if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                            colorPicker.value = this.value;
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
