<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Session;

/**
 * GET /api/users/me — info about currently signed-in user.
 *
 * Auth: cookie or Bearer token.
 * Response: full user row (minus internal fields).
 */
final class UsersMe
{
    public static function handle(): void
    {
        $session = Session::requireAuth();

        $stmt = App::db()->prepare("
            SELECT id, x_user_id, x_username, x_display_name, x_avatar_url,
                   kaspa_address, auto_reply_enabled,
                   total_received_kas, total_sent_kas,
                   tip_count_received, tip_count_sent,
                   created_at
            FROM users
            WHERE id = :id
        ");
        $stmt->execute(['id' => $session['user_id']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            // Session points to a deleted user — clean up and bail.
            Session::destroy($session['session_token']);
            App::abort(401, 'User not found.');
        }

        // Cast numerics — PDO returns DECIMAL as string, INT as string when emulated prepares disabled
        $user['id'] = (int) $user['id'];
        $user['auto_reply_enabled'] = (bool) $user['auto_reply_enabled'];
        $user['total_received_kas'] = (float) $user['total_received_kas'];
        $user['total_sent_kas'] = (float) $user['total_sent_kas'];
        $user['tip_count_received'] = (int) $user['tip_count_received'];
        $user['tip_count_sent'] = (int) $user['tip_count_sent'];
        $user['needs_address'] = $user['kaspa_address'] === '';

        App::jsonResponse($user);
    }
}
