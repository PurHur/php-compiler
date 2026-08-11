--TEST--
stdlib getprotobyname(null) TypeError under strict_types (#30282, ext/standard/network.c)
--FILE--
<?php
declare(strict_types=1);
try {
    getprotobyname(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:getprotobyname(): Argument #1 ($protocol) must be of type string, null given
