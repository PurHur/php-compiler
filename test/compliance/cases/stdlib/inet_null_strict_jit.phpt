--TEST--
stdlib ip2long/inet_pton/inet_ntop(null) JIT TypeError under strict_types (#29785, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
foreach (['ip2long', 'inet_pton', 'inet_ntop'] as $fn) {
    try {
        $fn(null);
        echo "fail:$fn\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
TypeError:ip2long(): Argument #1 ($ip) must be of type string, null given
TypeError:inet_pton(): Argument #1 ($ip) must be of type string, null given
TypeError:inet_ntop(): Argument #1 ($ip) must be of type string, null given
