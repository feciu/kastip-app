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
        'client_secret' => 'CHANGE_ME',  // for confidential clients; public client may not need it
        'redirect_uri'  => 'https://kastip.app/api/auth/x/callback',
        'scope'         => 'users.read',  // minimal — NIE 'tweet.read users.read'
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
