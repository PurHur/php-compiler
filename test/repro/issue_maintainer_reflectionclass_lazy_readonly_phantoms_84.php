<?php

declare(strict_types=1);

/**
 * #28516 — ReflectionClass lazy/readonly helper phantoms vs php-src.
 * php-src stub: newLazyGhost / newLazyProxy + reset/initialize helpers only
 * (ext/reflection/php_reflection.stub.php). No createLazy*, getReadOnlyProperties,
 * getLazyPropertyNames, resetAsLazyObject, getLazyInitializationException, getLazyProxyFactory.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_maintainer_reflectionclass_lazy_readonly_phantoms_84.php
 */
$phantoms = [
    'createLazyGhost',
    'createLazyProxy',
    'getLazyPropertyNames',
    'getReadOnlyProperties',
    'resetAsLazyObject',
    'getLazyInitializationException',
    'getLazyProxyFactory',
];
$real = [
    'newLazyGhost',
    'newLazyProxy',
    'resetAsLazyGhost',
    'resetAsLazyProxy',
    'initializeLazyObject',
    'isUninitializedLazyObject',
    'markLazyObjectAsInitialized',
    'getLazyInitializer',
];

$bad = [];
foreach ($phantoms as $m) {
    if (method_exists(ReflectionClass::class, $m)) {
        $bad[] = $m;
    }
}
$missing = [];
foreach ($real as $m) {
    if (!method_exists(ReflectionClass::class, $m)) {
        $missing[] = $m;
    }
}

if ($bad !== []) {
    echo 'phantoms:', implode(',', $bad), "\n";
    exit(1);
}
if ($missing !== []) {
    echo 'missing:', implode(',', $missing), "\n";
    exit(1);
}

echo "ok\n";