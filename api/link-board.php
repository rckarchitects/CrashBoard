<?php
/**
 * Link Board API
 *
 * Manages categories and link items for the link-board tile (kanban-style URL board
 * with categories and optional AI-generated summaries).
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

$userId = Auth::id();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

ensureLinkBoardTables();

/**
 * Ensure link_board_categories and link_board_items tables exist
 */
function ensureLinkBoardTables(): void
{
    global $userId;
    try {
        $t = Database::queryOne("SHOW TABLES LIKE 'link_board_categories'");
        if (empty($t)) {
            $fk = Database::queryOne("SELECT @@foreign_key_checks");
            $fkOn = (int)($fk['@@foreign_key_checks'] ?? 1) === 1;
            Database::execute("
                CREATE TABLE link_board_categories (
                    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    position INT UNSIGNED NOT NULL DEFAULT 0,
                    " . ($fkOn ? "FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE," : "") . "
                    INDEX idx_user_pos (user_id, position)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        $t2 = Database::queryOne("SHOW TABLES LIKE 'link_board_items'");
        if (empty($t2)) {
            $fk = Database::queryOne("SELECT @@foreign_key_checks");
            $fkOn = (int)($fk['@@foreign_key_checks'] ?? 1) === 1;
            Database::execute("
                CREATE TABLE link_board_items (
                    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    category_id INT UNSIGNED NOT NULL,
                    url VARCHAR(2048) NOT NULL,
                    title VARCHAR(255) NULL,
                    summary TEXT NULL,
                    position INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    " . ($fkOn ? "FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (category_id) REFERENCES link_board_categories(id) ON DELETE CASCADE," : "") . "
                    INDEX idx_category_pos (category_id, position),
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (Exception $e) {
        error_log('Link board tables: ' . $e->getMessage());
        jsonError('Database setup failed', 500);
    }
}

/**
 * Fetch URL and extract readable text (title + body text) for summarization
 */
function fetchPageContentForSummary(string $url): string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'follow_location' => 1,
            'user_agent' => 'Mozilla/5.0 (compatible; CrashBoard/1.0)',
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false || strlen($html) < 50) {
        return '';
    }
    $html = substr($html, 0, 120000); // ~120k chars max for API
    $title = '';
    if (preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = mb_substr($title, 0, 300);
    }
    // Remove script/style and get body text
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
        $html = $m[1];
    }
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    $text = mb_substr($text, 0, 8000);
    $combined = $title !== '' ? "Title: $title\n\n" : '';
    $combined .= $text;
    return $combined;
}

/**
 * Call Claude to summarize page content (1-2 sentences)
 */
function summarizeWithClaude(string $pageContent): string
{
    $claudeConfig = config('claude');
    if (empty($claudeConfig['api_key'])) {
        return '';
    }
    if (trim($pageContent) === '') {
        return '';
    }
    $systemPrompt = 'You are a summarizer. Given web page content (title and body text), reply with only a brief summary in 1-2 clear sentences. No preamble or labels.';
    $payload = [
        'model' => $claudeConfig['model'] ?? 'claude-sonnet-4-20250514',
        'max_tokens' => 150,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => "Summarize this page:\n\n" . $pageContent]
        ],
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'x-api-key: ' . $claudeConfig['api_key'],
                'anthropic-version: 2023-06-01',
            ]),
            'content' => json_encode($payload),
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents('https://api.anthropic.com/v1/messages', false, $context);
    if (!$response) {
        return '';
    }
    $data = json_decode($response, true);
    if (empty($data['content'][0]['text'])) {
        return '';
    }
    $summary = trim($data['content'][0]['text']);
    return mb_substr($summary, 0, 1000);
}

// --- Actions ---

if ($action === 'list' || $action === '') {
    if ($method !== 'GET' && $method !== 'POST') {
        jsonError('Method not allowed', 405);
    }
    $categories = Database::query(
        'SELECT id, name, position FROM link_board_categories WHERE user_id = ? ORDER BY position ASC, id ASC',
        [$userId]
    );
    $items = Database::query(
        'SELECT id, category_id, url, title, summary, position FROM link_board_items WHERE user_id = ? ORDER BY category_id ASC, position ASC, id ASC',
        [$userId]
    );
    jsonResponse([
        'categories' => array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'position' => (int) $r['position'],
            ];
        }, $categories),
        'items' => array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'category_id' => (int) $r['category_id'],
                'url' => $r['url'],
                'title' => $r['title'],
                'summary' => $r['summary'],
                'position' => (int) $r['position'],
            ];
        }, $items),
    ]);
}

if ($method !== 'POST') {
    jsonError('Method not allowed', 405);
}

