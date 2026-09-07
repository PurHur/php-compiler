<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim CallableResolver::resolveRoute(Closure) goes through
 * resolveByPredicate → is_callable → bindToContainer. Under AOT that path SEGVs
 * for route Closures registered via `$app->get('/hello', function…)`. Short-circuit
 * Closure (and other already-callable) values to bindToContainer only — Zend-equivalent
 * for the default AppFactory null container (bindTo is a no-op).
 *
 * php-src: Zend/zend_closures.c; zend_is_callable_ex for Closure objects.
 *
 * Usage: php script/composer/patch-slim-callable-resolver-36382.php CallableResolver.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} CallableResolver.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): Closure resolveRoute short-circuit')) {
    echo "CallableResolver.php already patched (#36382)\n";
    exit(0);
}
$old = <<<'PHP'
    public function resolveRoute($toResolve): callable
    {
        return $this->resolveByPredicate($toResolve, [$this, 'isRoute'], 'handle');
    }
PHP;
$new = <<<'PHP'
    public function resolveRoute($toResolve): callable
    {
        // AOT (#36382): Closure resolveRoute short-circuit — avoid resolveByPredicate
        // is_callable/bindToContainer on route Closures (SEGV under AOT after ROUTE-ADV).
        if ($toResolve instanceof \Closure) {
            if ($this->container) {
                /** @var \Closure $toResolve */
                $bound = $toResolve->bindTo($this->container);
                return $bound instanceof \Closure ? $bound : $toResolve;
            }
            return $toResolve;
        }
        if (is_callable($toResolve)) {
            return $this->bindToContainer($toResolve);
        }
        return $this->resolveByPredicate($toResolve, [$this, 'isRoute'], 'handle');
    }
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "resolveRoute pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text));
echo "patched CallableResolver for AOT (#36382)\n";
