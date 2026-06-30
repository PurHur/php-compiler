--TEST--
PHP 8.4 asymmetric visibility: promoted public private(set) — compile fatal (#13960, zend_compile.c)
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
--EXPECT_EXIT--
255
