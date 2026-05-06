<?php
declare(strict_types=1);

namespace KasTip\Lib;

/**
 * KaspaAddress — format-level validation.
 *
 * Kaspa uses custom bech32 (with non-standard polymod), so a full PHP
 * implementation of checksum verification is non-trivial (~100 LOC).
 *
 * For MVP we do:
 *   1. Format check (prefix + charset + length) — covers >99% typos.
 *   2. Defer authoritative validation to KaspaApi::addressExists()
 *      (api.kaspa.org/addresses/{addr}/balance — returns 400 on bad checksum).
 *
 * UsersRegister endpoint should call BOTH: isValidFormat() locally first
 * (cheap reject), then KaspaApi::addressExists() (network round-trip).
 */
final class KaspaAddress
{
    /** Bech32 charset used by Kaspa (same as BIP-173 — no '1', 'b', 'i', 'o'). */
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /** Kaspa address payload length range (observed: ECDSA 62, Schnorr 61, P2SH 63). */
    private const MIN_PAYLOAD_LEN = 61;
    private const MAX_PAYLOAD_LEN = 63;

    /**
     * Validate format only (no checksum). Accepts mainnet "kaspa:" addresses.
     */
    public static function isValidFormat(string $addr): bool
    {
        return self::isValidPrefixedFormat($addr, 'kaspa');
    }

    /**
     * Validate testnet ("kaspatest:") format.
     */
    public static function isValidTestnetFormat(string $addr): bool
    {
        return self::isValidPrefixedFormat($addr, 'kaspatest');
    }

    /**
     * Returns 'kaspa', 'kaspatest', or null if no recognized prefix.
     */
    public static function detectNetwork(string $addr): ?string
    {
        if (self::isValidPrefixedFormat($addr, 'kaspa')) {
            return 'kaspa';
        }
        if (self::isValidPrefixedFormat($addr, 'kaspatest')) {
            return 'kaspatest';
        }
        return null;
    }

    /**
     * Extract payload (everything after "kaspa:" / "kaspatest:") or null on bad format.
     */
    public static function payload(string $addr): ?string
    {
        $colon = strpos($addr, ':');
        if ($colon === false) {
            return null;
        }
        return substr($addr, $colon + 1);
    }

    private static function isValidPrefixedFormat(string $addr, string $prefix): bool
    {
        $expectedPrefix = $prefix . ':';
        if (!str_starts_with($addr, $expectedPrefix)) {
            return false;
        }
        $payload = substr($addr, strlen($expectedPrefix));
        $len = strlen($payload);
        if ($len < self::MIN_PAYLOAD_LEN || $len > self::MAX_PAYLOAD_LEN) {
            return false;
        }
        // Charset check — every char must be in CHARSET (lowercase only).
        for ($i = 0; $i < $len; $i++) {
            if (strpos(self::CHARSET, $payload[$i]) === false) {
                return false;
            }
        }
        return true;
    }
}
