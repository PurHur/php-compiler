<?php
class A {
    public int $ap = 1;
    protected int $ar = 2;
    private int $ai = 3;
    public static int $as = 0;
}
class B extends A {
    public int $bp = 4;
    private int $bi = 5;
}

$rc = new ReflectionClass(B::class);

echo "== unfiltered ==\n";
foreach ($rc->getProperties() as $p) {
    echo $p->getDeclaringClass()->getName(), '::$', $p->getName(), ' (', $p->isPrivate() ? 'private' : ($p->isProtected() ? 'protected' : 'public'), ")\n";
}

echo "== public only ==\n";
foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
    echo $p->getDeclaringClass()->getName(), '::$', $p->getName(), "\n";
}

echo "== private only ==\n";
foreach ($rc->getProperties(ReflectionProperty::IS_PRIVATE) as $p) {
    echo $p->getDeclaringClass()->getName(), '::$', $p->getName(), "\n";
}
