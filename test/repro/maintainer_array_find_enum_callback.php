<?php

declare(strict_types=1);

/** Issue #9038 — array_find() must pass enum case objects to callback (ext/standard/array.c). */
enum E: int
{
    case A = 1;
    case B = 2;
}

$found = array_find([E::A, E::B], function ($v) {
    if (!($v instanceof E)) {
        throw new RuntimeException('expected enum object, got ' . get_debug_type($v));
    }

    return $v === E::B;
});
var_dump($found);
