--TEST--
stdlib hash_hmac_file() unknown algo ValueError cites hash_hmac_file() (#30646, ext/hash/hash.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
try {
    hash_hmac_file('nope', '/etc/hosts', 'k');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hash_hmac_file(null, '/etc/hosts', 'k');
    echo "null uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hash_hmac('nope', 'data', 'key');
    echo "hmac uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
?>
--EXPECT--
hash_hmac_file(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm
hash_hmac_file(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm
hash_hmac(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm
