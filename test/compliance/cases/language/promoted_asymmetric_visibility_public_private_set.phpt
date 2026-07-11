--TEST--
PHP 8.4 asymmetric visibility: promoted public (private(set)) compiles (#16495, zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public (private(set)) int $x = 1) {}
}
echo (new D(42))->x, "\n";
--EXPECT--
42
