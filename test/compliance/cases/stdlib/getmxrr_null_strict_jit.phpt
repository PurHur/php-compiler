--TEST--
stdlib getmxrr(null)/dns_get_mx(null) JIT TypeError under strict_types (#29810, ext/standard/dns.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
$hosts = [];
try {
    getmxrr(null, $hosts);
    echo "fail:getmxrr\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$hosts2 = [];
try {
    dns_get_mx(null, $hosts2);
    echo "fail:dns_get_mx\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:getmxrr(): Argument #1 ($hostname) must be of type string, null given
TypeError:dns_get_mx(): Argument #1 ($hostname) must be of type string, null given
