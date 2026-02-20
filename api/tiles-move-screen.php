<?php
/**
 * Tiles Move Screen API Endpoint
 *
 * Moves a tile to another dashboard screen (main or screen2).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!Auth::check()) {
    jsonError('Unauthorized', 401);
}

if (!isAjax()) {
    jsonError('Invalid request', 400);
}

if (!Auth::verifyCsrf()) {
    jsonError('Invalid security token', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$tileId = isset($input['tile_id']) ? (int) $input['tile_id'] : 0;
$screen = isset($input['screen']) ? trim((string) $input['screen']) : '';

if ($tileId <= 0) {
    jsonError('Invalid tile ID', 400);
}

$allowed = ['main', 'screen2'];
if (!in_array($screen, $allowed, true)) {
    jsonError('Invalid screen. Use "main" or "screen2".', 400);
}

$userId = Auth::id();

try {
    $row = Database::queryOne(
        'SELECT id, screen FROM tiles WHERE id = ? AND user_id = ?',
        [$tileId, $userId]
    );
    if (!$row) {
        jsonError('Tile not found', 404);
    }

    if ($row['screen'] === $screen) {
        jsonResponse(['success' => true, 'message' => 'Tile already on this screen', 'position' => null]);
    }

    Database::beginTransaction();

    $maxPos = Database::queryOne(
        $screen === 'main'
            ? "SELECT COALESCE(MAX(position), 0) AS max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE AND (screen IS NULL OR screen = 'main')"
            : "SELECT COALESCE(MAX(position), 0) AS max_pos FROM tiles WHERE user_id = ? AND is_enabled = TRUE AND screen = 'screen2'",
        [$userId]
    );
    $newPosition = (int) ($maxPos['max_pos'] ?? 0) + 1;

    Database::execute(
        'UPDATE tiles SET screen = ?, position = ? WHERE id = ? AND user_id = ?',
        [$screen, $newPosition, $tileId, $userId]
    );

    Database::commit();

    jsonResponse(['success' => true, 'message' => 'Tile moved', 'position' => $newPosition]);
} catch (Exception $e) {
    Database::rollback();
    logMessage('Tile move screen error: ' . $e->getMessage(), 'error');
    jsonError('Failed to move tile', 500);
}
