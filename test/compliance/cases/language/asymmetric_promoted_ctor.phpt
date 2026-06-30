--TEST--
PHP 8.4 asymmetric visibility: promoted public private(set) (#13914, zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public private(set) int $x = 1) {}
}
echo (new C())->x, "\n";
try {
    (new C())->x = 2;
    echo "write uncaught\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
1
Error: Cannot modify private(set) property C::$x from global scope
