--TEST--
Language: parent param TypeError names resolved parent class (#29930, Zend/zend_execute_API.c)
--FILE--
<?php
class A
{
}
class B extends A
{
    public function f(parent $x)
    {
        echo "ok";
    }
}
try {
    (new B)->f(1);
    echo "noerr\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
B::f(): Argument #1 ($x) must be of type A, int given, called in %s on line %d
