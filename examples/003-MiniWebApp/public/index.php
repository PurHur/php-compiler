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
require __DIR__ . '/../src/Router.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

$router = new Router($config);
$router->handleRequest($method);
