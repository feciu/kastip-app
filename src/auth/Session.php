<?php
declare(strict_types=1);

namespace KasTip\Auth;

use KasTip\App;
use PDO;

/**
 * Session — dual-mode auth (cookie for web app, Bearer for extension).
 *
 * Backed by `sessions` table. Token is 32-byte random hex, used as both
 * cookie value AND Bearer token. Same row, same expiry.
 *
 *   Session::create($userId, 'web', $extId = null)        → token
 *   Session::current()                                    → ['user_id'=>..., 'token'=>...] or null
 *   Session::requireAuth()                                → array (calls App::abort(401) if missing)
 *   Session::destroy($token)                              → void
 *
 * Cookie settings: HTTPOnly, Secure, SameSite=Lax, 30-day expiry.
 *
 * Why Bearer for extension: MV3 service workers have known issues with
 * cross-origin cookies. Bearer header always works.
 */
final class Session
{
    public const COOKIE_NAME = 'kastip_session';
    public const TTL_DAYS = 30;

    /**
     * Create a new session for $userId. Sets cookie if web client.
     * Returns the token (caller may pass it to extension as JSON).
     */
    public static function create(int $userId, string $clientKind = 'web', ?string $extensionId = null): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400);

        $stmt = App::db()->prepare("
            INSERT INTO sessions
                (user_id, session_token, client_kind, extension_id, expires_at, user_agent)
            VALUES
                (:uid, :token, :kind, :ext, :exp, :ua)
        ");
        $stmt->execute([
            'uid'   => $userId,
            'token' => $token,
            'kind'  => $clientKind,
            'ext'   => $extensionId,
            'exp'   => $expiresAt,
            'ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
        ]);

        if ($clientKind === 'web' && !headers_sent()) {
            self::setCookie($token);
        }
        return $token;
    }

    /**
     * Resolve current session from either Bearer header or cookie. Returns row or null.
     *
     * @return array{id:int, user_id:int, session_token:string, client_kind:string}|null
     */
    public static function current(): ?array
    {
        $token = self::extractToken();
        if ($token === null) {
            return null;
        }
        $stmt = App::db()->prepare("
            SELECT id, user_id, session_token, client_kind, extension_id, expires_at
            FROM sessions
            WHERE session_token = :t AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // Bump last_used_at (best-effort).
        try {
            App::db()->prepare("UPDATE sessions SET last_used_at = NOW() WHERE id = :id")
                     ->execute(['id' => $row['id']]);
        } catch (\Throwable $e) {
            // Non-fatal.
        }
        return $row;
    }

    /**
     * Get current session or abort 401.
     *
     * @return array{id:int, user_id:int, session_token:string, client_kind:string}
     */
    public static function requireAuth(): array
    {
        $session = self::current();
        if ($session === null) {
            App::abort(401, 'Authentication required.');
        }
        return $session;
    }

    /**
     * Require auth via cookie (web) channel specifically. Used by endpoints
     * that should NOT be callable with a Bearer token (e.g. extension-link,
     * which mints new bearers — chaining bearer→bearer would let a leaked
     * token mint more tokens).
     *
     * @return array{id:int, user_id:int, session_token:string, client_kind:string}
     */
    public static function requireCookieAuth(): array
    {
        $session = self::requireAuth();
        $cookieToken = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($session['client_kind'] !== 'web' || !hash_equals($session['session_token'], $cookieToken)) {
            App::abort(403, 'This action requires a cookie session (sign in at https://kastip.app first).', 'cookie_auth_required');
        }
        return $session;
    }

    /**
     * Destroy session by token. Clears cookie.
     */
    public static function destroy(string $token): void
    {
        $stmt = App::db()->prepare("DELETE FROM sessions WHERE session_token = :t");
        $stmt->execute(['t' => $token]);
        if (!headers_sent()) {
            self::clearCookie();
        }
    }

    /**
     * Logout endpoint — destroys current session.
     */
    public static function logout(): void
    {
        $session = self::current();
        if ($session !== null) {
            self::destroy($session['session_token']);
        }
        App::jsonResponse(['ok' => true]);
    }

    /**
     * Garbage-collect expired sessions. Call periodically (cron) or eagerly.
     */
    public static function gc(): int
    {
        return (int) App::db()->exec("DELETE FROM sessions WHERE expires_at <= NOW()");
    }

    // ─── token extraction (Bearer header > cookie) ───────────────────────────

    private static function extractToken(): ?string
    {
        // Authorization: Bearer <token>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader !== '' && stripos($authHeader, 'Bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
            if ($token !== '' && self::isValidTokenFormat($token)) {
                return $token;
            }
        }
        // Cookie fallback
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($cookie !== '' && self::isValidTokenFormat($cookie)) {
            return $cookie;
        }
        return null;
    }

    private static function isValidTokenFormat(string $t): bool
    {
        // 64 hex chars (32 bytes)
        return preg_match('/^[a-f0-9]{64}$/', $t) === 1;
    }

    private static function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + self::TTL_DAYS * 86400,
            'path'     => '/',
            'domain'   => '',                  // current host only — kastip.app
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
