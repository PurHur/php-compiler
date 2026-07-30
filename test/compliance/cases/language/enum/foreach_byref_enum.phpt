--TEST--
Language: foreach by-ref on enum case elements preserves enum objects (#10134, zend_enum.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

foreach ([E::A, E::B] as &$v) {
    echo get_debug_type($v), "\n";
    $v = E::B;
    break;
}
echo get_debug_type($v), "\n";
--EXPECT--
E
E
