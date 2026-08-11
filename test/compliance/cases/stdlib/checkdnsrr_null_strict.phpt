--TEST--
stdlib checkdnsrr(null) TypeError under strict_types (#30261, ext/standard/dns.c)
--FILE--
<?php
declare(strict_types=1);
try {
    checkdnsrr(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    dns_check_record(null);
    echo "fail_alias\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:checkdnsrr(): Argument #1 ($hostname) must be of type string, null given
TypeError:dns_check_record(): Argument #1 ($hostname) must be of type string, null given
