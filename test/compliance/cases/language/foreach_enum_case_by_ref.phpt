--TEST--
Language: foreach by-reference on enum case arrays preserves enum objects (#5657)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::A, E::B];
foreach ($a as &$v) {
    echo get_debug_type($v), "\n";
    $v = E::B;
}
unset($v);
foreach ($a as $x) {
    echo get_debug_type($x), "\n";
}
--EXPECT--
E
E
E
E
