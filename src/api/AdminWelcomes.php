<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Admin;

/**
 * GET /api/admin/welcomes
 *
 * Returns user list with welcome-tip status, intended for the dashboard's
 * Welcomes tab. Includes derived "welcomed_by_you" (true if any sender_user_id
 * in admin_user_ids has confirmed/broadcast a tip to this recipient).
 *
 * Query: ?filter=needs|all|tipped|skipped|no_setup  (default: needs)
 *   needs    — has setup, no welcome tip yet, not marked skipped
 *   all      — every user with setup (default sort: newest first)
 *   tipped   — has setup, already received welcome from an admin account
 *   skipped  — welcome_status='skipped'
 *   no_setup — registered but no Kaspa address yet
 */
final class AdminWelcomes
{
    public static function handle(): void
    {
        Admin::requireAdmin();

        $filter = $_GET['filter'] ?? 'needs';
        if (!in_array($filter, ['needs', 'all', 'tipped', 'skipped', 'no_setup'], true)) {
            $filter = 'needs';
        }

        $adminIds = App::config('app.admin_user_ids', []);
        if (!is_array($adminIds) || empty($adminIds)) {
            App::jsonResponse(['users' => [], 'filter' => $filter, 'totals' => self::emptyTotals()]);
        }
        // Build IN-clause params for admin IDs.
        // Used in two distinct positions in the SQL → unique placeholder names
        // for each (PDO doesn't allow reusing the same named placeholder).
        $params = [];
        $welcomePlaceholders = [];
        $excludePlaceholders = [];
        foreach ($adminIds as $i => $aid) {
            $aid = (int) $aid;
            $welcomePlaceholders[] = ":aw$i";
            $excludePlaceholders[] = ":ax$i";
            $params[":aw$i"] = $aid;
            $params[":ax$i"] = $aid;
        }
        $welcomeIn = implode(',', $welcomePlaceholders);
        $excludeIn = implode(',', $excludePlaceholders);

        $sql = "
            SELECT
                u.id, u.x_username, u.x_display_name, u.x_avatar_url,
                u.kaspa_address, u.welcome_status, u.created_at,
                COALESCE(welcomes.cnt, 0)   AS welcome_count,
                COALESCE(welcomes.sum_kas, 0) AS welcome_kas,
                COALESCE(sent.cnt, 0)       AS tips_sent_count
            FROM users u
            LEFT JOIN (
                SELECT receiver_user_id, COUNT(*) AS cnt, SUM(amount_kas) AS sum_kas
                FROM tips
                WHERE sender_user_id IN ($welcomeIn)
                  AND status IN ('confirmed','broadcast')
                GROUP BY receiver_user_id
            ) welcomes ON welcomes.receiver_user_id = u.id
            LEFT JOIN (
                SELECT sender_user_id, COUNT(*) AS cnt
                FROM tips WHERE status IN ('confirmed','broadcast')
                GROUP BY sender_user_id
            ) sent ON sent.sender_user_id = u.id
            WHERE u.id NOT IN ($excludeIn)
            ORDER BY u.created_at DESC
        ";

        $stmt = App::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $users = [];
        $totals = self::emptyTotals();

        foreach ($rows as $r) {
            $hasSetup       = $r['kaspa_address'] !== '';
            $welcomed       = ((int) $r['welcome_count']) > 0;
            $skipped        = $r['welcome_status'] === 'skipped';
            $needsWelcome   = $hasSetup && !$welcomed && !$skipped;

            // Compute category for filter + totals
            if (!$hasSetup) {
                $category = 'no_setup';
            } elseif ($welcomed) {
                $category = 'tipped';
            } elseif ($skipped) {
                $category = 'skipped';
            } else {
                $category = 'needs';
            }
            $totals[$category]++;
            $totals['all']++;

            if ($filter !== 'all' && $filter !== $category) continue;

            $users[] = [
                'id'              => (int) $r['id'],
                'x_username'      => $r['x_username'],
                'x_display_name'  => $r['x_display_name'],
                'x_avatar_url'    => $r['x_avatar_url'],
                'has_setup'       => $hasSetup,
                'welcome_status'  => $r['welcome_status'],
                'welcomed_by_you' => $welcomed,
                'welcome_count'   => (int) $r['welcome_count'],
                'welcome_kas'     => (float) $r['welcome_kas'],
                'tips_sent_count' => (int) $r['tips_sent_count'],
                'created_at'      => $r['created_at'],
                'category'        => $category,
            ];
        }

        App::jsonResponse([
            'users'  => $users,
            'filter' => $filter,
            'totals' => $totals,
        ]);
    }

    private static function emptyTotals(): array
    {
        return ['needs' => 0, 'tipped' => 0, 'skipped' => 0, 'no_setup' => 0, 'all' => 0];
    }
}
