<?php
class A {
    public int $ap = 1;
    private int $ai = 2;
}
class B extends A {
    public int $bp = 3;
}

$rc = new ReflectionClass(B::class);
foreach ($rc->getProperties() as $p) {
    echo $p->getName(), ': ';
    echo method_exists($p, 'getDeclaringClass')
        ? $p->getDeclaringClass()->getName()
        : 'NO_METHOD';
    echo "\n";
}
