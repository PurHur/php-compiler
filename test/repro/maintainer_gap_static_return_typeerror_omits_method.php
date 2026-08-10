<?php

// #29913 — :static return TypeError must include Class::method(): prefix (zend_execute_API.c).
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
    echo 'msg:'.$e->getMessage()."\n";
}
