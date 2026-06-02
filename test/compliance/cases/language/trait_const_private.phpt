--TEST--
private trait class constant not accessible from global scope (issue #4651, Zend zend_constants.c)
--FILE--
<?php
trait T {
    private const SECRET = 42;
}
class C {
    use T;
}
try {
    echo C::SECRET;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot access private constant C::SECRET
