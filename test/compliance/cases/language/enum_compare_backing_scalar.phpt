--TEST--
Language: backed enum case === / == with backing scalar is false (zend_operators.c, #5798)
--FILE--
<?php
enum E: int { case A = 1; }
enum S: string { case B = 'x'; }

echo 'int-ident:', var_export(E::A === 1, true), "\n";
echo 'int-equal:', var_export(E::A == 1, true), "\n";
echo 'int-ident-rev:', var_export(1 === E::A, true), "\n";
echo 'int-equal-rev:', var_export(1 == E::A, true), "\n";
echo 'str-ident:', var_export(S::B === 'x', true), "\n";
echo 'str-equal:', var_export(S::B == 'x', true), "\n";
?>
--EXPECT--
int-ident:false
int-equal:false
int-ident-rev:false
int-equal-rev:false
str-ident:false
str-equal:false
