--TEST--
stdlib gethostbyaddr(null) JIT TypeError under strict_types (#29809, ext/standard/dns.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    gethostbyaddr(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:gethostbyaddr(): Argument #1 ($ip) must be of type string, null given
