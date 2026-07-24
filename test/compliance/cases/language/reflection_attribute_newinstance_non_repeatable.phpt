--TEST--
Language: ReflectionAttribute::newInstance() Error on non-IS_REPEATABLE duplicates (#22930)
--FILE--
<?php
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
--EXPECT--
0:Error:Attribute "A" must not be repeated
1:Error:Attribute "A" must not be repeated
r0:ok
r1:ok
