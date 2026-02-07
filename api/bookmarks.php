<?php
/**
 * Bookmarks API Endpoint
 *
 * Add and delete bookmarks for the bookmarks tile.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

Session::init();

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
$action = $input['action'] ?? '';
$userId = Auth::id();

// Ensure bookmarks table exists
try {
    $tableExists = Database::queryOne("SHOW TABLES LIKE 'bookmarks'");
    if (empty($tableExists)) {
        $fkCheck = Database::queryOne("SELECT @@foreign_key_checks");
        $fkEnabled = ($fkCheck['@@foreign_key_checks'] ?? 1) == 1;
        $sql = "CREATE TABLE bookmarks (
            id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            url VARCHAR(2048) NOT NULL,
            title VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            " . ($fkEnabled ? "FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE," : "") . "
            INDEX idx_user_created (user_id, created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::execute($sql);
    }
} catch (Exception $e) {
    error_log('Bookmarks table migration: ' . $e->getMessage());
}

switch ($action) {
    case 'add':
        $url = trim($input['url'] ?? '');
        $title = trim($input['title'] ?? '');

        if ($url === '') {
            jsonError('URL is required', 400);
        }

        // Basic URL validation
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            jsonError('Invalid URL', 400);
        }

        $url = mb_substr($url, 0, 2048);
        $title = $title !== '' ? mb_substr($title, 0, 255) : null;

        try {
            Database::execute(
                'INSERT INTO bookmarks (user_id, url, title) VALUES (?, ?, ?)',
                [$userId, $url, $title]
            );
            jsonResponse([
                'success' => true,
                'message' => 'Bookmark added',
                'id' => (int) Database::lastInsertId(),
                'url' => $url,
                'title' => $title
            ]);
        } catch (Exception $e) {
            logMessage('Add bookmark error: ' . $e->getMessage(), 'error');
            jsonError('Failed to add bookmark', 500);
        }
        break;

    case 'delete':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid bookmark ID', 400);
        }

        try {
            $deleted = Database::execute(
                'DELETE FROM bookmarks WHERE id = ? AND user_id = ?',
                [$id, $userId]
            );
            if ($deleted === 0) {
                jsonError('Bookmark not found', 404);
            }
            jsonResponse(['success' => true, 'message' => 'Bookmark deleted']);
        } catch (Exception $e) {
            logMessage('Delete bookmark error: ' . $e->getMessage(), 'error');
            jsonError('Failed to delete bookmark', 500);
        }
        break;

    default:
        jsonError('Invalid action', 400);
}
