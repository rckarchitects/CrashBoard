<?php
/**
 * Receive URL – Link board share endpoint
 *
 * Public endpoint (no login). Accepts POST with key and url in the body; adds the
 * URL to the user's Link board. Used from iOS Share Sheet / Shortcuts.
 *
 * POST to: receiveurl.php
 * Body: key=[secretkey]&url=[shared-url]  (form) or {"key":"...","url":"..."} (JSON)
 * The shared URL can be sent as plain text in the body; no query-string encoding needed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

// POST only (avoids iOS Shortcuts URL-encoding issues with GET query string)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendReceiveUrlResponse('Use POST to send the link. In Shortcuts: Post to your receive URL with body fields key and url.', 400);
}

// Read key and url from POST body (form or JSON)
$key = '';
$url = '';
$contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim($_SERVER['CONTENT_TYPE'])) : '';
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($input)) {
        $key = isset($input['key']) ? trim((string) $input['key']) : '';
        $url = isset($input['url']) ? trim((string) $input['url']) : '';
    }
} else {
    $key = isset($_POST['key']) ? trim((string) $_POST['key']) : '';
    $url = isset($_POST['url']) ? trim((string) $_POST['url']) : '';
}

if ($key === '') {
    sendReceiveUrlResponse('Missing key in request body.', 400);
}
if ($url === '') {
    sendReceiveUrlResponse('Missing url in request body. Send key and url as form fields or JSON (e.g. {"key":"your-key","url":"https://..."}).', 400);
}

// Look up user by secret key
$userCol = Database::queryOne("SHOW COLUMNS FROM users LIKE 'link_board_share_key'");
if (empty($userCol)) {
    sendReceiveUrlResponse('Invalid or expired link.', 403);
}

$user = Database::queryOne(
    'SELECT id, link_board_share_category_id FROM users WHERE link_board_share_key = ? AND link_board_share_key IS NOT NULL',
    [$key]
);
if (!$user) {
    sendReceiveUrlResponse('Invalid or expired link.', 403);
}

$userId = (int) $user['id'];
$preferredCategoryId = isset($user['link_board_share_category_id']) ? (int) $user['link_board_share_category_id'] : null;

// Normalize and validate URL
if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
}
if (filter_var($url, FILTER_VALIDATE_URL) === false) {
    sendReceiveUrlResponse('Invalid URL.', 400);
}
$url = mb_substr($url, 0, 2048);

ensureLinkBoardTablesForUser($userId);

// Resolve category
$categoryId = null;
if ($preferredCategoryId > 0) {
    $cat = Database::queryOne('SELECT id FROM link_board_categories WHERE id = ? AND user_id = ?', [$preferredCategoryId, $userId]);
    if ($cat) {
        $categoryId = (int) $cat['id'];
    }
}
if ($categoryId === null) {
    $first = Database::queryOne('SELECT id FROM link_board_categories WHERE user_id = ? ORDER BY position ASC, id ASC LIMIT 1', [$userId]);
    if ($first) {
        $categoryId = (int) $first['id'];
    }
}
if ($categoryId === null) {
    // Create default category
    Database::execute(
        'INSERT INTO link_board_categories (user_id, name, position) VALUES (?, ?, 0)',
        [$userId, 'Inbox']
    );
    $categoryId = (int) Database::lastInsertId();
}

// Optional: fetch page title
$title = fetchPageTitleForReceive($url);
$title = $title !== null && $title !== '' ? mb_substr($title, 0, 255) : null;

$maxPos = Database::queryOne('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM link_board_items WHERE category_id = ?', [$categoryId]);
$position = (int) ($maxPos['next_pos'] ?? 0);

Database::execute(
    'INSERT INTO link_board_items (user_id, category_id, url, title, summary, position) VALUES (?, ?, ?, ?, NULL, ?)',
    [$userId, $categoryId, $url, $title, $position]
);
$newItemId = (int) Database::lastInsertId();

// Trigger AI summary in background (same logic as link-board tile)
$pageContent = fetchPageContentForSummaryForReceive($url);
if ($pageContent !== '') {
    $summary = summarizeWithClaudeForReceive($pageContent);
    if ($summary !== '') {
        Database::execute('UPDATE link_board_items SET summary = ? WHERE id = ? AND user_id = ?', [$summary, $newItemId, $userId]);
    }
}

sendReceiveUrlResponse('Link added to your board.', 200, true);

/**
 * Send HTML response and exit
 */
function sendReceiveUrlResponse(string $message, int $statusCode = 200, bool $success = false): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    $dashboardUrl = rtrim(config('app.url', ''), '/') . '/';
    $title = $success ? 'Link added' : 'Error';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:2rem auto;padding:1rem;text-align:center;">';
    echo '<p style="font-size:1.125rem;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($success) {
        echo '<p><a href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#0d9488;">Open dashboard</a></p>';
    }
    echo '</body></html>';
    exit;
}

/**
 * Ensure link_board_categories and link_board_items exist (for a given user, used when creating default category)
 */
function ensureLinkBoardTablesForUser(int $userId): void
{
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
        error_log('ReceiveURL link board tables: ' . $e->getMessage());
        sendReceiveUrlResponse('Something went wrong.', 500);
    }
}

/**
 * Fetch page title from URL (for display in link board)
 */
function fetchPageTitleForReceive(string $url): ?string
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

/**
 * Fetch URL and extract readable text for summarization
 */
function fetchPageContentForSummaryForReceive(string $url): string
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
    $html = substr($html, 0, 120000);
    $title = '';
    if (preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = mb_substr($title, 0, 300);
    }
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
function summarizeWithClaudeForReceive(string $pageContent): string
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
