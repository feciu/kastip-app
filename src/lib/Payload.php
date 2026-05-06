<?php
declare(strict_types=1);

namespace KasTip\Lib;

use KasTip\App;

/**
 * Payload — embeds tip metadata in Kaspa TX `payload` field.
 *
 * Format (kaspa-cookbook §4):
 *   kastip:v1:tip:<tip_id>:<unix_ts>:<hash>
 *
 *   prefix    = "kastip"          (filter for our app)
 *   version   = "v1"              (bump on format change)
 *   type      = "tip"             ("donate", "invite" later if needed)
 *   tip_id    = INT (DB row id)   (lets us look up the tip)
 *   unix_ts   = INT (creation)    (10 digits, used in hash)
 *   hash      = 8-hex chars       (first 8 of SHA256(fields:ts:SECRET))
 *
 * Hash gives us anti-tampering — without session_secret an attacker can't
 * craft a valid payload for a fake tip_id. Backend verifies the hash on
 * /api/tips/confirm before accepting.
 *
 * Length budget: "kastip:v1:tip:9999999999:1730000000:abc12345" = 45 chars.
 * Kaspa payload limit is generous (1KB+), so we have plenty of headroom.
 */
final class Payload
{
    public const PREFIX = 'kastip';
    public const VERSION = 'v1';
    public const TYPE_TIP = 'tip';

    /**
     * Build payload string for a tip.
     */
    public static function buildTip(int $tipId, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $fields = self::TYPE_TIP . ':' . $tipId;
        $hash = self::computeHash($fields, $ts);
        return sprintf('%s:%s:%s:%d:%s', self::PREFIX, self::VERSION, $fields, $ts, $hash);
    }

    /**
     * Parse + verify a payload. Returns the parts on success, null on bad format/hash.
     *
     * @return array{type:string, tip_id:int, ts:int}|null
     */
    public static function parseTip(string $payload): ?array
    {
        // kastip:v1:tip:<id>:<ts>:<hash>
        $parts = explode(':', $payload);
        if (count($parts) !== 6) {
            return null;
        }
        [$prefix, $version, $type, $tipIdStr, $tsStr, $hash] = $parts;
        if ($prefix !== self::PREFIX) return null;
        if ($version !== self::VERSION) return null;
        if ($type !== self::TYPE_TIP) return null;
        if (!ctype_digit($tipIdStr)) return null;
        if (!ctype_digit($tsStr)) return null;

        $expected = self::computeHash($type . ':' . $tipIdStr, (int) $tsStr);
        if (!hash_equals($expected, $hash)) {
            return null;
        }
        return [
            'type' => $type,
            'tip_id' => (int) $tipIdStr,
            'ts' => (int) $tsStr,
        ];
    }

    /**
     * Verify timestamp is fresh — within ±N seconds. Kaspa TX may sit in
     * mempool for a while so we allow a generous window.
     */
    public static function isTimestampFresh(int $ts, int $maxAgeSeconds = 3600): bool
    {
        $now = time();
        return $ts <= $now + 60 && $ts >= $now - $maxAgeSeconds;
    }

    private static function computeHash(string $fields, int $ts): string
    {
        $secret = (string) App::config('session_secret', '');
        if ($secret === '' || $secret === 'CHANGE_ME') {
            throw new \RuntimeException('session_secret not configured');
        }
        $material = $fields . ':' . $ts . ':' . $secret;
        return substr(hash('sha256', $material), 0, 8);
    }
}
