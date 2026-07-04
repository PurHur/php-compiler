--TEST--
AOT: ReflectionEnumCase::getValue() pure enum returns case object (#16178, php_reflection.c)
--FILE--
<?php
enum PureEnum {
    case Alpha;
}

echo (new ReflectionEnum(PureEnum::class))->getCase('Alpha')->getValue()->name, "\n";

enum Backed: int {
    case A = 1;
}

var_export((new ReflectionEnum(Backed::class))->getCase('A')->getValue());
echo "\n";
--EXPECT--
Alpha
\Backed::A
