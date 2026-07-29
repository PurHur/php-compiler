<?php

declare(strict_types=1);

// #24672 — ReflectionProperty::isAbstract() on default 8.4.0-dev profile (no PHP_COMPILER_PROFILE).

abstract class A {
    abstract public string $x { get; }
}

class B extends A {
    public string $x { get => 'ok'; }
}

$r1 = new ReflectionProperty(A::class, 'x');
$r2 = new ReflectionProperty(B::class, 'x');
var_export($r1->isAbstract());
echo "\n";
var_export($r2->isAbstract());
echo "\n";
