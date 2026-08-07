<?php

declare(strict_types=1);

/**
 * #28517 — class_has_lazy_object_* free functions are phantoms vs php-src.
 * Under PROFILE≥8.4, lazy introspection stays on ReflectionClass APIs.
 */
foreach (['class_has_lazy_object_initializer', 'class_has_lazy_object_uninitializer'] as $fn) {
    if (function_exists($fn)) {
        echo "phantom:{$fn}\n";
        exit(1);
    }
}

$profile = getenv('PHP_COMPILER_PROFILE');
$forward84 = is_string($profile) && version_compare($profile, '8.4', '>=');
if ($forward84) {
    if (!method_exists(ReflectionClass::class, 'isUninitializedLazyObject')) {
        echo "fail: ReflectionClass::isUninitializedLazyObject missing\n";
        exit(1);
    }
    if (!method_exists(ReflectionClass::class, 'getLazyInitializer')) {
        echo "fail: ReflectionClass::getLazyInitializer missing\n";
        exit(1);
    }
    if (!method_exists(ReflectionClass::class, 'newLazyGhost')) {
        echo "fail: ReflectionClass::newLazyGhost missing\n";
        exit(1);
    }
}

echo "ok\n";