switch ($action) {
    case 'add_category':
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            jsonError('Category name is required', 400);
        }
        $name = mb_substr($name, 0, 100);
        $maxPos = Database::queryOne('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM link_board_categories WHERE user_id = ?', [$userId]);
        $pos = (int) ($maxPos['next_pos'] ?? 0);
        Database::execute('INSERT INTO link_board_categories (user_id, name, position) VALUES (?, ?, ?)', [$userId, $name, $pos]);
        jsonResponse(['success' => true, 'id' => (int) Database::lastInsertId(), 'name' => $name, 'position' => $pos]);
        break;

    case 'update_category':
        $id = (int) ($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if ($id <= 0 || $name === '') {
            jsonError('Invalid category or name', 400);
        }
        $name = mb_substr($name, 0, 100);
        $n = Database::execute('UPDATE link_board_categories SET name = ? WHERE id = ? AND user_id = ?', [$name, $id, $userId]);
        if ($n === 0) {
            jsonError('Category not found', 404);
        }
        jsonResponse(['success' => true]);
        break;

    case 'delete_category':
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid category', 400);
        }
        // Move items to first other category or delete if only one category
        $others = Database::query('SELECT id FROM link_board_categories WHERE user_id = ? AND id != ? ORDER BY position ASC', [$userId, $id]);
        if (count($others) > 0) {
            $targetId = (int) $others[0]['id'];
            Database::execute('UPDATE link_board_items SET category_id = ? WHERE category_id = ? AND user_id = ?', [$targetId, $id, $userId]);
        }
        $n = Database::execute('DELETE FROM link_board_categories WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($n === 0) {
            jsonError('Category not found', 404);
        }
        jsonResponse(['success' => true]);
        break;

    case 'add_item':
        $categoryId = (int) ($input['category_id'] ?? 0);
        $url = trim($input['url'] ?? '');
        $title = trim($input['title'] ?? '');
        if ($categoryId <= 0 || $url === '') {
            jsonError('Category and URL are required', 400);
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            jsonError('Invalid URL', 400);
        }
        $url = mb_substr($url, 0, 2048);
        $cat = Database::queryOne('SELECT id FROM link_board_categories WHERE id = ? AND user_id = ?', [$categoryId, $userId]);
        if (!$cat) {
            jsonError('Category not found', 404);
        }
        if ($title === '') {
            $title = fetchPageTitle($url);
        }
        $title = $title !== null && $title !== '' ? mb_substr($title, 0, 255) : null;
        $maxPos = Database::queryOne('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM link_board_items WHERE category_id = ?', [$categoryId]);
        $pos = (int) ($maxPos['next_pos'] ?? 0);
        Database::execute(
            'INSERT INTO link_board_items (user_id, category_id, url, title, summary, position) VALUES (?, ?, ?, ?, NULL, ?)',
            [$userId, $categoryId, $url, $title, $pos]
        );
        jsonResponse([
            'success' => true,
            'id' => (int) Database::lastInsertId(),
            'category_id' => $categoryId,
            'url' => $url,
            'title' => $title,
            'summary' => null,
            'position' => $pos,
        ]);
        break;

    case 'update_item':
        $id = (int) ($input['id'] ?? 0);
        $url = trim($input['url'] ?? '');
        $title = trim($input['title'] ?? '');
        $categoryId = isset($input['category_id']) ? (int) $input['category_id'] : null;
        if ($id <= 0) {
            jsonError('Invalid item', 400);
        }
        $row = Database::queryOne('SELECT id, category_id, url, title FROM link_board_items WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            jsonError('Item not found', 404);
        }
        if ($url !== '') {
            if (!preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                jsonError('Invalid URL', 400);
            }
            $url = mb_substr($url, 0, 2048);
        } else {
            $url = $row['url'];
        }
        $title = $title !== '' ? mb_substr(trim($title), 0, 255) : $row['title'];
        if ($categoryId !== null && $categoryId > 0) {
            $cat = Database::queryOne('SELECT id FROM link_board_categories WHERE id = ? AND user_id = ?', [$categoryId, $userId]);
            if (!$cat) {
                jsonError('Category not found', 404);
            }
            Database::execute('UPDATE link_board_items SET url = ?, title = ?, category_id = ? WHERE id = ? AND user_id = ?', [$url, $title, $categoryId, $id, $userId]);
        } else {
            Database::execute('UPDATE link_board_items SET url = ?, title = ? WHERE id = ? AND user_id = ?', [$url, $title, $id, $userId]);
        }
        jsonResponse(['success' => true]);
        break;

    case 'delete_item':
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid item', 400);
        }
        $n = Database::execute('DELETE FROM link_board_items WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($n === 0) {
            jsonError('Item not found', 404);
        }
        jsonResponse(['success' => true]);
        break;

    case 'move_item':
        $id = (int) ($input['id'] ?? 0);
        $categoryId = (int) ($input['category_id'] ?? 0);
        $position = (int) ($input['position'] ?? 0);
        if ($id <= 0 || $categoryId <= 0) {
            jsonError('Invalid item or category', 400);
        }
        $cat = Database::queryOne('SELECT id FROM link_board_categories WHERE id = ? AND user_id = ?', [$categoryId, $userId]);
        if (!$cat) {
            jsonError('Category not found', 404);
        }
        $n = Database::execute('UPDATE link_board_items SET category_id = ?, position = ? WHERE id = ? AND user_id = ?', [$categoryId, $position, $id, $userId]);
        if ($n === 0) {
            jsonError('Item not found', 404);
        }
        jsonResponse(['success' => true]);
        break;

    case 'summarize':
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid item', 400);
        }
        $row = Database::queryOne('SELECT id, url FROM link_board_items WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            jsonError('Item not found', 404);
        }
        $content = fetchPageContentForSummary($row['url']);
        $summary = $content !== '' ? summarizeWithClaude($content) : '';
        if ($summary !== '') {
            Database::execute('UPDATE link_board_items SET summary = ? WHERE id = ? AND user_id = ?', [$summary, $id, $userId]);
        }
        jsonResponse(['success' => true, 'summary' => $summary]);
        break;

    default:
        jsonError('Invalid action', 400);
}

function fetchPageTitle(string $url): ?string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 4,
            'follow_location' => 1,
            'user_agent' => 'Mozilla/5.0 (compatible; CrashBoard/1.0)',
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false || strlen($html) < 10) {
        return null;
    }
    $html = substr($html, 0, 65536);
    if (preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $title !== '' ? mb_substr($title, 0, 255) : null;
    }
    return null;
}
