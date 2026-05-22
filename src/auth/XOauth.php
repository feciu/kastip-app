<?php
declare(strict_types=1);

namespace KasTip\Auth;

use KasTip\App;
use PDO;

/**
 * XOauth — OAuth 2.0 PKCE flow for X.
 *
 * Endpoints:
 *   GET /api/auth/x/start[?from=<path>][&client_kind=web|extension][&ext_id=<id>]
 *     → 302 redirect to X authorize URL
 *
 *   GET /api/auth/x/callback?code=...&state=...
 *     → exchange code for token
 *     → fetch /2/users/me
 *     → INSERT/UPDATE users
 *     → create session
 *     → for web: 302 redirect to ?from=... (or /)
 *     → for extension: render minimal HTML with token in postMessage / hash
 *
 * State + code_verifier are stored in oauth_states table (TTL ~10min).
 *
 * Spec sections referenced: 4 (OAuth flow), 8 (security checklist).
 */
final class XOauth
{
    private const AUTHORIZE_URL = 'https://x.com/i/oauth2/authorize';
    private const TOKEN_URL     = 'https://api.x.com/2/oauth2/token';
    private const ME_URL        = 'https://api.x.com/2/users/me';
    private const STATE_TTL_SECONDS = 600; // 10 min

    /**
     * GET /api/auth/x/start
     */
    public static function start(): void
    {
        $cfg = App::config('x_oauth');
        if (empty($cfg['client_id']) || str_starts_with((string) $cfg['client_id'], 'CHANGE_ME')) {
            App::abort(503, 'X OAuth not configured.');
        }

        $clientKind = ($_GET['client_kind'] ?? 'web') === 'extension' ? 'extension' : 'web';
        $extensionId = $_GET['ext_id'] ?? null;
        if ($extensionId !== null && !preg_match('/^[a-z0-9]{1,64}$/i', $extensionId)) {
            $extensionId = null;
        }

        // Determine where to redirect after successful auth.
        //   web:       same-origin path from ?from= (default /)
        //   extension: chromiumapp.org URL from ?ext_redirect= (chrome.identity)
        if ($clientKind === 'extension') {
            $extRedirect = $_GET['ext_redirect'] ?? '';
            // Whitelist: only chromiumapp.org subdomains (chrome.identity.getRedirectURL format)
            if (preg_match('#^https://[a-z]{32}\.chromiumapp\.org(/[A-Za-z0-9_/.-]*)?$#i', $extRedirect)) {
                $from = $extRedirect;
            } else {
                App::abort(400, 'Extension flow requires valid ext_redirect (chromiumapp.org URL).');
            }
        } else {
            $from = $_GET['from'] ?? '/';
            if (!str_starts_with($from, '/')) {
                $from = '/';
            }
        }

        // PKCE: verifier = 43-128 chars URL-safe random; challenge = base64url(sha256(verifier))
        $verifier = self::base64UrlEncode(random_bytes(48));
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));
        $state = bin2hex(random_bytes(32));

        // Store state row
        App::db()->prepare("
            INSERT INTO oauth_states (state, code_verifier, client_kind, extension_id, redirect_after, expires_at)
            VALUES (:s, :v, :k, :e, :r, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
        ")->execute([
            's' => $state,
            'v' => $verifier,
            'k' => $clientKind,
            'e' => $extensionId,
            'r' => $from,
            'ttl' => self::STATE_TTL_SECONDS,
        ]);

        $params = [
            'response_type'         => 'code',
            'client_id'             => $cfg['client_id'],
            'redirect_uri'          => $cfg['redirect_uri'],
            'scope'                 => $cfg['scope'],
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];
        $url = self::AUTHORIZE_URL . '?' . http_build_query($params);
        header("Location: $url", true, 302);
        exit;
    }

    /**
     * GET /api/auth/x/callback?code=...&state=...
     */
    public static function callback(): void
    {
        $code = $_GET['code'] ?? '';
        $state = $_GET['state'] ?? '';
        $error = $_GET['error'] ?? null;

        // Try to recover redirect_after from state row before any cleanup,
        // so we can send the user back where they came from on cancel.
        $pdo = App::db();
        $stateRow = null;
        if ($state !== '') {
            $stmt = $pdo->prepare("
                SELECT code_verifier, client_kind, extension_id, redirect_after
                FROM oauth_states
                WHERE state = :s AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute(['s' => $state]);
            $stateRow = $stmt->fetch(PDO::FETCH_ASSOC);
            // Always cleanup the state row (one-time use, even on error).
            $pdo->prepare("DELETE FROM oauth_states WHERE state = :s")->execute(['s' => $state]);
        }

        if ($error !== null) {
            // User cancelled the OAuth prompt on X — that's not really an error,
            // bring them back to where they started (or landing if state lost).
            if ($error === 'access_denied') {
                $back = ($stateRow && !empty($stateRow['redirect_after']))
                    ? $stateRow['redirect_after']
                    : '/';
                $sep = str_contains($back, '?') ? '&' : '?';
                header("Location: {$back}{$sep}cancelled=1", true, 302);
                exit;
            }
            App::abort(400, "X OAuth error: $error");
        }
        if ($code === '' || $state === '') {
            App::abort(400, 'Missing code or state.');
        }
        if (!$stateRow) {
            App::abort(400, 'Invalid or expired state.');
        }

        $verifier = $stateRow['code_verifier'];
        $clientKind = $stateRow['client_kind'];
        $extensionId = $stateRow['extension_id'];
        $redirectAfter = $stateRow['redirect_after'] ?? '/';

        // ─── exchange code for access_token ─────────────────────────────────
        $cfg = App::config('x_oauth');
        $isPublic = (bool) ($cfg['public_client'] ?? true);
        $extraHeaders = [];
        if (!$isPublic && !empty($cfg['client_secret'])) {
            // Confidential client: Basic Auth with client_id:client_secret
            $extraHeaders[] = 'Authorization: Basic '
                . base64_encode("{$cfg['client_id']}:{$cfg['client_secret']}");
        }
        // Public client (PKCE-only): NO Basic Auth, just client_id in body.
        $tokenResp = self::httpPost(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $cfg['redirect_uri'],
            'code_verifier' => $verifier,
            'client_id'     => $cfg['client_id'],
        ], $extraHeaders);
        if ($tokenResp === null || empty($tokenResp['access_token'])) {
            error_log('[XOauth] token exchange failed: ' . json_encode($tokenResp));
            App::abort(502, 'Token exchange failed.');
        }
        $accessToken = $tokenResp['access_token'];

        // DEBUG: log token shape (mask the token itself)
        error_log(sprintf(
            '[XOauth] token OK: type=%s expires_in=%s scope=%s token_prefix=%s...%s len=%d',
            $tokenResp['token_type'] ?? 'unknown',
            $tokenResp['expires_in'] ?? 'unknown',
            $tokenResp['scope'] ?? '<none>',
            substr($accessToken, 0, 4),
            substr($accessToken, -4),
            strlen($accessToken)
        ));

        // ─── fetch /users/me ───────────────────────────────────────────────
        $me = self::httpGet(self::ME_URL . '?user.fields=profile_image_url,name', [
            "Authorization: Bearer $accessToken",
        ]);
        if ($me === null || empty($me['data']['id'])) {
            error_log('[XOauth] users/me failed: ' . json_encode($me));
            App::abort(502, 'Could not fetch X profile.');
        }
        $xId       = (string) $me['data']['id'];
        $xUsername = strtolower((string) ($me['data']['username'] ?? ''));
        $xName     = (string) ($me['data']['name'] ?? '');
        $xAvatar   = (string) ($me['data']['profile_image_url'] ?? '');

        // ─── upsert user ───────────────────────────────────────────────────
        // First-time users won't have kaspa_address. Spec says it's NOT NULL —
        // we use empty-string placeholder until they finish onboarding via
        // /api/users/register. Frontend redirects them to the address-entry step.
        $stmt = $pdo->prepare("
            INSERT INTO users (x_user_id, x_username, x_display_name, x_avatar_url, kaspa_address)
            VALUES (:id, :u, :n, :a, '')
            ON DUPLICATE KEY UPDATE
                x_username = VALUES(x_username),
                x_display_name = VALUES(x_display_name),
                x_avatar_url = VALUES(x_avatar_url),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['id' => $xId, 'u' => $xUsername, 'n' => $xName, 'a' => $xAvatar]);

        $stmt = $pdo->prepare("SELECT id, kaspa_address FROM users WHERE x_user_id = :id");
        $stmt->execute(['id' => $xId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $userId = (int) $userRow['id'];

        // ─── viral conversion tracking ─────────────────────────────────────
        // If anyone tried to tip this @handle while it was unregistered, mark
        // those invitation rows as converted. Best-effort, non-fatal.
        try {
            $pdo->prepare("
                UPDATE invitations
                SET converted_user_id = :uid, converted_at = NOW()
                WHERE invitee_x_username = :h AND converted_user_id IS NULL
            ")->execute(['uid' => $userId, 'h' => $xUsername]);
        } catch (\Throwable $e) { /* non-fatal */ }

        // ─── create session ────────────────────────────────────────────────
        $token = Session::create($userId, $clientKind, $extensionId);

        // ─── respond ───────────────────────────────────────────────────────
        if ($clientKind === 'extension') {
            // Issue a parallel web-kind session and Set-Cookie on this response,
            // before bouncing to chromiumapp.org. chrome.identity.launchWebAuthFlow
            // popups share the regular browser profile cookie jar, so the cookie
            // persists — any later visit to kastip.app/* in this profile finds the
            // user already signed in, no second OAuth round-trip required.
            Session::create($userId, 'web');

            // Extension flow: redirect to the chromiumapp.org URL with token.
            // chrome.identity.launchWebAuthFlow captures this and returns it
            // to the extension. redirect_after was validated to be chromiumapp.org.
            $sep = str_contains($redirectAfter, '?') ? '&' : '?';
            $needsAddress = $userRow['kaspa_address'] === '' ? '1' : '0';
            $loc = "{$redirectAfter}{$sep}token={$token}&needs_address={$needsAddress}";
            header("Location: $loc", true, 302);
            exit;
        }

        // Web flow: redirect to dashboard or stored redirect_after.
        // If no kaspa_address yet, force them to /onboard/address.
        if ($userRow['kaspa_address'] === '') {
            $redirectAfter = '/onboard/address';
        }
        header("Location: $redirectAfter", true, 302);
        exit;
    }

    /**
     * Render a minimal HTML page for extension OAuth callback.
     */
    private static function renderExtensionCallback(string $token, bool $needsAddress): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $tokenJs = json_encode($token);
        $needsAddrJs = json_encode($needsAddress);
        echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>KasTip — sign-in complete</title></head>
<body style="font-family:system-ui;text-align:center;padding:3rem;background:#0d0f1a;color:#e8eaed">
  <h2 style="background:linear-gradient(135deg,#49e9c9,#2bb89c);-webkit-background-clip:text;color:transparent">KasTip</h2>
  <p>Sign-in complete. You can close this window.</p>
  <script>
    (function() {
      const token = $tokenJs;
      const needsAddress = $needsAddrJs;
      try {
        if (window.opener) {
          window.opener.postMessage({type:'kastip:auth', token, needsAddress}, '*');
        }
      } catch (e) {}
      setTimeout(function() { window.close(); }, 800);
    })();
  </script>
</body></html>
HTML;
        exit;
    }

    /**
     * GC expired states. Call periodically.
     */
    public static function gc(): int
    {
        return (int) App::db()->exec("DELETE FROM oauth_states WHERE expires_at <= NOW()");
    }

    // ─── HTTP helpers ────────────────────────────────────────────────────────

    private static function httpPost(string $url, array $form, array $extraHeaders = []): ?array
    {
        $ch = curl_init($url);
        $headers = array_filter(array_merge([
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $extraHeaders));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'KasTip/1.0',
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $err !== '') {
            error_log("[XOauth] curl error: $err");
            return null;
        }
        if ($status >= 400) {
            error_log("[XOauth] POST $url → $status: " . substr((string) $body, 0, 500));
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function httpGet(string $url, array $extraHeaders = []): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,                            // include response headers in body
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $extraHeaders),
            CURLOPT_USERAGENT      => 'KasTip/1.0',
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            error_log("[XOauth] curl error: $err");
            return null;
        }
        $headers = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);

        if ($status >= 400) {
            $debug = "[" . gmdate('c') . "] GET $url → $status\n"
                   . "--- response headers ---\n$headers"
                   . "--- response body ---\n$body\n"
                   . "============================================\n\n";
            @file_put_contents('/tmp/kastip-xoauth-debug.log', $debug, FILE_APPEND);
            error_log("[XOauth] GET $url → $status (full response in /tmp/kastip-xoauth-debug.log)");
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function base64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
