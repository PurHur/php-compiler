--TEST--
Language: unset() on untyped static property must Error (#23691, zend_object_handlers.c)
--FILE--
<?php
class C {
    public static $x = 1;
}
try {
    unset(C::$x);
    echo "NO_THROW ", var_export(C::$x, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Attempt to unset static property C::$x
