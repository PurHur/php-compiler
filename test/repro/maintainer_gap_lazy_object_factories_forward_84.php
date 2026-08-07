<?php

declare(strict_types=1);

/**
 * #28414 / #16812 — PROFILE=8.4 keeps ReflectionClass::newLazy*; free createLazy* stay off.
 */
$forbidden = [
    'createLazyGhost',
    'createLazyProxy',
    'createlazyghost',
    'createlazyproxy',
];
foreach ($forbidden as $fn) {
    if (function_exists($fn)) {
        echo "fail: function_exists({$fn}) true under PHP_COMPILER_PROFILE=8.4 (phantom)\n";
        exit(1);
    }
}

// Probes that are still advertised with the ReflectionClass gate (not free createLazy*).
foreach (['class_has_lazy_object_initializer', 'class_has_lazy_object_uninitializer'] as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists({$fn}) false under PHP_COMPILER_PROFILE=8.4\n";
        exit(1);
    }
}

if (!method_exists(ReflectionClass::class, 'newLazyGhost')) {
    echo "fail: method_exists(ReflectionClass::class, 'newLazyGhost') false\n";
    exit(1);
}
if (!method_exists(ReflectionClass::class, 'newLazyProxy')) {
    echo "fail: method_exists(ReflectionClass::class, 'newLazyProxy') false\n";
    exit(1);
}

echo "ok\n";
