--TEST--
stdlib join()/implode() JIT — null separator TypeError under strict_types (#11013, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
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
