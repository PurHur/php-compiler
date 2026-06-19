--TEST--
Language: dynamic enum case fetch E::{$name} returns enum singleton (#9937, Zend/zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
enum Pure { case X; case Y; }

$names = ['A', 'B'];
$n = $names[0];
$x = E::{$n};
echo 'backed debug_type=', get_debug_type($x), "\n";
echo 'backed is_object=', is_object($x) ? 'yes' : 'no', "\n";
var_export($x === E::A);
echo "\n";
echo 'backed name=', $x->name, "\n";

$m = 'X';
$p = Pure::{$m};
echo 'unit debug_type=', get_debug_type($p), "\n";
var_export($p === Pure::X);
echo "\n";

try {
    E::{'Z'};
    echo "invalid: no error\n";
} catch (Error $e) {
    echo 'invalid: ', $e->getMessage(), "\n";
}
--EXPECT--
backed debug_type=E
backed is_object=yes
true
backed name=A
unit debug_type=Pure
true
invalid: Undefined class constant E::Z
