<?php
/**
 * Issue #25503 — ReflectionClass lazy-object APIs withheld on 8.2 reference profile.
 * php-src: Zend/zend_lazy_objects.c, ext/reflection/php_reflection.c (since 8.4.0)
 *
 * Run:
 *   php bin/vm.php test/repro/issue_25503_reflection_lazy_apis_profile.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_25503_reflection_lazy_apis_profile.php
 */
$r = new ReflectionClass(stdClass::class);
foreach ([
    'newLazyGhost',
    'newLazyProxy',
    'isUninitializedLazyObject',
    'resetAsLazyGhost',
    'getLazyInitializer',
] as $m) {
    echo $m, ' ', method_exists($r, $m) ? 'Y' : 'N', "\n";
}
