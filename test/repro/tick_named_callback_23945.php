<?php

declare(strict_types=1);

/**
 * #23945 — Zend stub names callback (not function_name); named calls must resolve.
 */
foreach (['register_tick_function', 'unregister_tick_function'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $bits = [];
    foreach ($rf->getParameters() as $p) {
        $bits[] = $p->getName().($p->isVariadic() ? '...' : '');
    }
    echo $fn, '=', implode(',', $bits), "\n";
}

$cb = static function (): void {};
register_tick_function(callback: $cb);
unregister_tick_function(callback: $cb);
echo "named ok\n";
