<?php
/**
 * Tiles Resize API Endpoint
 *
 * Saves the new size (column_span and row_span) of tiles for the current user.
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
$tiles = $input['tiles'] ?? [];

if (empty($tiles) || !is_array($tiles)) {
    jsonError('Invalid tile data', 400);
}

$userId = Auth::id();

try {
    // Start transaction
    Database::beginTransaction();

    foreach ($tiles as $tile) {
        $tileId = (int)($tile['id'] ?? 0);
        $columnSpan = (int)($tile['column_span'] ?? 1);
        $rowSpan = (int)($tile['row_span'] ?? 1);

        // Validate spans (1-4 columns, 1-4 rows)
        $columnSpan = max(1, min(5, $columnSpan));
        $rowSpan = max(1, min(5, $rowSpan));

        if ($tileId <= 0) {
            continue;
        }

        // Update only tiles belonging to this user
        Database::execute(
            'UPDATE tiles SET column_span = ?, row_span = ? WHERE id = ? AND user_id = ?',
            [$columnSpan, $rowSpan, $tileId, $userId]
        );
    }

    Database::commit();

    jsonResponse(['success' => true, 'message' => 'Tile sizes saved']);
} catch (Exception $e) {
    Database::rollback();
    logMessage('Tile resize error: ' . $e->getMessage(), 'error');
    jsonError('Failed to save tile sizes', 500);
}
