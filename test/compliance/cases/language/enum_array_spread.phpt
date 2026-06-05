--TEST--
Language: backed enum cases preserved in array spread (zend_operators.c, #6029)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$src = [E::A, E::B];
$dst = [...$src];
foreach ($dst as $v) {
    echo get_debug_type($v), "\n";
}
enum U { case X; case Y; }
$unit = [U::X, U::Y];
$spread = [...$unit];
foreach ($spread as $v) {
    echo get_debug_type($v), "\n";
}
?>
--EXPECT--
E
E
U
U
