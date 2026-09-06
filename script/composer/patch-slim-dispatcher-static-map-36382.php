<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim Dispatcher createDispatcher — build FastRoute static map from
 * exportFastRouteRows() without FastRouteCollector::addRoute / Std::parse.
 * addRoute's nested `$staticRoutes[$method][$path] = $id` + Std preg_split SEGVs or
 * silent-exits under IncludeHelper, leaving an empty map → Undefined array key "GET".
 * Scalar row assign is Zend-equivalent for static routes (Slim hello /hello).
 *
 * Requires: patch-slim-dispatcher-closure-36382.php already applied.
 *
 * php-src: Zend/zend_vm_def.h ZEND_ASSIGN_DIM; FastRoute static routes are a flat HT.
 *
 * Usage: php script/composer/patch-slim-dispatcher-static-map-36382.php Dispatcher.php
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
if (str_contains($text, 'AOT (#36382): static map from exportFastRouteRows')) {
    echo "Dispatcher.php static map already patched (#36382)\n";
    exit(0);
}

$old = <<<'PHP'
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

$new = <<<'PHP'
        // AOT (#36382): static map from exportFastRouteRows — skip FastRouteCollector
        // addRoute / Std::parse (nested dim assign + preg_split SEGVs / empty map →
        // Undefined array key "GET"). Static-only rows match Zend FastRoute getData().
        $basePath = $this->routeCollector->getBasePath();
        $rows = $this->routeCollector->exportFastRouteRows();
        $static = [];
        foreach ($rows as $row) {
            $method = $row[0];
            $routePath = $basePath . $row[1];
            $id = $row[2];
            if (!isset($static[$method])) {
                $static[$method] = [];
            }
            $static[$method][$routePath] = $id;
        }
        /** @var FastRouteDispatcher $dispatcher */
        $dispatcher = new FastRouteDispatcher([$static, []]);
PHP;

if (!str_contains($text, $old)) {
    fwrite(STDERR, "inline FastRoute registration block not found — apply patch-slim-dispatcher-closure-36382.php first\n");
    exit(1);
}
$patched = str_replace($old, $new, $text, $count);
if (1 !== $count) {
    fwrite(STDERR, "expected 1 static-map rewrite, got {$count}\n");
    exit(1);
}
if (false === file_put_contents($path, $patched)) {
    fwrite(STDERR, "write failed: {$path}\n");
    exit(1);
}
echo "patched Slim Dispatcher static map from export rows for AOT (#36382)\n";
