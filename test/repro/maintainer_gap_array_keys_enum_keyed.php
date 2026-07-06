<?php

declare(strict_types=1);

/**
 * Maintainer repro: array_keys/array_key_first/array_key_last on enum-keyed arrays (#9871).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_keys)
 */
enum E: int
{
    case A = 1;
    case B = 2;
}

$a = [];
$a[E::A] = 'x';
$a[E::B] = 'y';

$keys = array_keys($a);
if (2 !== count($keys)) {
    echo 'fail: expected 2 keys, got ', count($keys), "\n";
    exit(1);
}
if (!($keys[0] === E::A && $keys[1] === E::B)) {
    echo "fail: array_keys did not preserve enum case identity\n";
    exit(1);
}

$first = array_key_first($a);
$last = array_key_last($a);
if (!($first === E::A && $last === E::B)) {
    echo "fail: array_key_first/last enum identity\n";
    exit(1);
}

echo "ok\n";
