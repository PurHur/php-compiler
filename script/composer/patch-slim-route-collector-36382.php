<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim RouteCollector stores `?InvocationStrategyInterface $x = null` /
 * `?RouteParserInterface $x = null` then uses `?? new …`. Under AOT those typed null
 * defaults are TYPE_OBJECT slots that are isset/truthy, so `??` never takes the RHS and
 * assigning the hollow pointer silently exits. `instanceof` correctly rejects null
 * pointers (see Object_::emitInstanceOf). AppFactory::create() always omits these args.
 *
 * php-src: Zend/zend_execute.c ZEND_COALESCE / IS_NULL on typed locals.
 *
 * Usage: php script/composer/patch-slim-route-collector-36382.php path/to/RouteCollector.php
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
if (str_contains($text, 'AOT (#36382): typed nullable strategy/parser')) {
    echo "RouteCollector.php already patched (#36382)\n";
    exit(0);
}
$old = <<<'PHP'
        $this->responseFactory = $responseFactory;
        $this->callableResolver = $callableResolver;
        $this->container = $container;
        $this->defaultInvocationStrategy = $defaultInvocationStrategy ?? new RequestResponse();
        $this->routeParser = $routeParser ?? new RouteParser($this);
PHP;
$new = <<<'PHP'
        $this->responseFactory = $responseFactory;
        $this->callableResolver = $callableResolver;
        $this->container = $container;
        // AOT (#36382): typed nullable strategy/parser defaults are truthy object slots;
        // ?? never falls through. instanceof rejects null pointers (Zend-equivalent here).
        $this->defaultInvocationStrategy = $defaultInvocationStrategy instanceof InvocationStrategyInterface
            ? $defaultInvocationStrategy
            : new RequestResponse();
        $this->routeParser = $routeParser instanceof RouteParserInterface
            ? $routeParser
            : new RouteParser($this);
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "RouteCollector ctor pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text));
echo "patched RouteCollector for AOT (#36382)\n";
