<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Session;
use KasTip\Lib\KaspaAddress;
use KasTip\Lib\KaspaApi;
use KasTip\Lib\RateLimit;

/**
 * PUT /api/users/me/settings
 *
 * Update user-mutable settings. Only the keys present in the request body are
 * updated; absent keys are left as-is.
 *
 * Body (JSON), all optional:
 *   {
 *     "kaspa_address": "kaspa:...",       // re-validated through full pipeline
 *     "auto_reply_enabled": true|false
 *   }
 *
 * Returns the updated user object (same shape as /api/users/me).
 */
final class UsersMeSettings
{
    public static function handle(): void
    {
        $session = Session::requireAuth();
        $userId  = $session['user_id'];

        RateLimit::checkOrAbort('users-settings', 30, 3600, $userId);

        $payload = self::readJsonBody();

        $sets = [];
        $params = ['id' => $userId];

        if (array_key_exists('kaspa_address', $payload)) {
            $address = trim((string) $payload['kaspa_address']);
            if ($address === '') {
                App::abort(422, 'kaspa_address cannot be empty (use settings/delete to clear).');
            }
            if (!KaspaAddress::isValidFormat($address)) {
                App::abort(422, 'Invalid Kaspa address format.', 'invalid_format');
            }
            $exists = KaspaApi::addressExists($address);
            if ($exists === false) {
                App::abort(422, 'Kaspa address rejected by indexer.', 'invalid_checksum');
            }
            $sets[] = 'kaspa_address = :addr';
            $params['addr'] = $address;
        }

        if (array_key_exists('auto_reply_enabled', $payload)) {
            $sets[] = 'auto_reply_enabled = :ar';
            $params['ar'] = $payload['auto_reply_enabled'] ? 1 : 0;
        }

        if (empty($sets)) {
            App::abort(400, 'No updatable fields in body.');
        }

        $sql = 'UPDATE users SET ' . implode(', ', $sets)
             . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        App::db()->prepare($sql)->execute($params);

        // Return fresh user state (delegate to UsersMe by re-running its query).
        UsersMe::handle();
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
