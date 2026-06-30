--TEST--
Language: promoted constructor public private(set) (#13914, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
$d = new D();
echo $d->x, "\n";
try {
    $d->x = 2;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
1
Error: Cannot modify private(set) property D::$x from global scope
