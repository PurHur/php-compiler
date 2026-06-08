--TEST--
range() with backed enum operands warns and does not expand backing ints (#5537)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 3;
}
var_export(@range(E::A, E::B));
echo "\n";
var_export(@range(E::A, 3));
echo "\n";
var_export(@range(1, E::B));
echo "\n";
@range(E::A, E::B);
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";
--EXPECT--
array (
  0 => 1,
)
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  0 => 1,
)
warning: Object of class E could not be converted to int
