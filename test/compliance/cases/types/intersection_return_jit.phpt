--TEST--
Intersection return type on named function — JIT execute (#6499)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_JIT_EXECUTE')) {
    die('skip JIT execute not enabled');
}
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}

function f(): A&B {
    return new C();
}

class D {
    public function m(): A&B {
        return new C();
    }
}

echo get_class(f()), "\n";
echo get_class((new D())->m()), "\n";
?>
--EXPECT--
C
C
