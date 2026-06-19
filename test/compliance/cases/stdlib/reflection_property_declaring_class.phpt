--TEST--
ReflectionProperty::getDeclaringClass() — declaring class for inherited properties (#9878, ext/reflection/php_reflection.c)
--FILE--
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
    echo $p->getName(), ': ', $p->getDeclaringClass()->getName(), "\n";
}

$p = new ReflectionProperty(B::class, 'ap');
echo 'direct ap: ', $p->getDeclaringClass()->getName(), "\n";
$p = new ReflectionProperty(A::class, 'ai');
echo 'direct ai: ', $p->getDeclaringClass()->getName(), "\n";
--EXPECT--
ap: A
ai: A
bp: B
direct ap: A
direct ai: A
