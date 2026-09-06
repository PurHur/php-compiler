<?php

declare(strict_types=1);

/**
 * AOT (#36382): RouteCollectorProxy `$routeCollector ?? new RouteCollector(...)`.
 * Peer of App / RouteResolver typed-nullable coalesce patches.
 *
 * Usage: php script/composer/patch-slim-route-collector-proxy-36382.php path/to/RouteCollectorProxy.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} RouteCollectorProxy.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): typed nullable routeCollector')) {
    echo "RouteCollectorProxy.php already patched (#36382)\n";
    exit(0);
}
$old = <<<'PHP'
        $this->routeCollector = $routeCollector ?? new RouteCollector($responseFactory, $callableResolver, $container);
PHP;
$new = <<<'PHP'
        // AOT (#36382): typed nullable routeCollector default + ?? can skip construction.
        $this->routeCollector = $routeCollector instanceof RouteCollectorInterface
            ? $routeCollector
            : new RouteCollector($responseFactory, $callableResolver, $container);
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "RouteCollectorProxy coalesce pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text));
echo "patched RouteCollectorProxy for AOT (#36382)\n";
