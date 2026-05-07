<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Lib\Payload;

/**
 * POST /api/internal/tx-detected
 *
 * Called by the tx-watcher service whenever it spots a Kaspa transaction
 * carrying a payload that starts with "kastip:v1:". Idempotent — if the
 * tip is already confirmed with the same txid, returns OK without changes.
 *
 * Auth: Bearer header that exact-matches config('internal_token').
 *       NOT a user session — service-to-service auth.
 *
 * Body (JSON):
 *   {
 *     "txid":     "abc123...",
 *     "payload":  "kastip:v1:tip:42:1730000000:abc12345",
 *     "outputs":  [{"address":"kaspa:qpz...","amount":500000000}, ...],
 *     "block_hash": "..."   (optional, informational)
 *   }
 *
 * Response: {ok:true, status:'confirmed'|'noop'|'rejected', tip_id?}
 */
final class InternalTxDetected
{
    public static function handle(): void
    {
        self::requireInternalAuth();

        $body = self::readJsonBody();
        $txid    = trim((string) ($body['txid'] ?? ''));
        $payload = trim((string) ($body['payload'] ?? ''));
        $outputs = $body['outputs'] ?? [];

        if (!preg_match('/^[a-f0-9]{32,128}$/i', $txid)) {
            App::abort(400, 'Invalid txid.', 'invalid_txid');
        }
        if (!is_array($outputs)) {
            App::abort(400, 'outputs must be array.');
        }

        $parsed = Payload::parseTip($payload);
        if ($parsed === null) {
            // Unknown / malformed / hash-mismatch payload — drop silently.
            App::jsonResponse(['ok' => true, 'status' => 'rejected', 'reason' => 'payload_invalid']);
        }

        $tipId = $parsed['tip_id'];
        $pdo = App::db();

        $stmt = $pdo->prepare("
            SELECT id, sender_user_id, receiver_user_id, receiver_kaspa_address,
                   amount_kas, status, txid AS existing_txid
            FROM tips WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $tipId]);
        $tip = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tip) {
            // Watcher saw a TX whose payload references an unknown tip_id.
            // Could mean: tip row was deleted, or someone replayed an old payload.
            App::jsonResponse(['ok' => true, 'status' => 'rejected', 'reason' => 'tip_not_found']);
        }

        // Idempotency: already confirmed with this txid — no work needed.
        if ($tip['status'] === 'confirmed' && hash_equals((string) $tip['existing_txid'], $txid)) {
            App::jsonResponse(['ok' => true, 'status' => 'noop', 'tip_id' => $tipId]);
        }

        // Different state already with different txid → don't overwrite.
        // (Conservative: keep first-confirmed wins.)
        if ($tip['status'] === 'confirmed' && $tip['existing_txid'] !== null && $tip['existing_txid'] !== $txid) {
            error_log("[InternalTxDetected] tip $tipId already confirmed with different txid {$tip['existing_txid']}, ignoring $txid");
            App::jsonResponse(['ok' => true, 'status' => 'noop', 'reason' => 'already_confirmed_different_txid']);
        }

        // Verify outputs include the expected receiver+amount
        $expectedSompi = (int) round((float) $tip['amount_kas'] * 100_000_000);
        $expectedAddr  = $tip['receiver_kaspa_address'];
        $matched = false;
        foreach ($outputs as $out) {
            $a = $out['address'] ?? null;
            $amt = (int) ($out['amount'] ?? 0);
            if ($a === $expectedAddr && $amt >= $expectedSompi) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $pdo->prepare("UPDATE tips SET status='failed', txid=:t WHERE id=:id")
                ->execute(['t' => $txid, 'id' => $tipId]);
            error_log("[InternalTxDetected] tip $tipId TX $txid does NOT contain expected output (addr=$expectedAddr, sompi>=$expectedSompi)");
            App::jsonResponse(['ok' => true, 'status' => 'rejected', 'reason' => 'output_mismatch']);
        }

        // Confirm + update totals atomically
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE tips
                SET status='confirmed', txid=:t, confirmed_at=CURRENT_TIMESTAMP
                WHERE id=:id
            ")->execute(['t' => $txid, 'id' => $tipId]);

            $pdo->prepare("
                UPDATE users
                SET total_sent_kas = total_sent_kas + :amt,
                    tip_count_sent = tip_count_sent + 1
                WHERE id = :id
            ")->execute(['amt' => $tip['amount_kas'], 'id' => (int) $tip['sender_user_id']]);

            if ($tip['receiver_user_id'] !== null) {
                $pdo->prepare("
                    UPDATE users
                    SET total_received_kas = total_received_kas + :amt,
                        tip_count_received = tip_count_received + 1
                    WHERE id = :id
                ")->execute(['amt' => $tip['amount_kas'], 'id' => (int) $tip['receiver_user_id']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        App::jsonResponse([
            'ok' => true,
            'status' => 'confirmed',
            'tip_id' => $tipId,
            'txid'   => $txid,
        ]);
    }

    private static function requireInternalAuth(): void
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

    private static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') App::abort(400, 'Empty request body.');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) App::abort(400, 'Invalid JSON body.');
        return $decoded;
    }
}
