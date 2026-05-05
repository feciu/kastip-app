<?php
declare(strict_types=1);

namespace KasTip;

/**
 * Minimal REST router. No external deps.
 *
 *   $r = new Router();
 *   $r->get('/api/users/me', fn() => UsersMe::handle());
 *   $r->post('/api/tips/initiate', fn() => TipsInitiate::handle());
 *   $r->get('/u/{handle}', fn($p) => Profile::handle($p['handle']));
 *   $r->dispatch();
 *
 * Path patterns:
 *   /literal              → exact
 *   /api/tips/{id}        → captures {id}
 *   /api/tips/{id}/status → captures {id}
 *
 * Captured params arrive as the first arg to the handler.
 */
final class Router
{
    /** @var array<string, array<string, callable>> [method => [pattern => handler]] */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void    { $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): void   { $this->add('POST', $pattern, $handler); }
    public function put(string $pattern, callable $handler): void    { $this->add('PUT', $pattern, $handler); }
    public function delete(string $pattern, callable $handler): void { $this->add('DELETE', $pattern, $handler); }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[$method][$pattern] = $handler;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // Strip query string from path, normalize trailing slash (except root)
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // CORS preflight — handle before route matching
        if ($method === 'OPTIONS') {
            $this->sendCorsHeaders();
            http_response_code(204);
            exit;
        }

        $methodRoutes = $this->routes[$method] ?? [];
        foreach ($methodRoutes as $pattern => $handler) {
            $params = $this->matchPattern($pattern, $path);
            if ($params !== null) {
                $this->sendCorsHeaders();
                $handler($params);
                return;
            }
        }

        // Path matched some other method? → 405
        foreach ($this->routes as $m => $patterns) {
            if ($m === $method) continue;
            foreach (array_keys($patterns) as $pattern) {
                if ($this->matchPattern($pattern, $path) !== null) {
                    App::abort(405, "Method $method not allowed for $path");
                }
            }
        }

        // Otherwise → 404
        App::abort(404, "No route for $method $path");
    }

    /**
     * Match path against pattern. Returns captured params array, or null on no match.
     */
    private function matchPattern(string $pattern, string $path): ?array
    {
        // Fast path for exact literal patterns
        if (!str_contains($pattern, '{')) {
            return $pattern === $path ? [] : null;
        }
        // Convert pattern → regex
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (preg_match($regex, $path, $matches)) {
            return array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
        }
        return null;
    }

    private function sendCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = [
            App::baseUrl(),
            'https://www.kastip.app',
        ];
        // Chrome extensions: chrome-extension://{ID}. We'll learn the prod ID on submit;
        // for now allow any chrome-extension origin in dev. Tightened in prod via config.
        $extPattern = '#^(chrome-extension|moz-extension)://[a-z0-9]+$#i';

        if (in_array($origin, $allowed, true) || preg_match($extPattern, $origin)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Max-Age: 86400');
            header('Vary: Origin');
        }
    }
}
