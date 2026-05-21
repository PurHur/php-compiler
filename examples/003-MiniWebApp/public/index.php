<?php

declare(strict_types=1);

/**
 * MiniWebApp front controller (issues #67, #210, #246).
 *
 * Lint-first skeleton: class dispatch blockers (#58); __DIR__ includes lint-followed (#462).
 * VM/JIT/AOT serve recipes below expect failure until #67.
 *
 *   ./phpc lint --all examples/003-MiniWebApp
 *   ./phpc serve 127.0.0.1:8080 examples/003-MiniWebApp
 *   curl -s 'http://127.0.0.1:8080/?route=home'
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/Router.php';

$route = $_GET['route'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$knownRoutes = ['home', 'hello', 'contact', 'api/status'];
foreach ($knownRoutes as $known) {
    if ($known === $route) {
        break;
    }
}

$router = new Router($config);
$router->dispatch($method, $route);
