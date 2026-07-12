--TEST--
Language: BackedEnum::cases() returns enum case objects, spread preserves cases (#5530, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
var_export(E::cases());
echo "\n";
var_export([...E::cases()]);
echo "\n";
--EXPECT--
array (
  0 => 
  \E::A,
  1 => 
  \E::B,
)
array (
  0 => 
  \E::A,
  1 => 
  \E::B,
)
