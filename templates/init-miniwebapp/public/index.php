<?php

declare(strict_types=1);

/**
 * MiniWebApp-style front controller (phpc init --profile miniwebapp).
 *
 * Primary routing: PATH_INFO after index.php.
 * Fallback: ?route= for quick curls without PATH_INFO.
 *
 *   phpc lint --all .
 *   phpc serve 127.0.0.1:8080 .
 *   curl -s 'http://127.0.0.1:8080/index.php?route=home'
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/Router.php';

$route = 'home';
if (isset($_SERVER['PATH_INFO'])) {
    $pathInfo = $_SERVER['PATH_INFO'];
    if ('' !== $pathInfo) {
        if (0 === strpos($pathInfo, '/')) {
            $pathInfo = substr($pathInfo, 1);
        }
        if ('' !== $pathInfo) {
            $route = $pathInfo;
        }
    }
} elseif (isset($_GET['route'])) {
    $queryRoute = $_GET['route'];
    if ('' !== $queryRoute) {
        $route = $queryRoute;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$router = new Router($config);
$router->dispatch($method, $route);
