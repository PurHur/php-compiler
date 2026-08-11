--TEST--
stdlib getprotobynumber(null)/getservbyport(null) TypeError under strict_types (#30283, ext/standard/network.c)
--FILE--
<?php
declare(strict_types=1);
try {
    getservbyport(null, 'tcp');
    echo "fail-port\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    getprotobynumber(null);
    echo "fail-proto\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:getservbyport(): Argument #1 ($port) must be of type int, null given
TypeError:getprotobynumber(): Argument #1 ($protocol) must be of type int, null given
