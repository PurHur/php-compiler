--TEST--
Stdlib: ReflectionEnumUnitCase::getValue() returns enum case object (php_reflection.c, #16178)
--FILE--
<?php
enum Pure {
    case A;
}

$r = new ReflectionEnumUnitCase(Pure::class, 'A');
var_export($r->getValue());
echo "\n";
echo $r->getValue()->name, "\n";

enum Backed: string {
    case A = 'x';
}

$rb = new ReflectionEnumUnitCase(Backed::class, 'A');
var_export($rb->getValue());
echo "\n";
--EXPECT--
\Pure::A
A
\Backed::A
