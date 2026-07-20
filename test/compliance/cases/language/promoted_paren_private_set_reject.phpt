--TEST--
Language: promoted constructor public (private(set)) compiles on 8.4 profile (#16495, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public (private(set)) int $x = 1) {}
}
$d = new D();
try {
    $d->x = 2;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot modify private(set) property D::$x from global scope
