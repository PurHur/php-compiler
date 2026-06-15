--TEST--
PHP 8.4 asymmetric visibility: promoted private(get) read guard (#8760, zend_property_hooks.c)
--FILE--
<?php
class D {
    public function __construct(private(get) int $x = 1) {}
}
$d = new D();
try {
    echo $d->x, "\n";
    echo "uncaught\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot access private(get) property D::$x from global scope
