<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Session;

/**
 * Tip listings + single status. All require auth.
 *
 *   GET /api/tips/sent?limit=20&before=4567       — paginated DESC by id
 *   GET /api/tips/received?limit=20&before=4567   — same shape
 *   GET /api/tips/:id/status                       — single
 *
 * Pagination: cursor-based on tip.id (we have idx_sender_initiated, but id
 * monotonic == initiated_at order). `before` = exclusive upper bound.
 */
final class TipsList
{
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 20;

    public static function sent(): void
    {
        $session = Session::requireAuth();
        self::renderList(
            'sender_user_id',
            $session['user_id']
        );
    }

    public static function received(): void
    {
        $session = Session::requireAuth();
        self::renderList(
            'receiver_user_id',
            $session['user_id']
        );
    }

    public static function status(int $tipId): void
    {
        $session = Session::requireAuth();

        $stmt = App::db()->prepare("
            SELECT id, sender_user_id, receiver_user_id, receiver_x_username,
                   sender_kaspa_address, receiver_kaspa_address,
                   amount_kas, tweet_url, message, status, txid,
                   initiated_at, confirmed_at
            FROM tips WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $tipId]);
        $tip = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tip) App::abort(404, 'Tip not found.');

        // Sender or receiver may view
        if ((int) $tip['sender_user_id'] !== (int) $session['user_id']
            && (int) ($tip['receiver_user_id'] ?? 0) !== (int) $session['user_id']) {
            App::abort(403, 'Not your tip.');
        }

        App::jsonResponse(self::projectTip($tip));
    }

    private static function renderList(string $userColumn, int $userId): void
    {
        $limit = self::clampLimit($_GET['limit'] ?? null);
        $before = isset($_GET['before']) ? (int) $_GET['before'] : 0;

        $sql = "SELECT id, sender_user_id, receiver_user_id, receiver_x_username,
                       sender_kaspa_address, receiver_kaspa_address,
                       amount_kas, tweet_url, message, status, txid,
                       initiated_at, confirmed_at
                FROM tips
                WHERE $userColumn = :uid";
        $params = ['uid' => $userId];
        if ($before > 0) {
            $sql .= " AND id < :before";
            $params['before'] = $before;
        }
        $sql .= " ORDER BY id DESC LIMIT $limit";

        $stmt = App::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $items = array_map([self::class, 'projectTip'], $rows);
        $nextCursor = (count($items) === $limit && !empty($items))
            ? (int) end($items)['id']
            : null;

        App::jsonResponse([
            'items' => $items,
            'next_before' => $nextCursor,
        ]);
    }

    private static function projectTip(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'sender_user_id'      => (int) $r['sender_user_id'],
            'receiver_user_id'    => $r['receiver_user_id'] !== null ? (int) $r['receiver_user_id'] : null,
            'receiver_x_username' => $r['receiver_x_username'],
            'sender_kaspa_address'   => $r['sender_kaspa_address'],
            'receiver_kaspa_address' => $r['receiver_kaspa_address'],
            'amount_kas'   => (float) $r['amount_kas'],
            'tweet_url'    => $r['tweet_url'],
            'message'      => $r['message'],
            'status'       => $r['status'],
            'txid'         => $r['txid'],
            'initiated_at' => $r['initiated_at'],
            'confirmed_at' => $r['confirmed_at'],
        ];
    }

    private static function clampLimit(mixed $raw): int
    {
        $n = (int) $raw;
        if ($n <= 0) return self::DEFAULT_LIMIT;
        if ($n > self::MAX_LIMIT) return self::MAX_LIMIT;
        return $n;
    }
}
