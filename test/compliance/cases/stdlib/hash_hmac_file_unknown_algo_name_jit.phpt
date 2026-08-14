--JIT--
--TEST--
stdlib hash_hmac_file() JIT unknown algo ValueError cites hash_hmac_file() (#30646, ext/hash/hash.c)
--FILE--
<?php
try {
    hash_hmac_file('nope', '/etc/hosts', 'k');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hash_hmac('nope', 'data', 'key');
    echo "hmac uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_hmac_file(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm
hash_hmac(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm
