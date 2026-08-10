--TEST--
Language: self param TypeError names resolved class (#29930, Zend/zend_execute_API.c)
--FILE--
<?php
class A
{
    public function f(self $x)
    {
        echo "ok";
    }
}
try {
    (new A)->f(1);
    echo "noerr\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
A::f(): Argument #1 ($x) must be of type A, int given, called in %s on line %d
