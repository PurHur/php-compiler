<?php
#[Attribute(0)]
class A {}
#[A]
class C {}
try {
    (new ReflectionClass(C::class))->getAttributes()[0]->newInstance();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
