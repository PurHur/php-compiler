<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim App::__construct uses `$routeResolver ?? new RouteResolver(...)`.
 * When AppFactory passes an omitted typed-null across the call, AOT treats the hollow
 * `__object__*` as coalesce-truthy, so RouteResolver is never constructed and
 * `$dispatcher` stays uninitialized on `$app->handle()` / routing.
 *
 * `instanceof` rejects null pointers (Zend-equivalent; peer RouteCollector / RouteResolver
 * patches). Same for `$callableResolver ?? new CallableResolver` passed to parent.
 *
 * php-src: Zend/zend_vm_def.h ZEND_COALESCE; Zend/zend_execute.c IS_NULL on typed locals.
 *
 * Usage: php script/composer/patch-slim-app-36382.php path/to/App.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} App.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): typed nullable routeResolver')) {
    echo "App.php already patched (#36382)\n";
    exit(0);
}
$old = <<<'PHP'
        parent::__construct(
            $responseFactory,
            $callableResolver ?? new CallableResolver($container),
            $container,
            $routeCollector
        );

        $this->routeResolver = $routeResolver ?? new RouteResolver($this->routeCollector);
PHP;
$new = <<<'PHP'
        // AOT (#36382): typed nullable routeResolver / callableResolver defaults passed
        // across AppFactory→App are coalesce-truthy hollow object slots — ?? never
        // constructs. instanceof rejects null pointers (Zend-equivalent here).
        parent::__construct(
            $responseFactory,
            $callableResolver instanceof CallableResolverInterface
                ? $callableResolver
                : new CallableResolver($container),
            $container,
            $routeCollector
        );

        $this->routeResolver = $routeResolver instanceof RouteResolverInterface
            ? $routeResolver
            : new RouteResolver($this->routeCollector);
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "App::__construct coalesce pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text));
echo "patched App for AOT (#36382)\n";
