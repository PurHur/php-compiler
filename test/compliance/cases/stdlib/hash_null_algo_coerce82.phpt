--TEST--
stdlib hash() null $algo still coerces then ValueError on 8.2 profile (#20304, ext/hash/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
try {
    hash(null, 'x');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash(): Argument #1 ($algo) must be a valid hashing algorithm
