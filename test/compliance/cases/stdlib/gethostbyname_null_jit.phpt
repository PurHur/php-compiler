--TEST--
stdlib gethostbyname(null) JIT — TypeError (#18787, ext/standard/dns.c)
--JIT--
--FILE--
<?php
try {
    gethostbyname(null);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
gethostbyname(): Argument #1 ($hostname) must be of type string, null given
