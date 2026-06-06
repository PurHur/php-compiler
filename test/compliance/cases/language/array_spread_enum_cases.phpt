--TEST--
Language: array spread of Enum::cases() preserves enum case objects (#5583, zend_execute.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [...E::cases()];
var_export($a);
echo "\n";
var_export($a[0]);
echo "\n";
?>
--EXPECT--
array (
  0 => \E::A,
  1 => \E::B,
)
\E::A
