--TEST--
Language: backed enum loose == / != JIT execute parity (#9727, re-#9660, zend_operators.c)
--FILE--
<?php
enum E: int { case A = 1; }
enum S: string { case B = 'b'; }

var_export(E::A == 1);
echo "\n";
var_export(E::A != 1);
echo "\n";
var_export(S::B == 'b');
echo "\n";
var_export(S::B === 'b');
echo "\n";
var_export(E::A == E::A);
echo "\n";
var_export(E::A === E::A);
echo "\n";
?>
--EXPECT--
false
true
false
false
true
true
