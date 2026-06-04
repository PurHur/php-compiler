--TEST--
Language: enum user const E::FOO resolves at runtime (zend_enum.c, #5882 / #5054)
--FILE--
<?php
enum E: int {
    case A = 1;
    public const FOO = 2;
}
var_export([E::FOO, E::A->value]);
echo "\n";
--EXPECT--
array (
  0 => 2,
  1 => 1,
)
