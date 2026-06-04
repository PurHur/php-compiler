--TEST--
Language: optional parameter default with enum case singleton (zend_compile.c, #5885)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
function f(E $e = E::A) {
    return $e;
}
var_export([f(), f(E::B)]);
echo (f() === E::A) ? "same\n" : "diff\n";
--EXPECT--
array (
  0 => \E::A,
  1 => \E::B,
)same
