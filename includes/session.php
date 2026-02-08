<?php
/**
 * Session Management
 *
 * Handles secure session initialization and CSRF token management.
 */

declare(strict_types=1);

class Session
{
    private static bool $initialized = false;
    private static array $config = [];

    /**
     * Initialize session with secure settings
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$config = self::loadConfig();

        // Prevent session fixation
        if (session_status() === PHP_SESSION_NONE) {
            // Set secure session parameters
            $sessionConfig = self::$config['session'] ?? [];

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');

            session_set_cookie_params([
                'lifetime' => $sessionConfig['lifetime'] ?? 28800,
                'path' => '/',
                'domain' => '',
                'secure' => $sessionConfig['secure'] ?? true,
                'httponly' => $sessionConfig['httponly'] ?? true,
                'samesite' => $sessionConfig['samesite'] ?? 'Lax',
            ]);

            session_name($sessionConfig['name'] ?? 'crashboard_session');
            session_start();

            // Regenerate session ID periodically to prevent fixation
            if (!isset($_SESSION['_created'])) {
                $_SESSION['_created'] = time();
            } elseif (time() - $_SESSION['_created'] > 1800) {
                // Regenerate every 30 minutes
                session_regenerate_id(true);
                $_SESSION['_created'] = time();
            }
        }

        self::$initialized = true;
    }

    /**
     * Start session with a custom cookie lifetime (e.g. for "private computer" / remember me).
     * Call after Session::destroy() so the new session gets the long-lived cookie.
     */
    public static function startWithLifetime(int $seconds): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        self::$config = self::loadConfig();
        $sessionConfig = self::$config['session'] ?? [];

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => $seconds,
            'path' => '/',
            'domain' => '',
            'secure' => $sessionConfig['secure'] ?? true,
            'httponly' => $sessionConfig['httponly'] ?? true,
            'samesite' => $sessionConfig['samesite'] ?? 'Lax',
        ]);

        session_name($sessionConfig['name'] ?? 'crashboard_session');
        session_start();
        $_SESSION['_created'] = time();
        self::$initialized = true;
    }

    /**
     * Load configuration
     */
    private static function loadConfig(): array
    {
        $configPath = dirname(__DIR__) . '/config/config.php';

        if (!file_exists($configPath)) {
            return [];
        }

        return require $configPath;
    }

    /**
     * Regenerate session ID (call after login)
     */
    public static function regenerate(): void
    {
        self::init();
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }

    /**
     * Destroy session (logout)
     */
    public static function destroy(): void
    {
        self::init();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        self::$initialized = false;
    }

    /**
     * Get session value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::init();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set session value
     */
    public static function set(string $key, mixed $value): void
    {
        self::init();
        $_SESSION[$key] = $value;
    }

    /**
     * Check if session key exists
     */
    public static function has(string $key): bool
    {
        self::init();
        return isset($_SESSION[$key]);
    }

    /**
     * Remove session key
     */
    public static function remove(string $key): void
    {
        self::init();
        unset($_SESSION[$key]);
    }

    /**
     * Get flash message and remove it
     */
    public static function flash(string $key, mixed $default = null): mixed
    {
        self::init();
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Set flash message
     */
    public static function setFlash(string $key, mixed $value): void
    {
        self::init();
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
    {
        self::init();

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['_csrf_token_time'] = time();

        return $token;
    }

    /**
     * Get current CSRF token (generate if needed)
     */
    public static function getCsrfToken(): string
    {
        self::init();

        // Regenerate if expired (1 hour)
        if (
            !isset($_SESSION['_csrf_token']) ||
            !isset($_SESSION['_csrf_token_time']) ||
            (time() - $_SESSION['_csrf_token_time']) > 3600
        ) {
            return self::generateCsrfToken();
        }

        return $_SESSION['_csrf_token'];
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrfToken(string $token): bool
    {
        self::init();

        if (!isset($_SESSION['_csrf_token'])) {
            return false;
        }

        // Timing-safe comparison
        return hash_equals($_SESSION['_csrf_token'], $token);
    }

    /**
     * Get CSRF token input field HTML
     */
    public static function csrfField(): string
    {
        $token = self::getCsrfToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool
    {
        self::init();
        return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
    }

    /**
     * Get authenticated user ID
     */
    public static function getUserId(): ?int
    {
        self::init();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Set authenticated user
     */
    public static function setUser(int $userId, array $userData = []): void
    {
        self::init();
        self::regenerate(); // Prevent session fixation

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_data'] = $userData;
        $_SESSION['login_time'] = time();
    }

    /**
     * Get user data
     */
    public static function getUserData(): array
    {
        self::init();
        return $_SESSION['user_data'] ?? [];
    }
}
