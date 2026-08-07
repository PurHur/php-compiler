<?php

declare(strict_types=1);

/**
 * #28414 / #28517 / #16812 — PROFILE=8.4 keeps ReflectionClass::newLazy*;
 * free createLazy* and class_has_lazy_object_* stay off.
 */
$forbidden = [
    'createLazyGhost',
    'createLazyProxy',
    'createlazyghost',
    'createlazyproxy',
    'class_has_lazy_object_initializer',
    'class_has_lazy_object_uninitializer',
];
foreach ($forbidden as $fn) {
    if (function_exists($fn)) {
        echo "fail: function_exists({$fn}) true under PHP_COMPILER_PROFILE=8.4 (phantom)\n";
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
if (!method_exists(ReflectionClass::class, 'isUninitializedLazyObject')) {
    echo "fail: method_exists(ReflectionClass::class, 'isUninitializedLazyObject') false\n";
    exit(1);
}

echo "ok\n";
