--TEST--
Language: unset() on typed static property must Error (#6648, zend_object_handlers.c)
--FILE--
<?php
class C {
    public static int $x = 1;
}
try {
    unset(C::$x);
    echo "unset succeeded\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Attempt to unset static property C::$x
