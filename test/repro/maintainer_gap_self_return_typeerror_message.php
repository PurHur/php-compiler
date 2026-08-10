<?php

// #29911 — :self return TypeError must name the resolved class (zend_execute_API.c).
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
    echo 'msg:'.$e->getMessage()."\n";
}
