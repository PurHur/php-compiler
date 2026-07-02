<?php

declare(strict_types=1);

class ParentClass
{
    public function inherited(): void
    {
    }
}

class ChildClass extends ParentClass
{
}

$method = new ReflectionMethod(new ChildClass(), 'inherited');
if (!method_exists($method, 'getDeclaringClass')) {
    echo "fail: ReflectionMethod::getDeclaringClass() missing\n";
    exit(1);
}
if ('ParentClass' !== $method->getDeclaringClass()->getName()) {
    echo 'fail: declaring class '.$method->getDeclaringClass()->getName()." expected ParentClass\n";
    exit(1);
}

$byName = new ReflectionMethod(ChildClass::class, 'inherited');
if ('ParentClass' !== $byName->getDeclaringClass()->getName()) {
    echo 'fail: string ctor declaring class '.$byName->getDeclaringClass()->getName()." expected ParentClass\n";
    exit(1);
}

echo "ok\n";
