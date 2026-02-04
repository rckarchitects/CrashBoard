<?php
/**
 * Tiles Reorder API Endpoint
 *
 * Saves the new order of tiles for the current user.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require authentication
if (!Auth::check()) {
    jsonError('Unauthorized', 401);
}

// Require AJAX request
if (!isAjax()) {
    jsonError('Invalid request', 400);
}

// Verify CSRF
if (!Auth::verifyCsrf()) {
    jsonError('Invalid security token', 403);
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$order = $input['order'] ?? [];

if (empty($order) || !is_array($order)) {
    jsonError('Invalid order data', 400);
}

$userId = Auth::id();

try {
    // Start transaction
    Database::beginTransaction();

    foreach ($order as $item) {
        $tileId = (int)($item['id'] ?? 0);
        $position = (int)($item['position'] ?? 0);

        if ($tileId <= 0) {
            continue;
        }

        // Update only tiles belonging to this user
        Database::execute(
            'UPDATE tiles SET position = ? WHERE id = ? AND user_id = ?',
            [$position, $tileId, $userId]
        );
    }

    Database::commit();

    jsonResponse(['success' => true, 'message' => 'Tile order saved']);
} catch (Exception $e) {
    Database::rollback();
    logMessage('Tile reorder error: ' . $e->getMessage(), 'error');
    jsonError('Failed to save tile order', 500);
}
