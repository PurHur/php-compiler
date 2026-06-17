<?php

declare(strict_types=1);

$config = require __DIR__.'/config.php';
require_once __DIR__.'/Router.php';

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
$router->dispatch($method, $route);
