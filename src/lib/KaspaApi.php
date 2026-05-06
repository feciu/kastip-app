<?php
declare(strict_types=1);

namespace KasTip\Lib;

use KasTip\App;

/**
 * KaspaApi — thin REST wrapper around api.kaspa.org (and any compatible mirror).
 *
 * Used by:
 *   - UsersRegister: addressExists() — authoritative validation after KaspaAddress::isValidFormat().
 *   - TipsConfirm:   getTransaction() — verify TX outputs match what we expect.
 *   - Donations:     getAddressBalance() — for /support page (cached 30-60s per cookbook §5).
 *
 * Defensive against intermittent failures (cookbook §11):
 *   - Reasonable timeouts (5s connect, 10s total)
 *   - Returns null on transient errors instead of throwing — caller decides
 *   - Logs failures with enough context to debug
 *
 * Cache: in-process only (request lifetime). For longer-lived cache (e.g.
 * /support page balance), use a separate caching layer or Cloudflare Workers.
 */
final class KaspaApi
{
    /** @var array<string, array{at:int, value:mixed}> */
    private static array $cache = [];

    /**
     * Check if address exists / is valid per the indexer. Returns:
     *   true  — indexer accepted address (may have 0 balance, may not have transactions yet, but format+checksum OK)
     *   false — indexer rejected as invalid (bad checksum, malformed)
     *   null  — indexer error / network fail (caller should treat as "uncertain")
     */
    public static function addressExists(string $address): ?bool
    {
        $resp = self::get("/addresses/{$address}/balance");
        if ($resp === null) {
            return null;  // network error
        }
        if ($resp['status'] === 200) {
            return true;
        }
        // 400 / 422 from indexer = invalid address format/checksum
        if ($resp['status'] === 400 || $resp['status'] === 422) {
            return false;
        }
        // 404 — depends on indexer; usually means "valid format but no on-chain history".
        // We treat it as exists=true (format valid) — checksum was OK enough to look up.
        if ($resp['status'] === 404) {
            return true;
        }
        // 5xx etc.
        return null;
    }

    /**
     * Get balance in sompi. Cached 30s. Returns null on error.
     */
    public static function getAddressBalance(string $address): ?int
    {
        $cacheKey = "balance:$address";
        $cached = self::cacheGet($cacheKey, 30);
        if ($cached !== null) {
            return $cached;
        }
        $resp = self::get("/addresses/{$address}/balance");
        if ($resp === null || $resp['status'] !== 200) {
            return null;
        }
        $balance = $resp['body']['balance'] ?? null;
        if (!is_int($balance) && !is_string($balance)) {
            return null;
        }
        $sompi = (int) $balance;
        self::cacheSet($cacheKey, $sompi);
        return $sompi;
    }

    /**
     * Get full transaction by ID. Cached 60s (TX is immutable once confirmed).
     * Returns the decoded JSON body, or null if not found / error.
     */
    public static function getTransaction(string $txid): ?array
    {
        $cacheKey = "tx:$txid";
        $cached = self::cacheGet($cacheKey, 60);
        if ($cached !== null) {
            return $cached;
        }
        $resp = self::get("/transactions/{$txid}");
        if ($resp === null || $resp['status'] !== 200) {
            return null;
        }
        $body = $resp['body'];
        if (!is_array($body)) {
            return null;
        }
        self::cacheSet($cacheKey, $body);
        return $body;
    }

    /**
     * Verify a TX has an output matching given address + at least min_sompi.
     * This is the core check for /api/tips/confirm.
     *
     * Returns:
     *   true  — TX exists, has matching output
     *   false — TX exists but no matching output (or amount lower than expected)
     *   null  — TX not found / API error
     */
    public static function verifyTxOutput(string $txid, string $expectedAddress, int $minSompi): ?bool
    {
        $tx = self::getTransaction($txid);
        if ($tx === null) {
            return null;
        }
        $outputs = $tx['outputs'] ?? [];
        if (!is_array($outputs)) {
            return false;
        }
        foreach ($outputs as $out) {
            $addr = $out['script_public_key_address'] ?? $out['address'] ?? null;
            $amount = $out['amount'] ?? null;
            if ($addr === $expectedAddress && is_numeric($amount) && (int) $amount >= $minSompi) {
                return true;
            }
        }
        return false;
    }

    // ─── HTTP layer ──────────────────────────────────────────────────────────

    /**
     * @return array{status:int, body:mixed}|null
     */
    private static function get(string $path): ?array
    {
        $base = rtrim(App::config('kaspa_api_base', 'https://api.kaspa.org'), '/');
        $url = $base . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'KasTip/1.0 (+https://kastip.app)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            error_log("[KaspaApi] curl error fetching $url: $err");
            return null;
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) && $status !== 404) {
            // Non-JSON response on 200/4xx is unexpected; log and treat as null.
            // (404 may have empty body — that's fine.)
            if ($status >= 200 && $status < 600 && $status !== 404) {
                error_log("[KaspaApi] non-JSON response from $url (status $status)");
            }
        }
        return ['status' => $status, 'body' => $decoded ?? []];
    }

    private static function cacheGet(string $key, int $maxAgeSeconds): mixed
    {
        if (!isset(self::$cache[$key])) {
            return null;
        }
        $entry = self::$cache[$key];
        if (time() - $entry['at'] > $maxAgeSeconds) {
            unset(self::$cache[$key]);
            return null;
        }
        return $entry['value'];
    }

    private static function cacheSet(string $key, mixed $value): void
    {
        self::$cache[$key] = ['at' => time(), 'value' => $value];
    }
}
