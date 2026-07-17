--TEST--
stdlib hash()/hash_hmac()/hash_file() null $algo TypeError on 8.4 forward (#20304, ext/hash/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['hash', 'hash_hmac', 'hash_file'] as $fn) {
    try {
        if ($fn === 'hash') {
            $r = hash(null, 'x');
        } elseif ($fn === 'hash_hmac') {
            $r = hash_hmac(null, 'x', 'k');
        } else {
            $r = hash_file(null, '/etc/hosts');
        }
        echo $fn, ' uncaught ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
try {
    hash('no-such-algo', 'x');
    echo "unknown uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash(): Argument #1 ($algo) must be of type string, null given
hash_hmac(): Argument #1 ($algo) must be of type string, null given
hash_file(): Argument #1 ($algo) must be of type string, null given
hash(): Argument #1 ($algo) must be a valid hashing algorithm
