--TEST--
Reflection: ReflectionEnumCase::getValue() pure enum returns case object (#16178, php_reflection.c)
--FILE--
<?php
enum PureEnum {
    case Alpha;
}

echo (new ReflectionEnum(PureEnum::class))->getCase('Alpha')->getValue()->name, "\n";
--EXPECT--
Alpha
