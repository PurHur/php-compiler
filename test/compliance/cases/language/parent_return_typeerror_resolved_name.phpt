--TEST--
Language: :parent return TypeError names resolved parent class (#29912, Zend/zend_execute_API.c)
--FILE--
<?php
class A
{
}
class B extends A
{
    public static function bad(): parent
    {
        return new stdClass();
    }
}
try {
    B::bad();
    echo "noerr\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
B::bad(): Return value must be of type A, stdClass returned
