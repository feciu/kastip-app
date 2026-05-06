<?php
declare(strict_types=1);

namespace KasTip\Lib;

use KasTip\App;

/**
 * RateLimit — bucket-based rate limiting using rate_limits table.
 *
 * Bucket key = sha256(client_ip + user_id_or_zero + endpoint).
 * Bucket window is rounded to the nearest N seconds (window_seconds arg).
 *
 * Usage:
 *   RateLimit::checkOrAbort('tip-initiate', 10, 60);   // 10 requests / 60s window
 *   RateLimit::checkOrAbort('tip-initiate', 100, 86400, $userId);  // daily limit
 *
 * Returns silently on success, throws (well, App::abort 429) on limit exceeded.
 *
 * NOT a sliding window — fixed buckets — but that's fine for spam control.
 *
 * Cleanup: old rows accumulate. Caller may want a periodic
 *   DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 7 DAY);
 * (TODO: cron job in Etap F.)
 */
final class RateLimit
{
    /**
     * Increment counter for the bucket and abort with 429 if limit exceeded.
     */
    public static function checkOrAbort(
        string $bucket,
        int $maxRequests,
        int $windowSeconds,
        ?int $userId = null
    ): void {
        $count = self::increment($bucket, $windowSeconds, $userId);
        if ($count > $maxRequests) {
            App::abort(429, "Rate limit exceeded: max $maxRequests requests per {$windowSeconds}s.");
        }
    }

    /**
     * Increment counter and return current count for this bucket+window.
     */
    public static function increment(
        string $bucket,
        int $windowSeconds,
        ?int $userId = null
    ): int {
        $ip = App::clientIp();
        $key = self::makeKey($ip, $userId ?? 0, $bucket);
        $windowStart = self::roundWindow(time(), $windowSeconds);

        $pdo = App::db();

        // INSERT … ON DUPLICATE KEY UPDATE — atomic increment.
        $stmt = $pdo->prepare("
            INSERT INTO rate_limits (key_hash, window_start, request_count)
            VALUES (:k, FROM_UNIXTIME(:ws), 1)
            ON DUPLICATE KEY UPDATE request_count = request_count + 1
        ");
        $stmt->execute(['k' => $key, 'ws' => $windowStart]);

        // Read back the current count.
        $stmt = $pdo->prepare("
            SELECT request_count FROM rate_limits
            WHERE key_hash = :k AND window_start = FROM_UNIXTIME(:ws)
        ");
        $stmt->execute(['k' => $key, 'ws' => $windowStart]);
        $count = (int) $stmt->fetchColumn();
        return $count;
    }

    /**
     * Read current count without incrementing (for status / debug).
     */
    public static function currentCount(
        string $bucket,
        int $windowSeconds,
        ?int $userId = null
    ): int {
        $ip = App::clientIp();
        $key = self::makeKey($ip, $userId ?? 0, $bucket);
        $windowStart = self::roundWindow(time(), $windowSeconds);
        $stmt = App::db()->prepare("
            SELECT request_count FROM rate_limits
            WHERE key_hash = :k AND window_start = FROM_UNIXTIME(:ws)
        ");
        $stmt->execute(['k' => $key, 'ws' => $windowStart]);
        return (int) $stmt->fetchColumn();
    }

    private static function makeKey(string $ip, int $userId, string $bucket): string
    {
        return hash('sha256', $ip . '|' . $userId . '|' . $bucket);
    }

    private static function roundWindow(int $ts, int $windowSeconds): int
    {
        return (int) (floor($ts / $windowSeconds) * $windowSeconds);
    }
}
