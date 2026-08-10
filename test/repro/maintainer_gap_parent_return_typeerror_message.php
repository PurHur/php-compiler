<?php

// #29912 — :parent return TypeError must name the resolved parent class (zend_execute_API.c).
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
    echo 'msg:'.$e->getMessage()."\n";
}
