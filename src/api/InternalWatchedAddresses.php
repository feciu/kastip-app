<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;

/**
 * GET /api/internal/watched-addresses
 *
 * Returns a list of receiver Kaspa addresses for tipy that are still
 * waiting for on-chain confirmation. The tx-watcher service polls this
 * once a minute and uses the list to detect tipy sent from non-Kasware
 * wallets (which can't inject our 'kastip:v1:' payload).
 *
 * Auth: Bearer == config('internal_token').
 *
 * Response:
 *   {
 *     "ok": true,
 *     "addresses": [
 *       {
 *         "address": "kaspa:qpz...",
 *         "tips": [{"tip_id": 21, "amount_sompi": 100000000, "expires_at": "..."}]
 *       },
 *       ...
 *     ]
 *   }
 *
 * Window: 30 minutes from initiated_at — anything older we consider stale
 * and the user has to retry.
 */
final class InternalWatchedAddresses
{
    private const WINDOW_MINUTES = 30;

    public static function handle(): void
    {
        InternalAuth::require();

        $stmt = App::db()->query("
            SELECT id, receiver_kaspa_address, amount_kas,
                   DATE_ADD(initiated_at, INTERVAL " . self::WINDOW_MINUTES . " MINUTE) AS expires_at
            FROM tips
            WHERE status IN ('pending', 'broadcast')
              AND receiver_kaspa_address IS NOT NULL AND receiver_kaspa_address != ''
              AND initiated_at > DATE_SUB(NOW(), INTERVAL " . self::WINDOW_MINUTES . " MINUTE)
            ORDER BY id DESC
            LIMIT 500
        ");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $byAddr = [];
        foreach ($rows as $r) {
            $addr = $r['receiver_kaspa_address'];
            $byAddr[$addr] ??= ['address' => $addr, 'tips' => []];
            $byAddr[$addr]['tips'][] = [
                'tip_id'       => (int) $r['id'],
                'amount_sompi' => (int) round((float) $r['amount_kas'] * 100_000_000),
                'expires_at'   => $r['expires_at'],
            ];
        }

        App::jsonResponse([
            'ok' => true,
            'count' => count($byAddr),
            'addresses' => array_values($byAddr),
        ]);
    }
}
