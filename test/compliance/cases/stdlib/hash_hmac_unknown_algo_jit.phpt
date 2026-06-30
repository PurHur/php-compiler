--JIT--
--TEST--
stdlib hash_hmac() JIT unknown algorithm ValueError (#4408, ext/hash/hash.c)
--FILE--
<?php
try {
    hash_hmac('nope', 'data', 'key');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
echo hash_hmac('sha256', 'body', 'key'), "\n";
?>
--EXPECT--
hash_hmac(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm
515aae133b435d4000956731f68ae5cf5eb85d4f0dc6a546d2bfcd3595ec1ae1
