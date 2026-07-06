<?php

declare(strict_types=1);

/**
 * Maintainer repro: ReflectionEnumUnitCase::isDeprecated() since gate regression (#16867, re-#16821).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_enum_unit_case_is_deprecated()
 */

enum E: int {
    #[\Deprecated(message: 'old case', since: '8.4')]
    case A = 1;
    case B = 2;
}

$rA = new ReflectionEnumUnitCase(E::class, 'A');
if (!method_exists($rA, 'isDeprecated')) {
    fwrite(STDERR, "FAIL: ReflectionEnumUnitCase::isDeprecated missing\n");
    exit(1);
}
if (!$rA->isDeprecated()) {
    fwrite(STDERR, "FAIL: case A not deprecated (expected: ok)\n");
    exit(1);
}
$rB = new ReflectionEnumUnitCase(E::class, 'B');
if ($rB->isDeprecated()) {
    fwrite(STDERR, "FAIL: case B deprecated\n");
    exit(1);
}

echo "ok\n";
