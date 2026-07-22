--TEST--
stdlib ReflectionClass::getMethods() IS_ABSTRACT filter (#22129, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

abstract class A {
    abstract public function m(): void;
    public function concrete(): void {}
}

$r = new ReflectionClass(A::class);
foreach ($r->getMethods(ReflectionMethod::IS_ABSTRACT) as $m) {
    echo $m->getDeclaringClass()->getName(), '::', $m->getName(), "\n";
}
--EXPECT--
A::m
