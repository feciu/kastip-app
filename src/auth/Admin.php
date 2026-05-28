<?php
declare(strict_types=1);

namespace KasTip\Auth;

use KasTip\App;

/**
 * Admin gate for internal /admin/* pages and /api/admin/* endpoints.
 *
 * Identity comes from the regular web session (`Session::current()`).
 * Admin status is configured in `secrets.php → app.admin_user_ids` (user IDs).
 *
 * Solo-founder use only — no role hierarchy, no audit log.
 */
final class Admin
{
    public static function isAdmin(int $userId): bool
    {
        $ids = App::config('app.admin_user_ids', []);
        return is_array($ids) && in_array($userId, $ids, true);
    }

    public static function requireAdmin(): array
    {
        $session = Session::current();
        if ($session === null || !self::isAdmin((int) $session['user_id'])) {
            App::abort(404, 'Not found.');   // 404, not 403 — don't reveal existence
        }
        return $session;
    }
}
