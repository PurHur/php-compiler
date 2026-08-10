--TEST--
Language: ReflectionAttribute::newInstance Error when args passed to ctor-less attribute (#29955)
--FILE--
<?php
#[Attribute]
class A {}
#[A(1)]
function f() {}
try {
    $a = (new ReflectionFunction('f'))->getAttributes()[0]->newInstance();
    echo 'inst=', get_class($a), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

#[Attribute]
class B {
    public function __construct(public int $x) {}
}
#[B(1)]
function g() {}
try {
    $b = (new ReflectionFunction('g'))->getAttributes()[0]->newInstance();
    echo 'inst=', get_class($b), ' x=', $b->x, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

#[Attribute]
class C {}
#[C]
function h() {}
#[C()]
function i() {}
try {
    echo get_class((new ReflectionFunction('h'))->getAttributes()[0]->newInstance()), "\n";
    echo get_class((new ReflectionFunction('i'))->getAttributes()[0]->newInstance()), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Error:Attribute class A does not have a constructor, cannot pass arguments
inst=B x=1
C
C
