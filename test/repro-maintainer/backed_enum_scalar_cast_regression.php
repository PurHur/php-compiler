<?php
/**
 * Maintainer repro for #6961 — backed enum scalar casts match php-src zend_operators.c.
 *
 * Zend/php-src (8.2+): (int)/(float) on backed enum cases → E_WARNING + legacy object cast 1/1.0;
 * (bool) → true for any enum case object; (string)/strval() → catchable Error.
 * Backing scalars are not used for explicit scalar casts (#5714, #5791, #5508).
 */
enum E: int
{
    case Zero = 0;
    case FortyTwo = 42;
}

enum U
{
    case A;
}

echo 'int: ', @(int) E::FortyTwo, "\n";
echo 'float: ', @(float) E::FortyTwo, "\n";
echo 'bool_zero: ', (bool) E::Zero ? 'true' : 'false', "\n";
echo 'bool_forty_two: ', (bool) E::FortyTwo ? 'true' : 'false', "\n";

try {
    (string) E::FortyTwo;
    echo "string: fail\n";
} catch (Error $e) {
    echo 'string: ', $e->getMessage(), "\n";
}

try {
    strval(E::FortyTwo);
    echo "strval: fail\n";
} catch (Error $e) {
    echo 'strval: ', $e->getMessage(), "\n";
}

try {
    (string) U::A;
    echo "unit_string: fail\n";
} catch (Error $e) {
    echo 'unit_string: ', $e->getMessage(), "\n";
}
