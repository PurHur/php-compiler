--TEST--
Language: backed enum case ==/===/<=> with scalar operands is false (#9583, zend_operators.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

var_export(E::A == 1);
echo "\n";
var_export(E::A === 1);
echo "\n";
var_export(E::A <=> 1);
echo "\n";
var_export(E::A < 2);
echo "\n";
?>
--EXPECT--
false
false
1
false
