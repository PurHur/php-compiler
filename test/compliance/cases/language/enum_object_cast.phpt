--TEST--
Language: (object) cast on enum case — identity not empty stdClass (#9569, zend_operators.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; }
enum U { case X; }

$o = (object) E::A;
var_export($o === E::A);
echo "\n";
var_export($o instanceof E);
echo "\n";
var_export($o);
echo "\n";

$ux = U::X;
$o2 = (object) $ux;
var_export($o2 === U::X);
echo "\n";
var_export($o2 instanceof U);
echo "\n";
--EXPECT--
true
true
\E::A
true
true
