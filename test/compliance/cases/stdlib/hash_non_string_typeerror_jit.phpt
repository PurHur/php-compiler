--TEST--
stdlib hash()/hash_hmac() JIT — TypeError for non-string operands (#4951)
--FILE--
<?php
foreach (['hash', 'hash_hmac'] as $fn) {
    try {
        $fn('md5', []);
        echo "$fn: no throw\n";
    } catch (Throwable $e) {
        echo "$fn: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    hash_hmac('md5', 'data', new stdClass());
} catch (Throwable $e) {
    echo 'hash_hmac key: ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
hash: TypeError: hash(): Argument #2 ($data) must be of type string, array given
hash_hmac: ArgumentCountError: hash_hmac() expects at least 3 arguments, 2 given
hash_hmac key: TypeError: hash_hmac(): Argument #3 ($key) must be of type string, stdClass given
