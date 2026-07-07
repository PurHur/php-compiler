<?php

declare(strict_types=1);

interface I
{
}

class A implements I
{
}

class B extends A
{
}

abstract class AbstractC
{
}

$r = new ReflectionClass(B::class);

foreach (['isSubclassOf', 'implementsInterface', 'isInstance', 'isInstantiable', 'isAbstract', 'getParentClass', 'getConstructor'] as $m) {
    echo $m, '=', method_exists($r, $m) ? 'yes' : 'no', "\n";
}

echo 'isSubclassOf_A=', $r->isSubclassOf(A::class) ? 'yes' : 'no', "\n";
echo 'implements_I=', $r->implementsInterface(I::class) ? 'yes' : 'no', "\n";
echo 'isInstance_B=', $r->isInstance(new B()) ? 'yes' : 'no', "\n";
echo 'isInstance_A=', $r->isInstance(new A()) ? 'yes' : 'no', "\n";

$parent = $r->getParentClass();
echo 'parent=', $parent instanceof ReflectionClass ? $parent->getName() : 'false', "\n";

$ra = new ReflectionClass(AbstractC::class);
echo 'abstract_instantiable=', $ra->isInstantiable() ? 'yes' : 'no', "\n";
echo 'abstract_isabstract=', $ra->isAbstract() ? 'yes' : 'no', "\n";

$ri = new ReflectionClass(I::class);
echo 'interface_instantiable=', $ri->isInstantiable() ? 'yes' : 'no', "\n";

echo "ok\n";
