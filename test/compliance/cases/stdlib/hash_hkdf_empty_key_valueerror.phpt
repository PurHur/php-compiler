--TEST--
stdlib hash_hkdf() — empty key ValueError (#12231, ext/hash/hash_hkdf.c)
--FILE--
<?php
try {
    hash_hkdf('sha256', '', 32);
    echo "no exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hash_hkdf(): Argument #2 ($key) cannot be empty
