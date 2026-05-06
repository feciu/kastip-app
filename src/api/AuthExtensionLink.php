<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Session;

/**
 * POST /api/auth/extension-link
 *
 * "Sync extension with my logged-in web session" — instead of running another
 * OAuth flow through X (which uses whatever X session the browser currently
 * has, possibly a different account), the user can link the extension to the
 * SAME KasTip account that's already signed into kastip.app.
 *
 * Auth: cookie session ONLY (web). Bearer chaining is rejected — a leaked
 * bearer should not be able to mint additional bearers (defense-in-depth).
 *
 * Response: {ok, token, user} — token is a fresh extension-kind session token,
 * user is the same shape as /api/users/me so the popup can render immediately.
 */
final class AuthExtensionLink
{
    public static function handle(): void
    {
        $session = Session::requireCookieAuth();
        $userId = $session['user_id'];
        $token = Session::create($userId, 'extension');

        $stmt = App::db()->prepare("
            SELECT id, x_user_id, x_username, x_display_name, x_avatar_url,
                   kaspa_address, auto_reply_enabled,
                   total_received_kas, total_sent_kas,
                   tip_count_received, tip_count_sent,
                   created_at
            FROM users WHERE id = :id
        ");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            Session::destroy($session['session_token']);
            App::abort(404, 'User not found.');
        }

        $user['id'] = (int) $user['id'];
        $user['auto_reply_enabled'] = (bool) $user['auto_reply_enabled'];
        $user['total_received_kas'] = (float) $user['total_received_kas'];
        $user['total_sent_kas'] = (float) $user['total_sent_kas'];
        $user['tip_count_received'] = (int) $user['tip_count_received'];
        $user['tip_count_sent'] = (int) $user['tip_count_sent'];
        $user['needs_address'] = $user['kaspa_address'] === '';

        App::jsonResponse([
            'ok' => true,
            'token' => $token,
            'user' => $user,
        ]);
    }
}
