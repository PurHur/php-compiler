--TEST--
stdlib hash_init() invalid algo ValueError (#7174)
--FILE--
<?php
try {
    hash_init('nope');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_init(): Argument #1 ($algo) must be a valid hashing algorithm
