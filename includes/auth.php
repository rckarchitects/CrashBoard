<?php
/**
 * Authentication Handler
 *
 * Handles user authentication, password management, and rate limiting.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

class Auth
{
    private static array $config = [];

    /**
     * Load configuration
     */
    private static function loadConfig(): array
    {
        if (empty(self::$config)) {
            $configPath = dirname(__DIR__) . '/config/config.php';
            if (file_exists($configPath)) {
                self::$config = require $configPath;
            }
        }
        return self::$config;
    }

    /**
     * Get security config value
     */
    private static function getSecurityConfig(string $key, mixed $default = null): mixed
    {
        $config = self::loadConfig();
        return $config['security'][$key] ?? $default;
    }

    /**
     * Attempt to authenticate user
     */
    public static function attempt(string $username, string $password): array
    {
        $ip = self::getClientIp();

        // Check rate limiting
        if (self::isRateLimited($ip)) {
            return [
                'success' => false,
                'error' => 'Too many login attempts. Please try again later.',
            ];
        }

        // Find user
        $user = Database::queryOne(
            'SELECT id, username, email, password_hash, is_active FROM users WHERE username = ? OR email = ?',
            [$username, $username]
        );

        if (!$user) {
            self::logAttempt($ip, $username, false);
            return [
                'success' => false,
                'error' => 'Invalid username or password.',
            ];
        }

        // Check if account is active
        if (!$user['is_active']) {
            self::logAttempt($ip, $username, false);
            return [
                'success' => false,
                'error' => 'This account has been disabled.',
            ];
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            self::logAttempt($ip, $username, false);
            return [
                'success' => false,
                'error' => 'Invalid username or password.',
            ];
        }

        // Success - log and set session
        self::logAttempt($ip, $username, true);

        // Update last login
        Database::execute(
            'UPDATE users SET last_login = NOW() WHERE id = ?',
            [$user['id']]
        );

        // Set session
        Session::setUser($user['id'], [
            'username' => $user['username'],
            'email' => $user['email'],
        ]);

        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
            ],
        ];
    }

    /**
     * Log out current user
     */
    public static function logout(): void
    {
        Session::destroy();
    }

    /**
     * Check if current user is authenticated
     */
    public static function check(): bool
    {
        return Session::isAuthenticated();
    }

    /**
     * Get current authenticated user
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        $userId = Session::getUserId();
        return Database::queryOne(
            'SELECT id, username, email, last_login, created_at FROM users WHERE id = ?',
            [$userId]
        );
    }

    /**
     * Get current user ID
     */
    public static function id(): ?int
    {
        return Session::getUserId();
    }

    /**
     * Require authentication (redirect to login if not authenticated)
     */
    public static function require(): void
    {
        if (!self::check()) {
            Session::setFlash('error', 'Please log in to access this page.');
            Session::setFlash('redirect_to', $_SERVER['REQUEST_URI'] ?? '/');
            header('Location: /login.php');
            exit;
        }
    }

    /**
     * Hash password
     */
    public static function hashPassword(string $password): string
    {
        $cost = self::getSecurityConfig('bcrypt_cost', 12);
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    /**
     * Check if password needs rehashing
     */
    public static function needsRehash(string $hash): bool
    {
        $cost = self::getSecurityConfig('bcrypt_cost', 12);
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    /**
     * Create new user
     */
    public static function createUser(string $username, string $email, string $password): array
    {
        // Validate input
        if (strlen($username) < 3 || strlen($username) > 50) {
            return ['success' => false, 'error' => 'Username must be 3-50 characters.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        // Check if username or email exists
        $existing = Database::queryOne(
            'SELECT id FROM users WHERE username = ? OR email = ?',
            [$username, $email]
        );

        if ($existing) {
            return ['success' => false, 'error' => 'Username or email already exists.'];
        }

        // Create user
        try {
            Database::execute(
                'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
                [$username, $email, self::hashPassword($password)]
            );

            $userId = (int) Database::lastInsertId();

            // Create default tiles for new user
            self::createDefaultTiles($userId);

            return [
                'success' => true,
                'user_id' => $userId,
            ];
        } catch (Exception $e) {
            error_log('Failed to create user: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create user.'];
        }
    }

    /**
     * Create default tiles for a new user
     */
    private static function createDefaultTiles(int $userId): void
    {
        $defaultTiles = [
            ['tile_type' => 'email', 'title' => 'Inbox', 'position' => 1, 'column_span' => 1],
            ['tile_type' => 'calendar', 'title' => 'Calendar', 'position' => 2, 'column_span' => 1],
            ['tile_type' => 'todo', 'title' => 'Tasks', 'position' => 3, 'column_span' => 1],
            ['tile_type' => 'crm', 'title' => 'CRM Actions', 'position' => 4, 'column_span' => 1],
            ['tile_type' => 'claude', 'title' => 'AI Assistant', 'position' => 5, 'column_span' => 2],
        ];

        foreach ($defaultTiles as $tile) {
            Database::execute(
                'INSERT INTO tiles (user_id, tile_type, title, position, column_span) VALUES (?, ?, ?, ?, ?)',
                [$userId, $tile['tile_type'], $tile['title'], $tile['position'], $tile['column_span']]
            );
        }
    }

    /**
     * Change user password
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = Database::queryOne('SELECT password_hash FROM users WHERE id = ?', [$userId]);

        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'New password must be at least 8 characters.'];
        }

        Database::execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [self::hashPassword($newPassword), $userId]
        );

        return ['success' => true];
    }

    /**
     * Check if IP is rate limited
     */
    private static function isRateLimited(string $ip): bool
    {
        $maxAttempts = self::getSecurityConfig('rate_limit_attempts', 5);
        $windowSeconds = self::getSecurityConfig('rate_limit_window', 900);

        $result = Database::queryOne(
            'SELECT COUNT(*) as attempts FROM login_attempts
             WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND) AND successful = FALSE',
            [$ip, $windowSeconds]
        );

        return ($result['attempts'] ?? 0) >= $maxAttempts;
    }

    /**
     * Log login attempt
     */
    private static function logAttempt(string $ip, string $username, bool $successful): void
    {
        Database::execute(
            'INSERT INTO login_attempts (ip_address, username, successful) VALUES (?, ?, ?)',
            [$ip, $username, $successful ? 1 : 0]
        );
    }

    /**
     * Get client IP address
     */
    private static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy
            'HTTP_X_REAL_IP',            // Nginx
            'REMOTE_ADDR',               // Direct
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For can contain multiple IPs
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Verify CSRF token from request
     */
    public static function verifyCsrf(): bool
    {
        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return Session::verifyCsrfToken($token);
    }

    /**
     * Require valid CSRF token
     */
    public static function requireCsrf(): void
    {
        if (!self::verifyCsrf()) {
            http_response_code(403);
            die('Invalid security token. Please refresh the page and try again.');
        }
    }
}
