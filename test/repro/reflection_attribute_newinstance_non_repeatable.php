<?php
// #22930 — ReflectionAttribute::newInstance() rejects non-IS_REPEATABLE duplicates (php-src-strict).
#[Attribute(Attribute::TARGET_CLASS)]
class A {}
#[A]
#[A]
class C {}
foreach ((new ReflectionClass(C::class))->getAttributes() as $i => $attr) {
    try {
        $attr->newInstance();
        echo "$i:ok\n";
    } catch (Throwable $e) {
        echo "$i:", get_class($e), ':', $e->getMessage(), "\n";
    }
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class R {}
#[R]
#[R]
class D {}
foreach ((new ReflectionClass(D::class))->getAttributes() as $i => $attr) {
    try {
        $attr->newInstance();
        echo "r$i:ok\n";
    } catch (Throwable $e) {
        echo "r$i:", get_class($e), ':', $e->getMessage(), "\n";
    }
}
