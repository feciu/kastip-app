<?php
declare(strict_types=1);

namespace KasTip;

use PDO;
use PDOException;

/**
 * App — global config + singletons + response helpers.
 *
 * Loaded by bootstrap.php. Use static methods directly:
 *
 *   App::config('db.user')     → 'kastip'
 *   App::db()->query(...)      → PDO instance (lazy)
 *   App::jsonResponse([...])   → echo JSON + exit
 *   App::abort(404, 'Not found')
 */
final class App
{
    private static array $config = [];
    private static ?PDO $pdo = null;

    public static function loadConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Get config value. Supports dot-path: 'db.user', 'x_oauth.client_id'.
     * Returns $default if missing.
     */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }
        $node = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function isProd(): bool
    {
        return self::config('app.env', 'prod') === 'prod';
    }

    public static function isDebug(): bool
    {
        return (bool) self::config('app.debug', false);
    }

    public static function baseUrl(): string
    {
        return rtrim(self::config('app.base_url', 'https://kastip.app'), '/');
    }

    /**
     * Lazy PDO singleton. Throws on connection failure.
     */
    public static function db(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $db = self::config('db');
        if (!is_array($db)) {
            throw new \RuntimeException('db config missing');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'], $db['port'], $db['name'], $db['charset'] ?? 'utf8mb4'
        );
        try {
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        } catch (PDOException $e) {
            // Don't leak DB credentials in error message even in dev.
            throw new \RuntimeException('Database connection failed', 0, $e);
        }
        return self::$pdo;
    }

    /**
     * Echo JSON response and exit. Sets correct headers.
     */
    public static function jsonResponse(mixed $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Abort with status code + JSON error body.
     */
    public static function abort(int $status, ?string $message = null, ?string $code = null): never
    {
        $body = ['error' => $code ?? self::statusToCode($status)];
        if ($message !== null) {
            $body['message'] = $message;
        }
        self::jsonResponse($body, $status);
    }

    /**
     * Real client IP — prefers CF-Connecting-IP (we're behind Cloudflare).
     * If that header is absent (e.g. local CLI test or direct access), falls back to REMOTE_ADDR.
     */
    public static function clientIp(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    private static function statusToCode(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            422 => 'unprocessable',
            429 => 'rate_limited',
            500 => 'internal_error',
            503 => 'unavailable',
            default => 'error',
        };
    }
}
