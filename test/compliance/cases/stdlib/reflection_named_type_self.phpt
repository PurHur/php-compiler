--TEST--
ReflectionNamedType::getName() preserves self/parent/static spellings (#12349, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public function m(self $x): self {}
}
$rm = new ReflectionMethod(C::class, 'm');
echo $rm->getParameters()[0]->getType()->getName(), "\n";
echo $rm->getReturnType()->getName(), "\n";
echo $rm->getParameters()[0]->getType()->isBuiltin() ? '1' : '0', "\n";
?>
--EXPECT--
self
self
0
