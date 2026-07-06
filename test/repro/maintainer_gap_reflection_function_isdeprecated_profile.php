<?php

declare(strict_types=1);

/**
 * Maintainer repro: ReflectionFunction::isDeprecated() on 8.2 profile (#16359).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_function_is_deprecated() gated by PHP_VERSION_ID >= 80400.
 */

#[\Deprecated(message: 'old fn', since: '8.4')]
function dep(): void {}

function control(): void {}

$rf = new ReflectionFunction('dep');
if (!method_exists($rf, 'isDeprecated')) {
    fwrite(STDERR, "FAIL: ReflectionFunction::isDeprecated missing on reference profile\n");
    exit(1);
}
if ($rf->isDeprecated()) {
    fwrite(STDERR, "FAIL: ReflectionFunction::isDeprecated true for #[\\Deprecated] on reference profile\n");
    exit(1);
}
$rc = new ReflectionFunction('control');
if ($rc->isDeprecated()) {
    fwrite(STDERR, "FAIL: ReflectionFunction::isDeprecated true for control function\n");
    exit(1);
}

echo "ok\n";
