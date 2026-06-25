--TEST--
PHP 8.4 asymmetric visibility: promoted public private(set) compiles (#11546, zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public private(set) int $x = 1) {}
}
$c = new C();
echo $c->x, "\n";
try {
    $c->x = 2;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
1
Error: Cannot modify private(set) property C::$x from global scope
