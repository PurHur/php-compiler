--TEST--
Language: ReflectionClassConstant::hasType() on typed class constants (#17359)
--FILE--
<?php
class C {
    public const string NAME = 'hi';
    public const FOO = 42;
}
$typed = new ReflectionClassConstant(C::class, 'NAME');
echo $typed->hasType() ? "typed\n" : "untyped\n";
$untyped = new ReflectionClassConstant(C::class, 'FOO');
echo $untyped->hasType() ? "typed\n" : "untyped\n";
echo $typed->hasType() === ($typed->getType() !== null) ? "consistent\n" : "inconsistent\n";
--EXPECT--
typed
untyped
consistent
