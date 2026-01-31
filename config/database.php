<?php
/**
 * Database Connection Handler
 *
 * Provides PDO database connection with singleton pattern.
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Prevent direct instantiation
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Get database connection instance
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$config = self::loadConfig();
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    /**
     * Load database configuration
     */
    private static function loadConfig(): array
    {
        $configPath = __DIR__ . '/config.php';

        if (!file_exists($configPath)) {
            throw new RuntimeException(
                'Configuration file not found. Copy config.example.php to config.php and update values.'
            );
        }

        $config = require $configPath;

        if (!isset($config['database'])) {
            throw new RuntimeException('Database configuration is missing from config file.');
        }

        return $config['database'];
    }

    /**
     * Create PDO connection
     */
    private static function createConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::$config['host'],
            self::$config['port'] ?? 3306,
            self::$config['name'],
            self::$config['charset'] ?? 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
        ];

        try {
            $pdo = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                $options
            );

            return $pdo;
        } catch (PDOException $e) {
            // Log the actual error but show generic message
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed. Please check your configuration.');
        }
    }

    /**
     * Close the connection (for testing or cleanup)
     */
    public static function close(): void
    {
        self::$instance = null;
    }

    /**
     * Execute a query and return results
     */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return single row
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute an insert/update/delete and return affected rows
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get last inserted ID
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool
    {
        return self::getInstance()->rollBack();
    }
}
