<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;

/**
 * GET /api/users/lookup?handle=janek_xyz
 *
 * Public endpoint (no auth required). Used by the extension before initiating
 * a tip — to learn whether @handle has a KasTip account, and if so, where to
 * send the KAS.
 *
 * Response — registered:
 *   {"registered": true, "x_username": "janek_xyz", "x_display_name": "Janek",
 *    "x_avatar_url": "...", "kaspa_address": "kaspa:qpz..."}
 *
 * Response — unregistered:
 *   {"registered": false, "x_username": "janek_xyz"}
 */
final class UsersLookup
{
    public static function handle(): void
    {
        $handle = strtolower(trim((string) ($_GET['handle'] ?? '')));
        $handle = ltrim($handle, '@');

        if ($handle === '' || !preg_match('/^[a-z0-9_]{1,15}$/', $handle)) {
            App::abort(400, 'Invalid handle. Format: 1-15 lowercase letters/digits/underscores.');
        }

        $stmt = App::db()->prepare("
            SELECT x_username, x_display_name, x_avatar_url, kaspa_address
            FROM users
            WHERE x_username = :h AND kaspa_address != ''
            LIMIT 1
        ");
        $stmt->execute(['h' => $handle]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($user) {
            App::jsonResponse([
                'registered'     => true,
                'x_username'     => $user['x_username'],
                'x_display_name' => $user['x_display_name'],
                'x_avatar_url'   => $user['x_avatar_url'],
                'kaspa_address'  => $user['kaspa_address'],
            ]);
        }
        App::jsonResponse([
            'registered' => false,
            'x_username' => $handle,
        ]);
    }
}
