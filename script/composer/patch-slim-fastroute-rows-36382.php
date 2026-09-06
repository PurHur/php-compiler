<?php

declare(strict_types=1);

/**
 * AOT (#36382): capture FastRoute [methodsCsv, pattern, id] at RouteCollector::map()
 * time (locals are safe). exportFastRouteRows() returns that list untyped — typed
 * getRoutes(): array / Route::getMethods(): array SEGV across IncludeHelper units.
 *
 * php-src: Zend/zend_execute.c ZEND_RETURN for IS_ARRAY.
 *
 * Usage: php script/composer/patch-slim-fastroute-rows-36382.php RouteCollector.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} RouteCollector.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if ('RouteCollector.php' !== basename($path)) {
    fwrite(STDERR, "expected RouteCollector.php, got ".basename($path)."\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): exportFastRouteRows')) {
    echo "RouteCollector.php already patched (#36382)\n";
    exit(0);
}

if (!str_contains($text, 'protected array $routes = [];')) {
    fwrite(STDERR, "routes property not found\n");
    exit(1);
}
$text = str_replace(
    'protected array $routes = [];',
    "protected array \$routes = [];\n\n    /** @var list<array{0: string, 1: string, 2: string}> */\n    protected array \$fastRouteRows = [];",
    $text,
    $cProp
);
if (1 !== $cProp) {
    fwrite(STDERR, "expected 1 routes property expand, got {$cProp}\n");
    exit(1);
}

$oldMap = <<<'PHP'
    public function map(array $methods, string $pattern, $handler): RouteInterface
    {
        $route = $this->createRoute($methods, $pattern, $handler);
        $this->routes[$route->getIdentifier()] = $route;

        $routeName = $route->getName();
        if ($routeName !== null && !isset($this->routesByName[$routeName])) {
            $this->routesByName[$routeName] = $route;
        }

        $this->routeCounter++;

        return $route;
    }
PHP;

$newMap = <<<'PHP'
    public function map(array $methods, string $pattern, $handler): RouteInterface
    {
        $route = $this->createRoute($methods, $pattern, $handler);
        $this->routes[$route->getIdentifier()] = $route;

        // AOT (#36382): capture FastRoute row from map() locals — typed getRoutes /
        // getMethods across IncludeHelper units SEGV or yield empty method lists.
        $csv = '';
        $first = true;
        foreach ($methods as $method) {
            if (!$first) {
                $csv .= ',';
            }
            $first = false;
            $csv .= $method;
        }
        $this->fastRouteRows[] = [$csv, $route->getPattern(), $route->getIdentifier()];

        $routeName = $route->getName();
        if ($routeName !== null && !isset($this->routesByName[$routeName])) {
            $this->routesByName[$routeName] = $route;
        }

        $this->routeCounter++;

        return $route;
    }
PHP;

if (!str_contains($text, $oldMap)) {
    fwrite(STDERR, "map() pattern not found\n");
    exit(1);
}
$text = str_replace($oldMap, $newMap, $text, $cMap);
if (1 !== $cMap) {
    fwrite(STDERR, "expected 1 map() rewrite, got {$cMap}\n");
    exit(1);
}

$oldGet = <<<'PHP'
    public function getRoutes(): array
    {
        return $this->routes;
    }
PHP;
$newGet = <<<'PHP'
    /**
     * AOT (#36382): exportFastRouteRows — untyped scalar rows captured in map().
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function exportFastRouteRows()
    {
        return $this->fastRouteRows;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
PHP;
if (!str_contains($text, $oldGet)) {
    fwrite(STDERR, "getRoutes pattern not found\n");
    exit(1);
}
$text = str_replace($oldGet, $newGet, $text, $cGet);
if (1 !== $cGet) {
    fwrite(STDERR, "expected 1 getRoutes rewrite, got {$cGet}\n");
    exit(1);
}

if (false === file_put_contents($path, $text)) {
    fwrite(STDERR, "write failed\n");
    exit(1);
}
echo "patched RouteCollector.php FastRoute rows helpers (#36382)\n";
