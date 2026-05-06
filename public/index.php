<?php
/**
 * KasTip — front controller.
 * Single entry point for everything except static assets.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use KasTip\App;
use KasTip\Router;

$r = new Router();

// ─── health / status ─────────────────────────────────────────────────────────
$r->get('/api/health', function () {
    App::jsonResponse([
        'ok' => true,
        'time' => gmdate('c'),
        'env' => App::config('app.env'),
    ]);
});

// ─── auth ─────────────────────────────────────────────────────────────────
$r->get('/api/auth/x/start',    fn() => \KasTip\Auth\XOauth::start());
$r->get('/api/auth/x/callback', fn() => \KasTip\Auth\XOauth::callback());
$r->post('/api/auth/logout',    fn() => \KasTip\Auth\Session::logout());

// Quick "who am I" (sanity check that session/bearer works end-to-end)
$r->get('/api/auth/whoami', function () {
    $s = \KasTip\Auth\Session::current();
    if ($s === null) {
        \KasTip\App::jsonResponse(['authenticated' => false]);
    }
    \KasTip\App::jsonResponse([
        'authenticated' => true,
        'user_id' => $s['user_id'],
        'client_kind' => $s['client_kind'],
    ]);
});

// ─── users ────────────────────────────────────────────────────────────────
$r->get('/api/users/me',          fn() => \KasTip\Api\UsersMe::handle());
$r->put('/api/users/me/settings', fn() => \KasTip\Api\UsersMeSettings::handle());
$r->post('/api/users/register',   fn() => \KasTip\Api\UsersRegister::handle());
$r->get('/api/users/lookup',      fn() => \KasTip\Api\UsersLookup::handle());

// ─── tips ─────────────────────────────────────────────────────────────────
$r->post('/api/tips/initiate',     fn() => \KasTip\Api\TipsInitiate::handle());
$r->post('/api/tips/confirm',      fn() => \KasTip\Api\TipsConfirm::handle());
$r->get('/api/tips/sent',          fn() => \KasTip\Api\TipsList::sent());
$r->get('/api/tips/received',      fn() => \KasTip\Api\TipsList::received());
$r->get('/api/tips/{id}/status',   fn($p) => \KasTip\Api\TipsList::status((int) $p['id']));

// ─── web pages (server-rendered HTML) ─────────────────────────────────────
$r->get('/onboard/address', fn() => \KasTip\Web\Onboard::renderAddressForm());
$r->get('/dashboard',       fn() => \KasTip\Web\Dashboard::render());
// $r->get('/u/{handle}', fn($p) => \KasTip\Web\Profile::render($p['handle']));

// ─── landing ──────────────────────────────────────────────────────────────
$r->get('/', fn() => \KasTip\Web\Landing::render());

$r->dispatch();
