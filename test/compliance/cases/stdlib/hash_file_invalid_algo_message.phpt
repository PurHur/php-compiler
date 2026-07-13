--TEST--
stdlib hash_file() invalid algo message names callee (ext/hash/hash.c, issue #18675)
--FILE--
<?php
try {
    hash_file('nope', __FILE__);
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_file(): Argument #1 ($algo) must be a valid hashing algorithm

