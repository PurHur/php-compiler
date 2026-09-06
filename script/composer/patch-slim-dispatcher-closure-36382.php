<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim\Routing\Dispatcher::createDispatcher — avoid
 * `\FastRoute\simpleDispatcher` Closure (bound `$this` / use()-captured row
 * foreach SEGVs under IncludeHelper). Inline RouteCollector fill from
 * exportFastRouteRows() then `new FastRouteDispatcher(...)`.
 *
 * Requires: patch-slim-fastroute-rows-36382.php on Route.php + RouteCollector.php.
 *
 * php-src: Zend/zend_closures.c; FastRoute simpleDispatcher is a thin wrapper.
 *
 * Usage: php script/composer/patch-slim-dispatcher-closure-36382.php path/to/Dispatcher.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} Dispatcher.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): inline FastRoute registration')) {
    echo "Dispatcher.php already patched (#36382)\n";
    exit(0);
}

$old = <<<'PHP'
        $routeDefinitionCallback = function (FastRouteCollector $r): void {
            $basePath = $this->routeCollector->getBasePath();

            foreach ($this->routeCollector->getRoutes() as $route) {
                $r->addRoute($route->getMethods(), $basePath . $route->getPattern(), $route->getIdentifier());
            }
        };

        $cacheFile = $this->routeCollector->getCacheFile();
        if ($cacheFile) {
            /** @var FastRouteDispatcher $dispatcher */
            $dispatcher = \FastRoute\cachedDispatcher($routeDefinitionCallback, [
                'dataGenerator' => GroupCountBased::class,
                'dispatcher' => FastRouteDispatcher::class,
                'routeParser' => new Std(),
                'cacheFile' => $cacheFile,
            ]);
        } else {
            /** @var FastRouteDispatcher $dispatcher */
            $dispatcher = \FastRoute\simpleDispatcher($routeDefinitionCallback, [
                'dataGenerator' => GroupCountBased::class,
                'dispatcher' => FastRouteDispatcher::class,
                'routeParser' => new Std(),
            ]);
        }
PHP;

$new = <<<'PHP'
        // AOT (#36382): inline FastRoute registration — simpleDispatcher Closure +
        // use()-foreach over export rows SEGVs under IncludeHelper. Same effect as
        // FastRoute\simpleDispatcher without the callback.
        $basePath = $this->routeCollector->getBasePath();
        $rows = $this->routeCollector->exportFastRouteRows();
        $frCollector = new FastRouteCollector(new Std(), new GroupCountBased());
        foreach ($rows as $row) {
            // methodsCsv is a single method for the hello fixture; FastRoute accepts string.
            $frCollector->addRoute($row[0], $basePath . $row[1], $row[2]);
        }
        /** @var FastRouteDispatcher $dispatcher */
        $dispatcher = new FastRouteDispatcher($frCollector->getData());
PHP;

if (!str_contains($text, $old)) {
    fwrite(STDERR, "createDispatcher FastRoute block not found in {$path}\n");
    exit(1);
}

$patched = str_replace($old, $new, $text, $count);
if (1 !== $count) {
    fwrite(STDERR, "expected 1 replacement, got {$count} in {$path}\n");
    exit(1);
}
if (false === file_put_contents($path, $patched)) {
    fwrite(STDERR, "write failed: {$path}\n");
    exit(1);
}
echo "patched Slim Dispatcher inline FastRoute registration for AOT (#36382)\n";
