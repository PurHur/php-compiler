<?php
/** Repro #29955 — ReflectionAttribute::newInstance args + no ctor. */
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
