<?php

declare(strict_types=1);

/**
 * Maintainer gap: ReflectionProperty PHP 8.4 hook/lazy APIs phantom on 8.2 reference profile (#17493).
 *
 * Zend 8.2: method_exists() false for hasHooks/skipLazyInitialization.
 * VM must match until PHP_COMPILER_PROFILE=8.4.
 */

$methods = [
    'hasHook',
    'hasHooks',
    'getHook',
    'getHooks',
    'skipLazyInitialization',
    'isLazy',
];

foreach ($methods as $method) {
    if (method_exists(ReflectionProperty::class, $method)) {
        echo "fail: {$method} visible on reference profile\n";
        exit(1);
    }
}

echo "ok\n";
