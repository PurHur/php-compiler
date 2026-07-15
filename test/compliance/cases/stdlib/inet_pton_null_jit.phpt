--TEST--
stdlib inet_pton(null) JIT — TypeError (#18789, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
try {
    inet_pton(null);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
inet_pton(): Argument #1 ($address) must be of type string, null given
