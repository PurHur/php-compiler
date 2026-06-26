--TEST--
stdlib hash_pbkdf2() — non-positive iterations and negative length ValueError (#12230, ext/hash/hash_pbkdf2.c)
--FILE--
<?php
foreach ([0, -1] as $iterations) {
    try {
        hash_pbkdf2('sha256', 'password', 'salt', $iterations, 32);
        echo "iterations={$iterations}: no exception\n";
    } catch (ValueError $e) {
        echo "iterations={$iterations}: ", $e->getMessage(), "\n";
    }
}
try {
    hash_pbkdf2('sha256', 'password', 'salt', 1, -1);
    echo "length=-1: no exception\n";
} catch (ValueError $e) {
    echo "length=-1: ", $e->getMessage(), "\n";
}
--EXPECT--
iterations=0: hash_pbkdf2(): Argument #4 ($iterations) must be greater than 0
iterations=-1: hash_pbkdf2(): Argument #4 ($iterations) must be greater than 0
length=-1: hash_pbkdf2(): Argument #5 ($length) must be greater than or equal to 0
