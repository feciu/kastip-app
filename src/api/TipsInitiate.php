<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Session;
use KasTip\Lib\Payload;
use KasTip\Lib\RateLimit;

/**
 * POST /api/tips/initiate
 *
 * Body (JSON):
 *   {
 *     "receiver_handle": "janek_xyz",
 *     "amount_kas": 5.0,
 *     "tweet_url": "https://x.com/janek_xyz/status/...",  (optional)
 *     "message": "Great post!"                              (optional)
 *   }
 *
 * Two response shapes — registered receiver vs unregistered (invitation flow).
 *
 * Registered:
 *   {
 *     "tip_id": 4567,
 *     "receiver_status": "registered",
 *     "receiver_address": "kaspa:qpz...",
 *     "amount_sompi": 500000000,
 *     "amount_kas": 5.0,
 *     "payload": "kastip:v1:tip:4567:1730000000:abc12345",
 *     "qr_uri": "kaspa:qpz...?amount=5&label=tip-to-janek_xyz"
 *   }
 *
 * Unregistered (invitation flow — no TX, just a viral hook):
 *   {
 *     "tip_id": null,
 *     "receiver_status": "unregistered",
 *     "invitation": {
 *       "invite_token": "abc123def456...",
 *       "invite_url": "https://kastip.app/u/janek_xyz?invite=abc123...",
 *       "suggested_reply": "Hey @janek_xyz! Tried to tip you 5 KAS via @kastipapp ⚡ ..."
 *     }
 *   }
 */
final class TipsInitiate
{
    private const MIN_TIP_KAS    = 0.5;
    private const SOMPI_PER_KAS  = 100_000_000;
    private const HANDLE_REGEX   = '/^[a-z0-9_]{1,15}$/';

