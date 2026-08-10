--TEST--
Language: :self return TypeError names resolved class (#29911, Zend/zend_execute_API.c)
--FILE--
<?php
class A
{
    public static function make(): self
    {
        return new static();
    }
}
class C extends A
{
    public static function make(): self
    {
        return new A();
    }
}
try {
    C::make();
    echo "noerr\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
C::make(): Return value must be of type C, A returned
