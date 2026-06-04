--TEST--
Language: foreach by-value over enum case array preserves enum objects (#5595)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
foreach ([E::A, E::B] as $v) {
    echo get_debug_type($v), ' ';
}
echo "\n";
$a = [E::A, E::B];
foreach ($a as &$ref) {
}
unset($ref);
foreach ($a as $v) {
    echo (int) ($v instanceof E), ' ';
}
echo "\n";
--EXPECT--
E E 
1 1 
