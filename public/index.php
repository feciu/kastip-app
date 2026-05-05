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

// ─── auth (X OAuth) — wired in C8 ─────────────────────────────────────────
// $r->get('/api/auth/x/start',    fn() => \KasTip\Auth\XOauth::start());
// $r->get('/api/auth/x/callback', fn() => \KasTip\Auth\XOauth::callback());
// $r->post('/api/auth/logout',    fn() => \KasTip\Auth\Session::logout());

// ─── users ────────────────────────────────────────────────────────────────
// $r->get('/api/users/me',           fn() => \KasTip\Api\UsersMe::handle());
// $r->put('/api/users/me/settings',  fn() => \KasTip\Api\UsersMeSettings::handle());
// $r->post('/api/users/register',    fn() => \KasTip\Api\UsersRegister::handle());
// $r->get('/api/users/lookup',       fn() => \KasTip\Api\UsersLookup::handle());

// ─── tips ─────────────────────────────────────────────────────────────────
// $r->post('/api/tips/initiate',   fn() => \KasTip\Api\TipsInitiate::handle());
// $r->post('/api/tips/confirm',    fn() => \KasTip\Api\TipsConfirm::handle());
// $r->get('/api/tips/sent',        fn() => \KasTip\Api\TipsSent::handle());
// $r->get('/api/tips/received',    fn() => \KasTip\Api\TipsReceived::handle());
// $r->get('/api/tips/{id}/status', fn($p) => \KasTip\Api\TipStatus::handle((int) $p['id']));

// ─── public profile pages (rendered server-side) ──────────────────────────
// $r->get('/u/{handle}', fn($p) => \KasTip\Web\Profile::render($p['handle']));

// ─── landing ──────────────────────────────────────────────────────────────
$r->get('/', function () {
    // Until we build the real landing page, fall through to static index.html.
    // nginx will serve it directly when the request doesn't reach PHP, but if
    // someone hits it through index.php (e.g. /?foo=bar), give them the same.
    $html = __DIR__ . '/index.html';
    if (is_file($html)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($html);
        return;
    }
    KasTip\App::jsonResponse(['ok' => true, 'note' => 'KasTip is live']);
});

$r->dispatch();
