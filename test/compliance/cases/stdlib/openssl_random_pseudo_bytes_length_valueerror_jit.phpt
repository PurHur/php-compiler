--TEST--
stdlib openssl_random_pseudo_bytes() JIT — negative length ValueError cites openssl (#18156)
--FILE--
<?php
try {
    openssl_random_pseudo_bytes(-1, $strong);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
openssl_random_pseudo_bytes(): Argument #1 ($length) must be greater than 0
