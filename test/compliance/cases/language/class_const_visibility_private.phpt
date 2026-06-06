--TEST--
private/protected class constants reject external fetch (issue #6784, Zend zend_constants.c)
--FILE--
<?php
class C {
    private const X = 1;
    protected const Y = 2;
}
class P {
    private const Z = 9;
}
class Child extends P {
    public function f(): void {
        try {
            echo parent::Z;
        } catch (Error $e) {
            echo $e->getMessage(), "\n";
        }
    }
}
enum E: int {
    case A = 1;
    private const SECRET = 3;
}
try {
    echo C::X;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo C::Y;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo E::SECRET;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
(new Child())->f();
--EXPECT--
Cannot access private constant C::X
Cannot access protected constant C::Y
Cannot access private constant E::SECRET
Cannot access private constant P::Z
