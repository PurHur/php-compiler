<?php

declare(strict_types=1);

/**
 * Maintainer repro: ReflectionClassConstant::isDeprecated() on #[\Deprecated] class constants (#16820).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_class_constant_is_deprecated()
 */

class C {
    #[\Deprecated(message: 'Old const', since: '8.4')]
    public const X = 1;
    public const Y = 2;
}

$rc = new ReflectionClassConstant(C::class, 'X');
if (!$rc->isDeprecated()) {
    fwrite(STDERR, "not_deprecated\n");
    exit(1);
}
$control = new ReflectionClassConstant(C::class, 'Y');
if ($control->isDeprecated()) {
    fwrite(STDERR, "FAIL: control constant deprecated\n");
    exit(1);
}

echo "deprecated\n";
