--TEST--
Language: self:: / parent:: still allowed in compile-time constants (#31145, Zend/zend_compile.c)
--FILE--
<?php
class A {
    const X = 2;
}
class C extends A {
    const Y = self::X;
    const Z = parent::X;
    function f($a = self::X, $b = parent::X) {
        return [$a, $b];
    }
    public $p = self::X;
}
echo C::Y, "\n";
echo C::Z, "\n";
echo (new C)->p, "\n";
echo implode(',', (new C)->f()), "\n";
--EXPECT--
2
2
2
2,2
