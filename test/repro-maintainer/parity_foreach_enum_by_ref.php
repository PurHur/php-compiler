<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::A, E::B];
foreach ($a as &$v) {
    echo get_debug_type($v), "\n";
}
unset($v);
