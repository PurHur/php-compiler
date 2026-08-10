--TEST--
Language: :static return TypeError includes Class::method(): prefix (#29913, Zend/zend_execute_API.c)
--FILE--
<?php
class A
{
    public static function make(): static
    {
        return new static();
    }
}
class B extends A
{
    public static function make(): static
    {
        return new A();
    }
}
try {
    B::make();
    echo "noerr\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

class C29913
{
    public static function badNull(): static
    {
        return null;
    }
}
try {
    C29913::badNull();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
B::make(): Return value must be of type B, A returned
C29913::badNull(): Return value must be of type C29913, null returned
