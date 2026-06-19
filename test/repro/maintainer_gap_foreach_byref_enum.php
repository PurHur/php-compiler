<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

foreach ([E::A, E::B] as &$v) {
    echo get_debug_type($v), "\n";
    $v = E::B;
    break;
}
echo get_debug_type($v), "\n";
