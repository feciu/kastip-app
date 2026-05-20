<?php
/**
 * KasTip secrets — TEMPLATE.
 *
 * Copy to secrets.php and fill in real values. NEVER commit secrets.php.
 *
 *   cp config/secrets.example.php config/secrets.php
 *   chmod 600 config/secrets.php
 */

return [
    // MariaDB
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'kastip',
        'user' => 'kastip',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // X (Twitter) OAuth — fill in after registering app at developer.x.com
    'x_oauth' => [
        'client_id'     => 'CHANGE_ME',
        'client_secret' => 'CHANGE_ME',  // ignored for Public clients (Native App / SPA)
        'redirect_uri'  => 'https://kastip.app/api/auth/x/callback',
        // X v2 quirk: /2/users/me returns generic 403 when called with only
        // 'users.read'. 'tweet.read users.read' is the minimum that actually
        // works — both scopes are read-only; no write/posting permission is
        // requested or granted.
        'scope'         => 'tweet.read users.read',
        // X Dev Portal "Type of App":
        //   Native App / Single-page App  →  public_client = true  (PKCE only, NO Basic Auth)
        //   Web App, Automated App or Bot →  public_client = false (PKCE + Basic Auth with secret)
        // Sending Basic Auth on a Public client makes X issue an App-only token,
        // which then gets 403 on /users/me. Match this flag to your portal config.
        'public_client' => true,
    ],

    // 32-byte hex random — used to sign session tokens / PKCE state.
    // Generate fresh: `openssl rand -hex 32`
    'session_secret' => 'CHANGE_ME',

    // Kaspa node API base — env-var override per cookbook §1
    'kaspa_api_base' => 'https://api.kaspa.org',

    // Donate address (optional — only if /support page deployed). Cold storage, generated offline.
    'donate_address' => null,  // e.g. 'kaspa:qpz...'

    // Application
    'app' => [
        'env' => 'prod',                  // 'prod' | 'dev'
        'base_url' => 'https://kastip.app',
        'debug' => false,
    ],
];
