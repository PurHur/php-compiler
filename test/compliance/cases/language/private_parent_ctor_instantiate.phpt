--TEST--
Language: private parent constructor blocks child instantiation (#5382, zend_objects.c)
--FILE--
<?php
abstract class A {
    private function __construct() {}
}
class B extends A {}

try {
    new B();
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Call to private A::__construct() from global scope
