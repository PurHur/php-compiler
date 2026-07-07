--TEST--
Stdlib: ReflectionClass inheritance API — isSubclassOf/implementsInterface/isInstance (ext/reflection/php_reflection.c, #6302)
--FILE--
<?php
interface I {}
class A implements I {}
class B extends A {}
abstract class AbstractC {}

$r = new ReflectionClass(B::class);
echo method_exists($r, 'isSubclassOf') ? '1' : '0';
echo "\n";
echo $r->isSubclassOf(A::class) ? '1' : '0';
echo "\n";
echo $r->implementsInterface(I::class) ? '1' : '0';
echo "\n";
echo $r->isInstance(new B()) ? '1' : '0';
echo "\n";
echo $r->isInstance(new A()) ? '1' : '0';
echo "\n";

$parent = $r->getParentClass();
echo $parent instanceof ReflectionClass ? $parent->getName() : 'false';
echo "\n";

$ra = new ReflectionClass(AbstractC::class);
echo $ra->isInstantiable() ? '1' : '0';
echo "\n";
echo $ra->isAbstract() ? '1' : '0';
echo "\n";

$ri = new ReflectionClass(I::class);
echo $ri->isInstantiable() ? '1' : '0';
echo "\n";
--EXPECT--
1
1
1
1
0
A
0
1
0
