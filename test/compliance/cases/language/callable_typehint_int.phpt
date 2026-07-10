--TEST--
callable type hint rejects int at call boundary (#17742, Zend/zend_type_check.c)
--FILE--
<?php
declare(strict_types=1);

function takesCallable(callable $cb): void
{
}

try {
    takesCallable(1);
    echo "fail\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECTF--
TypeError: takesCallable(): Argument #1 ($cb) must be of type callable, int given, called in %s on line %d
