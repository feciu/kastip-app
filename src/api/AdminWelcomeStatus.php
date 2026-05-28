<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Admin;

/**
 * POST /api/admin/users/{id}/welcome-status
 *
 * Body: {"status": "pending"|"skipped"}
 *
 * Manual toggle for the Welcomes dashboard tab. "tipped" is derived from the
 * tips table (not stored), so it can't be set here — admin marks "skipped"
 * to remove a user from the needs-welcome list without sending KAS.
 */
final class AdminWelcomeStatus
{
    public static function handle(int $userId): void
    {
        Admin::requireAdmin();

        $raw = file_get_contents('php://input');
        $body = $raw ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            App::abort(400, 'Invalid JSON body.');
        }
        $status = $body['status'] ?? '';
        if (!in_array($status, ['pending', 'skipped'], true)) {
            App::abort(422, 'status must be "pending" or "skipped".');
        }

        $stmt = App::db()->prepare('
            UPDATE users SET welcome_status = :s, updated_at = CURRENT_TIMESTAMP WHERE id = :id
        ');
        $stmt->execute(['s' => $status, 'id' => $userId]);

        if ($stmt->rowCount() === 0) {
            App::abort(404, 'User not found.');
        }

        App::jsonResponse(['ok' => true, 'user_id' => $userId, 'welcome_status' => $status]);
    }
}
