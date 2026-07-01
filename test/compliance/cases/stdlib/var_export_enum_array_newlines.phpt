--TEST--
stdlib var_export() — enum case array elements break line after => (#14262, ext/standard/var.c)
--FILE--
<?php
enum E: int
{
    case A = 1;
    case B = 2;
}

echo var_export([E::A, E::B], true);
?>
--EXPECT--
array (
  0 => 
  \E::A,
  1 => 
  \E::B,
)
