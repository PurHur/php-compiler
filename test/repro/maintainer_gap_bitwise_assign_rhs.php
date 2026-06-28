<?php

declare(strict_types=1);

/**
 * Maintainer gap: simple assign with bitwise RHS must not warn (Zend silent).
 *
 * @see https://github.com/PurHur/php-compiler/issues/13114
 */

function ok(string $label, mixed $actual, mixed $expected): void
{
    if ($actual === $expected) {
        echo "ok {$label}\n";

        return;
    }
    echo "fail {$label}: got ";
    var_export($actual);
    echo " expected ";
    var_export($expected);
    echo "\n";
}

$x = 1 | 2;
ok('or', $x, 3);

$x = 1 ^ 2;
ok('xor', $x, 3);

$x = 1 & 2;
ok('and', $x, 0);

$x = 1 << 2;
ok('shift left', $x, 4);

$x = 8 >> 1;
ok('shift right', $x, 4);
