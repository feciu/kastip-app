<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Session;
use KasTip\Lib\KaspaApi;
use KasTip\Lib\RateLimit;

/**
 * POST /api/tips/confirm
 *
 * Body (JSON): {"tip_id": 4567, "txid": "abc123..."}
 *
 * Flow:
 *   1. Sender must own the tip row.
 *   2. Tip must be in 'pending' or 'broadcast' state (idempotent on retry).
 *   3. Fetch TX from api.kaspa.org (KaspaApi::verifyTxOutput).
 *   4. If TX has a matching output (receiver_address, ≥ amount_sompi):
 *        - status='confirmed', txid stored
 *        - users.totals updated (sender.sent, receiver.received)
 *      If TX exists but no matching output:
 *        - status='failed' with note in logs
 *      If api.kaspa.org returns null (transient):
 *        - status='broadcast' (we'll retry later via cron — TODO Etap F)
 *
 * Rate limit: 30/min (sender may retry while TX propagates).
 */
final class TipsConfirm
{
    private const SOMPI_PER_KAS = 100_000_000;

    public static function handle(): void
    {
        $session = Session::requireAuth();
        $senderId = $session['user_id'];

        RateLimit::checkOrAbort('tips-confirm', 30, 60, $senderId);

        $payload = self::readJsonBody();
        $tipId = (int) ($payload['tip_id'] ?? 0);
        $txid  = trim((string) ($payload['txid'] ?? ''));

        if ($tipId <= 0) App::abort(400, 'Missing or invalid tip_id.');
        if (!preg_match('/^[a-f0-9]{32,128}$/i', $txid)) App::abort(400, 'Invalid txid format.');

        $pdo = App::db();
        $stmt = $pdo->prepare("
            SELECT id, sender_user_id, receiver_user_id, receiver_kaspa_address,
                   amount_kas, status, txid AS existing_txid
            FROM tips WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $tipId]);
        $tip = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tip) App::abort(404, 'Tip not found.');
        if ((int) $tip['sender_user_id'] !== $senderId) {
            App::abort(403, 'You do not own this tip.');
        }
        if ($tip['status'] === 'confirmed' && $tip['existing_txid'] === $txid) {
            // Idempotent — already confirmed with this txid.
            App::jsonResponse(['ok' => true, 'status' => 'confirmed', 'note' => 'already_confirmed']);
        }
        if (!in_array($tip['status'], ['pending', 'broadcast'], true)) {
            App::abort(409, "Tip is in state '{$tip['status']}', cannot confirm.", 'bad_state');
        }

        $expectedSompi = (int) round((float) $tip['amount_kas'] * self::SOMPI_PER_KAS);
        $verified = KaspaApi::verifyTxOutput($txid, $tip['receiver_kaspa_address'], $expectedSompi);

        if ($verified === null) {
            // Transient — mark as broadcast and let cron re-verify later.
            $pdo->prepare("UPDATE tips SET status='broadcast', txid=:t WHERE id=:id")
                ->execute(['t' => $txid, 'id' => $tipId]);
            App::jsonResponse([
                'ok' => true,
                'status' => 'broadcast',
                'note' => 'verification_pending',
                'message' => 'TX recorded; will re-verify when indexer is reachable.',
            ]);
        }

        if ($verified === false) {
            // TX exists but no matching output to receiver.
            $pdo->prepare("UPDATE tips SET status='failed', txid=:t WHERE id=:id")
                ->execute(['t' => $txid, 'id' => $tipId]);
            error_log(sprintf(
                '[TipsConfirm] TX %s does not match expected output (tip_id=%d, expected_addr=%s, expected_sompi=%d)',
                $txid, $tipId, $tip['receiver_kaspa_address'], $expectedSompi
            ));
            App::abort(422, 'TX does not contain expected output to receiver.', 'tx_mismatch');
        }

        // verified === true → mark confirmed + update totals atomically
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
            ")->execute(['amt' => $tip['amount_kas'], 'id' => $senderId]);

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
            'txid' => $txid,
        ]);
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
