--TEST--
stdlib getservbyname(null) TypeError under strict_types (#30281, ext/standard/network.c)
--FILE--
<?php
declare(strict_types=1);
try {
    getservbyname(null, 'tcp');
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    getservbyname('http', null);
    echo "fail_protocol\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:getservbyname(): Argument #1 ($service) must be of type string, null given
TypeError:getservbyname(): Argument #2 ($protocol) must be of type string, null given
