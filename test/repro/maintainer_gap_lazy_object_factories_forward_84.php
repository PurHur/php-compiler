<?php

declare(strict_types=1);

$required = [
    'createLazyGhost',
    'createLazyProxy',
    'class_has_lazy_object_initializer',
    'class_has_lazy_object_uninitializer',
];
foreach ($required as $fn) {
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
