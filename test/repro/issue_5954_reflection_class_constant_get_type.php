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
