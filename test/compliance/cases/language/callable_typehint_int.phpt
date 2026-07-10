--TEST--
language callable type hint rejects non-callable int (#17742, Zend/zend_type_check.c)
--FILE--
<?php
function takesCallable(callable $c): void {
    echo 'entered', "\n";
}

try {
    takesCallable(1);
    echo 'no error', "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
takesCallable(): Argument #1 ($c) must be of type callable, int given, called in %s on line %d