    public static function handle(): void
    {
        $session = Session::requireAuth();
        $senderId = $session['user_id'];

        // Sender-side rate limit: 10/min, 100/day
        RateLimit::checkOrAbort('tips-initiate-min', 10, 60, $senderId);
        RateLimit::checkOrAbort('tips-initiate-day', 100, 86400, $senderId);

        $payload = self::readJsonBody();

        $handle = strtolower(ltrim(trim((string) ($payload['receiver_handle'] ?? '')), '@'));
        if (!preg_match(self::HANDLE_REGEX, $handle)) {
            App::abort(422, 'Invalid receiver_handle.', 'invalid_handle');
        }

        $amountKas = (float) ($payload['amount_kas'] ?? 0);
        if (!is_finite($amountKas) || $amountKas < self::MIN_TIP_KAS) {
            App::abort(422, 'Minimum tip is ' . self::MIN_TIP_KAS . ' KAS.', 'amount_too_small');
        }

        $tweetUrl = self::sanitizeTweetUrl($payload['tweet_url'] ?? null);
        $message  = self::truncate(trim((string) ($payload['message'] ?? '')), 280);

        // Look up sender row (we need their address for the tips row)
        $sender = self::loadSender($senderId);
        if ($sender['kaspa_address'] === '') {
            App::abort(409, 'Set your receiving Kaspa address before tipping (visit /onboard/address).', 'sender_missing_address');
        }

        // Look up receiver — registered iff has non-empty kaspa_address
        $stmt = App::db()->prepare("
            SELECT id, x_username, kaspa_address
            FROM users
            WHERE x_username = :h
            LIMIT 1
        ");
        $stmt->execute(['h' => $handle]);
        $receiver = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Anti-self-tip
        if ($receiver && (int) $receiver['id'] === $senderId) {
            App::abort(422, 'You cannot tip yourself.', 'self_tip');
        }

        if ($receiver && $receiver['kaspa_address'] !== '') {
            self::respondRegistered(
                senderId: $senderId,
                senderAddress: $sender['kaspa_address'],
                receiverId: (int) $receiver['id'],
                receiverHandle: $receiver['x_username'],
                receiverAddress: $receiver['kaspa_address'],
                amountKas: $amountKas,
                tweetUrl: $tweetUrl,
                message: $message
            );
        }

        self::respondUnregistered(
            senderId: $senderId,
            receiverHandle: $handle,
            amountKas: $amountKas,
            tweetUrl: $tweetUrl,
            message: $message
        );
    }

    private static function respondRegistered(
        int $senderId,
        string $senderAddress,
        int $receiverId,
        string $receiverHandle,
        string $receiverAddress,
        float $amountKas,
        ?string $tweetUrl,
        string $message
    ): void {
        $sompi = (int) round($amountKas * self::SOMPI_PER_KAS);

        // Cancel any prior pending/broadcast tipy for the same (sender, receiver)
        // pair. Otherwise repeated 'Show QR' clicks pile up unmatched intents
        // and confuse the watcher's FIFO matching (it would credit the oldest
        // when the user actually sent for the newest).
        App::db()->prepare("
            UPDATE tips
            SET status = 'cancelled'
            WHERE sender_user_id = :s
              AND receiver_user_id = :ri
              AND status IN ('pending', 'broadcast')
              AND txid IS NULL
        ")->execute(['s' => $senderId, 'ri' => $receiverId]);

        // Insert tip row in 'pending' state
        $stmt = App::db()->prepare("
            INSERT INTO tips
                (sender_user_id, sender_kaspa_address, receiver_x_username,
                 receiver_user_id, receiver_kaspa_address, amount_kas,
                 tweet_url, message, status)
            VALUES
                (:s, :sa, :rh, :ri, :ra, :amt, :tu, :msg, 'pending')
        ");
        $stmt->execute([
            's'   => $senderId,
            'sa'  => $senderAddress,
            'rh'  => $receiverHandle,
            'ri'  => $receiverId,
            'ra'  => $receiverAddress,
            'amt' => number_format($amountKas, 8, '.', ''),
            'tu'  => $tweetUrl,
            'msg' => $message !== '' ? $message : null,
        ]);
        $tipId = (int) App::db()->lastInsertId();

        $payloadStr = Payload::buildTip($tipId);

        // Persist payload so /confirm can verify hash without recomputing
        App::db()->prepare("UPDATE tips SET payload = :p WHERE id = :id")
            ->execute(['p' => $payloadStr, 'id' => $tipId]);

        $qrUri = self::buildKaspaUri($receiverAddress, $amountKas, "tip-to-$receiverHandle");

        App::jsonResponse([
            'tip_id'           => $tipId,
            'receiver_status'  => 'registered',
            'receiver_handle'  => $receiverHandle,
            'receiver_address' => $receiverAddress,
            'amount_sompi'     => $sompi,
            'amount_kas'       => $amountKas,
            'payload'          => $payloadStr,
            'qr_uri'           => $qrUri,
        ]);
    }

    private static function respondUnregistered(
        int $senderId,
        string $receiverHandle,
        float $amountKas,
        ?string $tweetUrl,
        string $message
    ): void {
        // Reuse existing pending invitation for this (inviter, invitee) pair if fresh (last 24h),
        // otherwise create a new one. This keeps invite_url stable when sender retries.
        $pdo = App::db();
        $stmt = $pdo->prepare("
            SELECT invite_token FROM invitations
            WHERE inviter_user_id = :inv AND invitee_x_username = :iv AND clicked_at IS NULL
              AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['inv' => $senderId, 'iv' => $receiverHandle]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $token = $existing['invite_token'];
        } else {
            $token = bin2hex(random_bytes(16));  // 32 hex chars
            $pdo->prepare("
                INSERT INTO invitations
                    (invite_token, inviter_user_id, invitee_x_username,
                     intended_amount_kas, tweet_url, message)
                VALUES
                    (:t, :inv, :iv, :amt, :tu, :msg)
            ")->execute([
                't'   => $token,
                'inv' => $senderId,
                'iv'  => $receiverHandle,
                'amt' => number_format($amountKas, 8, '.', ''),
                'tu'  => $tweetUrl,
                'msg' => $message !== '' ? $message : null,
            ]);
        }

        $inviteUrl = App::baseUrl() . "/u/$receiverHandle?invite=$token";
        $reply = sprintf(
            "Hey @%s! Tried to tip you %s KAS via @kastipapp ⚡ — sign up to claim: %s",
            $receiverHandle,
            rtrim(rtrim(number_format($amountKas, 4, '.', ''), '0'), '.'),
            $inviteUrl
        );

        App::jsonResponse([
            'tip_id'          => null,
            'receiver_status' => 'unregistered',
            'invitation' => [
                'invite_token'    => $token,
                'invite_url'      => $inviteUrl,
                'suggested_reply' => $reply,
            ],
            'message' => "@$receiverHandle doesn't have KasTip yet. Share the invitation reply.",
        ]);
    }

    private static function loadSender(int $senderId): array
    {
        $stmt = App::db()->prepare("SELECT kaspa_address FROM users WHERE id = :id");
        $stmt->execute(['id' => $senderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            App::abort(401, 'Sender not found.');
        }
        return $row;
    }

    private static function buildKaspaUri(string $address, float $amountKas, string $label): string
    {
        $params = http_build_query([
            'amount' => rtrim(rtrim(number_format($amountKas, 8, '.', ''), '0'), '.'),
            'label'  => $label,
        ]);
        return "$address?$params";
    }

    private static function sanitizeTweetUrl(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') return null;
        $url = trim($url);
        if (strlen($url) > 512) return null;
        // Only accept x.com / twitter.com URLs to avoid arbitrary URL injection.
        if (!preg_match('#^https?://(www\.)?(x|twitter)\.com/[^\s]+$#i', $url)) return null;
        return $url;
    }

    private static function truncate(string $s, int $max): string
    {
        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max);
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
