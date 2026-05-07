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
        InternalAuth::require();

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

        $pdo = App::db();
        $parsed = $payload !== '' ? Payload::parseTip($payload) : null;

        // Path A: KasTip-aware wallet (Kasware). Payload identifies tip directly.
        if ($parsed !== null) {
            self::confirmByTipId($parsed['tip_id'], $txid, $outputs, $pdo);
            // confirmByTipId terminates with jsonResponse
            return;
        }

        // Path B: foreign wallet (Kaspium, Tangem, etc.). Payload absent or
        // unrecognized. Try to match by output address + amount against any
        // pending/broadcast tip from the last 30 minutes.
        self::confirmByAddressMatch($txid, $outputs, $pdo);
    }

    /**
     * Path A — payload-driven match. Used when we own the wallet UX (Kasware).
     */
    private static function confirmByTipId(int $tipId, string $txid, array $outputs, \PDO $pdo): void
    {

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

    /**
     * Path B — address-driven match. Used for foreign wallets that don't
     * inject our payload (Kaspium, Tangem, etc.). Matches output address +
     * amount against pending tipy in the last 30 minutes.
     *
     * Strategy:
     *   - For each output in TX, see if it matches any pending tip's
     *     receiver_kaspa_address with amount >= expected.
     *   - First-match-wins: if multiple pending tipy could match, take
     *     the oldest one (FIFO; user retried? earliest needs help most).
     *   - Skip if any of these tipy were already confirmed with this txid
     *     (idempotent).
     */
    private static function confirmByAddressMatch(string $txid, array $outputs, \PDO $pdo): void
    {
        // Build lookup map: address → output amount sompi
        $byAddr = [];
        foreach ($outputs as $out) {
            $a = $out['address'] ?? null;
            $amt = (int) ($out['amount'] ?? 0);
            if ($a === null || $amt <= 0) continue;
            $byAddr[$a] = max($byAddr[$a] ?? 0, $amt);
        }
        if (empty($byAddr)) {
            App::jsonResponse(['ok' => true, 'status' => 'rejected', 'reason' => 'no_outputs']);
        }

        // Idempotency guard: if any tip already has this txid, skip the match attempt entirely.
        $stmt = $pdo->prepare("SELECT id, status FROM tips WHERE txid = :t LIMIT 1");
        $stmt->execute(['t' => $txid]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            App::jsonResponse(['ok' => true, 'status' => 'noop', 'reason' => 'txid_already_processed', 'tip_id' => (int) $existing['id']]);
        }

        // Find candidate pending tipy — receiver_kaspa_address in our output map,
        // status pending/broadcast, and recent (< 30 min).
        $placeholders = implode(',', array_fill(0, count($byAddr), '?'));
        $sql = "
            SELECT id, sender_user_id, receiver_user_id, receiver_kaspa_address,
                   amount_kas, status
            FROM tips
            WHERE status IN ('pending', 'broadcast')
              AND receiver_kaspa_address IN ($placeholders)
              AND initiated_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_keys($byAddr));
        $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Score each candidate by closeness to actual amount on its address.
        // Priorities:
        //   1. Exact match (within 1 sompi tolerance for rounding) — perfect.
        //   2. Least-overpayment match (smallest positive diff) — user paid
        //      slightly more, presumably for THIS specific tip.
        //   3. Underpayment is NOT a match (user sent less than asked → tip
        //      stays pending → user can retry or backend times out).
        // FIFO id-asc as tiebreaker if multiple candidates tie on diff.
        $scored = [];
        foreach ($candidates as $tip) {
            $expectedSompi = (int) round((float) $tip['amount_kas'] * 100_000_000);
            $actualSompi   = (int) ($byAddr[$tip['receiver_kaspa_address']] ?? 0);
            $diff = $actualSompi - $expectedSompi;
            if ($diff < 0) continue;  // underpayment — skip
            $scored[] = ['tip' => $tip, 'diff' => $diff, 'expected' => $expectedSompi];
        }
        usort($scored, function ($a, $b) {
            if ($a['diff'] !== $b['diff']) return $a['diff'] <=> $b['diff'];
            return $a['tip']['id'] <=> $b['tip']['id'];
        });

        // Telemetry: log when multiple candidates were viable. If we see
        // these regularly in production, time to add a unique-amount offset
        // mechanism. For now, single-tip-per-pair + exact-match priority is
        // expected to handle nearly all real-world traffic at MVP scale.
        if (count($scored) > 1) {
            $tipIds = array_map(fn($s) => $s['tip']['id'], $scored);
            $diffs = array_map(fn($s) => $s['diff'], $scored);
            error_log(sprintf(
                '[InternalTxDetected] AMBIGUOUS_MATCH txid=%s candidates=[%s] diffs=[%s] picked=tip_%d',
                $txid,
                implode(',', $tipIds),
                implode(',', $diffs),
                $scored[0]['tip']['id']
            ));
        }

        foreach ($scored as $s) {
            $tip = $s['tip'];
            // Match — confirm + update totals
            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    UPDATE tips
                    SET status='confirmed', txid=:t, confirmed_at=CURRENT_TIMESTAMP
                    WHERE id=:id
                ")->execute(['t' => $txid, 'id' => $tip['id']]);

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
            error_log("[InternalTxDetected] address-match: tip {$tip['id']} confirmed via TX $txid (no payload)");
            App::jsonResponse([
                'ok' => true,
                'status' => 'confirmed',
                'tip_id' => (int) $tip['id'],
                'txid'   => $txid,
                'matched_by' => 'address_amount',
            ]);
        }

        // No pending tip matched — TX was probably an unrelated transfer to one of our addresses.
        App::jsonResponse(['ok' => true, 'status' => 'rejected', 'reason' => 'no_pending_tip_match']);
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
