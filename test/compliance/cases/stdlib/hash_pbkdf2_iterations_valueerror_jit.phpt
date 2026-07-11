--TEST--
stdlib hash_pbkdf2() JIT — non-positive iterations ValueError (#12230, ext/hash/hash_pbkdf2.c)
--FILE--
<?php
try {
    hash_pbkdf2('sha256', 'password', 'salt', 0, 32);
    echo "no exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hash_pbkdf2(): Argument #4 ($iterations) must be greater than 0
