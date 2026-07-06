<?php

declare(strict_types=1);

/**
 * Maintainer repro: ReflectionFunction::isDeprecated() forward profile since gate (#16821, #16867).
 *
 * php-src: ext/reflection/php_reflection.c — ZEND_ACC_DEPRECATED gated by since vs language profile;
 * PHP_COMPILER_PROFILE=8.4 must report deprecated for since: '8.4'.
 */

#[\Deprecated(message: 'old fn', since: '8.4')]
function dep_forward(): void {}

function control_forward(): void {}

$rf = new ReflectionFunction('dep_forward');
if (!method_exists($rf, 'isDeprecated')) {
    fwrite(STDERR, "FAIL: ReflectionFunction::isDeprecated missing on forward profile\n");
    exit(1);
}
if (!$rf->isDeprecated()) {
    fwrite(STDERR, "FAIL: ReflectionFunction::isDeprecated false for since 8.4 on forward profile\n");
    exit(1);
}
$rc = new ReflectionFunction('control_forward');
if ($rc->isDeprecated()) {
    fwrite(STDERR, "FAIL: ReflectionFunction::isDeprecated true for control function\n");
    exit(1);
}

echo "ok\n";
