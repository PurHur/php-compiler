<?php

declare(strict_types=1);

/**
 * MiniWebApp front controller (skeleton #67 closed; #210, #489, runtime #539).
 *
 * Primary routing: PATH_INFO after index.php (DevServer #276).
 * Deprecated fallback: ?route= for skeleton-era URLs.
 *
 *   ./phpc lint --all examples/003-MiniWebApp
 *   ./phpc serve 127.0.0.1:8080 examples/003-MiniWebApp
 *   curl -s 'http://127.0.0.1:8080/index.php/hello?name=Dev'
 */

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Router.php';

$route = 'home';
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ('' !== $pathInfo) {
    if (0 === strpos($pathInfo, '/')) {
        $pathInfo = substr($pathInfo, 1);
    }
    if ('' !== $pathInfo) {
        $route = $pathInfo;
    }
} else {
    $queryRoute = $_GET['route'] ?? '';
    if ('' !== $queryRoute) {
        $route = $queryRoute;
    }
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

$router = new Router($config);
if (!$router instanceof Router) {
    http_response_code(500);
    echo "Router bootstrap failed\n";
    exit(1);
}
$router->dispatch($method, $route);
