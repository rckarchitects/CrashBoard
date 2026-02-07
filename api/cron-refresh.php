<?php
/**
 * Cron: refresh tile caches for all users
 *
 * Call this URL on a schedule (e.g. every 5 minutes) so tile data is pre-loaded
 * and the dashboard does not load everything on login. Note tiles are excluded.
 *
 * Authentication: pass cron secret via ?token=... or header X-Cron-Token.
 * No session or user login required.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$secret = config('cron.secret', '');
if ($secret === '') {
    header('Content-Type: application/json');
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Cron refresh is disabled. Set cron.secret in config.']);
    exit;
}

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if ($token === '' || !hash_equals($secret, $token)) {
    header('Content-Type: application/json');
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Invalid or missing cron token.']);
    exit;
}

$baseUrl = rtrim(config('cron.base_url', '') ?: (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');
$tilesUrl = $baseUrl . '/api/tiles.php';
$refreshed = [];
$errors = [];

$users = Database::query('SELECT id FROM users');
foreach ($users as $row) {
    $uid = (int) $row['id'];
    $url = $tilesUrl . '?token=' . urlencode($secret) . '&user_id=' . $uid;
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result !== false) {
        $refreshed[] = $uid;
    } else {
        $errors[] = ['user_id' => $uid];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'refreshed' => $refreshed,
    'count' => count($refreshed),
    'errors' => $errors,
]);
