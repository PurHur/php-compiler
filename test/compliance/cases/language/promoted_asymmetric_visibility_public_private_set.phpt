--TEST--
PHP 8.4 asymmetric visibility: promoted public private(set) outside read/write (#8760, #9161, zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
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
Error: Cannot modify public private(set) property D::$x from global scope
