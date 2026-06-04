--TEST--
Language: ReflectionClassConstant::getType() on typed class constants (#5954)
--FILE--
<?php
class C {
    public const string NAME = 'hi';
    public const FOO = 42;
}
$rc = new ReflectionClassConstant(C::class, 'NAME');
$t = $rc->getType();
echo $t->getName(), "\n";
$untyped = new ReflectionClassConstant(C::class, 'FOO');
echo $untyped->getType() === null ? "null\n" : "typed\n";
--EXPECT--
string
null
