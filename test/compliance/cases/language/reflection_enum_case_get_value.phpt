--TEST--
Language: ReflectionEnumCase::getValue() returns enum case object not backing scalar (#9537, php_reflection.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$c = (new ReflectionEnum(E::class))->getCase('A');
var_export($c->getValue());
echo "\n";
var_export($c->getBackingValue());
echo "\n";

$viaCases = (new ReflectionEnum(E::class))->getCases()[1];
var_export($viaCases->getValue());
echo "\n";
--EXPECT--
\E::A
1
\E::B
