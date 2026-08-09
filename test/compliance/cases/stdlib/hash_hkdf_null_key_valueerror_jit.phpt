--TEST--
stdlib hash_hkdf() JIT — null key coerces then ValueError empty key (#19341, ext/hash/hash_hkdf.c)
--FILE--
<?php
foreach ([null, ''] as $k) {
    try {
        hash_hkdf('sha256', $k);
        echo "no exception\n";
    } catch (ValueError $e) {
        echo var_export($k, true), ' ', $e->getMessage(), "\n";
    }
}
--EXPECT--
NULL hash_hkdf(): Argument #2 ($key) must not be empty
'' hash_hkdf(): Argument #2 ($key) must not be empty
