<?php
// Issue #22581 — ReflectionClassConstant::$class / getDeclaringClass() for inherited constants
class Base
{
    public const C = 1;
}
class Child extends Base
{
}

$r = new ReflectionClassConstant(Child::class, 'C');
echo 'class=', $r->class, "\n";
echo 'getDeclaringClass=', $r->getDeclaringClass()->getName(), "\n";

$d = new ReflectionClassConstant(Base::class, 'C');
echo 'direct_class=', $d->class, "\n";
echo 'direct_decl=', $d->getDeclaringClass()->getName(), "\n";

$via = (new ReflectionClass(Child::class))->getReflectionConstant('C');
echo 'via_class=', $via->class, "\n";
echo 'via_decl=', $via->getDeclaringClass()->getName(), "\n";

foreach ((new ReflectionClass(Child::class))->getReflectionConstants() as $c) {
    if ($c->getName() === 'C') {
        echo 'list_class=', $c->class, "\n";
        echo 'list_decl=', $c->getDeclaringClass()->getName(), "\n";
    }
}
