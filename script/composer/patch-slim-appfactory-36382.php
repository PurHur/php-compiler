<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim AppFactory::create uses
 *   return new App(self::determineResponseFactory(), $x ?? static::$x, …);
 * php-cfg places `self::make()`-shaped args after TYPE_NEW with EXEC_NORETURN and
 * duplicate ARG_SEND slots, so the construct never receives the ResponseFactory
 * (SIGSEGV / abort before App::__construct body). Spill args to temps first
 * (Zend-equivalent; peer RouteCollector / StreamTrait patches).
 *
 * php-src: Zend/zend_vm_def.h ZEND_NEW / ZEND_SEND_VAL / ZEND_DO_FCALL.
 *
 * Usage: php script/composer/patch-slim-appfactory-36382.php path/to/AppFactory.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} AppFactory.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): spill AppFactory::create args')) {
    echo "AppFactory.php already patched (#36382)\n";
    exit(0);
}
$old = <<<'PHP'
    ): App {
        static::$responseFactory = $responseFactory ?? static::$responseFactory;
        return new App(
            self::determineResponseFactory(),
            $container ?? static::$container,
            $callableResolver ?? static::$callableResolver,
            $routeCollector ?? static::$routeCollector,
            $routeResolver ?? static::$routeResolver,
            $middlewareDispatcher ?? static::$middlewareDispatcher
        );
    }
PHP;
$new = <<<'PHP'
    ): App {
        // AOT (#36382): spill AppFactory::create args to temps before `new App` —
        // inline `determineResponseFactory()` / `?? static::$x` as NEW operands
        // mis-wires ARG_SEND (duplicate slots / EXEC_NORETURN) under php-cfg.
        static::$responseFactory = $responseFactory ?? static::$responseFactory;
        $resolvedResponseFactory = self::determineResponseFactory();
        $resolvedContainer = $container ?? static::$container;
        $resolvedCallableResolver = $callableResolver ?? static::$callableResolver;
        $resolvedRouteCollector = $routeCollector ?? static::$routeCollector;
        $resolvedRouteResolver = $routeResolver ?? static::$routeResolver;
        $resolvedMiddlewareDispatcher = $middlewareDispatcher ?? static::$middlewareDispatcher;
        return new App(
            $resolvedResponseFactory,
            $resolvedContainer,
            $resolvedCallableResolver,
            $resolvedRouteCollector,
            $resolvedRouteResolver,
            $resolvedMiddlewareDispatcher
        );
    }
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "AppFactory::create pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text));
echo "patched AppFactory for AOT (#36382)\n";
