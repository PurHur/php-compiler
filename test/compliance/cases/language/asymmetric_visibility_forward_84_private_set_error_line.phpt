--TEST--
Language: asymmetric visibility forward 8.4 — private(set) Error::getLine() is assign in method (#29665, zend_object_handlers.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {
    public private(set) string $x = "a";
}
class B extends A {
    public function setX(string $v): void
    {
        $this->x = $v;
    }
}
$b = new B();
try {
    $b->setX("z");
} catch (Error $e) {
    echo "method:", $e->getMessage(), " line=", $e->getLine(), "\n";
}
try {
    $b->x = "z";
} catch (Error $e) {
    echo "direct:", $e->getMessage(), " line=", $e->getLine(), "\n";
}
--EXPECT--
method:Cannot modify private(set) property A::$x from scope B line=8
direct:Cannot modify private(set) property A::$x from global scope line=18
