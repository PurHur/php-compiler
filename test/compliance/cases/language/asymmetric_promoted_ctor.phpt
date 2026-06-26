--TEST--
PHP 8.4 asymmetric visibility: promoted public private(set) compiles (#11868, zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public private(set) int $x = 1) {}
}
echo (new C())->x, "\n";
--EXPECT--
1
