<?php
/**
 * KasTip bootstrap — pierwsza rzecz wczytywana przez public/index.php i CLI scripts.
 *
 *   require_once __DIR__ . '/../src/bootstrap.php';
 *
 * Po wczytaniu dostępne:
 *   KasTip\App::config()           → array (z config/secrets.php)
 *   KasTip\App::config('db.user')  → dot-path lookup
 *   KasTip\App::db()               → PDO singleton (lazy)
 *   KasTip\App::isProd()           → bool
 *   KasTip\App::jsonResponse($data, $status = 200)  → echo + exit
 *   KasTip\App::abort($status, $message = null)     → echo + exit
 *
 * Klasy z namespace KasTip\{Lib,Auth,Api,Models}\* są autoloadowane.
 */

declare(strict_types=1);

// ─── timezone ────────────────────────────────────────────────────────────────
date_default_timezone_set('UTC');

// ─── autoloader (PSR-4-ish, namespace KasTip\* → src/{lowercase}/*) ──────────
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'KasTip\\')) {
        return;
    }
    $rel = substr($class, strlen('KasTip\\'));
    // Lib\Foo → lib/Foo
    $parts = explode('\\', $rel);
    if (count($parts) >= 2) {
        $parts[0] = strtolower($parts[0]);
    }
    $path = __DIR__ . '/' . implode('/', $parts) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// ─── load config (must exist) ────────────────────────────────────────────────
$configPath = __DIR__ . '/../config/secrets.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "Server misconfigured: secrets.php missing.";
    exit(1);
}

\KasTip\App::loadConfig(require $configPath);

// ─── error handling ──────────────────────────────────────────────────────────
if (\KasTip\App::isProd()) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

set_exception_handler(function (\Throwable $e): void {
    $isProd = \KasTip\App::isProd();
    error_log(sprintf(
        '[KasTip] Uncaught %s: %s in %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'error' => 'internal_error',
        'message' => $isProd ? 'An internal error occurred.' : $e->getMessage(),
        'trace' => $isProd ? null : $e->getTraceAsString(),
    ]);
    exit(1);
});
