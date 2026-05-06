<?php
declare(strict_types=1);

namespace KasTip\Api;

use KasTip\App;
use KasTip\Auth\Session;
use KasTip\Lib\KaspaAddress;
use KasTip\Lib\KaspaApi;
use KasTip\Lib\RateLimit;

/**
 * POST /api/users/register
 *
 * Set or update the current user's Kaspa receiving address.
 *
 * Body (JSON): {"kaspa_address": "kaspa:qpz..."}
 *
 * Validation pipeline:
 *   1. Format check (KaspaAddress::isValidFormat — regex + charset + length)
 *   2. Authoritative check (KaspaApi::addressExists — round-trip to api.kaspa.org)
 *      - true  → accept
 *      - false → reject (bad checksum)
 *      - null  → accept (transient API failure; caller might still be valid)
 *                Spec calls this "graceful degrade". If api.kaspa.org is hard
 *                down for hours, users can still register; we re-validate later.
 *
 * Rate limit: 10 attempts / hour per user (prevents address-rotation abuse).
 */
final class UsersRegister
{
    public static function handle(): void
    {
        $session = Session::requireAuth();
        $userId = $session['user_id'];

        RateLimit::checkOrAbort('users-register', 10, 3600, $userId);

        $payload = self::readJsonBody();
        $address = trim((string) ($payload['kaspa_address'] ?? ''));

        if ($address === '') {
            App::abort(400, 'Missing kaspa_address.');
        }

        // 1. Format
        if (!KaspaAddress::isValidFormat($address)) {
            App::abort(422, 'Invalid Kaspa address format.', 'invalid_format');
        }

        // 2. Authoritative (best-effort)
        $exists = KaspaApi::addressExists($address);
        if ($exists === false) {
            App::abort(422, 'Kaspa address rejected by indexer (bad checksum?).', 'invalid_checksum');
        }
        // null → graceful degrade (api.kaspa.org transiently unavailable). Accept.

        // 3. Persist
        $stmt = App::db()->prepare("
            UPDATE users
            SET kaspa_address = :addr, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(['addr' => $address, 'id' => $userId]);

        App::jsonResponse([
            'ok' => true,
            'kaspa_address' => $address,
            'verified_on_chain' => $exists === true,
        ]);
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
