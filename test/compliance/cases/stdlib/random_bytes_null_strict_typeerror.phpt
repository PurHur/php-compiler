--TEST--
stdlib random_bytes(null)/openssl_random_pseudo_bytes(null) under strict_types — TypeError (#19230)
--FILE--
<?php
declare(strict_types=1);
foreach (['random_bytes', 'openssl_random_pseudo_bytes'] as $fn) {
    try {
        $fn(null);
        echo "$fn: ok\n";
    } catch (Throwable $e) {
        echo "$fn: ", get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
random_bytes: TypeError
random_bytes(): Argument #1 ($length) must be of type int, null given
openssl_random_pseudo_bytes: TypeError
openssl_random_pseudo_bytes(): Argument #1 ($length) must be of type int, null given
