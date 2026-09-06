<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim RouteResolver stores `?DispatcherInterface $dispatcher = null`
 * then `$this->dispatcher = $dispatcher ?? new Dispatcher(...)`. Under AOT the omitted
 * typed null default + `??` merge can leave the non-nullable typed property uninitialized
 * (`Typed property Slim\Routing\RouteResolver::$dispatcher must not be accessed before
 * initialization` on `$app->handle()`). `instanceof` rejects null pointers (Zend-equivalent
 * here; peer RouteCollector strategy/parser patch).
 *
 * php-src: Zend/zend_vm_def.h ZEND_COALESCE; Zend/zend_execute.c IS_NULL on typed locals.
 *
 * Usage: php script/composer/patch-slim-route-resolver-36382.php path/to/RouteResolver.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} RouteResolver.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): typed nullable dispatcher')) {
    echo "RouteResolver.php already patched (#36382)\n";
    exit(0);
}
$old = <<<'PHP'
    public function __construct(RouteCollectorInterface $routeCollector, ?DispatcherInterface $dispatcher = null)
    {
        $this->routeCollector = $routeCollector;
        $this->dispatcher = $dispatcher ?? new Dispatcher($routeCollector);
    }
PHP;
$new = <<<'PHP'
    public function __construct(RouteCollectorInterface $routeCollector, ?DispatcherInterface $dispatcher = null)
    {
        $this->routeCollector = $routeCollector;
        // AOT (#36382): typed nullable dispatcher default + ?? can leave $this->dispatcher
        // uninitialized under AOT. instanceof rejects null pointers (Zend-equivalent here).
        $this->dispatcher = $dispatcher instanceof DispatcherInterface
            ? $dispatcher
            : new Dispatcher($routeCollector);
    }
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "RouteResolver ctor pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text));
echo "patched RouteResolver for AOT (#36382)\n";
