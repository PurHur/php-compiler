<?php

declare(strict_types=1);

/**
 * AOT (#36382): FastRoute RouteCollector::addRoute uses `foreach ((array) $httpMethod as $method)`.
 * The first TYPE_CAST_ARRAY in a Slim IncludeHelper graph inlines standalone get_object_vars
 * native class-id dispatch (JitGetObjectVarsNative) into a module that already has Uri/ParseUrl
 * + FastRoute classes — measured stall of several minutes on a single opcode breadcrumb
 * (`FastRoute\RouteCollector::addRoute:op=N:type=37`).
 *
 * Slim always passes a string method (`GET` / `POST` / …). Rewrite to an is_array ternary that
 * builds a one-element list without CAST_ARRAY — Zend-equivalent for string|array.
 *
 * php-src: Zend/zend_operators.c — convert_to_array; Zend/zend_vm_def.h ZEND_CAST / ZEND_FE_*.
 *
 * Usage: php script/composer/patch-fastroute-array-cast-36382.php path/to/RouteCollector.php
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

if (str_contains($text, 'AOT (#36382): avoid (array) cast in addRoute')) {
    echo "RouteCollector.php array cast already patched (#36382)\n";
    exit(0);
}

$old = <<<'PHP'
        $routeDatas = $this->routeParser->parse($route);
        foreach ((array) $httpMethod as $method) {
            foreach ($routeDatas as $routeData) {
                $this->dataGenerator->addRoute($method, $routeData, $handler);
            }
        }
PHP;

$new = <<<'PHP'
        $routeDatas = $this->routeParser->parse($route);
        // AOT (#36382): avoid (array) cast in addRoute — TYPE_CAST_ARRAY mid-IncludeHelper
        // inlines JitGetObjectVarsNative class-id dispatch and stalls Slim AOT for minutes.
        // String|array httpMethod → list without CAST_ARRAY (Zend-equivalent for Slim).
        $methods = is_array($httpMethod) ? $httpMethod : [$httpMethod];
        foreach ($methods as $method) {
            foreach ($routeDatas as $routeData) {
                $this->dataGenerator->addRoute($method, $routeData, $handler);
            }
        }
PHP;

if (!str_contains($text, $old)) {
    fwrite(STDERR, "addRoute (array) foreach pattern not found in {$path}\n");
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

echo "patched FastRoute RouteCollector::addRoute array cast for AOT (#36382)\n";
