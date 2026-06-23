--TEST--
stdlib join()/implode() — null separator TypeError (#10292, ext/standard/string.c)
--FILE--
<?php
try {
    join(null, ['a', 'b']);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    implode(null, ['a', 'b']);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
join(): Argument #1 ($separator) must be of type array|string, null given
TypeError
implode(): Argument #1 ($separator) must be of type array|string, null given
