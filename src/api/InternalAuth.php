<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;

/**
 * Bearer auth for /api/internal/* endpoints.
 * Token is shared between PHP backend and the tx-watcher service (config('internal_token')).
 */
final class InternalAuth
{
    public static function require(): void
    {
        $expected = (string) App::config('internal_token', '');
        if ($expected === '' || str_contains($expected, 'CHANGE_ME')) {
            App::abort(503, 'internal_token not configured.');
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') !== 0) {
            App::abort(401, 'Bearer token required.');
        }
        $token = trim(substr($auth, 7));
        if (!hash_equals($expected, $token)) {
            App::abort(403, 'Invalid internal token.');
        }
    }
}
