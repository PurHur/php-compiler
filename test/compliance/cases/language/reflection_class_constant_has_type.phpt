--TEST--
Language: ReflectionClassConstant::hasType() on typed class constants (#17359)
--FILE--
<?php
class C {
    public const string NAME = 'hi';
    public const FOO = 42;
}
$typed = new ReflectionClassConstant(C::class, 'NAME');
$plain = new ReflectionClassConstant(C::class, 'FOO');
echo $typed->hasType() ? "typed\n" : "untyped\n";
echo $plain->hasType() ? "typed\n" : "untyped\n";
$t = $typed->getType();
echo $t->getName(), "\n";
--EXPECT--
typed
untyped
string
