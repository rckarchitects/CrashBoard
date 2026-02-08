<?php
/**
 * Helper Functions
 *
 * Common utility functions used throughout the application.
 */

declare(strict_types=1);

/**
 * Load configuration
 */
function config(string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $configPath = dirname(__DIR__) . '/config/config.php';
        $config = file_exists($configPath) ? require $configPath : [];
    }

    if ($key === null) {
        return $config;
    }

    // Support dot notation: 'database.host'
    $keys = explode('.', $key);
    $value = $config;

    foreach ($keys as $k) {
        if (!isset($value[$k])) {
            return $default;
        }
        $value = $value[$k];
    }

    return $value;
}

/**
 * Escape HTML output
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Get base URL
 */
function baseUrl(string $path = ''): string
{
    $baseUrl = config('app.url', '');
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

/**
 * Get asset URL with cache busting.
 * Prefer public/assets when it exists so the version matches the file the server serves (when doc root is public).
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $base = dirname(__DIR__);
    $filePath = $base . '/assets/' . $path;
    $publicPath = $base . '/public/assets/' . $path;
    if (file_exists($publicPath)) {
        $filePath = $publicPath;
    }
    $version = file_exists($filePath) ? filemtime($filePath) : time();
    return baseUrl('assets/' . $path) . '?v=' . $version;
}

/**
 * URL for dashboard.js. Always use public/assets/ so the browser loads the canonical
 * fixed file (when doc root is project root, /public/assets/... serves public/assets/).
 */
function dashboard_script_url(): string
{
    $path = 'js/dashboard.js';
    $base = dirname(__DIR__);
    $publicPath = $base . '/public/assets/' . $path;
    $version = file_exists($publicPath) ? filemtime($publicPath) : time();
    return baseUrl('public/assets/' . $path) . '?v=' . $version;
}

/**
 * Redirect to URL
 */
function redirect(string $url, int $statusCode = 302): void
{
    header('Location: ' . $url, true, $statusCode);
    exit;
}

/**
 * Return JSON response
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Return error JSON response
 */
function jsonError(string $message, int $statusCode = 400, array $extra = []): void
{
    jsonResponse(array_merge(['error' => $message], $extra), $statusCode);
}

/**
 * Check if request is AJAX
 */
function isAjax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Get request method
 */
function requestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Check if POST request
 */
function isPost(): bool
{
    return requestMethod() === 'POST';
}

/**
 * Get POST value
 */
function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

/**
 * Get GET value
 */
function get(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

/**
 * Format date for display
 */
function formatDate(string $date, string $format = 'M j, Y'): string
{
    $timezone = config('app.timezone', 'UTC');
    $dt = new DateTime($date, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($timezone));
    return $dt->format($format);
}

/**
 * Format datetime for display
 */
function formatDateTime(string $datetime, string $format = 'M j, Y g:i A'): string
{
    return formatDate($datetime, $format);
}

/**
 * Format relative time (e.g., "2 hours ago")
 */
function timeAgo(string $datetime): string
{
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'just now';
    }

    $intervals = [
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
    ];

    foreach ($intervals as $seconds => $label) {
        $count = floor($diff / $seconds);
        if ($count > 0) {
            return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
        }
    }

    return 'just now';
}

/**
 * Encrypt data using app encryption key
 */
function encrypt(string $data): string
{
    $key = config('encryption_key');
    if (!$key) {
        throw new RuntimeException('Encryption key not configured.');
    }

    $key = hex2bin($key);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt data using app encryption key
 */
function decrypt(string $data): string
{
    $key = config('encryption_key');
    if (!$key) {
        throw new RuntimeException('Encryption key not configured.');
    }

    $key = hex2bin($key);
    $data = base64_decode($data);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);

    $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($decrypted === false) {
        throw new RuntimeException('Decryption failed.');
    }

    return $decrypted;
}

/**
 * Generate a random string
 */
function randomString(int $length = 32): string
{
    return bin2hex(random_bytes($length / 2));
}

/**
 * Truncate string with ellipsis
 */
function truncate(string $text, int $length = 100, string $suffix = '...'): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }

    return mb_substr($text, 0, $length - mb_strlen($suffix)) . $suffix;
}

/**
 * Convert HTML to plain text preserving only line breaks (no other formatting).
 * Used for email body preview so popups are legible.
 */
function htmlToPlainTextWithLineBreaks(string $html): string
{
    $text = $html;
    // Turn line-break and block elements into newlines before stripping tags
    $text = preg_replace('/<br\s*\/?>\s*/i', "\n", $text);
    $text = preg_replace('/<\/?(p|div|tr|li|h[1-6])\b[^>]*>\s*/i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Collapse multiple spaces/tabs within a line to single space
    $text = preg_replace('/[ \t]+/', ' ', $text);
    // Collapse 2+ newlines to single newline so line spacing is even
    $text = preg_replace('/\n{2,}/', "\n", $text);
    return trim($text);
}

/**
 * Strip HTML and truncate
 */
function excerpt(string $html, int $length = 150): string
{
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    return truncate(trim($text), $length);
}

/**
 * Log message to error log
 */
function logMessage(string $message, string $level = 'info'): void
{
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[{$timestamp}] [{$level}] {$message}";
    error_log($formatted);
}

/**
 * Debug dump (only in debug mode)
 */
function dd(mixed ...$vars): void
{
    if (!config('app.debug', false)) {
        return;
    }

    echo '<pre style="background:#1e1e1e;color:#dcdcdc;padding:1rem;margin:1rem;overflow:auto;">';
    foreach ($vars as $var) {
        var_dump($var);
    }
    echo '</pre>';
    exit;
}

/**
 * Cache get/set helper
 */
function cache(string $key, ?callable $callback = null, int $ttl = 300): mixed
{
    if (!config('cache.enabled', true)) {
        return $callback ? $callback() : null;
    }

    // Ensure Database class is loaded
    if (!class_exists('Database')) {
        require_once __DIR__ . '/../config/database.php';
    }

    try {
        // Try to get from cache
        $cached = Database::queryOne(
            'SELECT cache_data FROM api_cache WHERE cache_key = ? AND expires_at > NOW()',
            [$key]
        );

        if ($cached) {
            return json_decode($cached['cache_data'], true);
        }

        // No cache or expired, execute callback
        if ($callback === null) {
            return null;
        }

        $data = $callback();

        // Store in cache
        Database::execute(
            'INSERT INTO api_cache (cache_key, cache_data, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), expires_at = VALUES(expires_at)',
            [$key, json_encode($data), $ttl]
        );

        return $data;
    } catch (Throwable $e) {
        // If database error, log it and return callback result or null
        error_log('Cache error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        // Return callback result if available, otherwise null
        return $callback ? $callback() : null;
    }
}

/**
 * Clear cache by key pattern
 */
function cacheClear(string $pattern = '%'): int
{
    return Database::execute(
        'DELETE FROM api_cache WHERE cache_key LIKE ?',
        [$pattern]
    );
}

/**
 * Sanitize filename
 */
function sanitizeFilename(string $filename): string
{
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return preg_replace('/_+/', '_', $filename);
}

/**
 * Get file extension
 */
function getExtension(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Format bytes to human readable
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}
