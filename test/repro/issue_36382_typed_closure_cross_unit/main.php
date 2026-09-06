<?php
/**
 * #36382 — typed closure param method call across an IncludeHelper unit.
 *
 * Incremental AOT (`PHP_COMPILER_AOT_INCREMENTAL_INCLUDES=1`) require_once's class
 * files before the entry body. Closures must not be precompiled before that
 * NestedJIT — otherwise `$r->addRoute()` on a typed `RouteCollector $r` aborts
 * with `undefined method …::addroute()`.
 *
 * php-src: Zend/zend_object_handlers.c zend_std_get_method;
 * Zend/zend_compile.c zend_compile_closure (body after prior includes).
 */
use FastRoute\RouteCollector;

$cb = function (RouteCollector $r): void {
    $r->addRoute('GET', '/hello', 'hello_id');
};
$rc = new RouteCollector();
$cb($rc);
echo isset($rc->routes['GET']['/hello']) ? $rc->routes['GET']['/hello'] : 'MISSING', "\n";
