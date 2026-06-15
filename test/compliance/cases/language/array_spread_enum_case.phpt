--TEST--
Language: array spread with enum cases preserves case objects (#8814, zend_compile.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [...[E::A], E::B];
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
