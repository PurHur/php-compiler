--TEST--
Language: &$obj->readonlyProp Errors at fetch — Zend get_property_ptr_ptr (#25620, zend_readonly.c)
--FILE--
<?php
class A {
    public function __construct(public readonly int $x) {}
}
$a = new A(1);
try {
    $r = &$a->x;
    echo "REF_OK\n";
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
try {
    $a->x = 2;
    echo "WRITE_OK\n";
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
class U { public readonly int $x; }
$u = new U();
try {
    $r2 = &$u->x;
    echo "UNINIT_OK\n";
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot modify readonly property A::$x
Error: Cannot modify readonly property A::$x
Error: Cannot indirectly modify readonly property U::$x
