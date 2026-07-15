--TEST--
stdlib inet_ntop(null) — TypeError (#18789, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    inet_ntop(null);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
inet_ntop(): Argument #1 ($in_addr) must be of type string, null given
