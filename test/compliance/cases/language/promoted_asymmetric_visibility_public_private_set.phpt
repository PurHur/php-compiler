--TEST--
PHP 8.4 asymmetric visibility: promoted private(set) outside read/write (#8760, #9161, zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(private(set) int $x = 1) {}
}
$d = new D();
echo $d->x, "\n";
try {
    $d->x = 2;
    echo "write uncaught\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
1
Error: Cannot modify private(set) property D::$x from global scope
