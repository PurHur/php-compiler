--TEST--
is_a()/is_subclass_of() inline ClassName::class operand (#17502, ext/standard/class.c)
--FILE--
<?php
class ParentClass {}
class ChildClass extends ParentClass {}

echo is_a(new ChildClass(), ParentClass::class) ? '1' : '0';
echo is_subclass_of(new ChildClass(), ParentClass::class) ? '1' : '0';
echo "\n";
--EXPECT--
11
