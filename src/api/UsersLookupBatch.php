<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Session;
use KasTip\Lib\RateLimit;

/**
 * POST /api/users/lookup-batch
 *
 * Auth required (Bearer). Used by the extension content script to color tip
 * buttons by recipient status before the user clicks — mint for "ready"
 * (has Kaspa address), gray for "invite" (needs claim flow).
 *
 * Body:
 *   {"handles": ["alice", "bob", "carol"]}
 *
 * Response:
 *   {"statuses": {"alice": "ready", "bob": "invite", "carol": "invite"}}
 *
 * Notes:
 *   - Returns "invite" for unknown handles too (no info leak — the same is
 *     learned by clicking the button anyway).
 *   - Max 100 handles per request, deduped, lowercased before lookup.
 *   - Rate-limited generously (60 req/min/user) — power-scrollers covered
 *     by debouncing + cache in the content script.
 */
final class UsersLookupBatch
{
    private const MAX_HANDLES = 100;

    public static function handle(): void
    {
        $session = Session::requireAuth();
        $userId  = $session['user_id'];

        RateLimit::checkOrAbort('lookup-batch', 60, 60, $userId);

        $payload = self::readJsonBody();
        $raw = $payload['handles'] ?? null;
        if (!is_array($raw)) {
            App::abort(400, '`handles` must be an array.');
        }
        if (count($raw) > self::MAX_HANDLES) {
            App::abort(400, 'Maximum ' . self::MAX_HANDLES . ' handles per request.');
        }

        // Normalize: lowercase, strip leading @, validate shape.
        // Build an ordered list of valid normalized handles + dedup set for SQL.
        $statuses = [];
        $normToOriginal = [];
        $uniqueHandles = [];
        foreach ($raw as $h) {
            if (!is_string($h)) continue;
            $norm = ltrim(strtolower(trim($h)), '@');
            if ($norm === '' || !preg_match('/^[a-z0-9_]{1,15}$/', $norm)) continue;
            $statuses[$norm] = 'invite';
            if (!isset($normToOriginal[$norm])) {
                $normToOriginal[$norm] = true;
                $uniqueHandles[] = $norm;
            }
        }

        if (empty($uniqueHandles)) {
            App::jsonResponse(['statuses' => (object) []]);
        }

        // Single SQL: which of these handles have a non-empty kaspa_address.
        $placeholders = [];
        $params = [];
        foreach ($uniqueHandles as $i => $h) {
            $key = ':h' . $i;
            $placeholders[] = $key;
            $params[$key] = $h;
        }
        $sql = 'SELECT x_username FROM users WHERE x_username IN ('
             . implode(',', $placeholders)
             . ") AND kaspa_address != ''";
        $stmt = App::db()->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $statuses[strtolower($row['x_username'])] = 'ready';
        }

        App::jsonResponse(['statuses' => $statuses]);
    }

    private static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            App::abort(400, 'Empty request body.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            App::abort(400, 'Invalid JSON body.');
        }
        return $decoded;
    }
}
