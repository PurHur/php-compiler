<?php

declare(strict_types=1);

/**
 * array_pad([E::A], N, E::B) — inline enum haystack + pad value (#8883, ext/standard/array.c).
 */
enum E: int
{
    case A = 1;
    case B = 2;
}

$result = array_pad([E::A], 3, E::B);
$expected = [E::A, E::B, E::B];
if ($result !== $expected) {
    echo 'fail shape: ', var_export($result, true), "\n";
    exit(1);
}

foreach ($result as $v) {
    if (!$v instanceof E) {
        echo 'fail type: ', get_debug_type($v), "\n";
        exit(1);
    }
}

echo "ok\n";
