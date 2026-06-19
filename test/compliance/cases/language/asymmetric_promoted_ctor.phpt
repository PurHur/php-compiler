--TEST--
PHP 8.4 asymmetric visibility: promoted public private(set) with default (#10001, zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public private(set) int $x = 1) {}
}
$c = new C(2);
echo $c->x, "\n";
try {
    $c->x = 3;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
2
Error: Cannot modify private(set) property C::$x from global scope
