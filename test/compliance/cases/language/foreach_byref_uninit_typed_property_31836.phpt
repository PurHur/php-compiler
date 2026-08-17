--TEST--
Language: foreach-by-ref on uninitialized typed property uses by-ref Error (#31836, zend_object_handlers.c)
--FILE--
<?php
class C {
    public int $a;
}
$o = new C;
try {
    $r = &$o->a;
    echo "byref_ok\n";
} catch (Error $e) {
    echo 'byref:', $e->getMessage(), "\n";
}

class D {
    public array $a;
}
$d = new D;
try {
    foreach ($d->a as &$v) {
    }
    echo "foreach_ok\n";
} catch (Error $e) {
    echo 'foreach:', $e->getMessage(), "\n";
}
--EXPECT--
byref:Cannot access uninitialized non-nullable property C::$a by reference
foreach:Cannot access uninitialized non-nullable property D::$a by reference
